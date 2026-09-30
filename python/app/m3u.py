"""Geracao da playlist M3U (espelha lista.php) e do EPG XMLTV (espelha epg.php)."""
from __future__ import annotations

import html
import re
from datetime import datetime, timedelta, timezone
from typing import Any

from . import db, youtube
from .config import PLAYLIST_FORMAT, YT_API_KEY


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
    formato: str = "",
) -> str:
    """Monta a M3U do usuario.

    - videos de canal/videos soltos recebem `vod="1"` no cabecalho (identificam o
      item como VOD); a live e a entrada do modo canal NAO recebem (sao fluxos
      continuos).
    - formato "hls" (padrao): URL .m3u8, o player puxa segmentos e da para
      pular trecho. "ts": MPEG-TS continuo (o jeito antigo, so para painel que
      nao aceita .m3u8).
    """
    ext = (formato or PLAYLIST_FORMAT or "hls").lower()
    ext = "ts" if ext == "ts" else "m3u8"

    lines: list[str] = [
        f'#EXTM3U url-tvg="{base}/epg.php?u={owner["username"]}&t={owner["token"]}"'
    ]
    if not YT_API_KEY:
        lines.append("# [AVISO] YT_API_KEY nao configurada: nenhum video sera listado.")

    def stream_url(vid: str) -> str:
        if mode == "vlc":
            return f"{base}/stream.php?id={vid}"
        return f"{base}/stream.php/{vid}.{ext}"

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
            entry(name, stream_url(vid), vod=True, tvg_logo=logo, tvg_name=name)
            continue

        channel_id = c.get("channel_id") or ""
        if not channel_id:
            continue

        if canal:
            entry(name, f"{base}/stream.php/c-{channel_id}.{ext}", vod=False, tvg_logo=logo, tvg_name=name)
            continue

        limit = max(1, min(500, int(c.get("max_videos", 50) or 50)))
        live_id = youtube.live_video_id(channel_id)
        if live_id:
            entry(f"[AO VIVO] {name}", stream_url(live_id), vod=False, tvg_logo=logo,
                  tvg_name=f"[AO VIVO] {name}")

        data = youtube.cached_channel_videos(channel_id, limit)
        items = data.get("items", [])
        if not live_id and not items:
            lines.append(f"# [AVISO] Nenhum conteudo para '{name}' ({channel_id}). Verifique YT_API_KEY.")
        for it in items:
            vid = it.get("id") or ""
            if not vid or vid == live_id:
                continue
            entry(it.get("title", ""), stream_url(vid), vod=True, tvg_logo=it.get("thumb") or logo)

    return "\n".join(lines) + "\n"


def build_channel_playlist(
    owner: dict[str, Any],
    base: str,
    channel: dict[str, Any],
    items: list[dict[str, Any]],
    formato: str = "",
    ao_vivo: bool = False,
) -> str:
    """Playlist .m3u8 de UM canal, so com os videos que ele marcou.

    E o mesmo formato da M3U geral (a lista plana #EXTINF + URL e o que os
    paineis IPTV e o VLC entendem), mas com um arquivo por canal e somente a
    selecao dele -- pronto para baixar, abrir no VLC ou cadastar no painel.

    - cada video recebe `vod="1"` (sao VOD) e aponta para /stream.php/ID.m3u8;
    - a live [AO VIVO] entra primeiro, sem vod, so com ao_vivo=True.
    """
    ext = (formato or PLAYLIST_FORMAT or "hls").lower()
    ext = "ts" if ext == "ts" else "m3u8"

    name = channel.get("name") or "YouTube"
    tvg_id = channel.get("tvg_id") or "yt_" + name[:8]
    logo = channel.get("logo") or ""
    grupo = "CANAL | " + name.upper()
    lines: list[str] = [
        f'#EXTM3U url-tvg="{base}/epg.php?u={owner["username"]}&t={owner["token"]}"',
        f"# {name} - {len(items)} video(s) selecionado(s)",
    ]

    entrou = 0

    def entry(title: str, url: str, vod: bool, thumb: str = "", tvg_name: str = "") -> None:
        nonlocal entrou
        t = _clean_title(title)
        attrs = f'tvg-id="{tvg_id}"'
        if tvg_name:
            attrs += f' tvg-name="{_clean_title(tvg_name)}"'
        if thumb or logo:
            attrs += f' tvg-logo="{thumb or logo}"'
        attrs += f' group-title="{grupo}"'
        if vod:
            attrs = 'vod="1" ' + attrs
        lines.append(f"#EXTINF:-1 {attrs},{t}")
        lines.append(f"#EXTGRP:{grupo}")
        lines.append(url)
        entrou += 1

    if ao_vivo:
        live_id = youtube.live_video_id(channel.get("channel_id") or "")
        if live_id:
            entry(f"[AO VIVO] {name}", f"{base}/stream.php/{live_id}.{ext}", vod=False,
                  tvg_name=f"[AO VIVO] {name}")

    for it in items:
        vid = it.get("id") or ""
        if not vid:
            continue
        entry(it.get("title") or vid, f"{base}/stream.php/{vid}.{ext}", vod=True,
              thumb=it.get("thumb") or "")

    if not entrou:
        lines.append("# [AVISO] Nenhum video neste canal ainda. "
                     "Marque os videos na tela de downloads do canal.")
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
