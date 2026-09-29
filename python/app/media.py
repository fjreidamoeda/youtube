"""Loop cache (yt-dlp) + ffmpeg: download em segundo plano, remux para MPEG-TS,
HLS (.m3u8 com segmentos), concat do modo canal e resolucao de URL direta."""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Any

import httpx

from .config import (
    CACHE_DIR,
    COOKIES_FILE,
    CREATE_NO_WINDOW,
    FFMPEG,
    FFPROBE,
    HLS_DIR,
    HLS_IDLE_SECONDS,
    HLS_LIST_SIZE,
    HLS_MAX_CACHE_MB,
    HLS_MAX_SESSIONS,
    HLS_PRESET,
    HLS_TIME,
    HLS_VOD_MAX_AGE,
    LOOP_MAX_AGE,
    MAX_CONCURRENT_DOWNLOADS,
    UA_DESKTOP,
    YTDLP,
    clean_id,
    log,
    video_id_ok,
)

MIN_BYTES = 1_000_000
FAIL_BACKOFF = 120  # segundos sem martelar o yt-dlp depois de uma falha
PARTIAL_MARKS = (".part", ".ytdl", ".temp", ".f_", ".f-")  # download em andamento


# ------------------------------------------------------------------ caminhos

def loop_prefix(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid))


def loop_path(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid) + ".mp4")


def find_loop(vid: str) -> Path | None:
    """Arquivo JA BAIXADO (completo) do video. Ignora .part/.ytdl — servir um
    download pela metade produz video que trava no meio (e no concat, corta a
    transicao)."""
    prefix = loop_prefix(vid)
    name = prefix.name
    for p in (prefix.with_suffix(ext) for ext in (".mp4", ".mkv", ".webm", ".flv", ".mov")):
        if p.is_file() and p.stat().st_size > MIN_BYTES:
            return p
    for p in CACHE_DIR.glob(name + "*"):
        if not p.is_file() or p.suffix == ".log":
            continue
        if any(mark in p.name for mark in PARTIAL_MARKS):
            continue
        if p.stat().st_size > MIN_BYTES:
            return p
    return None


def pid_file(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid) + ".pid")


def fail_file(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid) + ".fail")


def start_file(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid) + ".start")


def ytdlp_log(vid: str) -> Path:
    return CACHE_DIR / ("loop_" + clean_id(vid) + ".log")


def ffmpeg_log(vid: str) -> Path:
    return CACHE_DIR / ("ffmpeg_" + clean_id(vid) + ".log")


# ------------------------------------------------------------------ processos

def process_alive(pid: int) -> bool:
    """Windows nao aceita os.kill(pid, 0) (mataria o processo) -> usa tasklist."""
    if pid <= 0:
        return False
    if os.name == "nt":
        try:
            out = subprocess.run(
                ["tasklist", "/FI", f"PID eq {pid}", "/NH"],
                capture_output=True,
                text=True,
                timeout=10,
                creationflags=CREATE_NO_WINDOW,
            ).stdout
            return bool(out) and str(pid) in out and "INFO" not in out.upper()[:8]
        except Exception:
            return False
    try:
        os.kill(pid, 0)
        return True
    except OSError:
        return False


def download_pid(vid: str) -> int:
    p = pid_file(vid)
    if not p.is_file():
        return 0
    try:
        return int(p.read_text(encoding="utf-8").strip() or 0)
    except Exception:
        return 0


def active_downloads() -> int:
    n = 0
    for p in CACHE_DIR.glob("loop_*.pid"):
        try:
            pid = int(p.read_text(encoding="utf-8").strip() or 0)
        except Exception:
            continue
        if pid > 0 and process_alive(pid):
            n += 1
    return n


# ------------------------------------------------------------------ yt-dlp

def _ytdlp_base() -> list[str]:
    return [YTDLP, "--no-playlist", "--no-warnings", "--no-check-certificates"]


def _ytdlp_cookies() -> list[str]:
    if COOKIES_FILE and Path(COOKIES_FILE).is_file():
        return ["--cookies", COOKIES_FILE]
    return []


def _ytdlp_formats() -> list[list[str]]:
    """Formatos tentados em ordem. O segundo plano (android/tv) destrava
    videos que o YouTube bloqueia para IP de datacenter."""
    return [
        ["-f", "18/bestvideo+bestaudio/b"],
        ["-f", "18/bestvideo+bestaudio/b", "--extractor-args", "youtube:player_client=android,tv"],
    ]


def start_download(vid: str) -> bool:
    """Dispara o download do video em segundo plano. Devolve True se iniciou."""
    vid = clean_id(vid)
    if not video_id_ok(vid):
        return False
    existing = find_loop(vid)
    if existing and (time.time() - existing.stat().st_mtime) < LOOP_MAX_AGE:
        return True
    if download_pid(vid) and process_alive(download_pid(vid)):
        return True
    if fail_file(vid).is_file() and (time.time() - fail_file(vid).stat().st_mtime) < FAIL_BACKOFF:
        return False

    target = loop_path(vid)
    lg = ytdlp_log(vid)
    args = (
        _ytdlp_base()
        + _ytdlp_formats()[0]
        + ["--newline", "--no-mtime", "--retries", "3", "--fragment-retries", "3",
           "--concurrent-fragments", "4", "--socket-timeout", "20"]
        + _ytdlp_cookies()
        + ["-o", str(target), f"https://www.youtube.com/watch?v={vid}"]
    )
    try:
        start_file(vid).write_text(str(int(time.time())), encoding="utf-8")
        fail_file(vid).unlink(missing_ok=True)
        with open(lg, "a", encoding="utf-8", errors="replace") as fh:
            fh.write(f"\n{time.strftime('%c')} iniciando yt-dlp {vid}\n")
            fh.flush()
            proc = subprocess.Popen(
                args,
                stdout=fh,
                stderr=subprocess.STDOUT,
                stdin=subprocess.DEVNULL,
                creationflags=CREATE_NO_WINDOW,
            )
        pid_file(vid).write_text(str(proc.pid), encoding="utf-8")
        log(f"download iniciado {vid} pid={proc.pid}")
        _reap_when_done(vid, proc)
        return True
    except FileNotFoundError:
        log(f"yt-dlp nao encontrado: {YTDLP}")
        return False
    except Exception as e:
        log(f"falha ao iniciar download {vid}: {e}")
        return False


def _reap_when_done(vid: str, proc: subprocess.Popen) -> None:
    """Marca .fail quando o processo morre sem arquivo valido e tenta 1x mais
    com outro player client (destrava video bloqueado)."""
    import threading

    def worker() -> None:
        rc = proc.wait()
        pid_file(vid).unlink(missing_ok=True)
        ok = find_loop(vid) is not None
        if not ok:
            # segunda tentativa com player_client alternativo
            lg = ytdlp_log(vid)
            args = (
                _ytdlp_base() + _ytdlp_formats()[1] + ["--newline", "--no-mtime", "--retries", "2"]
                + _ytdlp_cookies()
                + ["-o", str(loop_path(vid)), f"https://www.youtube.com/watch?v={vid}"]
            )
            try:
                with open(lg, "a", encoding="utf-8", errors="replace") as fh:
                    fh.write(f"\n{time.strftime('%c')} retentando yt-dlp (android,tv) {vid}\n")
                    fh.flush()
                    p2 = subprocess.Popen(
                        args, stdout=fh, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                        creationflags=CREATE_NO_WINDOW,
                    )
                pid_file(vid).write_text(str(p2.pid), encoding="utf-8")
                rc2 = p2.wait()
                pid_file(vid).unlink(missing_ok=True)
                ok = find_loop(vid) is not None
                rc = rc2
            except Exception:
                pass
        if ok:
            fail_file(vid).unlink(missing_ok=True)
            log(f"download OK {vid}")
        else:
            fail_file(vid).write_text(str(int(time.time())), encoding="utf-8")
            log(f"download FALHOU {vid} (rc={rc})")

    threading.Thread(target=worker, daemon=True).start()


def ensure_download(vid: str) -> bool:
    return start_download(vid)


def wait_download(vid: str, max_seconds: int) -> bool:
    """Espera (bloqueante) o download completar. True se o arquivo final existe."""
    vid = clean_id(vid)
    if find_loop(vid):
        return True
    fail_file(vid).unlink(missing_ok=True)
    if not start_download(vid):
        return False
    deadline = time.time() + max(0, max_seconds)
    saw_pid_at: float | None = None
    while time.time() < deadline:
        if find_loop(vid):
            return True
        pid = download_pid(vid)
        if pid > 0:
            saw_pid_at = time.time()
        elif saw_pid_at is None and time.time() > deadline - max_seconds + 4:
            # yt-dlp nem chegou a subir: nao adianta esperar
            return False
        time.sleep(0.5)
    return find_loop(vid) is not None


def download_state(vid: str) -> dict[str, Any]:
    p = find_loop(vid)
    pid = download_pid(vid)
    st = {"file": None, "size": 0, "downloading": False, "failed": False}
    if p:
        st["file"] = str(p)
        st["size"] = p.stat().st_size
    st["downloading"] = pid > 0 and process_alive(pid)
    st["failed"] = fail_file(vid).is_file() and not st["downloading"] and not p
    return st


# ------------------------------------------------------------------ ffprobe

_probe_cache: dict[str, dict[str, Any]] = {}


def ffprobe_info(path: str | Path, max_age: float = 3600) -> dict[str, Any]:
    """Infos do arquivo: codec, dimensao, SAR, fps, audio. Cacheado por caminho+mtime."""
    p = Path(path)
    key = f"{p}:{p.stat().st_mtime if p.is_file() else 0}"
    hit = _probe_cache.get(key)
    if hit:
        return hit
    out: dict[str, Any] = {}
    try:
        args = [
            FFPROBE, "-v", "error", "-show_entries",
            "stream=codec_type,codec_name,width,height,sample_aspect_ratio,display_aspect_ratio,avg_frame_rate,channels",
            "-of", "json", str(p),
        ]
        r = subprocess.run(args, capture_output=True, text=True, timeout=30, creationflags=CREATE_NO_WINDOW)
        if r.returncode == 0:
            import json as _json

            data = _json.loads(r.stdout or "{}")
            for st in data.get("streams", []):
                if st.get("codec_type") == "video" and "v" not in out:
                    out["v"] = st
                elif st.get("codec_type") == "audio" and "a" not in out:
                    out["a"] = st
    except Exception as e:
        out["error"] = str(e)
    _probe_cache[key] = out
    return out


def ffprobe_dims(path: str | Path) -> tuple[int, int] | None:
    v = ffprobe_info(path).get("v") or {}
    try:
        return int(v.get("width", 0)), int(v.get("height", 0))
    except Exception:
        return None


# ------------------------------------------------------------------ ffmpeg

def _copy_ok(path: str | Path) -> bool:
    """`-c copy` so serve se o video for h264 (e o audio aac/mp3)."""
    info = ffprobe_info(path)
    v = (info.get("v") or {}).get("codec_name", "")
    a = (info.get("a") or {}).get("codec_name", "")
    return v == "h264" and a in ("", "aac", "mp3")


def ts_command(src: str, is_local: bool, reencode: bool = False) -> list[str]:
    """Comando EXATO de producao: remux para MPEG-TS (o que o painel IPTV entende).

    Sem h264_metadata=sample_aspect_ratio (quebraria o ffmpeg 4.x) e sem -c copy
    quando o codec nao for compativel -> nesse caso re-encode."""
    args = [FFMPEG, "-y", "-hide_banner", "-loglevel", "error", "-analyzeduration", "2000000", "-probesize", "2000000"]
    if is_local:
        # repete o video para o fluxo ser continuo (o player fica "sem sinal" se acabar)
        args += ["-stream_loop", "-1"]
    args += ["-i", src]
    if reencode or not _copy_ok(src):
        args += ["-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
                 "-c:a", "aac", "-ar", "44100", "-ac", "2"]
    else:
        args += ["-c", "copy", "-bsf:v", "h264_mp4toannexb"]
    args += ["-f", "mpegts", "-"]
    return args


def start_ts_stream(src: str, is_local: bool, logpath: Path | None = None):
    """Sobe o ffmpeg e devolve o Popen (stdout = MPEG-TS)."""
    args = ts_command(src, is_local)
    fh = None
    try:
        if logpath:
            fh = open(logpath, "a", buffering=1, encoding="utf-8", errors="replace")
            fh.write(f"\n{time.strftime('%c')} {' '.join(args)}\n")
        proc = subprocess.Popen(
            args, stdout=subprocess.PIPE, stderr=(fh or subprocess.DEVNULL),
            stdin=subprocess.DEVNULL, creationflags=CREATE_NO_WINDOW, bufsize=0,
        )
        return proc, fh
    except FileNotFoundError:
        log(f"ffmpeg nao encontrado: {FFMPEG}")
        raise


def resolve_direct_url(vid: str) -> str | None:
    """URL googlevideo via yt-dlp (fallback quando o download local falha)."""
    for fmt in _ytdlp_formats():
        args = [YTDLP, "--no-playlist", "--no-warnings", "--no-check-certificates",
                "-f", "best[protocol=https]/best", "--get-url"] + _ytdlp_cookies() + [
            f"https://www.youtube.com/watch?v={clean_id(vid)}"
        ]
        try:
            r = subprocess.run(args, capture_output=True, text=True, timeout=40, creationflags=CREATE_NO_WINDOW)
            if r.returncode == 0:
                for line in (r.stdout or "").splitlines():
                    line = line.strip()
                    if line.startswith("http"):
                        return line
        except Exception:
            continue
    return None


# ------------------------------------------------------------------ modo canal

def pick_channel_videos(paths: list[Path], max_items: int = 20) -> list[Path]:
    """Ignora videos portrait (esticam no painel) e agrupa por dimensoes,
    mantendo o maior grupo (garante transicao limpa no concat)."""
    groups: dict[tuple[int, int], list[Path]] = {}
    for p in paths:
        d = ffprobe_dims(p)
        if not d:
            continue
        w, h = d
        if h > w:  # vertical/portrait
            continue
        groups.setdefault(d, []).append(p)
    if not groups:
        return []
    best = max(groups.items(), key=lambda kv: (len(kv[1]), kv[0][0] * kv[0][1]))
    return best[1][:max_items]


def write_concat_list(channel_id: str, files: list[Path]) -> Path:
    """Lista do demuxer concat. Usa caminho ABSOLUTO e aspas: nomes com espacos
    (ex.: arquivos .ts do YouTube) quebram o concat se nao forem escapados."""
    cf = CACHE_DIR / ("concat_" + clean_id(channel_id) + ".txt")
    lines = []
    for f in files:
        p = str(f.resolve()).replace("\\", "/").replace("'", "'\\''")
        lines.append(f"file '{p}'")
    cf.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return cf


def start_channel_ts(channel_id: str, files: list[Path], logpath: Path | None = None):
    """Concat com RE-ENCODE (normaliza resolucao/codec/audio e garante a
    transicao entre videos) + loop, servindo MPEG-TS continuo."""
    cf = write_concat_list(channel_id, files)
    args = [
        FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
        "-f", "concat", "-safe", "0", "-stream_loop", "-1", "-i", str(cf),
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p", "-g", "50",
        "-c:a", "aac", "-ar", "44100", "-ac", "2",
        "-f", "mpegts", "-",
    ]
    fh = None
    try:
        if logpath:
            fh = open(logpath, "a", buffering=1, encoding="utf-8", errors="replace")
            fh.write(f"\n{time.strftime('%c')} CONCAT canal {channel_id}: {cf.name} ({len(files)} videos)\n")
        proc = subprocess.Popen(
            args, stdout=subprocess.PIPE, stderr=(fh or subprocess.DEVNULL),
            stdin=subprocess.DEVNULL, creationflags=CREATE_NO_WINDOW, bufsize=0,
        )
        return proc, fh
    except FileNotFoundError:
        log(f"ffmpeg nao encontrado: {FFMPEG}")
        raise


# ------------------------------------------------------------------ varios

def ensure_channel_downloads(channel_id: str, max_videos: int = 50) -> list[str]:
    """Garante o download (2o plano) dos videos recentes de um canal."""
    from . import youtube

    data = youtube.cached_channel_videos(channel_id, max_videos)
    ids = [it["id"] for it in data.get("items", []) if video_id_ok(it.get("id", ""))]
    for vid in ids:
        if active_downloads() >= MAX_CONCURRENT_DOWNLOADS:
            break
        start_download(vid)
    return ids


def watch_downloads_once(max_concurrent: int = MAX_CONCURRENT_DOWNLOADS) -> dict[str, int]:
    """Uma passada do download continuo."""
    from . import db

    res = {"canais": 0, "videos": 0, "ativos": 0}
    seen: set[str] = set()
    for c in db.channels_with_download():
        cid = c.get("channel_id") or ""
        if not cid or cid in seen:
            continue
        seen.add(cid)
        res["canais"] += 1
        if active_downloads() >= max_concurrent:
            break
        ids = ensure_channel_downloads(cid, int(c.get("max_videos", 50) or 50))
        res["videos"] += len(ids)
        time.sleep(0.4)  # nao dispara tudo de uma vez
    res["ativos"] = active_downloads()
    log(f"watch: canais={res['canais']} videos={res['videos']} ativos={res['ativos']}")
    return res


def http_head(url: str, timeout: float = 8.0) -> tuple[int, str]:
    try:
        r = httpx.head(url, timeout=timeout, follow_redirects=True, headers={"User-Agent": UA_DESKTOP})
        return r.status_code, r.headers.get("content-type", "")
    except Exception:
        return 0, ""


def disk_free_mb() -> float:
    try:
        du = subprocess.run(["du", "-sm", str(CACHE_DIR)], capture_output=True, text=True)
        if du.returncode == 0:
            return float(du.stdout.split()[0])
    except Exception:
        pass
    total = 0
    for p in CACHE_DIR.glob("loop_*"):
        try:
            total += p.stat().st_size
        except OSError:
            pass
    return total / 1_048_576


# ================================================================== HLS (.m3u8)
#
# Por que segmentar em vez de mandar o MPEG-TS: o painel/player recebe uma
# playlist .m3u8 e puxa pedacinhos (segundos). Isso da seek (avancar pular
# trecho), para/recomeca sem recarregar tudo e nunca fica "sem sinal" num
# video longo. O ffmpeg e quem produz os segmentos no disco.
#
# Dois tipos de sessao:
#   - VOD  (video solto / item de canal): playlist completa, cresce e termina
#           com #EXT-X-ENDLIST -> o player pode andar em qualquer ponto.
#   - LIVE (transmissao ao vivo / modo canal): janela deslizante com
#           delete_segments -> disco limitado e o player acompanha a borda ao vivo.

_SESS_LOCK = threading.Lock()
_SESS: dict[str, dict[str, Any]] = {}  # chave -> {proc, kind, last_seen, dir}
# Um segmento so ja basta para o player comecar (ele recarrega a playlist e
# pega o resto). Esperar 2 custava ~4s a mais de encode na primeira vez.
_SEG_MIN_READY = 1


def hls_key(video_id: str = "", channel_id: str = "") -> str:
    """Chave unica por fluxo: 'v<video>' ou 'c<canal>' (serve de nome de pasta)."""
    if channel_id:
        return "c" + clean_id(channel_id)
    return "v" + clean_id(video_id)


def hls_dir(key: str, create: bool = False) -> Path:
    d = HLS_DIR / key
    if create:
        d.mkdir(parents=True, exist_ok=True)
    return d


def hls_playlist(key: str) -> Path:
    return HLS_DIR / key / "index.m3u8"


def _read_text(path: Path, attempts: int = 8, delay: float = 0.15) -> str:
    """Le um texto do disco tolerando a janela em que o ffmpeg esta escrevendo.

    No Windows o ffmpeg abre a playlist sem compartilhamento de leitura: entre uma
    atualizacao e outra o arquivo fica bloqueado e a leitura da PermissionError.
    Nao e erro de verdade, e so o instante da reescrita -- por isso a repeticao."""
    last: Exception | None = None
    for i in range(attempts):
        try:
            return path.read_text(encoding="utf-8", errors="replace")
        except PermissionError as e:  # ffmpeg reescrevendo agora
            last = e
            time.sleep(delay)
        except FileNotFoundError:
            return ""
        except OSError as e:
            last = e
            time.sleep(delay)
    if last:
        log(f"nao consegui ler {path.name}: {last}")
        return ""
    return ""


def hls_is_complete(key: str) -> bool:
    p = hls_playlist(key)
    return "#EXT-X-ENDLIST" in _read_text(p)


def hls_segment_count(key: str) -> int:
    try:
        return sum(1 for _ in (HLS_DIR / key).glob("seg_*.ts"))
    except OSError:
        return 0


def hls_vod_ready(key: str) -> bool:
    """Ja existe um VOD completo em disco? (evita re-encodar a cada play)"""
    p = hls_playlist(key)
    if not p.is_file() or not hls_is_complete(key):
        return False
    try:
        if time.time() - p.stat().st_mtime > HLS_VOD_MAX_AGE:
            return False
    except OSError:
        return False
    return hls_segment_count(key) > 0


def hls_command(
    key: str,
    src: str,
    live: bool,
    is_local: bool = True,
    concat: Path | None = None,
) -> list[str]:
    """Comando ffmpeg que gera os segmentos .ts e a playlist index.m3u8.

    Re-encoda sempre (nao da para usar -c copy com -force_key_frames, e sem
    keyframe no inicio de cada segmento o player sobe atrasado e a seek falha)."""
    seg = max(2, HLS_TIME)
    out = hls_dir(key, create=True)
    args = [FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
            "-analyzeduration", "2000000", "-probesize", "2000000"]
    if concat is not None:
        # modo canal: repete a lista inteira (os videos ficam em sequencia)
        args += ["-f", "concat", "-safe", "0", "-stream_loop", "-1", "-i", str(concat)]
    else:
        if live and is_local:
            # VOD precisa TERMINAR (ENDLIST) para o player poder andar no video;
            # so o fluxo continuo e repetido.
            args += ["-stream_loop", "-1"]
        args += ["-i", src]

    args += [
        "-c:v", "libx264", "-preset", HLS_PRESET, "-pix_fmt", "yuv420p",
        "-force_key_frames", f"expr:gte(t,n_forced*{seg})",
        "-c:a", "aac", "-ar", "44100", "-ac", "2",
        "-f", "hls", "-hls_time", str(seg),
        "-hls_flags", "independent_segments+temp_file",
        "-hls_segment_filename", str(out / "seg_%05d.ts"),
    ]
    if live:
        args += ["-hls_list_size", str(HLS_LIST_SIZE),
                 "-hls_flags", "independent_segments+temp_file+delete_segments+omit_endlist"]
    else:
        # hls_list_size 0 = playlist com TODOS os segmentos. O padrao do ffmpeg e
        # 5 (!): a playlist do VOD guardava so os ultimos 5 e o seek nao tinha
        # para onde ir -- era o que impedia pular trecho no painel.
        args += ["-hls_list_size", "0"]
    # NAO usamos -hls_playlist_type vod: com ela o ffmpeg so grava a playlist no
    # fim do encode (testado no ffmpeg 8.0) e o painel levaria 503 ate la. Sem
    # essa flag a playlist cresce segmento a segmento (o player ja comeca a
    # tocar) e o ffmpeg acrescenta o #EXT-X-ENDLIST quando acaba -> seek total.
    args.append(str(out / "index.m3u8"))
    return args


def _kill(proc: subprocess.Popen | None) -> None:
    if proc is None or proc.poll() is not None:
        return
    try:
        proc.kill()
        proc.wait(timeout=5)
    except Exception:
        pass


def hls_stop(key: str, remove_dir: bool = True) -> None:
    """Encerra a sessao e (opcionalmente) apaga os segmentos."""
    with _SESS_LOCK:
        s = _SESS.pop(key, None)
    if s:
        _kill(s["proc"])
    if remove_dir:
        shutil.rmtree(hls_dir(key), ignore_errors=True)


def hls_running(key: str) -> bool:
    with _SESS_LOCK:
        s = _SESS.get(key)
        if not s:
            return False
        if s["proc"].poll() is not None:
            _SESS.pop(key, None)
            return False
        return True


def hls_touch(key: str) -> None:
    """Marca a sessao como 'alguem esta assistindo' (o reaper nao mata)."""
    with _SESS_LOCK:
        s = _SESS.get(key)
        if s is not None and s["proc"].poll() is None:
            s["last_seen"] = time.monotonic()


def hls_start(
    key: str,
    src: str,
    live: bool,
    is_local: bool = True,
    concat: Path | None = None,
    logpath: Path | None = None,
) -> tuple[bool, str]:
    """Garante um ffmpeg gerando segmentos para `key`.

    Devolve (ok, motivo). Reaproveita a sessao que ja estiver rodando."""
    with _SESS_LOCK:
        s = _SESS.get(key)
        if s and s["proc"].poll() is None:
            s["last_seen"] = time.monotonic()
            return True, "reaproveitado"
        if s:
            _SESS.pop(key, None)
        running = sum(1 for x in _SESS.values() if x["proc"].poll() is None)
        if running >= HLS_MAX_SESSIONS:
            return False, (f"limite de {HLS_MAX_SESSIONS} transmissoes HLS simultaneas; "
                           f"feche um player e tente de novo")

    # fluxo vivo nao reaproveita: a pasta e zerada para o ffmpeg recomecar limpo
    if live:
        shutil.rmtree(hls_dir(key), ignore_errors=True)
    hls_dir(key, create=True)

    args = hls_command(key, src, live, is_local, concat)
    fh = None
    try:
        if logpath:
            fh = open(logpath, "a", buffering=1, encoding="utf-8", errors="replace")
            fh.write(f"\n{time.strftime('%c')} HLS {'live' if live else 'vod'} {key}: {' '.join(args)}\n")
        proc = subprocess.Popen(
            args, stdout=subprocess.DEVNULL, stderr=(fh or subprocess.DEVNULL),
            stdin=subprocess.DEVNULL, creationflags=CREATE_NO_WINDOW,
        )
    except FileNotFoundError:
        if fh:
            fh.close()
        log(f"ffmpeg nao encontrado: {FFMPEG}")
        return False, "ffmpeg nao encontrado no servidor"
    except Exception as e:
        if fh:
            fh.close()
        return False, f"nao consegui iniciar o ffmpeg: {e}"

    with _SESS_LOCK:
        _SESS[key] = {"proc": proc, "kind": "live" if live else "vod",
                      "last_seen": time.monotonic(), "fh": fh, "dir": hls_dir(key)}
    log(f"hls: sessao {'live' if live else 'vod'} iniciada em {key}")
    return True, "iniciada"


def hls_wait_ready(key: str, max_seconds: float) -> bool:
    """Espera a playlist aparecer e ganhar alguns segmentos.

    Sem isso o painel receberia uma .m3u8 vazia/inexistente e marcaria
    'sem sinal' enquanto o ffmpeg ainda nem gravou o primeiro pedaco."""
    deadline = time.monotonic() + max(0.0, max_seconds)
    while True:
        pl = hls_playlist(key)
        if pl.is_file() and hls_segment_count(key) >= _SEG_MIN_READY:
            return True
        if not hls_running(key) and not pl.is_file():
            return False
        if time.monotonic() >= deadline:
            return False
        time.sleep(0.4)


def read_playlist(key: str, base: str) -> str:
    """Le a index.m3u8 e reescreve os segmentos para URL ABSOLUTA.

    Necessario porque o ffmpeg grava nomes relativos (seg_00001.ts): servindo a
    playlist em /stream.php/ID.m3u8, o player procuraria /stream.php/seg_00001.ts
    e receberia 404."""
    text = _read_text(hls_playlist(key))
    out: list[str] = []
    for line in text.splitlines():
        s = line.strip()
        if not s or s.startswith("#"):
            out.append(line)
        else:
            out.append(f"{base}/hls/{key}/{s}")
    return "\n".join(out) + "\n"


def hls_dir_mb(key: str) -> float:
    total = 0
    for p in hls_dir(key).glob("*"):
        try:
            total += p.stat().st_size
        except OSError:
            pass
    return total / 1_048_576


def _prune_hls_cache(keep: set[str]) -> None:
    """Mantem o cache de segmentos abaixo do teto, apagando o mais antigo."""
    dirs: list[tuple[float, str]] = []
    total = 0.0
    try:
        kids = list(HLS_DIR.iterdir())
    except OSError:
        return
    for d in kids:
        if not d.is_dir() or d.name in keep:
            continue
        mb = hls_dir_mb(d.name)
        total += mb
        try:
            dirs.append((d.stat().st_mtime, d.name))
        except OSError:
            pass
    if total <= HLS_MAX_CACHE_MB:
        return
    for _, name in sorted(dirs):  # mais antigo primeiro
        if total <= HLS_MAX_CACHE_MB:
            break
        mb = hls_dir_mb(name)
        shutil.rmtree(HLS_DIR / name, ignore_errors=True)
        total -= mb
        log(f"hls: cache cheio, apagando segmentos de {name} ({mb:.0f} MB)")


def reap_hls() -> dict[str, Any]:
    """Rotina periodica: mata fluxo sem ninguem assistindo e limpa disco."""
    now = time.monotonic()
    mortos: list[str] = []
    with _SESS_LOCK:
        for key in list(_SESS):
            s = _SESS[key]
            if s["proc"].poll() is not None:
                # VOD terminou sozinho (chegou no ENDLIST)
                _SESS.pop(key, None)
                fh = s.get("fh")
                if fh:
                    try:
                        fh.close()
                    except Exception:
                        pass
                if s["kind"] == "live":
                    shutil.rmtree(hls_dir(key), ignore_errors=True)
                continue
            if now - s["last_seen"] > HLS_IDLE_SECONDS:
                _kill(s["proc"])
                _SESS.pop(key, None)
                fh = s.get("fh")
                if fh:
                    try:
                        fh.close()
                    except Exception:
                        pass
                if s["kind"] == "live":
                    shutil.rmtree(hls_dir(key), ignore_errors=True)
                mortos.append(key)
    if mortos:
        log(f"hls: {len(mortos)} transmissao(oes) sem audiencia encerrada(s)")
    with _SESS_LOCK:
        vivos = [k for k, v in _SESS.items() if v["proc"].poll() is None]
    _prune_hls_cache(set(vivos))
    return {"ativos": len(vivos), "encerrados": len(mortos)}


def hls_status() -> list[dict[str, Any]]:
    with _SESS_LOCK:
        itens = [
            {"key": k, "tipo": v["kind"], "mb": round(hls_dir_mb(k), 1),
             "segmentos": hls_segment_count(k), "completo": hls_is_complete(k)}
            for k, v in _SESS.items() if v["proc"].poll() is None
        ]
    return sorted(itens, key=lambda x: x["key"])
