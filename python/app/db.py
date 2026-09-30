"""Banco SQLite: usuarios, sessoes e canais (espelha o auth.php do PHP)."""
from __future__ import annotations

import hashlib
import hmac
import os
import secrets
import sqlite3
import time
from typing import Any

from .config import DB_PATH

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'user',
    status        TEXT NOT NULL DEFAULT 'active',
    token         TEXT NOT NULL UNIQUE,
    note          TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS channels (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    video_id    TEXT NOT NULL DEFAULT '',
    channel_id  TEXT NOT NULL DEFAULT '',
    name        TEXT NOT NULL DEFAULT '',
    logo        TEXT NOT NULL DEFAULT '',
    tvg_id      TEXT NOT NULL DEFAULT '',
    max_videos  INTEGER NOT NULL DEFAULT 50,
    download    INTEGER NOT NULL DEFAULT 0,
    has_selection INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS sessions (
    token      TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS channel_videos (
    channel_row INTEGER NOT NULL,
    video_id    TEXT NOT NULL,
    sel         INTEGER NOT NULL DEFAULT 1,
    pos         INTEGER NOT NULL DEFAULT 0,
    updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (channel_row, video_id)
);
CREATE INDEX IF NOT EXISTS idx_channels_user ON channels(user_id);
CREATE INDEX IF NOT EXISTS idx_chvideos ON channel_videos(channel_row);
"""


_wal_ok = False  # o modo WAL ja fica gravado no arquivo; so precisa ser pedido 1x


def get_conn() -> sqlite3.Connection:
    global _wal_ok
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=10000;")
    if not _wal_ok:
        # Trocar o journal_mode precisa de trava exclusiva; com disco saturado
        # (downloads + ffmpeg escrevendo ao mesmo tempo) isso pode estourar o
        # timeout e derrubar um request que so queria LEITURA. Como o modo ja
        # fica persistido no arquivo, e pedida a 1a conexao apenas e, se falhar,
        # segue em frente (a proxima tentativa refaz).
        try:
            conn.execute("PRAGMA journal_mode=WAL;")
            _wal_ok = True
        except sqlite3.OperationalError:
            pass
    conn.execute("PRAGMA foreign_keys=ON;")
    return conn


def init_db() -> None:
    conn = get_conn()
    try:
        conn.executescript(SCHEMA)
        # Migracoes: bancos criados antes destas colunas.
        cols = {r["name"] for r in conn.execute("PRAGMA table_info(channels)")}
        if "max_videos" not in cols:
            conn.execute("ALTER TABLE channels ADD COLUMN max_videos INTEGER NOT NULL DEFAULT 50")
        if "download" not in cols:
            conn.execute("ALTER TABLE channels ADD COLUMN download INTEGER NOT NULL DEFAULT 0")
        # 0 = o usuario ainda nao escolheu os videos (entra tudo na playlist)
        # 1 = a escolha vale (inclusive se ele desmarcou tudo)
        if "has_selection" not in cols:
            conn.execute("ALTER TABLE channels ADD COLUMN has_selection INTEGER NOT NULL DEFAULT 0")
        # 'sel' distingue "marcado" de "desmarcado" -- sem ele, um video que o
        # usuario desmarcou voltaria para a playlist no proximo sync.
        cvcols = {r["name"] for r in conn.execute("PRAGMA table_info(channel_videos)")}
        if "sel" not in cvcols:
            conn.execute("ALTER TABLE channel_videos ADD COLUMN sel INTEGER NOT NULL DEFAULT 1")
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------- senha

def hash_password(password: str) -> str:
    salt = secrets.token_bytes(16)
    dk = hashlib.pbkdf2_hmac("sha256", password.encode(), salt, 200_000)
    return f"pbkdf2$200000${salt.hex()}${dk.hex()}"


def check_password(password: str, stored: str) -> bool:
    try:
        _, iters, salt_hex, dk_hex = stored.split("$")
        dk = hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(salt_hex), int(iters))
        return hmac.compare_digest(dk.hex(), dk_hex)
    except Exception:
        return False


# ---------------------------------------------------------------- usuarios

def new_token() -> str:
    return secrets.token_urlsafe(24)


def create_user(username: str, password: str, role: str = "user", status: str = "active") -> dict[str, Any]:
    username = (username or "").strip()
    if not username or not password:
        raise ValueError("usuario e senha obrigatorios")
    conn = get_conn()
    try:
        conn.execute(
            "INSERT INTO users (username, password_hash, role, status, token) VALUES (?,?,?,?,?)",
            (username, hash_password(password), role, status, new_token()),
        )
        conn.commit()
        return get_user(username)  # type: ignore[return-value]
    finally:
        conn.close()


def get_user(username: str) -> dict[str, Any] | None:
    conn = get_conn()
    try:
        row = conn.execute("SELECT * FROM users WHERE username=?", (username,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def get_user_by_id(uid: int) -> dict[str, Any] | None:
    conn = get_conn()
    try:
        row = conn.execute("SELECT * FROM users WHERE id=?", (uid,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def list_users() -> list[dict[str, Any]]:
    conn = get_conn()
    try:
        return [dict(r) for r in conn.execute("SELECT * FROM users ORDER BY id")]
    finally:
        conn.close()


def set_user_status(uid: int, status: str) -> None:
    conn = get_conn()
    try:
        conn.execute("UPDATE users SET status=? WHERE id=?", (status, uid))
        conn.commit()
    finally:
        conn.close()


def user_by_playlist_token(user: str, token: str) -> dict[str, Any] | None:
    conn = get_conn()
    try:
        row = conn.execute(
            "SELECT * FROM users WHERE username=? AND token=?", (user or "", token or "")
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def ensure_admin() -> str | None:
    """Cria o admin padrao na primeira execucao. Devolve a senha gerada."""
    conn = get_conn()
    try:
        row = conn.execute("SELECT COUNT(*) AS n FROM users WHERE role='admin'").fetchone()
        if row and row["n"] > 0:
            return None
    finally:
        conn.close()
    pwd = secrets.token_urlsafe(9)
    create_user("admin", pwd, role="admin", status="active")
    return pwd


# ---------------------------------------------------------------- sessoes

SESSION_TTL = 7 * 24 * 3600


def create_session(uid: int) -> str:
    tok = secrets.token_urlsafe(32)
    now = int(time.time())
    conn = get_conn()
    try:
        conn.execute(
            "INSERT INTO sessions (token, user_id, created_at, expires_at) VALUES (?,?,?,?)",
            (tok, uid, now, now + SESSION_TTL),
        )
        conn.commit()
    finally:
        conn.close()
    return tok


def session_user(token: str | None) -> dict[str, Any] | None:
    if not token:
        return None
    conn = get_conn()
    try:
        row = conn.execute(
            "SELECT s.expires_at, u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token=?",
            (token,),
        ).fetchone()
        if not row:
            return None
        if int(row["expires_at"]) < int(time.time()):
            conn.execute("DELETE FROM sessions WHERE token=?", (token,))
            conn.commit()
            return None
        return dict(row)
    finally:
        conn.close()


def drop_session(token: str | None) -> None:
    if not token:
        return
    conn = get_conn()
    try:
        conn.execute("DELETE FROM sessions WHERE token=?", (token,))
        conn.commit()
    finally:
        conn.close()


# ---------------------------------------------------------------- canais

def channels_for_user(uid: int) -> list[dict[str, Any]]:
    conn = get_conn()
    try:
        return [dict(r) for r in conn.execute("SELECT * FROM channels WHERE user_id=? ORDER BY id", (uid,))]
    finally:
        conn.close()


def channel_by_id(cid: int) -> dict[str, Any] | None:
    conn = get_conn()
    try:
        row = conn.execute("SELECT * FROM channels WHERE id=?", (cid,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def find_channel_anywhere(channel_id: str) -> dict[str, Any] | None:
    """O modo canal e accessed pela URL (sem sessao): acha o canal em qualquer
    usuario para pegar max_videos."""
    conn = get_conn()
    try:
        row = conn.execute("SELECT * FROM channels WHERE channel_id=? LIMIT 1", (channel_id,)).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def add_channel(uid: int, c: dict[str, Any]) -> int:
    conn = get_conn()
    try:
        cur = conn.execute(
            "INSERT INTO channels (user_id, video_id, channel_id, name, logo, tvg_id, max_videos, download)"
            " VALUES (?,?,?,?,?,?,?,0)",
            (
                uid,
                c.get("video_id", "") or "",
                c.get("channel_id", "") or "",
                c.get("name", "") or "",
                c.get("logo", "") or "",
                c.get("tvg_id", "") or ("yt_" + hashlib.md5((c.get("channel_id") or c.get("name") or "x").encode()).hexdigest()[:8]),
                max(1, min(500, int(c.get("max_videos", 50) or 50))),
            ),
        )
        conn.commit()
        return int(cur.lastrowid)
    finally:
        conn.close()


def delete_channel(cid: int, uid: int | None = None) -> None:
    conn = get_conn()
    try:
        if uid is None:
            conn.execute("DELETE FROM channels WHERE id=?", (cid,))
        else:
            conn.execute("DELETE FROM channels WHERE id=? AND user_id=?", (cid, uid))
        conn.execute("DELETE FROM channel_videos WHERE channel_row=?", (cid,))
        conn.commit()
    finally:
        conn.close()


def set_channel_qty(cid: int, uid: int, qty: int) -> None:
    conn = get_conn()
    try:
        conn.execute(
            "UPDATE channels SET max_videos=? WHERE id=? AND user_id=?",
            (max(1, min(500, qty)), cid, uid),
        )
        conn.commit()
    finally:
        conn.close()


def set_channel_download(cid: int, uid: int, on: bool) -> None:
    conn = get_conn()
    try:
        conn.execute(
            "UPDATE channels SET download=? WHERE id=? AND user_id=?", (1 if on else 0, cid, uid)
        )
        conn.commit()
    finally:
        conn.close()


def channels_with_download() -> list[dict[str, Any]]:
    conn = get_conn()
    try:
        return [
            dict(r)
            for r in conn.execute("SELECT * FROM channels WHERE download=1 AND channel_id<>''")
        ]
    finally:
        conn.close()


def count_downloads_enabled() -> int:
    conn = get_conn()
    try:
        return int(conn.execute("SELECT COUNT(*) FROM channels WHERE download=1 AND channel_id<>''").fetchone()[0])
    finally:
        conn.close()


# ------------------------------------------------- videos selecionados do canal

def save_selection(row_id: int, video_ids: list[str], marcar_tudo: bool = False) -> None:
    """Grava a escolha do usuario (a ordem das caixas = ordem da playlist .m3u8).

    Fica guardada de proposito: o .m3u8 do canal precisa poder ser baixado/
    aberto em qualquer momento depois, e nao so no instante do clique.

    O que ficou de fora entra com sel=0 -- e o que impede o sync de devolver o
    video para a playlist. Guardar o "nao" e tao importante quanto o "sim".
    marcar_tudo=True (botao "baixar TODOS") apaga essa memoria e marca tudo."""
    conn = get_conn()
    try:
        atuais = {
            r["video_id"]
            for r in conn.execute("SELECT video_id FROM channel_videos WHERE channel_row=?", (row_id,))
        }
        escolhidos = list(dict.fromkeys(video_ids))
        conn.execute("DELETE FROM channel_videos WHERE channel_row=?", (row_id,))
        ultimo = len(escolhidos)
        for pos, vid in enumerate(escolhidos):
            conn.execute(
                "INSERT OR IGNORE INTO channel_videos (channel_row, video_id, sel, pos) VALUES (?,?,1,?)",
                (row_id, vid, pos),
            )
        for vid in sorted(atuais - set(escolhidos)):
            if marcar_tudo:
                break
            conn.execute(
                "INSERT OR IGNORE INTO channel_videos (channel_row, video_id, sel, pos) VALUES (?,?,0,?)",
                (row_id, vid, ultimo),
            )
            ultimo += 1
        conn.execute("UPDATE channels SET has_selection=1 WHERE id=?", (row_id,))
        conn.commit()
    finally:
        conn.close()


def sync_selection(row_id: int, video_ids: list[str], has_selection: bool) -> list[str]:
    """Mantem a selecao em dia com os videos que o canal publica.

    - Video que o usuario ainda nao viu (upload novo) entra marcado, para a
      playlist do canal ir crescendo sozinha junto com o download continuo.
    - O que ele desmarcou continua fora (sel=0), mesmo que o sync rode mil vezes.
    - A ordem escolhida e preservada; os novos vao para o fim.
    - Se ele nunca escolheu, entra tudo.
    Devolve a lista de ids marcados, na ordem final."""
    conn = get_conn()
    try:
        gravados = {
            r["video_id"]: (r["pos"], r["sel"])
            for r in conn.execute("SELECT video_id, pos, sel FROM channel_videos WHERE channel_row=?", (row_id,))
        }
        if not has_selection or not gravados:
            # primeira vez: tudo marcado, na ordem em que o canal devolveu
            conn.execute("DELETE FROM channel_videos WHERE channel_row=?", (row_id,))
            for pos, vid in enumerate(video_ids):
                conn.execute(
                    "INSERT OR IGNORE INTO channel_videos (channel_row, video_id, sel, pos) VALUES (?,?,1,?)",
                    (row_id, vid, pos),
                )
            gravados = {vid: (pos, 1) for pos, vid in enumerate(video_ids)}
        else:
            ultimo = max((p for p, _ in gravados.values()), default=-1) + 1
            for vid in video_ids:
                if vid not in gravados:
                    gravados[vid] = (ultimo, 1)
                    ultimo += 1
                    conn.execute(
                        "INSERT OR IGNORE INTO channel_videos (channel_row, video_id, sel, pos) VALUES (?,?,1,?)",
                        (row_id, vid, gravados[vid][0]),
                    )
        conn.commit()
        # some com o que foi apagado do canal, para a playlist nao apontar para video morto
        valendo = set(video_ids)
        return [
            vid
            for vid, (pos, sel) in sorted(gravados.items(), key=lambda kv: kv[1][0])
            if sel and vid in valendo
        ]
    finally:
        conn.close()


def selection_for(row_id: int) -> list[str]:
    """Ids marcados do canal, na ordem escolhida (vazio = ainda nao escolheu)."""
    conn = get_conn()
    try:
        return [
            r["video_id"]
            for r in conn.execute(
                "SELECT video_id FROM channel_videos WHERE channel_row=? AND sel=1 ORDER BY pos", (row_id,)
            )
        ]
    finally:
        conn.close()


def clear_selection(row_id: int) -> None:
    conn = get_conn()
    try:
        conn.execute("DELETE FROM channel_videos WHERE channel_row=?", (row_id,))
        conn.execute("UPDATE channels SET has_selection=0 WHERE id=?", (row_id,))
        conn.commit()
    finally:
        conn.close()
