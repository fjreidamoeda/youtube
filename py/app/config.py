"""Configuracao: le o .env (sem dependencia externa) e expoe os ajustes."""
from __future__ import annotations

import os
import shutil
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


def load_dotenv(path: Path | None = None) -> None:
    """Le .env simples (KEY=VALOR) sem depender do python-dotenv."""
    p = path or (BASE_DIR / ".env")
    if not p.is_file():
        return
    for raw in p.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.strip()
        val = val.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = val


load_dotenv()


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, "") or default)
    except ValueError:
        return default


def _path(name: str, default: Path) -> Path:
    v = os.getenv(name, "").strip()
    return Path(v).expanduser() if v else default


def _tool(env_name: str, candidates: list[str]) -> str:
    """Usa o valor do .env se existir; senao procura um executavel conhecido."""
    v = os.getenv(env_name, "").strip()
    if v:
        return v
    for c in candidates:
        found = shutil.which(c) if not Path(c).is_file() else (c if Path(c).is_file() else None)
        if found:
            return found
    return candidates[0]


CACHE_DIR = _path("CACHE_DIR", BASE_DIR / "cache")
DB_PATH = _path("DB_PATH", BASE_DIR / "app.db")
CACHE_DIR.mkdir(parents=True, exist_ok=True)

YT_API_KEY = os.getenv("YT_API_KEY", "").strip()
BASE_URL = os.getenv("BASE_URL", "").strip().rstrip("/")

FFMPEG = _tool("FFMPEG", ["ffmpeg", "/usr/bin/ffmpeg"])
FFPROBE = _tool("FFPROBE", ["ffprobe", "/usr/bin/ffprobe"])
YTDLP = _tool("YTDLP", ["yt-dlp", "yt-dlp.exe"])

# Windows: esconde a janela do console ao rodar ffmpeg/yt-dlp.
CREATE_NO_WINDOW = 0x08000000 if sys.platform == "win32" else 0

COOKIES_FILE = os.getenv("YTDLP_COOKIES", "").strip()

MAX_CONCURRENT_DOWNLOADS = _int("MAX_CONCURRENT_DOWNLOADS", 3)
LOOP_MAX_AGE = _int("LOOP_MAX_AGE", 6 * 3600)
IPTV_WAIT_SECONDS = _int("IPTV_WAIT_SECONDS", 12)
VLC_WAIT_SECONDS = _int("VLC_WAIT_SECONDS", 20)
CHANNEL_WAIT_SECONDS = _int("CHANNEL_WAIT_SECONDS", 25)
WATCH_INTERVAL = _int("WATCH_INTERVAL", 240)

YOUTUBE_API = "https://www.googleapis.com/youtube/v3"
UA_DESKTOP = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)


def video_id_ok(vid: str) -> bool:
    return bool(vid) and all(c.isalnum() or c in "-_" for c in vid) and 6 <= len(vid) <= 24


def clean_id(vid: str) -> str:
    return "".join(c for c in (vid or "") if c.isalnum() or c in "-_")


def log(msg: str) -> None:
    """Log simples em cache/app.log."""
    from datetime import datetime

    line = f"{datetime.now().isoformat(timespec='seconds')} {msg}\n"
    try:
        with open(CACHE_DIR / "app.log", "a", encoding="utf-8") as fh:
            fh.write(line)
    except Exception:
        pass
