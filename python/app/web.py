"""Rotas FastAPI: painel, playlist, streaming (IPTV/VLC/modo canal) e diagnostico."""
from __future__ import annotations

import asyncio
import hmac
import json
import os
import platform
import re
import subprocess
import sys
import threading
import time
import urllib.parse
from contextlib import asynccontextmanager
from hashlib import sha256
from pathlib import Path
from typing import Any, AsyncIterator

from fastapi import FastAPI, Request
from fastapi.responses import (
    FileResponse,
    HTMLResponse,
    PlainTextResponse,
    RedirectResponse,
    Response,
    StreamingResponse,
)

from . import config, db, m3u, media, ui, watch, youtube
from .config import CACHE_DIR, YT_API_KEY, clean_id, log, video_id_ok

@asynccontextmanager
async def lifespan(_app: FastAPI):
    db.init_db()
    pwd = db.ensure_admin()
    if pwd:
        log("admin criado")
        print(f"[yt-iptv] usuario admin criado | senha: {pwd}  (troque depois)", flush=True)
    # o monitor roda em uma thread solta e nao bloqueia o startup
    watch.start()
    # .part de download morto ocupa GB e enche o disco -- limpa antes de comecar
    await asyncio.to_thread(media.limpa_partidos)
    # varredura dos fluxos HLS: encerra o que ninguem esta assistindo
    reaper = asyncio.create_task(_hls_reaper())
    livre = await asyncio.to_thread(media.disco_livre_mb)
    print(
        f"[yt-iptv] cache={CACHE_DIR} | ffmpeg={config.FFMPEG} | yt-dlp={config.YTDLP} | "
        f"base_url={config.BASE_URL or '(do request)'} | playlist={config.PLAYLIST_FORMAT.upper()} | "
        f"disco livre={livre} MB",
        flush=True,
    )
    if livre < media.DISCO_MINIMO_MB:
        print(
            f"[yt-iptv] ATENCAO: so {livre} MB livres no disco. Os downloads vao ficar "
            f"parados ate sobrar {media.DISCO_MINIMO_MB} MB -- apague videos antigos do cache.",
            flush=True,
        )
    yield
    reaper.cancel()
    watch.stop()


async def _hls_reaper() -> None:
    """Passa a cada 20s: mata ffmpeg de HLS sem audiencia e limita o cache."""
    while True:
        try:
            await asyncio.sleep(20)
            await asyncio.to_thread(media.reap_hls)
        except asyncio.CancelledError:
            raise
        except Exception as e:  # nunca derruba o servidor por causa da limpeza
            log(f"hls reaper: {e}")


app = FastAPI(title="YouTube IPTV", docs_url=None, redoc_url=None, lifespan=lifespan)

SESSION_COOKIE = "yt_sid"
CHUNK = 262_144


# ------------------------------------------------------------------ csrf / sessao

def _secret() -> bytes:
    f = CACHE_DIR / "secret.key"
    if f.is_file():
        return f.read_bytes()
    key = os.urandom(32)
    try:
        f.write_bytes(key)
    except Exception:
        pass
    return key


def _sign(value: str) -> str:
    return hmac.new(_secret(), value.encode(), sha256).hexdigest()[:32]


def _csrf(user: dict) -> str:
    return _sign(str(user["token"]))


async def form(request: Request) -> dict[str, list[str]]:
    """Parse do corpo de formulario sem depender do python-multipart."""
    body = (await request.body()).decode("utf-8", "replace")
    out: dict[str, list[str]] = {}
    for k, v in urllib.parse.parse_qsl(body, keep_blank_values=True):
        out.setdefault(k, []).append(v)
    return out


def one(f: dict[str, list[str]], key: str, default: str = "") -> str:
    v = f.get(key)
    return v[0] if v else default


def current_user(request: Request) -> dict[str, Any] | None:
    u = db.session_user(request.cookies.get(SESSION_COOKIE))
    if not u:
        return None
    u["_csrf"] = _csrf(u)
    return u


def need_user(request: Request) -> dict[str, Any]:
    u = current_user(request)
    if not u:
        raise _Redirect("/login")
    return u


class _Redirect(Exception):
    def __init__(self, to: str):
        self.to = to


@app.exception_handler(_Redirect)
async def _redirect_handler(request: Request, exc: _Redirect):  # noqa: ARG001
    return RedirectResponse(exc.to, status_code=303)


def check_csrf(f: dict[str, list[str]], user: dict[str, Any]) -> None:
    if not hmac.compare_digest(one(f, "csrf", ""), _csrf(user)):
        raise _Redirect("/")


def base_url(request: Request) -> str:
    """URL que vai dentro da playlist.

    Se o BASE_URL estiver vazio (ou apontando para localhost) usamos o host do
    proprio request: e o que evita a playlist sair com "localhost" quando o
    painel IPTV esta em outro aparelho da rede.
    """
    cfg = config.BASE_URL
    if cfg and "localhost" not in cfg and "127.0.0.1" not in cfg:
        return cfg
    return f"{request.url.scheme}://{request.url.netloc}"


# ------------------------------------------------------------------ login

@app.on_event("startup")
def _startup() -> None:
    db.init_db()
    pwd = db.ensure_admin()
    if pwd:
        log("admin criado")
        print(f"[yt-iptv] usuario admin criado | senha: {pwd}  (troque depois)", flush=True)
    watch.start()
    print(f"[yt-iptv] cache={CACHE_DIR} | ffmpeg={config.FFMPEG} | yt-dlp={config.YTDLP}", flush=True)


@app.get("/login", response_class=HTMLResponse)
async def login_form(request: Request):
    if current_user(request):
        return RedirectResponse("/", status_code=303)
    resp = HTMLResponse(ui.login_page(_sign("login")))
    resp.set_cookie("yt_lcsrf", _sign("login"), httponly=True, samesite="lax")
    return resp


@app.post("/login")
async def login_submit(request: Request):
    f = await form(request)
    if not hmac.compare_digest(one(f, "csrf", ""), request.cookies.get("yt_lcsrf", "")):
        return HTMLResponse(ui.login_page(_sign("login"), err="Sessao expirada. Tente de novo."), status_code=400)
    user = db.get_user(one(f, "username", "").strip())
    if not user or not db.check_password(one(f, "password", ""), user["password_hash"]):
        return HTMLResponse(ui.login_page(_sign("login"), err="Usuario ou senha invalidos."), status_code=401)
    if user["status"] != "active":
        return HTMLResponse(ui.login_page(_sign("login"), err="Usuario ainda nao ativado pelo admin."), status_code=403)
    resp = RedirectResponse("/", status_code=303)
    resp.set_cookie(SESSION_COOKIE, db.create_session(int(user["id"])), httponly=True, samesite="lax", max_age=db.SESSION_TTL)
    resp.delete_cookie("yt_lcsrf")
    return resp


@app.post("/logout")
async def logout(request: Request):
    db.drop_session(request.cookies.get(SESSION_COOKIE))
    resp = RedirectResponse("/login", status_code=303)
    resp.delete_cookie(SESSION_COOKIE)
    return resp


# ------------------------------------------------------------------ painel

@app.get("/", response_class=HTMLResponse)
async def panel(request: Request, livre: str = "", apagados: str = ""):
    user = current_user(request)
    if not user:
        return RedirectResponse("/login", status_code=303)
    base = base_url(request)
    tok = f"u={user['username']}&t={user['token']}"
    msg = ""
    if apagados:
        msg = (f"Cache liberado: {apagados} video(s) apagado(s), agora com "
               f"{livre or '?'} MB livres no disco.")
    html_out = ui.panel_page(
        user,
        db.channels_for_user(int(user["id"])),
        base,
        m3u_url=f"{base}/lista.php?{tok}",
        vlc_url=f"{base}/lista.php?{tok}&mode=vlc",
        canal_url=f"{base}/lista.php?{tok}&modo=canal",
        daemon=watch.running(),
        cache_mb=media.disk_free_mb(),
        livre_mb=await asyncio.to_thread(media.disco_livre_mb),
        msg=msg,
    )
    return HTMLResponse(html_out)


@app.post("/cache/purgar")
async def cache_purgar(request: Request):
    """Liberar espaco em disco apagando do cache os videos mais antigos.

    Sem isso o cache cresce ate encher o disco, e ai os downloads param e o
    ffmpeg falha. Quem chama decide o alvo (MB livres que quer ter)."""
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    try:
        alvo = int(one(f, "alvo_mb", "0"))
    except ValueError:
        alvo = 0
    r = await asyncio.to_thread(media.purga_cache, alvo or None)
    return RedirectResponse(f"/?livre={r['livre_mb']}&apagados={r['apagados']}", status_code=303)


@app.post("/canais/add")
async def channel_add(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    link = one(f, "link").strip()
    name = one(f, "name").strip()
    if not link:
        return RedirectResponse("/?erro=1", status_code=303)
    norm = youtube.normalize_input(link)
    if not norm:
        return RedirectResponse("/?erro=2", status_code=303)
    kind, value = norm
    channels = db.channels_for_user(int(user["id"]))
    if any((c.get("channel_id") if kind == "channel_id" else c.get("video_id")) == value for c in channels):
        return RedirectResponse("/?erro=3", status_code=303)
    if kind == "video_id":
        info = youtube.video_info(value)
        db.add_channel(
            int(user["id"]),
            {
                "video_id": value,
                "name": name or info.get("title") or value,
                "logo": info.get("thumb") or f"https://i.ytimg.com/vi/{value}/hqdefault.jpg",
                "tvg_id": "yt_" + sha256(value.encode()).hexdigest()[:8],
            },
        )
    else:
        snip = youtube.channel_snippet(value)
        db.add_channel(
            int(user["id"]),
            {
                "channel_id": value,
                "name": name or snip.get("title") or value,
                "logo": snip.get("logo", ""),
                "tvg_id": "yt_" + sha256(value.encode()).hexdigest()[:8],
                "max_videos": one(f, "qty", "50") or 50,
            },
        )
    return RedirectResponse("/", status_code=303)


@app.post("/canais/qty")
async def channel_qty(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    db.set_channel_qty(int(one(f, "id", "0") or 0), int(user["id"]), int(one(f, "qty", "50") or 50))
    return RedirectResponse("/", status_code=303)


@app.post("/canais/delete")
async def channel_delete(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    db.delete_channel(int(one(f, "id", "0") or 0), int(user["id"]))
    return RedirectResponse("/", status_code=303)


@app.post("/canais/download")
async def channel_download_toggle(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    on = one(f, "on", "0") == "1"
    db.set_channel_download(int(one(f, "id", "0") or 0), int(user["id"]), on)
    if on:
        threading.Thread(target=watch.trigger_once, daemon=True).start()
    return RedirectResponse(request.headers.get("referer", "/"), status_code=303)


@app.get("/grade/{channel_id}", response_class=HTMLResponse)
async def grade(channel_id: str, request: Request, pt: str = ""):
    user = need_user(request)
    channel_id = clean_id(channel_id)
    ch = next((c for c in db.channels_for_user(int(user["id"])) if c.get("channel_id") == channel_id), None)
    if not ch:
        return RedirectResponse("/", status_code=303)
    data = youtube.channel_videos(channel_id, 30, clean_id(pt))
    return HTMLResponse(ui.grid_page(user, ch, data["items"], data["next"], base_url(request)))


# ------------------------------------------------------------------ downloads

def _channel_row(uid: int, channel_id: str) -> dict[str, Any] | None:
    """Linha do canal (channels.id) de um usuario -- e a chave da selecao."""
    return next(
        (c for c in db.channels_for_user(uid) if c.get("channel_id") == channel_id and not c.get("video_id")),
        None,
    )


@app.get("/dl/{channel_id}", response_class=HTMLResponse)
async def dl_channel(channel_id: str, request: Request):
    user = need_user(request)
    channel_id = clean_id(channel_id)
    ch = next((c for c in db.channels_for_user(int(user["id"])) if c.get("channel_id") == channel_id), None)
    if not ch:
        return RedirectResponse("/", status_code=303)
    data = await asyncio.to_thread(
        youtube.cached_channel_videos, channel_id, int(ch.get("max_videos", 50) or 50)
    )
    items = data["items"]
    # a escolha das caixas fica guardada no banco: e dela que nasce o .m3u8 do canal
    escolhidos = set(await asyncio.to_thread(
        db.sync_selection, int(ch["id"]), [it["id"] for it in items if video_id_ok(it.get("id", ""))],
        bool(ch.get("has_selection")),
    ))
    states = {v["id"]: media.download_state(v["id"]) for v in items}
    return HTMLResponse(ui.dl_page(user, ch, items, states, watch.running(),
                                    chosen=escolhidos, base=base_url(request)))


@app.get("/dl/v/{video_id}", response_class=HTMLResponse)
async def dl_video(video_id: str, request: Request):
    user = need_user(request)
    vid = clean_id(video_id)
    info = youtube.video_info(vid)
    item = {"id": vid, "title": info.get("title") or vid, "thumb": info.get("thumb", "")}
    return HTMLResponse(
        ui.dl_page(user, {"id": 0, "name": item["title"]}, [item], {vid: media.download_state(vid)},
                   watch.running(), is_video=True)
    )


@app.post("/dl/start")
async def dl_start(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    ids = [clean_id(v) for v in f.get("ids", []) if video_id_ok(clean_id(v))]
    for vid in ids:
        media.start_download(vid)
    return RedirectResponse(request.headers.get("referer", "/"), status_code=303)


@app.post("/dl/select")
async def dl_select(request: Request):
    """Grava as caixas marcadas do canal.

    O botao de 'baixar MP4' manda acao=baixar e ai os .mp4 vao para uma thread
    solta; o de 'so salvar' nao baixa nada. Baixar pela pagina travaria ela (50
    yt-dlp juntos derrubam o servidor), e baixar junto com a lista confundiria:
    a lista .m3u8 e um arquivo de texto, nao traz video junto."""
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    row = _channel_row(int(user["id"]), clean_id(one(f, "canal")))
    if not row:
        return RedirectResponse(request.headers.get("referer", "/"), status_code=303)
    ids = [clean_id(v) for v in f.get("ids", []) if video_id_ok(clean_id(v))]
    await asyncio.to_thread(db.save_selection, int(row["id"]), ids)
    if ids and one(f, "acao", "") == "baixar":
        threading.Thread(target=media.start_many, args=(ids,), daemon=True).start()
    return RedirectResponse(request.headers.get("referer", "/"), status_code=303)


@app.post("/dl/all")
async def dl_all(request: Request):
    """Marca todos os videos do canal na selecao e baixa os .mp4."""
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    cid = clean_id(one(f, "canal"))
    qty = 50
    row = None
    for c in db.channels_for_user(int(user["id"])):
        if c.get("channel_id") == cid:
            qty = int(c.get("max_videos", 50) or 50)
            row = c
    # "baixar TODOS" tambem vira a selecao: a playlist do canal passa a ter tudo
    if row:
        data = await asyncio.to_thread(youtube.cached_channel_videos, cid, qty)
        await asyncio.to_thread(
            db.save_selection, int(row["id"]),
            [it["id"] for it in data["items"] if video_id_ok(it.get("id", ""))], True,
        )
    threading.Thread(target=media.ensure_channel_downloads, args=(cid, qty), daemon=True).start()
    return RedirectResponse(request.headers.get("referer", "/"), status_code=303)


@app.post("/dl/one")
async def dl_one(request: Request):
    return await dl_start(request)


# ------------------------------------------------------------------ admin

@app.get("/admin", response_class=HTMLResponse)
async def admin_page(request: Request):
    user = need_user(request)
    if user["role"] != "admin":
        return HTMLResponse(ui.layout("Proibido", "<div class='card'>Somente admin.</div>", user), status_code=403)
    base = base_url(request)
    return HTMLResponse(ui.admin_page(user, db.list_users(), base, ""))


@app.post("/admin/add")
async def admin_add(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    if user["role"] != "admin":
        return RedirectResponse("/", status_code=303)
    try:
        db.create_user(one(f, "username").strip(), one(f, "password"), role=one(f, "role", "user"))
    except Exception:
        pass
    return RedirectResponse("/admin", status_code=303)


@app.post("/admin/status")
async def admin_status(request: Request):
    user = need_user(request)
    f = await form(request)
    check_csrf(f, user)
    if user["role"] != "admin":
        return RedirectResponse("/", status_code=303)
    db.set_user_status(int(one(f, "id", "0") or 0), one(f, "status", "active"))
    return RedirectResponse("/admin", status_code=303)


# ------------------------------------------------------------------ playlist / epg

_trigger_lock = threading.Lock()
_last_trigger = 0.0


def _trigger_watch() -> None:
    """Gatilho do download continuo (a playlist e buscada pelo painel IPTV
    varias vezes por hora, entao serve de poller). Nao bloqueia a resposta."""
    global _last_trigger
    with _trigger_lock:
        if time.time() - _last_trigger < 90:
            return
        _last_trigger = time.time()
    threading.Thread(target=watch.trigger_once, daemon=True).start()


def _playlist_owner(u: str, t: str) -> dict[str, Any] | None:
    owner = db.user_by_playlist_token(u, t)
    if not owner or owner["status"] != "active":
        return None
    return owner


@app.get("/lista.php")
@app.get("/lista.m3u")
async def lista(request: Request, u: str = "", t: str = "", modo: str = "", mode: str = "iptv",
                download: str = "", formato: str = ""):
    owner = _playlist_owner(u, t)
    if not owner:
        return PlainTextResponse("Acesso negado: token de playlist invalido ou usuario nao ativo.", status_code=403)
    body = m3u.build_playlist(owner, base_url(request), mode=mode, canal=(modo == "canal"), formato=formato)
    if any(c.get("download") for c in db.channels_for_user(int(owner["id"]))):
        _trigger_watch()
    headers = {"Content-Type": "application/x-mpegURL; charset=utf-8"}
    if download == "1":
        headers["Content-Disposition"] = 'attachment; filename="playlist.m3u"'
    return PlainTextResponse(body, headers=headers)


def _safe_filename(name: str) -> str:
    s = re.sub(r"[^A-Za-z0-9._-]+", "_", (name or "canal").strip())[:48]
    return s.strip("._-") or "canal"


@app.get("/canal/{channel_id}.m3u8")
@app.get("/canal/{channel_id}.m3u")
async def canal_playlist(
    channel_id: str,
    request: Request,
    u: str = "",
    t: str = "",
    download: str = "",
    formato: str = "",
    ao_vivo: str = "",
):
    """Playlist .m3u8 de UM canal: so os videos marcados nas caixas de selecao.

    A selecao fica salva no banco, entao da para baixar o arquivo, abrir no VLC
    ou cadastrar a URL no painel a qualquer momento depois de escolher os videos.

    Acesso pelo token de playlist (u/t, como no /lista.php) ou pelo cookie de
    sessao do painel -- assim o botao "baixar .m3u8" funciona na propria tela."""
    owner = _playlist_owner(u, t)
    if not owner:
        owner = db.session_user(request.cookies.get(SESSION_COOKIE))
    if not owner or owner["status"] != "active":
        return PlainTextResponse("Acesso negado: token de playlist invalido ou usuario nao ativo.",
                                 status_code=403)

    channel_id = clean_id(channel_id)
    row = _channel_row(int(owner["id"]), channel_id)
    if not row:
        return PlainTextResponse("Canal nao encontrado na sua conta.", status_code=404)

    data = await asyncio.to_thread(
        youtube.cached_channel_videos, channel_id, int(row.get("max_videos", 50) or 50)
    )
    todos = [it for it in data["items"] if video_id_ok(it.get("id", ""))]
    escolhidos = await asyncio.to_thread(
        db.sync_selection, int(row["id"]), [it["id"] for it in todos], bool(row.get("has_selection"))
    )
    por_id = {it["id"]: it for it in todos}
    itens = [por_id[i] for i in escolhidos if i in por_id]

    body = m3u.build_channel_playlist(
        owner, base_url(request), row, itens, formato=formato,
        ao_vivo=ao_vivo in ("1", "s", "sim", "true"),
    )
    headers = {"Content-Type": "application/vnd.apple.mpegurl; charset=utf-8"}
    if download == "1":
        nome = _safe_filename(row.get("name") or channel_id)
        headers["Content-Disposition"] = f'attachment; filename="{nome}.m3u8"'
    return PlainTextResponse(body, headers=headers)


@app.get("/epg.php")
@app.get("/epg.xml")
async def epg(request: Request, u: str = "", t: str = ""):
    owner = _playlist_owner(u, t)
    if not owner:
        return PlainTextResponse("Acesso negado.", status_code=403)
    return PlainTextResponse(m3u.build_epg(owner), headers={"Content-Type": "application/xml; charset=utf-8"})


# ------------------------------------------------------------------ streaming

def is_iptv_request(request: Request) -> bool:
    """Mesma classificacao do PHP: HEAD, ?iptv=1, ?xui=1, UA de painel ou URL .ts/.m3u8."""
    if request.method == "HEAD":
        return True
    q = request.query_params
    if q.get("iptv") == "1" or q.get("xui") == "1":
        return True
    ua = (request.headers.get("user-agent") or "").lower()
    if any(k in ua for k in ("xtream", "xui", "stalker", "exoplayer", "tvg", "kodi")):
        return True
    if request.url.path.endswith((".ts", ".m3u8")):
        return True
    return False


async def _pump(proc: subprocess.Popen, fh, idle_timeout: float = 25.0) -> AsyncIterator[bytes]:
    loop = asyncio.get_running_loop()
    last = time.time()
    try:
        while True:
            try:
                chunk = await asyncio.wait_for(
                    loop.run_in_executor(None, proc.stdout.read, CHUNK), timeout=idle_timeout
                )
            except asyncio.TimeoutError:
                if proc.poll() is not None:
                    break
                continue
            if not chunk:
                break
            last = time.time()
            yield chunk
    finally:
        try:
            proc.kill()
        except Exception:
            pass
        try:
            proc.wait(timeout=5)
        except Exception:
            pass
        if fh:
            try:
                fh.close()
            except Exception:
                pass


def ts_response(proc: subprocess.Popen, fh) -> StreamingResponse:
    return StreamingResponse(
        _pump(proc, fh),
        media_type="video/mp2t",
        headers={"Cache-Control": "no-cache, no-store", "Connection": "close"},
    )


async def _proxy(url: str, request: Request) -> StreamingResponse:
    import httpx

    headers = {"User-Agent": config.UA_DESKTOP}
    rng = request.headers.get("range")
    if rng:
        headers["Range"] = rng
    client = httpx.AsyncClient(timeout=None, follow_redirects=True)
    up = await client.get(url, headers=headers)
    out_headers = {}
    for h in ("content-type", "content-length", "content-range", "accept-ranges"):
        v = up.headers.get(h)
        if v:
            out_headers[h] = v

    async def gen():
        try:
            async for c in up.aiter_bytes(CHUNK):
                yield c
        finally:
            await client.aclose()

    return StreamingResponse(gen(), status_code=up.status_code, headers=out_headers)


def file_response(path: Path, request: Request, media_type: str) -> Response:
    """Arquivo local com suporte a Range (para o VLC dar seek)."""
    size = path.stat().st_size
    rng = request.headers.get("range", "")
    m = re.match(r"bytes=(\d*)-(\d*)", rng or "")

    async def read_chunks(start: int, end: int) -> AsyncIterator[bytes]:
        loop = asyncio.get_running_loop()
        fh = await loop.run_in_executor(None, open, path, "rb")
        try:
            await loop.run_in_executor(None, fh.seek, start)
            remaining = end - start + 1
            while remaining > 0:
                n = await loop.run_in_executor(None, fh.read, min(CHUNK, remaining))
                if not n:
                    break
                remaining -= len(n)
                yield n
        finally:
            fh.close()

    base_headers = {"Accept-Ranges": "bytes"}
    if m:
        g1, g2 = m.group(1), m.group(2)
        if g1:
            start = int(g1)
            end = int(g2) if g2 else size - 1
        else:  # bytes=-N (ultimos N bytes)
            start = max(0, size - int(g2 or 0))
            end = size - 1
        start = max(0, min(start, size - 1))
        end = max(start, min(end, size - 1))
        base_headers["Content-Range"] = f"bytes {start}-{end}/{size}"
        base_headers["Content-Length"] = str(end - start + 1)
        return StreamingResponse(read_chunks(start, end), status_code=206, media_type=media_type, headers=base_headers)

    base_headers["Content-Length"] = str(size)
    return StreamingResponse(read_chunks(0, size - 1), media_type=media_type, headers=base_headers)


async def serve_channel_ts(channel_id: str, request: Request) -> Response:
    """Modo canal: toca todos os videos do canal em sequencia, em MPEG-TS
    continuo (re-encoding normaliza resolucao/codec e garante a transicao).

    As esperas/ffprobe vao em thread: bloqueando o event loop, um painel
    esperando download pararia o servidor inteiro."""
    limit = 50
    ch = await asyncio.to_thread(db.find_channel_anywhere, channel_id)
    if ch:
        limit = int(ch.get("max_videos", 50) or 50)
    data = await asyncio.to_thread(youtube.cached_channel_videos, channel_id, limit)
    items = [it["id"] for it in data["items"] if video_id_ok(it.get("id", ""))]

    # espera o primeiro video ficar pronto (evita "sem sinal" no painel)
    if items:
        await asyncio.to_thread(media.ensure_download, items[0])
        await asyncio.to_thread(media.wait_download, items[0], config.CHANNEL_WAIT_SECONDS)

    found = await asyncio.gather(*(asyncio.to_thread(media.find_loop, v) for v in items[:20]))
    picked = await asyncio.to_thread(media.pick_channel_videos, [p for p in found if p])
    if not picked:
        return PlainTextResponse(
            "Sem video local para o canal ainda. Abra o gerenciador de downloads "
            "(/dl/CHANNELID) e baixe os videos, ou tente de novo em instantes.",
            media_type="text/plain",
            status_code=503,
        )
    proc, fh = media.start_channel_ts(channel_id, picked, CACHE_DIR / f"ffmpeg_c{channel_id}.log")
    return ts_response(proc, fh)


# ------------------------------------------------------------------ HLS (.m3u8)

M3U8_TYPE = "application/vnd.apple.mpegurl"
SEG_RE = re.compile(r"^seg_\d{4,6}\.ts$")
HLS_KEY_RE = re.compile(r"^[A-Za-z0-9_-]{1,40}$")


def _hls_playlist(text: str) -> Response:
    return Response(
        text,
        media_type=M3U8_TYPE,
        headers={
            "Cache-Control": "no-cache, no-store, must-revalidate",
            "Pragma": "no-cache",
            "Access-Control-Allow-Origin": "*",
        },
    )


def _hls_fail(msg: str) -> Response:
    return PlainTextResponse(msg, media_type="text/plain", status_code=503)


def _hls_send(key: str, request: Request) -> Response:
    """Playlist com URLs absolutas, sem deixar exception virar 500."""
    try:
        return _hls_playlist(media.read_playlist(key, base_url(request)))
    except Exception as e:
        log(f"hls: falha ao ler a playlist de {key}: {e!r}")
        return _hls_fail("Nao consegui ler a playlist HLS. Tente de novo em instantes.")


@app.api_route("/hls/{key}/{filename}", methods=["GET", "HEAD"])
async def hls_segment(key: str, filename: str, request: Request):
    """Segmento .ts do fluxo HLS.

    O nome passa por regex (nada de '..' ou barra invertida) e o arquivo sai
    direto do disco: e o que o player pede de poucos em poucos segundos."""
    if not HLS_KEY_RE.match(key) or not SEG_RE.match(filename):
        return PlainTextResponse("segmento invalido", media_type="text/plain", status_code=404)
    path = media.hls_dir(key) / filename
    if not path.is_file():
        return PlainTextResponse("segmento ainda nao pronto", media_type="text/plain", status_code=404)
    # alguem esta assistindo: impede o reaper de derrubar o ffmpeg no meio
    media.hls_touch(key)
    if request.method == "HEAD":
        return Response(status_code=200, media_type="video/mp2t",
                        headers={"Content-Length": str(path.stat().st_size)})
    return FileResponse(path, media_type="video/mp2t",
                        headers={"Cache-Control": "public, max-age=30"})


async def serve_hls_video(vid: str, request: Request) -> Response:
    """Um video como HLS: playlist que cresce e termina com ENDLIST.

    Todas as chamadas do media.py sao blocking (espera download, roda ffprobe,
    resolve URL) e vao para uma thread: se ficassem no event loop, um painel
    esperando 20s por um download pararia o servidor inteiro."""
    key = media.hls_key(vid)
    if await asyncio.to_thread(media.hls_vod_ready, key):
        await asyncio.to_thread(media.hls_touch, key)
        return _hls_send(key, request)

    t0 = time.monotonic()

    def mark(etapa: str) -> None:
        # loga etapa lenta: serve para achar o gargalo quando o painel demora
        dt = time.monotonic() - t0
        if dt > 3:
            log(f"hls: {etapa} levou {dt:.1f}s (id={vid})")

    mark("inicio")
    local = await asyncio.to_thread(media.find_loop, vid)
    mark("find_loop")
    if local:
        log(f"hls id={vid} a partir do cache {local.name}")
    else:
        await asyncio.to_thread(media.ensure_download, vid)
        await asyncio.to_thread(media.wait_download, vid, config.IPTV_WAIT_SECONDS)
        local = await asyncio.to_thread(media.find_loop, vid)
        mark("espera de download")

    if local:
        src, is_local = str(local), True
    else:
        # sem download: encoda direto da URL do YouTube (funciona, so demora)
        src = await asyncio.to_thread(media.resolve_direct_url, vid)
        is_local = False
        mark("resolve_direct_url")
        if not src:
            return _hls_fail(
                f"Nao consegui pegar o video {vid}. Baixe em /dl/v/{vid} e tente de novo."
            )

    ok, motivo = await asyncio.to_thread(
        media.hls_start, key, src, False, is_local, None, media.ffmpeg_log(vid)
    )
    mark("hls_start")
    if not ok:
        return _hls_fail(motivo)
    pronto = await asyncio.to_thread(media.hls_wait_ready, key, config.HLS_FIRST_WAIT)
    mark("espera do 1o segmento")
    if not pronto:
        return _hls_fail(
            f"O video {vid} ainda esta sendo preparado para HLS. Tente de novo em instantes "
            f"(o log esta em cache/ffmpeg_{vid}.log)."
        )
    return _hls_send(key, request)


async def serve_channel_hls(channel_id: str, request: Request) -> Response:
    """Modo canal em HLS: janela deslizante, os videos ficam em sequencia e o
    fluxo nao acaba (o painel ve um 'canal' continuo)."""
    key = media.hls_key(channel_id=channel_id)
    await asyncio.to_thread(media.hls_touch, key)

    # caminho rapido: a transmissao deste canal ja esta no ar
    if await asyncio.to_thread(media.hls_running, key):
        pl = media.hls_playlist(key)
        if pl.is_file() and await asyncio.to_thread(media.hls_segment_count, key) >= 2:
            return _hls_send(key, request)

    limit = 50
    ch = await asyncio.to_thread(db.find_channel_anywhere, channel_id)
    if ch:
        limit = int(ch.get("max_videos", 50) or 50)
    data = await asyncio.to_thread(youtube.cached_channel_videos, channel_id, limit)
    items = [it["id"] for it in data["items"] if video_id_ok(it.get("id", ""))]

    if items:
        await asyncio.to_thread(media.ensure_download, items[0])
        await asyncio.to_thread(media.wait_download, items[0], config.CHANNEL_WAIT_SECONDS)

    found = await asyncio.gather(*(asyncio.to_thread(media.find_loop, v) for v in items[:20]))
    files = [p for p in found if p]
    picked = await asyncio.to_thread(media.pick_channel_videos, files)
    if not picked:
        return _hls_fail(
            "Sem video local do canal ainda. Abra o gerenciador de downloads "
            "(/dl/CHANNELID), baixe os videos e tente de novo."
        )
    concat = await asyncio.to_thread(media.write_concat_list, channel_id, picked)
    ok, motivo = await asyncio.to_thread(
        media.hls_start, key, "", True, True, concat, CACHE_DIR / f"ffmpeg_c{channel_id}.log"
    )
    if not ok:
        return _hls_fail(motivo)
    if not await asyncio.to_thread(media.hls_wait_ready, key, config.HLS_FIRST_WAIT):
        return _hls_fail("O canal ainda esta montando a transmissao. Tente de novo em instantes.")
    return _hls_send(key, request)


@app.api_route("/stream.php", methods=["GET", "HEAD"])
@app.api_route("/stream.php/{rest:path}", methods=["GET", "HEAD"])
async def stream(request: Request, rest: str = ""):
    q = request.query_params
    vid = clean_id(q.get("id", ""))
    channel_id = ""
    want_hls = q.get("hls") == "1"
    name = (rest or "").strip("/")
    if name:
        if name.lower().endswith(".m3u8"):
            want_hls = True
            name = name[: -len(".m3u8")]
        stem = name.rsplit(".", 1)[0] if "." in name else name
        if stem.startswith("c-"):
            channel_id = clean_id(stem[2:])
        else:
            vid = clean_id(stem)
    if not vid and not channel_id:
        return PlainTextResponse("Faltou id do video ou canal.", status_code=400)

    iptv = is_iptv_request(request)

    # ---- HLS (.m3u8 + segmentos): o formato que o painel IPTV prefere
    if want_hls:
        try:
            if channel_id:
                return await serve_channel_hls(channel_id, request)
            if request.method == "HEAD":
                # probe do painel: responde 200 com o tipo certo da playlist
                return Response(status_code=200, media_type=M3U8_TYPE)
            return await serve_hls_video(vid, request)
        except Exception as e:
            # painel prefere "sem sinal" (503) a erro 500: ele tenta de novo
            log(f"hls: erro preparando {vid or channel_id}: {e!r}")
            return _hls_fail("Erro interno preparando o HLS. Tente de novo em instantes.")

    # HEAD: resposta imediata (probe dos painéis Xtream/XUI; 10-15s de timeout)
    if request.method == "HEAD":
        if channel_id or iptv:
            return Response(status_code=200, media_type="video/mp2t")
        local = media.find_loop(vid)
        if local:
            return Response(status_code=200, media_type="video/mp4")
        url = media.resolve_direct_url(vid)
        return Response(status_code=200 if url else 503, media_type="video/mp2t" if url else "text/plain")

    if channel_id:
        return await serve_channel_ts(channel_id, request)

    local = media.find_loop(vid)
    if local:
        log(f"id={vid} loop local {local.name}")
        if iptv:
            try:
                proc, fh = media.start_ts_stream(str(local), True, media.ffmpeg_log(vid))
                return ts_response(proc, fh)
            except FileNotFoundError:
                return PlainTextResponse("ffmpeg nao encontrado no servidor.", status_code=500)
        return file_response(local, request, "video/mp4")

    if iptv:
        # sem cache: baixa em linha (rapido no VPS) e serve. Limite curto porque
        # o painel da "sem sinal" se demorarmos.
        if await asyncio.to_thread(media.wait_download, vid, config.IPTV_WAIT_SECONDS):
            local = await asyncio.to_thread(media.find_loop, vid)
            if local:
                try:
                    proc, fh = media.start_ts_stream(str(local), True, media.ffmpeg_log(vid))
                    return ts_response(proc, fh)
                except FileNotFoundError:
                    pass
        url = await asyncio.to_thread(media.resolve_direct_url, vid)
        if not url:
            return PlainTextResponse(
                f"Falha ao resolver o stream do video {vid}. "
                f"Veja o log em cache/stream.log e o diagnostico em /stream_diag.php?why={vid}",
                status_code=503,
            )
        try:
            proc, fh = media.start_ts_stream(url, False, media.ffmpeg_log(vid))
            return ts_response(proc, fh)
        except FileNotFoundError:
            return await _proxy(url, request)

    # VLC / players normais: arquivo local (com seek) ou URL direta via proxy
    if await asyncio.to_thread(media.wait_download, vid, config.VLC_WAIT_SECONDS):
        local = await asyncio.to_thread(media.find_loop, vid)
        if local:
            return file_response(local, request, "video/mp4")
    url = await asyncio.to_thread(media.resolve_direct_url, vid)
    if url:
        return await _proxy(url, request)
    return PlainTextResponse(
        f"Nao foi possivel tocar o video {vid} agora. Tente de novo em instantes.",
        media_type="text/plain",
        status_code=503,
    )


# ------------------------------------------------------------------ diagnostico

def _tail(path: Path, n: int) -> str:
    if not path.is_file():
        return "(nao existe)"
    try:
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except Exception as e:
        return f"(erro ao ler: {e})"
    return "\n".join(lines[-n:]) or "(vazio)"


@app.get("/vercheck.php")
@app.get("/vercheck")
async def vercheck():
    def run(args: list[str]) -> str:
        try:
            r = subprocess.run(args, capture_output=True, text=True, timeout=25, creationflags=config.CREATE_NO_WINDOW)
            return (r.stdout or r.stderr).strip().splitlines()[0] if (r.stdout or r.stderr).strip() else "?"
        except Exception as e:
            return f"ERRO: {e}"

    lines = [
        "YouTube IPTV (Python) - status do servidor",
        f"python      : {sys.version.split()[0]} ({platform.system()} {platform.release()})",
        f"base_dir    : {config.BASE_DIR}",
        f"cache_dir   : {CACHE_DIR}",
        f"db          : {config.DB_PATH} ({'ok' if config.DB_PATH.is_file() else 'AUSENTE'})",
        f"yt_api_key  : {'CONFIGURADA' if YT_API_KEY else 'AUSENTE (nada sera listado)'}",
        f"ffmpeg      : {run([config.FFMPEG, '-version'])}",
        f"ffprobe     : {run([config.FFPROBE, '-version'])}",
        f"yt-dlp      : {run([config.YTDLP, '--version'])}",
        f"cookies     : {config.COOKIES_FILE if config.COOKIES_FILE else '(sem cookies)'}",
        f"base_url    : {config.BASE_URL or '(derivado do request)'}",
        f"formato M3U : {config.PLAYLIST_FORMAT.upper()} (.m3u8=HLS / .ts=MPEG-TS; ?formato=ts troca)",
        f"HLS         : {config.HLS_DIR} | segmento={config.HLS_TIME}s | "
        f"janela={config.HLS_LIST_SIZE} | max simultaneas={config.HLS_MAX_SESSIONS}",
        f"HLS ativas  : " + (
            ", ".join(f"{s['key']}({s['tipo']},{s['segmentos']}seg,{s['mb']}MB)" for s in media.hls_status())
            or "nenhuma"
        ),
        f"monitor     : {'rodando' if watch.running() else 'parado'}",
        f"canais com download continuo: {db.count_downloads_enabled()}",
        f"downloads ativos            : {media.active_downloads()}",
        f"cache em disco              : {media.disk_free_mb():.0f} MB",
        f"disco livre                 : {media.disco_livre_mb():.0f} MB"
        + ("  (ABAIXO DO MINIMO: downloads parados!)" if media.disco_livre_mb() < media.DISCO_MINIMO_MB else ""),
        f"usuarios                    : {len(db.list_users())}",
    ]
    return PlainTextResponse("\n".join(lines) + "\n")


@app.get("/stream_diag.php")
async def stream_diag(request: Request, id: str = "", why: str = "", c: str = ""):
    out: list[str] = []

    if c:
        cid = clean_id(c)
        out.append(f"--- CANAL {cid} ---")
        data = youtube.cached_channel_videos(cid, 50)
        for it in data["items"]:
            vid = it["id"]
            st = media.download_state(vid)
            dims = ""
            if st["file"]:
                d = media.ffprobe_dims(st["file"])
                dims = f"{d[0]}x{d[1]}" if d else "?"
            status = f"{st['size'] / 1048576:.1f} MB" if st["file"] else ("baixando" if st["downloading"] else "-")
            out.append(f"{vid:12} {status:>10} {dims:>10}  {it['title'][:44]}")
        out.append(f"videos: {len(data['items'])}")
        return PlainTextResponse("\n".join(out) + "\n")

    vid = clean_id(why or id)
    if not vid:
        return PlainTextResponse("Uso: /stream_diag.php?why=VIDEOID  |  ?id=VIDEOID  |  ?c=CHANNELID\n")

    st = media.download_state(vid)
    out += [
        f"--- POR QUE {vid} ---",
        f"loop: {st['file'] or '(sem arquivo)'} ({st['size']} bytes)",
        f"baixando agora: {'SIM' if st['downloading'] else 'nao'}   falhou antes: {'SIM' if st['failed'] else 'nao'}",
        "",
        f"--- cache/loop_{vid}.log (ultimas 30 linhas) ---",
        _tail(media.ytdlp_log(vid), 30),
        "",
        f"--- cache/ffmpeg_{vid}.log (ultimas 20 linhas) ---",
        _tail(media.ffmpeg_log(vid), 20),
        "",
        "--- teste de extracao yt-dlp ---",
    ]
    try:
        r = subprocess.run(
            [config.YTDLP, "--no-playlist", "--simulate", "--no-warnings", "--no-check-certificates",
             "--print", "%(id)s | dur=%(duration)s | disp=%(availability)s | live=%(live_status)s | %(title).60s"]
            + (["--cookies", config.COOKIES_FILE] if config.COOKIES_FILE else [])
            + [f"https://www.youtube.com/watch?v={vid}"],
            capture_output=True, text=True, timeout=60, creationflags=config.CREATE_NO_WINDOW,
        )
        out.append(f"rc={r.returncode}")
        out.append((r.stdout or "").strip()[-2000:] or "(sem saida)")
        if r.stderr:
            out.append("stderr: " + r.stderr.strip()[-800:])
    except Exception as e:
        out.append(f"ERRO: {e}")

    out += ["", "--- oEmbed (disponibilidade) ---"]
    try:
        import httpx

        r = httpx.get(
            "https://www.youtube.com/oembed",
            params={"url": f"https://www.youtube.com/watch?v={vid}", "format": "json"},
            timeout=8, headers={"User-Agent": config.UA_DESKTOP},
        )
        out.append(f"http: {r.status_code}")
        out.append(r.text[:300])
        if r.status_code != 200:
            out.append("=> video privado, removido, com restricao de login/idade ou da regiao.")
    except Exception as e:
        out.append(f"ERRO: {e}")

    return PlainTextResponse("\n".join(out) + "\n")


@app.get("/healthz")
async def healthz():
    return {
        "yt": "youtube-iptv",  # marcador: o iniciar.bat usa para saber que a porta
        "ok": True,            # ja e o nosso app e nao outro programa
        "watch": watch.running(),
        "cache_mb": round(media.disk_free_mb(), 1),
        "disco_livre_mb": await asyncio.to_thread(media.disco_livre_mb),
        "downloads_ativos": media.active_downloads(),
    }
