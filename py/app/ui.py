"""HTML do painel (login, canais, grade, gerenciador de downloads, admin)."""
from __future__ import annotations

import html
from typing import Any

CSS = """
:root{--bg:#f8fafc;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;
--primary:#3b82f6;--primary2:#2563eb;--danger:#ef4444;--radius:12px}
*{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);padding:20px;line-height:1.5}
.container{max-width:1060px;margin:0 auto}
header{text-align:center;margin-bottom:26px}
header h1{font-size:26px;font-weight:700;margin-bottom:6px}
header p{color:var(--muted);font-size:14px}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;font-size:14px;color:var(--muted)}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:20px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05)}
.card h2{font-size:17px;font-weight:600;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:10px}
.msg{background:#dbeafe;color:#1e40af;padding:11px 15px;border-radius:8px;margin-bottom:18px;font-weight:500;font-size:14px}
.msg.err{background:#fee2e2;color:#991b1b}
label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin:12px 0 6px}
input[type=text],input[type=password],input[type=number]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px}
input[type=number]{width:90px}
.btn{display:inline-block;background:var(--primary);color:#fff;border:0;padding:9px 16px;border-radius:8px;
font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn:hover{background:var(--primary2)}
.btn-outline{background:#fff;color:var(--primary);border:1px solid var(--primary)}
.btn-outline:hover{background:#eff6ff}
.btn-danger{background:var(--danger)}
.btn-danger:hover{background:#dc2626}
.btn-sm{padding:6px 11px;font-size:12px}
.btn-gray{background:#64748b}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);padding:8px;border-bottom:2px solid var(--border)}
td{padding:10px 8px;border-bottom:1px solid var(--border);font-size:14px;vertical-align:middle}
.tag{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;background:#e0e7ff;color:#3730a3}
.tag-vid{background:#fef3c7;color:#92400e}
.tag-ok{background:#dcfce7;color:#166534}
.tag-off{background:#f1f5f9;color:#64748b}
.mono{font-family:ui-monospace,Consolas,monospace;font-size:11px;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px}
.vid{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.vid img{width:100%;display:block;aspect-ratio:16/9;object-fit:cover;background:#e2e8f0}
.vid .body{padding:9px}
.vid .t{font-size:12px;line-height:1.35;height:3.1em;overflow:hidden;margin-bottom:6px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.muted{color:var(--muted);font-size:12px}
.pill{background:#f1f5f9;border:1px solid var(--border);border-radius:999px;padding:2px 9px;font-size:11px;color:var(--muted)}
"""

JS_ALL = """
function selTodas(src){document.querySelectorAll('.dl-check').forEach(c=>c.checked=src);}
"""


def esc(s: Any) -> str:
    return html.escape(str(s or ""), quote=True)


def layout(title: str, body: str, user: dict | None = None, msg: str = "", err: str = "") -> str:
    top = ""
    if user:
        top = f"""
<div class="topbar">
  <div>Logado como <strong>{esc(user['username'])}</strong></div>
  <div class="row">
    <a class="btn btn-outline btn-sm" href="/">Canais</a>
    <a class="btn btn-outline btn-sm" href="/admin">Usuarios</a>
    <form method="post" action="/logout" style="display:inline">
      <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
      <button class="btn btn-gray btn-sm">Sair</button>
    </form>
  </div>
</div>"""
    msg_html = ""
    if msg:
        msg_html += f'<div class="msg">{esc(msg)}</div>'
    if err:
        msg_html += f'<div class="msg err">{esc(err)}</div>'
    return f"""<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{esc(title)} - YouTube IPTV</title>
<style>{CSS}</style></head>
<body><div class="container">
<header><h1>YouTube IPTV</h1><p>Canais do YouTube como TV no seu painel</p></header>
{top}{msg_html}{body}
</div><script>{JS_ALL}</script></body></html>"""


def login_page(csrf: str, msg: str = "", err: str = "") -> str:
    body = f"""
<div class="card" style="max-width:420px;margin:0 auto">
  <h2>Entrar</h2>
  <form method="post" action="/login">
    <input type="hidden" name="csrf" value="{esc(csrf)}">
    <label>Usuario</label>
    <input type="text" name="username" autofocus>
    <label>Senha</label>
    <input type="password" name="password">
    <div style="margin-top:16px"><button class="btn">Entrar</button></div>
  </form>
</div>"""
    return layout("Entrar", body, None, msg, err)


def panel_page(user: dict, channels: list[dict], base: str, m3u_url: str, vlc_url: str,
               canal_url: str, daemon: bool, cache_mb: float) -> str:
    rows = []
    for c in channels:
        is_vid = bool(c.get("video_id"))
        logo = c.get("logo") or ""
        thumb = f'<img src="{esc(logo)}" style="width:44px;height:44px;border-radius:8px;object-fit:cover">' if logo else ""
        if is_vid:
            tipo = '<span class="tag tag-vid">Video Fixo</span>'
            acoes = (f'<a class="btn btn-outline btn-sm" href="/stream.php?id={esc(c["video_id"])}" target="_blank">Abrir</a>'
                     f'<a class="btn btn-outline btn-sm" href="/dl/v/{esc(c["video_id"])}">Baixar</a>')
            qtd = '<span class="muted">-</span>'
            ident = c["video_id"]
        else:
            tipo = '<span class="tag">Canal Dinamico</span>'
            acoes = (f'<a class="btn btn-outline btn-sm" href="/grade/{esc(c["channel_id"])}">Ver Grade</a>'
                     f'<a class="btn btn-sm" href="/dl/{esc(c["channel_id"])}">Baixar</a>')
            qtd = f"""<form method="post" action="/canais/qty" class="row" style="gap:6px">
              <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
              <input type="hidden" name="id" value="{int(c['id'])}">
              <input type="number" name="qty" min="1" max="500" value="{int(c.get('max_videos', 50))}">
              <button class="btn btn-sm">OK</button></form>"""
            ident = c["channel_id"]
        dl_on = bool(c.get("download"))
        acoes += (f'<form method="post" action="/canais/delete" style="display:inline" '
                  f'onsubmit="return confirm(\'Remover este canal?\')">'
                  f'<input type="hidden" name="csrf" value="{esc(user["_csrf"])}">'
                  f'<input type="hidden" name="id" value="{int(c["id"])}">'
                  f'<button class="btn btn-danger btn-sm">X</button></form>')
        rows.append(f"""<tr>
<td class="row">{thumb}<div><strong>{esc(c.get('name'))}</strong><br><span class="mono">{esc(ident)}</span></div></td>
<td>{tipo} {'<span class="pill">download continuo</span>' if dl_on else ''}</td>
<td>{qtd}</td>
<td class="row">{acoes}</td></tr>""")

    tabela = f"""
<div class="card">
  <h2>Meus canais</h2>
  <div class="table-wrap"><table>
  <tr><th>Nome / ID</th><th>Tipo</th><th>Qtd. videos</th><th>Acoes</th></tr>
  {''.join(rows) or '<tr><td colspan="4" class="muted">Nenhum canal ainda. Adicione abaixo.</td></tr>'}
  </table></div>
</div>"""

    add = """
<div class="card">
  <h2>Adicionar canal ou video</h2>
  <form method="post" action="/canais/add">
    <input type="hidden" name="csrf" value="CSRF">
    <label>Link, @canal ou ID</label>
    <input type="text" name="link" placeholder="@canal, https://youtube.com/@canal, UC..., ou link do video">
    <label>Nome (opcional)</label>
    <input type="text" name="name" placeholder="deixa em branco para usar o nome do canal">
    <div style="margin-top:14px"><button class="btn">Adicionar</button></div>
  </form>
</div>""".replace("CSRF", esc(user["_csrf"]))

    links = f"""
<div class="card">
  <h2>Minhas playlists</h2>
  <table>
    <tr><th>Modo</th><th>Link (use no painel IPTV)</th></tr>
    <tr><td>IPTV (padrao)</td><td><input type="text" readonly value="{esc(m3u_url)}"></td></tr>
    <tr><td>IPTV modo canal (toca todos em sequencia)</td><td><input type="text" readonly value="{esc(canal_url)}"></td></tr>
    <tr><td>VLC / players normais</td><td><input type="text" readonly value="{esc(vlc_url)}"></td></tr>
  </table>
  <p class="muted" style="margin-top:10px">
    Monitor de download continuo: <strong>{'rodando' if daemon else 'parado'}</strong> &nbsp;|&nbsp;
    cache em disco: {cache_mb:.0f} MB
  </p>
</div>"""

    return layout("Painel", tabela + add + links, user)


def grid_page(user: dict, channel: dict, videos: list[dict], next_token: str, base: str) -> str:
    cards = []
    for v in videos:
        cards.append(f"""<div class="vid">
  <a href="/stream.php?id={esc(v['id'])}" target="_blank"><img src="{esc(v.get('thumb'))}" alt=""></a>
  <div class="body"><div class="t">{esc(v.get('title'))}</div>
  <div class="row" style="gap:6px">
    <a class="btn btn-sm" href="/stream.php?id={esc(v['id'])}" target="_blank">Abrir</a>
    <a class="btn btn-outline btn-sm" href="/dl/v/{esc(v['id'])}">Baixar</a>
  </div></div></div>""")
    prox = ""
    if next_token:
        prox = (f'<div style="margin-top:16px"><a class="btn" href="/grade/{esc(channel["channel_id"])}'
                f'?pt={esc(next_token)}"> proxima pagina</a></div>')
    body = f"""
<div class="card">
  <div class="row" style="justify-content:space-between">
    <h2 style="border:0;margin:0;padding:0">{esc(channel.get('name'))}</h2>
    <div class="row">
      <a class="btn btn-sm" href="/dl/{esc(channel['channel_id'])}">Baixar videos</a>
      <a class="btn btn-outline btn-sm" href="/">Voltar</a>
    </div>
  </div>
  <div class="grid" style="margin-top:16px">{''.join(cards) or '<p class="muted">Sem videos.</p>'}</div>
  {prox}
</div>"""
    return layout("Grade", body, user)


def dl_page(user: dict, channel: dict | None, videos: list[dict], states: dict[str, dict],
            daemon: bool, is_video: bool = False) -> str:
    titulo = (channel or {}).get("name") or "Video"
    rows = []
    for v in videos:
        st = states.get(v["id"], {})
        if st.get("file"):
            tag = f'<span class="tag tag-ok">{st["size"] / 1048576:.1f} MB</span>'
        elif st.get("downloading"):
            tag = '<span class="tag">baixando...</span>'
        elif st.get("failed"):
            tag = '<span class="tag tag-vid">falhou (tentar de novo)</span>'
        else:
            tag = '<span class="tag tag-off">nao baixado</span>'
        chk = "" if is_video else f'<input type="checkbox" name="ids" value="{esc(v["id"])}" class="dl-check">'
        vid = v["id"]
        rows.append(f"""<tr>
<td>{chk}</td>
<td><strong style="font-size:13px">{esc(v.get('title'))}</strong><br><span class="mono">{esc(vid)}</span></td>
<td>{tag}</td>
<td class="row">
  <a class="btn btn-outline btn-sm" href="/stream.php?id={esc(vid)}" target="_blank">Testar</a>
  <form method="post" action="/dl/start" style="display:inline">
    <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
    <input type="hidden" name="ids" value="{esc(vid)}">
    <button class="btn btn-sm">Baixar</button>
  </form>
</td></tr>""")

    canal_id = (channel or {}).get("channel_id") or ""
    cid = int((channel or {}).get("id") or 0)
    down_on = bool((channel or {}).get("download"))

    seletor = "" if is_video else f"""
<div class="card">
  <h2>1. Escolha os videos</h2>
  <form method="post" action="/dl/start">
    <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
    <input type="hidden" name="canal" value="{esc(canal_id)}">
    <div class="row" style="margin-bottom:12px">
      <label class="row" style="margin:0;gap:6px;cursor:pointer;font-weight:500;color:var(--text)">
        <input type="checkbox" onclick="selTodas(this.checked)" style="width:auto"> Selecionar todos</label>
      <button class="btn">Baixar selecionados ({len(videos)})</button>
    </div>
    <div class="table-wrap"><table>
      <tr><th style="width:28px"></th><th>Video</th><th>Status</th><th>Acoes</th></tr>
      {''.join(rows)}
    </table></div>
  </form>
  <form method="post" action="/dl/all" style="margin-top:14px">
    <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
    <input type="hidden" name="canal" value="{esc(canal_id)}">
    <button class="btn btn-outline">Baixar TODOS do canal</button>
  </form>
</div>"""

    continuo = "" if is_video else f"""
<div class="card">
  <h2>2. Download continuo</h2>
  <p class="muted">Mantem os videos deste canal baixados e baixa os uploads novos sozinho
     (o monitor roda em segundo plano, a pagina pode ficar fechada).</p>
  <form method="post" action="/canais/download" class="row" style="margin-top:10px">
    <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
    <input type="hidden" name="id" value="{cid}">
    <input type="hidden" name="on" value="{'0' if down_on else '1'}">
    <button class="btn {'btn-danger' if down_on else ''}">
      {'Desativar download continuo' if down_on else 'Ativar download continuo'}</button>
    <span class="pill">monitor: {'rodando' if daemon else 'parado'}</span>
  </form>
</div>"""

    body = f"""
<div class="card">
  <div class="row" style="justify-content:space-between">
    <h2 style="border:0;margin:0;padding:0">Downloads - {esc(titulo)}</h2>
    <a class="btn btn-outline btn-sm" href="/">Voltar</a>
  </div>
</div>
{seletor}{continuo}"""

    if is_video:
        body += f"""
<div class="card">
  <h2>1. Video unico</h2>
  <table>
    <tr><th style="width:28px"></th><th>Video</th><th>Status</th><th>Acoes</th></tr>
    {''.join(rows)}
  </table>
</div>"""
    return layout("Downloads", body, user)


def admin_page(user: dict, users: list[dict], base: str, m3u_url: str) -> str:
    rows = []
    for u in users:
        acoes = "".join(
            f"""<form method="post" action="/admin/status" style="display:inline">
<input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
<input type="hidden" name="id" value="{int(u['id'])}">
<input type="hidden" name="status" value="{'active' if u['status'] != 'active' else 'pending'}">
<button class="btn btn-sm">{'Ativar' if u['status'] != 'active' else 'Desativar'}</button></form>"""
            for _ in [0]
        )
        rows.append(f"""<tr><td><strong>{esc(u['username'])}</strong></td>
<td>{esc(u['role'])}</td><td>{esc(u['status'])}</td>
<td class="mono">{esc(u['token'])}</td>
<td><input type="text" readonly value="{esc(base)}/lista.php?u={esc(u['username'])}&amp;t={esc(u['token'])}"></td>
<td>{acoes}</td></tr>""")
    novo = f"""
<div class="card">
  <h2>Novo usuario</h2>
  <form method="post" action="/admin/add" class="row" style="align-items:flex-end">
    <input type="hidden" name="csrf" value="{esc(user['_csrf'])}">
    <div><label>Usuario</label><input type="text" name="username"></div>
    <div><label>Senha</label><input type="text" name="password"></div>
    <div><label>Perfil</label>
      <select name="role"><option value="user">user</option><option value="admin">admin</option></select></div>
    <div><button class="btn">Criar</button></div>
  </form>
</div>"""
    body = f"""
<div class="card"><h2>Usuarios</h2><div class="table-wrap"><table>
<tr><th>Usuario</th><th>Perfil</th><th>Situacao</th><th>Token</th><th>Playlist</th><th></th></tr>
{''.join(rows)}</table></div></div>{novo}"""
    return layout("Usuarios", body, user)
