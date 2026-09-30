"""Factory de event loop para o uvicorn (loop=app.loops:selector_loop_factory).

No Windows o uvicorn >=0.36 usa o ProactorEventLoop para o loop "asyncio" e
ignora a politica do asyncio. O Proactor derruba o servidor com
"OSError: [WinError 64] ... Accept failed on a socket" quando um cliente se
desconecta de repente -- exatamente o que o painel IPTV faz ao trocar de canal.
O SelectorEventLoop nao sofre com isso, entao forçamos ele aqui.

Contrato do uvicorn para loop customizado: a string "modulo:atributo" deve
resolver para algo chamavel sem argumentos que devolva uma INSTANCIA de loop
(o Runner do asyncio chama loop_factory() e usa o resultado). Por isso aqui
devolvemos o loop construido, e nao a classe.
"""
from __future__ import annotations

import asyncio
import sys


def selector_loop_factory() -> asyncio.AbstractEventLoop:
    if sys.platform == "win32":
        # SelectorEventLoop nao da suporte a subprocessos no Windows, mas o app
        # so usa subprocessos via threading/Popen (ffmpeg, yt-dlp), nunca pelo
        # asyncio -- entao nada pede o Proactor.
        return asyncio.SelectorEventLoop()
    return asyncio.new_event_loop()