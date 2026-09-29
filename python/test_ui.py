"""Testa as telas do painel e as variantes de playlist (canal / vlc / epg)."""
from __future__ import annotations

import os
import re

import httpx

BASE = "http://127.0.0.1:8000"
CHANNEL = os.getenv("TEST_CHANNEL", "UCIpylxq_JDl2YpvTpN11p9Q")
USER, PWD = os.getenv("TEST_USER", "teste"), os.getenv("TEST_PASS", "teste123")

c = httpx.Client(base_url=BASE, timeout=60, follow_redirects=True)
ok = lambda t, e="": print(f"[ OK ] {t} {e}")
fail = lambda t, e="": print(f"[FALHA] {t} {e}")


def csrf(html: str) -> str:
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ""


r = c.get("/login")
if c.post("/login", data={"csrf": csrf(r.text), "username": USER, "password": PWD}).url.path == "/login":
    fail("login")
    raise SystemExit(1)
ok("login", USER)

# --- telas -------------------------------------------------------------
pages = {
    "painel": "/",
    "grade": f"/grade/{CHANNEL}",
    "downloads": f"/dl/{CHANNEL}",
    "download video solto": "/dl/v/mR1HSQgVvcA",
}
for nome, path in pages.items():
    r = c.get(path)
    (ok if r.status_code == 200 and "Entrar" not in r.text else fail)(
        f"tela {nome}", f"{r.status_code} {len(r.text)}b"
    )

# /admin so para admin (403 esperado para user comum)
r = c.get("/admin")
(ok if r.status_code == 403 else fail)("admin bloqueado para user", f"{r.status_code}")
if os.getenv("TEST_ADMIN_PASS"):
    a = httpx.Client(base_url=BASE, timeout=60, follow_redirects=True)
    ra = a.get("/login")
    a.post("/login", data={"csrf": csrf(ra.text), "username": "admin", "password": os.environ["TEST_ADMIN_PASS"]})
    ra = a.get("/admin")
    (ok if ra.status_code == 200 and "Novo usuario" in ra.text else fail)(f"tela admin (admin)", f"{ra.status_code}")
    # criar usuario pela tela
    a.post("/admin/add", data={"csrf": csrf(a.get("/admin").text), "username": "u2", "password": "x12345", "role": "user"})
    (ok if "u2" in a.get("/admin").text else fail)("criar usuario pelo admin")
    a.post("/admin/status", data={"csrf": csrf(a.get("/admin").text), "id": "1", "status": "pending"})
    (ok if "pending" in a.get("/admin").text else fail)("desativar usuario")
    a.post("/admin/status", data={"csrf": csrf(a.get("/admin").text), "id": "1", "status": "active"})
    (ok if "active" in a.get("/admin").text else fail)("reativar usuario")

# botao de download continuo (liga e desliga)
csrfv = csrf(c.get("/").text)
mid = re.search(r'name="id" value="(\d+)"', c.get(f"/dl/{CHANNEL}").text)
if mid:
    cid = mid.group(1)
    c.post("/canais/download", data={"csrf": csrfv, "id": cid, "on": "1"})
    on = '<input type="hidden" name="on" value="0">' in c.get(f"/dl/{CHANNEL}").text
    c.post("/canais/download", data={"csrf": csrfv, "id": cid, "on": "0"})
    off = '<input type="hidden" name="on" value="1">' in c.get(f"/dl/{CHANNEL}").text
    (ok if on and off else fail)("botao download continuo", f"ligou={on} desligou={off}")
else:
    fail("botao download continuo", "form nao encontrado")

# --- playlist ---------------------------------------------------------
m = re.search(r'lista\.php\?u=([^&"]+)&amp;t=([^&"]+)', c.get("/").text)
u, t = m.group(1), m.group(2)


def check(nome: str, path: str, deve_ter: list[str], nao_deve: list[str]) -> str:
    r = c.get(path)
    body = r.text
    problems = [x for x in deve_ter if x not in body] + [x for x in nao_deve if x in body]
    (ok if r.status_code == 200 and not problems else fail)(
        f"playlist {nome}", f"{r.status_code} {len(body)}b" + (f" faltando={problems}" if problems else "")
    )
    return body


pl = check("padrao", f"/lista.php?u={u}&t={t}", ['#EXTM3U url-tvg=', 'vod="1"', f"/stream.php/"], [])
n_vod = pl.count('vod="1"')
n_inf = sum(1 for l in pl.splitlines() if l.startswith("#EXTINF"))
ok("  marcador VOD", f"{n_vod}/{n_inf} itens")
if n_vod != n_inf:
    fail("  TODO item de video deveria ter vod=1", f"{n_vod}/{n_inf}")

pl_c = check("modo canal", f"/lista.php?u={u}&t={t}&modo=canal", [f"/stream.php/c-{CHANNEL}.m3u8"], ['vod="1"'])
ok("  modo canal sem vod", f"{pl_c.count('vod=')} marcadores (esperado 0)")

# o padrao da playlist agora e HLS (.m3u8); ?formato=ts volta ao MPEG-TS
pl_t = check("modo canal formato=ts", f"/lista.php?u={u}&t={t}&modo=canal&formato=ts", [f"/stream.php/c-{CHANNEL}.ts"], [])
check("hls na playlist padrao", f"/lista.php?u={u}&t={t}", [".m3u8"], [".ts"])
ok("  vod aponta para .m3u8", f"{pl.count('.m3u8')} itens")

pl_v = check("vlc", f"/lista.php?u={u}&t={t}&mode=vlc", ["stream.php?id="], [".ts"])
ok("  vlc sem .ts", f"{pl_v.count('stream.php?id=')} itens")

epg = check("epg", f"/epg.php?u={u}&t={t}", ["<tv ", "<channel id=", "<programme start="], [])

# tela de download de um video solto
check("video solto", "/dl/v/dQw4w9WgXcQ", ["Downloads"], [])

# charset
r = c.get(f"/lista.php?u={u}&t={t}")
ok("charset", r.headers.get("content-type", ""))

# urls absolutas e sem barra duplicada
bad = [l for l in pl.splitlines() if l.startswith("http") and ("youtube.com/watch" in l or "//stream" in l)]
(ok if not bad else fail)("urls da playlist", f"{len(bad)} suspeitas")

# --- compactacao opcional: modo=gz -------------------------------------
r = c.get(f"/lista.php?u={u}&t={t}&download=1")
ok("download=1 (anexo)", r.headers.get("content-disposition", "")[:40])

print("\nFIM")
