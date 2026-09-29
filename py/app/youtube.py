"""YouTube Data API v3 (via httpx) com cache em memoria + disco."""
from __future__ import annotations

import json
import re
import threading
import time
from pathlib import Path
from typing import Any

import httpx

from .config import CACHE_DIR, UA_DESKTOP, YOUTUBE_API, YT_API_KEY

_MEM: dict[str, tuple[float, Any]] = {}
_MEM_TTL = 1800.0
_lock = threading.Lock()


def _cache_get(key: str, ttl: float = _MEM_TTL) -> Any | None:
    with _lock:
        hit = _MEM.get(key)
    if hit and (time.time() - hit[0]) < ttl:
        return hit[1]
    f = CACHE_DIR / ("api_" + re.sub(r"[^A-Za-z0-9_.-]", "_", key)[:80] + ".json")
    if f.is_file() and (time.time() - f.stat().st_mtime) < ttl:
        try:
            data = json.loads(f.read_text(encoding="utf-8"))
            with _lock:
                _MEM[key] = (f.stat().st_mtime, data)
            return data
        except Exception:
            return None
    return None


def _cache_put(key: str, data: Any) -> Any:
    with _lock:
        _MEM[key] = (time.time(), data)
    f = CACHE_DIR / ("api_" + re.sub(r"[^A-Za-z0-9_.-]", "_", key)[:80] + ".json")
    try:
        f.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    except Exception:
        pass
    return data


def _api(path: str, params: dict[str, Any], ttl: float = _MEM_TTL, use_cache: bool = True) -> Any | None:
    if not YT_API_KEY:
        return None
    key = path + "?" + "&".join(f"{k}={v}" for k, v in sorted(params.items()) if v not in (None, ""))
    if use_cache:
        cached = _cache_get(key, ttl)
        if cached is not None:
            return cached
    params = {k: v for k, v in params.items() if v not in (None, "")}
    params["key"] = YT_API_KEY
    try:
        r = httpx.get(f"{YOUTUBE_API}/{path}", params=params, timeout=20, headers={"User-Agent": UA_DESKTOP})
        if r.status_code != 200:
            return None
        data = r.json()
    except Exception:
        return None
    return _cache_put(key, data)


# ------------------------------------------------------------------ canais

def search_channels(q: str, max_results: int = 10) -> list[dict[str, Any]]:
    data = _api("search", {"part": "snippet", "type": "channel", "q": q, "maxResults": max_results}, ttl=3600)
    out = []
    for it in (data or {}).get("items", []):
        sn = it.get("snippet", {})
        out.append(
            {
                "id": sn.get("channelId", ""),
                "title": sn.get("title", ""),
                "description": sn.get("description", ""),
                "thumb": (sn.get("thumbnails", {}).get("high", {}) or {}).get("url", ""),
            }
        )
    return out


def channel_snippet(channel_id: str) -> dict[str, Any]:
    data = _api("channels", {"part": "snippet", "id": channel_id}, ttl=86400)
    items = (data or {}).get("items") or []
    if not items:
        return {}
    sn = items[0].get("snippet", {})
    thumbs = sn.get("thumbnails", {})
    return {
        "title": sn.get("title", ""),
        "logo": (thumbs.get("medium") or thumbs.get("high") or thumbs.get("default") or {}).get("url", ""),
    }


def uploads_playlist(channel_id: str) -> str | None:
    data = _api("channels", {"part": "contentDetails", "id": channel_id}, ttl=86400)
    items = (data or {}).get("items") or []
    if not items:
        return None
    return items[0].get("contentDetails", {}).get("relatedPlaylists", {}).get("uploads")


def _playlist_items(playlist_id: str, max_results: int, page_token: str = "") -> tuple[list[dict[str, Any]], str]:
    data = _api(
        "playlistItems",
        {"part": "snippet", "playlistId": playlist_id, "maxResults": min(50, max(1, max_results)), "pageToken": page_token},
        ttl=600,
        use_cache=not page_token,
    )
    items: list[dict[str, Any]] = []
    for it in (data or {}).get("items", []):
        sn = it.get("snippet", {})
        vid = sn.get("resourceId", {}).get("videoId")
        if not vid:
            continue
        items.append(
            {
                "id": vid,
                "title": sn.get("title", ""),
                "thumb": (sn.get("thumbnails", {}).get("high") or {}).get("url", ""),
                "published": sn.get("publishedAt", ""),
            }
        )
    return items, (data or {}).get("nextPageToken", "")


def channel_videos(channel_id: str, max_results: int = 50, page_token: str = "") -> dict[str, Any]:
    """Videos recentes do canal (paginado)."""
    pl = uploads_playlist(channel_id)
    if not pl:
        return {"items": [], "next": ""}
    items, nxt = _playlist_items(pl, max_results, page_token)
    return {"items": items, "next": nxt}


def cached_channel_videos(channel_id: str, max_results: int = 50) -> dict[str, Any]:
    """Lista de videos com cache de 30 min (usada na playlist e no download)."""
    res = channel_videos(channel_id, max_results)
    return res


def live_video_id(channel_id: str) -> str | None:
    """Video AO VIVO do canal, se houver."""
    data = _api(
        "search",
        {"part": "snippet", "type": "video", "eventType": "live", "channelId": channel_id, "maxResults": 1},
        ttl=120,
    )
    for it in (data or {}).get("items", []):
        vid = it.get("id", {}).get("videoId")
        if vid:
            return vid
    return None


def video_info(video_id: str) -> dict[str, Any]:
    """Titulo/thumb de um video (oEmbed nao gasta quota)."""
    cached = _cache_get("oembed:" + video_id, ttl=86400)
    if cached:
        return cached
    try:
        r = httpx.get(
            "https://www.youtube.com/oembed",
            params={"url": f"https://www.youtube.com/watch?v={video_id}", "format": "json"},
            timeout=8,
            headers={"User-Agent": UA_DESKTOP},
        )
        if r.status_code == 200:
            d = r.json()
            return _cache_put(
                "oembed:" + video_id,
                {"title": d.get("title", ""), "author": d.get("author_name", ""), "thumb": d.get("thumbnail_url", "")},
            )
    except Exception:
        pass
    return {}


# ------------------------------------------------------------------ normalizacao

def normalize_input(link: str) -> tuple[str, str] | None:
    """Aceita link/ID de canal ou de video. Devolve ('channel_id'|'video_id', valor)."""
    s = (link or "").strip()
    if not s:
        return None
    if re.fullmatch(r"UC[A-Za-z0-9_-]{20,}", s):
        return ("channel_id", s)

    m = re.search(r"youtube\.com/channel/(UC[A-Za-z0-9_-]{20,})", s, re.I)
    if m:
        return ("channel_id", m.group(1))
    m = re.search(r"youtu\.be/([A-Za-z0-9_-]{11})", s, re.I)
    if m:
        return ("video_id", m.group(1))
    m = re.search(r"[?&]v=([A-Za-z0-9_-]{11})", s)
    if m:
        return ("video_id", m.group(1))
    m = re.search(r"youtube\.com/(?:shorts|embed|live|v)/([A-Za-z0-9_-]{11})", s, re.I)
    if m:
        return ("video_id", m.group(1))

    handle = None
    if s.startswith("@"):
        handle = s[1:]
    else:
        m = re.search(r"youtube\.com/@([^/?#\s]+)", s, re.I)
        if m:
            handle = m.group(1)
    if handle:
        found = search_channels(handle, 1)
        if found:
            return ("channel_id", found[0]["id"])
        return None

    if re.fullmatch(r"[A-Za-z0-9_-]{11}", s):
        # Pode ser video ou channel shorthand: tenta video.
        return ("video_id", s)

    found = search_channels(s, 1)
    if found:
        return ("channel_id", found[0]["id"])
    return None
