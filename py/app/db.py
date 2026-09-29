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
    download    INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS sessions (
    token      TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_channels_user ON channels(user_id);
"""


def get_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH, timeout=15)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.execute("PRAGMA busy_timeout=8000;")
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
