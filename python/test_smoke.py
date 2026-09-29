"""Teste de fumaca: login -> adiciona canal -> playlist -> download -> stream TS -> modo canal.
Uso: python test_smoke.py [CHANNEL_ID]
"""
from __future__ import annotations

import re
import subprocess
import sys
import time
from pathlib import Path

import httpx

BASE = "http://127.0.0.1:8000"
CHANNEL = sys.argv[1] if len(sys.argv) > 1 else "UCIpylxq_JDl2YpvTpN11p9Q"
import os

USER = os.getenv("TEST_USER", "admin")
PWD = os.getenv("TEST_PASS", "") or (sys.argv[2] if len(sys.argv) > 2 else "")

if not PWD:
    for line in (Path("server.out.log").read_text(encoding="utf-8", errors="replace").splitlines()
                 if Path("server.out.log").is_file() else []):
        m = re.search(r"senha: (\S+)", line)
        if m:
            PWD = m.group(1)
            break

c = httpx.Client(base_url=BASE, timeout=120, follow_redirects=True)
ok = lambda t, extra="": print(f"[ OK ] {t} {extra}")
fail = lambda t, extra="": print(f"[FALHA] {t} {extra}")


def csrf(html: str) -> str:
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ""


# 1) login
r = c.get("/login")
tok = csrf(r.text)
r = c.post("/login", data={"csrf": tok, "username": USER, "password": PWD})
if "/login" in str(r.url) and "Entrar" in r.text:
    fail("login", r.text[:200])
    sys.exit(1)
ok("login", f"como {USER}")

# 2) garantir o canal
r = c.get("/")
tok = csrf(r.text)
c.post("/canais/add", data={"csrf": tok, "link": CHANNEL, "name": "FLAZOEIRO (teste)"})
r = c.get("/")
ok("canal adicionado", "FLAZOEIRO" if "FLAZOEIRO" in r.text else "ERRO ao adicionar")

# 3) playlist
tokurl = re.search(r"lista\.php\?u=([^&\"]+)&amp;t=([^&\"]+)", r.text) or re.search(r"lista\.php\?u=([^&\"]+)&t=([^&\"]+)", r.text)
user, ptok = tokurl.group(1), tokurl.group(2)
pl = c.get(f"/lista.php?u={user}&t={ptok}").text
lines = [l for l in pl.splitlines() if l.strip()]
vod = sum(1 for l in lines if 'vod="1"' in l)
extinf = sum(1 for l in lines if l.startswith("#EXTINF"))
print(f"\n--- playlist: {extinf} itens, {vod} com vod=\"1\" ---")
for l in lines[:6]:
    print("   ", l[:150])

vids = [l for l in lines if l.startswith("http") and "/stream.php/" in l and "/c-" not in l]
if not vids:
    fail("playlist sem itens")
    sys.exit(1)
first = vids[0].rsplit("/", 1)[-1].replace(".ts", "")
ok("header VOD", f"{vod}/{extinf} itens marcados")

# 4) modo canal
ch_url = f"/stream.php/c-{CHANNEL}.ts"
ok("modo canal", ch_url)

# 5) baixar 1 video e servir TS
tok = csrf(c.get("/").text)
c.post("/dl/start", data={"csrf": tok, "ids": [first]})
print(f"\nbaixando {first} (ate 180s)...")
sys.path.insert(0, str(Path(__file__).parent))
from app import media  # noqa: E402

t0 = time.time()
ready = media.wait_download(first, 180)
dt = time.time() - t0
if ready:
    f = media.find_loop(first)
    ok("download", f"{f.name} {f.stat().st_size / 1048576:.1f} MB em {dt:.0f}s")
else:
    fail("download", "timeout")
    print(media.ytdlp_log(first).read_text(encoding="utf-8", errors="replace")[-1500:])

# 6) HEAD (probe do painel)
h = c.head(f"/stream.php/{first}.ts")
ok("HEAD probe", f"status={h.status_code} type={h.headers.get('content-type')}")

# 7) GET TS -> arquivo -> ffprobe
for label, url in (("IPTV .ts", f"/stream.php/{first}.ts"), ("VLC mp4", f"/stream.php?id={first}")):
    try:
        with c.stream("GET", url) as resp:
            data = b""
            for chunk in resp.iter_bytes(262144):
                data += chunk
                if len(data) > 700_000:
                    break
        tmp = Path(f"smoke_{label.split()[0]}.bin")
        tmp.write_bytes(data)
        pr = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=format_name,duration",
             "-show_entries", "stream=codec_type,codec_name,width,height", "-of", "default=nw=1", str(tmp)],
            capture_output=True, text=True, timeout=30)
        info = " ".join(pr.stdout.split())
        (ok if len(data) > 100_000 else fail)(f"GET {label}", f"{len(data)} bytes | {info[:150]}")
    except Exception as e:
        fail(f"GET {label}", str(e)[:200])

# 8) modo canal: le um pedaco
try:
    with c.stream("GET", ch_url) as resp:
        data = b""
        t0 = time.time()
        for chunk in resp.iter_bytes(262144):
            data += chunk
            if len(data) > 900_000 or time.time() - t0 > 90:
                break
    tmp = Path("smoke_canal.bin")
    tmp.write_bytes(data)
    pr = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=format_name",
                         "-show_entries", "stream=codec_name,width,height", "-of", "default=nw=1", str(tmp)],
                        capture_output=True, text=True, timeout=30)
    (ok if len(data) > 100_000 else fail)("GET modo canal", f"{len(data)} bytes | {' '.join(pr.stdout.split())[:150]}")
except Exception as e:
    fail("GET modo canal", str(e)[:200])

# 9) Range (seek do VLC)
try:
    r = c.get(f"/stream.php?id={first}", headers={"Range": "bytes=0-1023"})
    ok("Range/VLC seek", f"status={r.status_code} bytes={len(r.content)} cr={r.headers.get('content-range')}")
except Exception as e:
    fail("Range", str(e)[:150])

# 10) diagnostico
d = c.get(f"/stream_diag.php?why={first}").text
ok("stream_diag", f"{len(d)} chars")
for line in d.splitlines():
    if "loop:" in line or "http:" in line or "rc=" in line:
        print("   ", line[:120])
print("\nFIM")
