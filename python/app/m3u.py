"""Geracao da playlist M3U (espelha lista.php) e do EPG XMLTV (espelha epg.php)."""
from __future__ import annotations

import html
import re
from datetime import datetime, timedelta, timezone
from typing import Any

from . import db, youtube
from .config import YT_API_KEY


def _clean_title(t: str) -> str:
    """M3U: o titulo vem depois da virgula do #EXTINF, entao nao pode ter virgula
    (nem quebra de linha / aspas)."""
    t = re.sub(r"[\r\n]+", " ", t or "")
    t = t.replace(",", " ").replace('"', "'")
    return t.strip()


def _xmltv_time(dt: datetime) -> str:
    off = dt.strftime("%z") or "+0000"
    return dt.strftime("%Y%m%d%H%M%S") + " " + off[:3] + ":" + off[3:]


def _group(name: str) -> str:
    return "CANAIS | " + (name.upper() if name else "YOUTUBE")


def build_playlist(
    owner: dict[str, Any],
    base: str,
    mode: str = "iptv",
    canal: bool = False,
) -> str:
    """Monta a M3U do usuario.

    - videos de canal/videos soltos recebem `vod="1"` no cabecalho (identificam o
      item como VOD); a live e a entrada do modo canal NAO recebem (sao fluxos
      continuos).
    - URL mantem o sufixo .ts: e o que faz o painel classificar como IPTV.
    """
    lines: list[str] = [
        f'#EXTM3U url-tvg="{base}/epg.php?u={owner["username"]}&t={owner["token"]}"'
    ]
    if not YT_API_KEY:
        lines.append("# [AVISO] YT_API_KEY nao configurada: nenhum video sera listado.")

    for c in db.channels_for_user(int(owner["id"])):
        name = c.get("name") or "YouTube"
        tvg_id = c.get("tvg_id") or "yt_" + name[:8]
        logo = c.get("logo") or ""
        group = _group(name)

        def entry(title: str, url: str, vod: bool, tvg_logo: str = "", tvg_name: str = "") -> None:
            t = _clean_title(title)
            attrs = f'tvg-id="{tvg_id}"'
            if tvg_name:
                attrs += f' tvg-name="{_clean_title(tvg_name)}"'
            if tvg_logo:
                attrs += f' tvg-logo="{tvg_logo}"'
            attrs += f' group-title="{group}"'
            if vod:
                attrs = "vod=\"1\" " + attrs
            lines.append(f"#EXTINF:-1 {attrs},{t}")
            lines.append(f"#EXTGRP:{group}")
            lines.append(url)

        if c.get("video_id"):
            vid = c["video_id"]
            url = f"{base}/stream.php?id={vid}" if mode == "vlc" else f"{base}/stream.php/{vid}.ts"
            entry(name, url, vod=True, tvg_logo=logo, tvg_name=name)
            continue

        channel_id = c.get("channel_id") or ""
        if not channel_id:
            continue

        if canal:
            entry(name, f"{base}/stream.php/c-{channel_id}.ts", vod=False, tvg_logo=logo, tvg_name=name)
            continue

        limit = max(1, min(500, int(c.get("max_videos", 50) or 50)))
        live_id = youtube.live_video_id(channel_id)
        if live_id:
            entry(f"[AO VIVO] {name}", f"{base}/stream.php/{live_id}.ts", vod=False, tvg_logo=logo,
                  tvg_name=f"[AO VIVO] {name}")

        data = youtube.cached_channel_videos(channel_id, limit)
        items = data.get("items", [])
        if not live_id and not items:
            lines.append(f"# [AVISO] Nenhum conteudo para '{name}' ({channel_id}). Verifique YT_API_KEY.")
        for it in items:
            vid = it.get("id") or ""
            if not vid or vid == live_id:
                continue
            url = f"{base}/stream.php?id={vid}" if mode == "vlc" else f"{base}/stream.php/{vid}.ts"
            entry(it.get("title", ""), url, vod=True, tvg_logo=it.get("thumb") or logo)

    return "\n".join(lines) + "\n"


def build_epg(owner: dict[str, Any], block_hours: int = 6, blocks_ahead: int = 4) -> str:
    """XMLTV basico (blocos 'transmissao continua'), igual ao epg.php."""
    now = datetime.now().astimezone()
    out = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<tv generator-info-name="yt-auto-xmltv" source-info-name="YouTube">',
    ]
    channels = db.channels_for_user(int(owner["id"]))
    for c in channels:
        name = c.get("name") or "YouTube"
        tvg_id = c.get("tvg_id") or "yt_" + name[:8]
        out.append(f'  <channel id="{html.escape(tvg_id, quote=True)}">')
        out.append(f"    <display-name>{html.escape(name)}</display-name>")
        if c.get("logo"):
            out.append(f'    <icon src="{html.escape(c["logo"], quote=True)}" />')
        out.append("  </channel>")

    for c in channels:
        name = c.get("name") or "YouTube"
        tvg_id = c.get("tvg_id") or "yt_" + name[:8]
        start = now
        for _ in range(blocks_ahead):
            end = start + timedelta(hours=block_hours)
            out.append(
                f'  <programme start="{_xmltv_time(start)}" stop="{_xmltv_time(end)}" '
                f'channel="{html.escape(tvg_id, quote=True)}">'
            )
            out.append("    <title lang=\"pt\">Ao vivo</title>")
            out.append(f'    <desc lang="pt">{html.escape("Transmissao continua: " + name)}</desc>')
            out.append('    <category lang="pt">Ao vivo</category>')
            out.append("  </programme>")
            start = end
    out.append("</tv>")
    return "\n".join(out) + "\n"
