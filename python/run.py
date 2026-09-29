"""Sobe o servidor:  python run.py

Extras (uteis quando esquecer a senha):
    python run.py 8080                    sobe em outra porta
    python run.py --senha MINHASENHA      redefine a senha do admin
    python run.py --criar usuario:senha   cria um usuario
"""
from __future__ import annotations

import os
import sys


def _uso() -> str:
    return (
        "uso: python run.py [porta] [--senha SENHA] [--criar usuario:senha]\n"
        "  porta                numero da porta (padrao 8000)\n"
        "  --senha SENHA        redefine a senha do usuario admin\n"
        "  --criar user:senha   cria um usuario novo\n"
    )


def main(argv: list[str]) -> int:
    port = int(os.getenv("PORT", "8000"))
    i = 0
    while i < len(argv):
        a = argv[i]
        if a in ("--port", "-p") and i + 1 < len(argv):
            port = int(argv[i + 1])
            i += 2
        elif a.startswith("--port="):
            port = int(a.split("=", 1)[1])
            i += 1
        elif a == "--senha" and i + 1 < len(argv):
            from app import db

            db.init_db()
            nova = argv[i + 1]
            user = db.get_user("admin") or db.ensure_admin()
            if not user:
                db.create_user("admin", nova, role="admin", status="active")
            else:
                conn = db.get_conn()
                conn.execute(
                    "UPDATE users SET password_hash=?, status='active' WHERE username='admin'",
                    (db.hash_password(nova),),
                )
                conn.commit()
                conn.close()
            print(f"Senha do admin definida: {nova}")
            return 0
        elif a == "--criar" and i + 1 < len(argv):
            from app import db

            db.init_db()
            user, _, pwd = argv[i + 1].partition(":")
            db.create_user(user, pwd or "123456")
            print(f"Usuario criado: {user}")
            return 0
        elif a.isdigit():
            port = int(a)
            i += 1
        elif a in ("-h", "--help"):
            print(_uso())
            return 0
        else:
            print(f"opcao desconhecida: {a}\n{_uso()}")
            return 1

    import uvicorn

    os.environ["PORT"] = str(port)
    # No Windows o ProactorEventLoop (padrao do asyncio) derruba o servidor com
    # "OSError: [WinError 64] ... Accept failed on a socket" quando um cliente
    # desconecta de repente -- exatamente o que o painel IPTV faz ao trocar de
    # canal. SelectorEventLoop nao sofre com isso.
    loop = "asyncio"
    uvicorn.run(
        "app.web:app",
        host=os.getenv("HOST", "0.0.0.0"),
        port=port,
        log_level=os.getenv("LOG_LEVEL", "info"),
        loop=loop,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
