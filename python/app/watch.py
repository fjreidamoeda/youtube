"""Monitor de download continuo (thread em background, como o daemon bg_watch_downloads.php)."""
from __future__ import annotations

import threading
import time

from . import config, db, media

_stop = threading.Event()
_thread: threading.Thread | None = None


def _loop() -> None:
    last = 0.0
    while not _stop.is_set():
        try:
            if db.count_downloads_enabled() == 0:
                # nada marcado: dorme profundo, mas continua "vivo" para nao
                # deixar lixo de thread quando o usuario ativar depois.
                _stop.wait(60)
                continue
            if time.time() - last >= config.WATCH_INTERVAL:
                media.watch_downloads_once()
                last = time.time()
            _stop.wait(30)
        except Exception as e:  # nunca derruba a thread
            config.log(f"watch: erro {e}")
            _stop.wait(60)


def start() -> None:
    global _thread
    if _thread and _thread.is_alive():
        return
    _stop.clear()
    _thread = threading.Thread(target=_loop, name="watch-downloads", daemon=True)
    _thread.start()
    config.log("monitor de download continuo iniciado")


def stop() -> None:
    _stop.set()


def running() -> bool:
    return bool(_thread and _thread.is_alive())


def trigger_once() -> dict[str, int]:
    """Passada unica (usada pelo gatilho na playlist e pelo botao do painel)."""
    return media.watch_downloads_once()
