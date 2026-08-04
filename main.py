import asyncio
import json
import os
import re
import subprocess
import tempfile
import urllib.parse
from pathlib import Path
from typing import Optional

import httpx
from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException, Query, Request
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse, Response, StreamingResponse
from fastapi.staticfiles import StaticFiles
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError

load_dotenv()

YOUTUBE_API_KEY = os.getenv("YOUTUBE_API_KEY")
YOUTUBE_API_BASE = "https://www.googleapis.com/youtube/v3"

if not YOUTUBE_API_KEY:
    raise RuntimeError("YOUTUBE_API_KEY not set in .env")

youtube = build("youtube", "v3", developerKey=YOUTUBE_API_KEY)

app = FastAPI(title="YouTube IPTV")

app.mount("/static", StaticFiles(directory="static"), name="static")

BASE_URL = os.getenv("BASE_URL", "http://localhost:8000")

CHANNELS_FILE = Path("channels.json")


def load_channels() -> list:
    if not CHANNELS_FILE.exists():
        return []
    try:
        data = json.loads(CHANNELS_FILE.read_text(encoding="utf-8"))
        return data if isinstance(data, list) else []
    except Exception:
        return []


def save_channels(channels: list) -> None:
    CHANNELS_FILE.write_text(json.dumps(channels, ensure_ascii=False, indent=2), encoding="utf-8")


def resolve_channel_id(link: str) -> Optional[str]:
    """Aceita channel_id, @handle, ou URL do canal."""
    link = link.strip()
    if re.match(r"^UC[0-9A-Za-z_-]{20,}$", link):
        return link

    handle = None
    if link.startswith("@"):
        handle = link[1:]
    elif re.search(r"youtube\.com/@([^/?#]+)", link, re.I):
        handle = re.search(r"youtube\.com/@([^/?#]+)", link, re.I).group(1)
    elif re.search(r"youtube\.com/channel/(UC[0-9A-Za-z_-]{20,})", link, re.I):
        return re.search(r"youtube\.com/channel/(UC[0-9A-Za-z_-]{20,})", link, re.I).group(1)

    if handle:
        try:
            resp = youtube.search().list(part="snippet", type="channel", q=handle, maxResults=1).execute()
            for item in resp.get("items", []):
                ch_id = item["snippet"].get("channelId")
                if ch_id:
                    return ch_id
        except HttpError:
            pass

    try:
        resp = youtube.search().list(part="snippet", type="channel", q=link, maxResults=1).execute()
        for item in resp.get("items", []):
            ch_id = item["snippet"].get("channelId")
            if ch_id:
                return ch_id
    except HttpError:
        pass
    return None


def get_live_video_id(channel_id: str) -> Optional[str]:
    """Vídeo AO VIVO do canal, se houver."""
    try:
        resp = youtube.search().list(
            part="snippet", type="video", eventType="live",
            channelId=channel_id, order="viewCount", maxResults=1,
        ).execute()
        for item in resp.get("items", []):
            vid = item["id"].get("videoId")
            if vid:
                return vid
    except HttpError:
        pass
    return None


def get_latest_video_id(channel_id: str) -> Optional[str]:
    """Último vídeo publicado no canal."""
    try:
        chan = youtube.channels().list(part="contentDetails", id=channel_id).execute()
        if not chan.get("items"):
            return None
        playlist_id = chan["items"][0]["contentDetails"]["relatedPlaylists"]["uploads"]
        res = youtube.playlistItems().list(
            part="snippet", playlistId=playlist_id, maxResults=1,
        ).execute()
        for item in res.get("items", []):
            vid = item["snippet"]["resourceId"].get("videoId")
            if vid:
                return vid
    except HttpError:
        pass
    return None


def get_best_video_id(channel_id: str):
    """Live primeiro; se não tiver live, último vídeo."""
    live = get_live_video_id(channel_id)
    if live:
        return {"video_id": live, "is_live": True}
    latest = get_latest_video_id(channel_id)
    if latest:
        return {"video_id": latest, "is_live": False}
    return {"video_id": None, "is_live": False}


@app.get("/", response_class=HTMLResponse)
async def index():
    html_path = Path("templates/index.html")
    if not html_path.exists():
        return HTMLResponse("<h1>index.html not found</h1>", status_code=500)
    return HTMLResponse(html_path.read_text(encoding="utf-8"))


@app.get("/api/search_channels")
async def search_channels(q: str = Query(...), max_results: int = Query(10, le=50)):
    try:
        request = youtube.search().list(
            part="snippet",
            type="channel",
            q=q,
            maxResults=max_results,
        )
        response = request.execute()
    except HttpError as e:
        raise HTTPException(status_code=500, detail=str(e))

    channels = []
    for item in response.get("items", []):
        channels.append({
            "id": item["snippet"]["channelId"],
            "title": item["snippet"]["title"],
            "description": item["snippet"]["description"],
            "thumbnail": item["snippet"]["thumbnails"]["high"]["url"],
        })
    return {"channels": channels}


@app.get("/api/get_videos")
async def get_videos(
    channel_id: str = Query(...),
    max_results: int = Query(50, le=50),
):
    try:
        channel_resp = youtube.channels().list(
            part="contentDetails", id=channel_id
        ).execute()
        if not channel_resp.get("items"):
            raise HTTPException(status_code=404, detail="Channel not found")
        playlist_id = channel_resp["items"][0]["contentDetails"]["relatedPlaylists"]["uploads"]

        videos = []
        next_token = None
        while len(videos) < max_results:
            req = youtube.playlistItems().list(
                part="snippet",
                playlistId=playlist_id,
                maxResults=min(50, max_results - len(videos)),
                pageToken=next_token,
            )
            res = req.execute()
            for item in res.get("items", []):
                video_id = item["snippet"]["resourceId"]["videoId"]
                videos.append({
                    "id": video_id,
                    "title": item["snippet"]["title"],
                    "thumbnail": item["snippet"]["thumbnails"]["high"]["url"],
                    "published_at": item["snippet"]["publishedAt"],
                })
            next_token = res.get("nextPageToken")
            if not next_token:
                break
    except HttpError as e:
        raise HTTPException(status_code=500, detail=str(e))

    return {"videos": videos}


@app.get("/api/export_m3u")
async def export_m3u(
    ids: str = Query(..., description="Comma-separated video IDs"),
    titles: str = Query(..., description="Comma-separated titles"),
    thumbnails: str = Query("", description="Comma-separated thumbnails"),
    category: str = Query("CANAIS"),
):
    video_ids = ids.split(",")
    video_titles = titles.split(",")
    video_thumbnails = thumbnails.split(",") if thumbnails else []

    valid_categories = {"CANAIS", "FILMES", "SERIES"}
    if category not in valid_categories:
        category = "CANAIS"

    lines = ["#EXTM3U", f"#PLAYLIST:YouTube IPTV", f"#CATEGORY:{category}"]
    lines.append('#EXTVLCOPT:http-user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
    lines.append("#EXTVLCOPT:http-referrer=https://www.youtube.com/")
    lines.append("")

    for i, vid in enumerate(video_ids):
        title = video_titles[i] if i < len(video_titles) else f"Video {vid}"
        title_clean = re.sub(r"[,:#\n\r]", " ", title).strip()
        thumb = video_thumbnails[i] if i < len(video_thumbnails) else ""
        lines.append(
            f'#EXTINF:-1 tvg-id="{vid}" tvg-name="{title_clean}"'
            f' tvg-logo="{thumb}" group-title="{category}",{title_clean}'
        )
        lines.append(f"{BASE_URL}/play/{vid}")

    content = "\n".join(lines)
    filename = f"youtube_{category.lower()}.m3u"
    from fastapi.responses import StreamingResponse

    async def stream():
        yield content.encode("utf-8")

    return StreamingResponse(
        stream(),
        media_type="application/x-mpegURL",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


def resolve_stream_url(video_id: str) -> str:
    yt_url = f"https://www.youtube.com/watch?v={video_id}"
    formats = [
        "best[ext=mp4][acodec!=none][protocol=https]/best",
        "best",
    ]
    for fmt in formats:
        try:
            result = subprocess.run(
                ["yt-dlp", "-f", fmt, "--get-url", "--no-playlist", "--no-warnings", yt_url],
                capture_output=True, text=True, timeout=45,
            )
            if result.returncode == 0:
                url = result.stdout.strip().split("\n")[0]
                if url.startswith("http"):
                    return url
        except (FileNotFoundError, subprocess.TimeoutExpired, subprocess.CalledProcessError):
            continue
    return ""


async def proxy_from(url: str, request: Request):
    """Relaya os bytes de uma URL (válida pro IP do VPS) para o player."""
    headers = {}
    for h in ("Range", "If-Range", "User-Agent", "Referer"):
        v = request.headers.get(h)
        if v:
            headers[h] = v
    headers.setdefault("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")

    client = httpx.AsyncClient(timeout=None)
    upstream = await client.request("GET", url, headers=headers, follow_redirects=True)

    resp_headers = {}
    for h in ("content-type", "content-length", "content-range", "accept-ranges", "content-disposition"):
        v = upstream.headers.get(h)
        if v:
            resp_headers[h] = v

    async def gen():
        try:
            async for chunk in upstream.aiter_bytes(chunk_size=65536):
                yield chunk
        finally:
            await client.aclose()

    return StreamingResponse(
        gen(),
        status_code=upstream.status_code,
        headers=resp_headers,
        media_type=None,
    )


def rewrite_hls_manifest(content: str, manifest_url: str, video_id: str) -> str:
    """Reescreve as URLs de segmentos do m3u8 para passarem pelo proxy do VPS."""
    lines = []
    for raw in content.splitlines():
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#"):
            lines.append(line)
        else:
            abs_url = urllib.parse.urljoin(manifest_url, line)
            proxied = f"{BASE_URL}/hls/{video_id}?u={urllib.parse.quote(abs_url, safe='')}"
            lines.append(proxied)
    return "\n".join(lines)


@app.get("/api/export_sql")
async def export_sql(
    ids: str = Query(..., description="Comma-separated video IDs"),
    titles: str = Query(..., description="Comma-separated titles"),
    thumbnails: str = Query("", description="Comma-separated thumbnails"),
    descriptions: str = Query("", description="Comma-separated descriptions"),
    category: str = Query("CANAIS"),
    mode: str = Query("direct", description="'direct' = URL real do .m3u8 (expira), 'proxy' = /play/ endpoint (precisa servidor rodando)"),
    streamflow_url: str = Query("http://localhost/streamflow", description="URL base do StreamFlow (só usado em mode=proxy)"),
):
    video_ids = ids.split(",")
    video_titles = titles.split(",")
    video_thumbnails = thumbnails.split(",") if thumbnails else []
    video_descs = descriptions.split(",") if descriptions else []

    type_map = {"CANAIS": "channel", "FILMES": "movie", "SERIES": "series"}
    content_type = type_map.get(category, "channel")
    category_name = f"YouTube - {category}"

    # Se mode=direct, resolve URLs agora com yt-dlp
    if mode == "direct":
        resolved_urls = []
        for vid in video_ids:
            url = resolve_stream_url(vid)
            resolved_urls.append(url)
    else:
        resolved_urls = []

    lines = [
        f"-- YouTube IPTV - SQL para StreamFlow ({category})",
        f"-- Modo: {mode}",
        f"-- Execute no phpMyAdmin ou terminal MySQL",
        f"",
        f"-- 1) Cria a categoria se nao existir",
        f"INSERT IGNORE INTO categories (name, type) VALUES ('{category_name}', '{content_type}');",
        f"",
        f"-- 2) Insere os videos (usa SELECT pra pegar o category_id automaticamente)",
    ]

    for i, vid in enumerate(video_ids):
        title = video_titles[i] if i < len(video_titles) else f"Video {vid}"
        title_clean = title.replace("'", "\\'")
        thumb = video_thumbnails[i] if i < len(video_thumbnails) else ""
        desc = video_descs[i] if i < len(video_descs) else ""
        desc_clean = desc.replace("'", "\\'")

        if mode == "direct" and resolved_urls[i]:
            stream_url = resolved_urls[i].replace("'", "\\'")
        else:
            stream_url = f"{streamflow_url.rstrip('/')}/play/{vid}"

        lines.append(
            f"INSERT INTO content (type, title, category_id, url, cover_url, tvg_id, tvg_name, description, status) "
            f"SELECT '{content_type}', '{title_clean}', id, '{stream_url}', '{thumb}', '{vid}', '{title_clean}', '{desc_clean}', 'active' "
            f"FROM categories WHERE name = '{category_name}';"
        )

    lines.append(f"")
    lines.append(f"-- Total: {len(video_ids)} itens")

    content = "\n".join(lines)
    filename = f"youtube_{category.lower()}_streamflow.sql"
    from fastapi.responses import StreamingResponse

    async def stream():
        yield content.encode("utf-8")

    return StreamingResponse(
        stream(),
        media_type="application/sql",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


@app.get("/play/{video_id}")
async def play_video(video_id: str):
    yt_url = f"https://www.youtube.com/watch?v={video_id}"
    try:
        result = subprocess.run(
            ["yt-dlp", "-f", "best[protocol=https]/best", "--get-url", yt_url],
            capture_output=True,
            text=True,
            timeout=30,
        )
        if result.returncode == 0:
            stream_url = result.stdout.strip().split("\n")[0]
            if stream_url.startswith("http"):
                return RedirectResponse(url=stream_url)
    except (FileNotFoundError, subprocess.TimeoutExpired, subprocess.CalledProcessError):
        pass

    # Fallback: redirect to YouTube
    return RedirectResponse(url=yt_url)


@app.post("/api/channels/add")
async def add_channel(request: Request):
    body = await request.json()
    link = (body.get("link") or "").strip()
    name = (body.get("name") or "").strip()
    if not link:
        raise HTTPException(status_code=400, detail="Cole um link, @canal ou ID do canal.")

    channel_id = resolve_channel_id(link)
    if not channel_id:
        raise HTTPException(status_code=404, detail="Canal não encontrado.")

    if not name:
        try:
            chan = youtube.channels().list(part="snippet", id=channel_id).execute()
            name = chan["items"][0]["snippet"]["title"]
        except Exception:
            name = link

    channels = load_channels()
    for c in channels:
        if c.get("channel_id") == channel_id:
            raise HTTPException(status_code=409, detail="Este canal já está na lista.")

    logo = ""
    try:
        chan = youtube.channels().list(part="snippet", id=channel_id).execute()
        logo = chan["items"][0]["snippet"]["thumbnails"]["medium"]["url"]
    except Exception:
        pass

    entry = {
        "channel_id": channel_id,
        "name": name,
        "logo": logo,
        "group": "YouTube",
    }
    channels.append(entry)
    save_channels(channels)
    return {"ok": True, "channel": entry}


@app.get("/api/channels")
async def list_channels():
    return {"channels": load_channels()}


@app.post("/api/channels/delete")
async def delete_channel(request: Request):
    body = await request.json()
    idx = int(body.get("index", -1))
    channels = load_channels()
    if 0 <= idx < len(channels):
        channels.pop(idx)
        save_channels(channels)
        return {"ok": True}
    raise HTTPException(status_code=400, detail="Índice inválido.")


@app.get("/api/channel_status")
async def channel_status(channel_id: str = Query(...)):
    info = get_best_video_id(channel_id)
    if not info["video_id"]:
        raise HTTPException(status_code=404, detail="Canal sem vídeo encontrado.")
    return info


@app.get("/play_channel/{channel_id}")
async def play_channel(channel_id: str):
    info = get_best_video_id(channel_id)
    if not info["video_id"]:
        return RedirectResponse(url=f"https://www.youtube.com/channel/{channel_id}")
    return await play_video(info["video_id"])


@app.get("/stream/{video_id}")
async def stream_video(video_id: str, request: Request):
    """Resolve via yt-dlp e TRANSMITE pelo VPS (evita 403 de IP vinculado)."""
    url = await asyncio.to_thread(resolve_stream_url, video_id)
    if not url:
        raise HTTPException(status_code=404, detail="Não foi possível resolver o stream.")

    is_hls = (".m3u8" in url) or ("manifest" in url)
    if is_hls:
        async with httpx.AsyncClient(timeout=30, follow_redirects=True) as client:
            r = await client.get(url)
            if r.status_code != 200:
                raise HTTPException(status_code=502, detail="Falha ao buscar manifest.")
            content = rewrite_hls_manifest(r.text, str(r.url), video_id)
        return Response(content=content, media_type="application/vnd.apple.mpegurl")
    return await proxy_from(url, request)


@app.get("/hls/{video_id}")
async def hls_segment(video_id: str, request: Request, u: str = Query(...)):
    """Proxy de segmentos .ts do HLS."""
    return await proxy_from(u, request)


@app.get("/channel_stream/{channel_id}")
async def channel_stream(channel_id: str, request: Request):
    """Live se houver; senão último vídeo — transmitido pelo proxy do VPS."""
    info = get_best_video_id(channel_id)
    if not info["video_id"]:
        raise HTTPException(status_code=404, detail="Canal sem vídeo encontrado.")
    return await stream_video(info["video_id"], request)


@app.get("/playlist.m3u8")
async def playlist_m3u8():
    channels = load_channels()
    lines = ["#EXTM3U"]
    for c in channels:
        name = c.get("name") or "YouTube"
        logo = c.get("logo") or ""
        tvg_id = c.get("channel_id") or "yt"
        url = f"{BASE_URL}/channel_stream/{c['channel_id']}"
        lines.append(
            f'#EXTINF:-1 tvg-id="{tvg_id}" tvg-name="{name}" tvg-logo="{logo}" group-title="YouTube",{name}'
        )
        lines.append(url)
    content = "\n".join(lines)
    from fastapi.responses import StreamingResponse

    async def stream():
        yield content.encode("utf-8")

    return StreamingResponse(
        stream(),
        media_type="application/x-mpegURL",
        headers={"Content-Disposition": 'attachment; filename="youtube.m3u8"'},
    )


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8000, reload=True)
