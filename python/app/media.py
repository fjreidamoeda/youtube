"""Loop cache (yt-dlp) + ffmpeg: download em segundo plano, remux para MPEG-TS,
concat do modo canal e resolucao de URL direta."""
from __future__ import annotations

import os
import re
import subprocess
import sys
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
