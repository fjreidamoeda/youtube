# YouTube IPTV (Python) — réplica do app em PHP

Versão em **Python/FastAPI** do painel de canais do YouTube. É independente do
app em PHP: roda sozinho, sem depender de nada externo além de `ffmpeg`,
`yt-dlp` e da YouTube Data API.

Mantém as **mesmas URLs** do PHP, então as playlists e links que já funcionam
continuam válidos:

| Recurso | URL |
|---|---|
| Painel (login) | `/` |
| Playlist IPTV (M3U) | `/lista.php?u=USUARIO&t=TOKEN` |
| Playlist IPTV modo canal | `/lista.php?u=USUARIO&t=TOKEN&modo=canal` |
| Playlist para VLC/players | `/lista.php?u=USUARIO&t=TOKEN&mode=vlc` |
| EPG (XMLTV) | `/epg.php?u=USUARIO&t=TOKEN` |
| Stream IPTV (MPEG-TS) | `/stream.php/VIDEOID.ts` |
| Stream VLC (MP4 com seek) | `/stream.php?id=VIDEOID` |
| Modo canal (sequência) | `/stream.php/c-CHANNELID.ts` |
| Gerenciador de downloads | `/dl/CHANNELID` |
| Diagnóstico | `/stream_diag.php?why=VIDEOID` |
| Status do servidor | `/vercheck` |
| Health check | `/healthz` |

---

## O que ele faz

- **Login por usuário** com senha (hash PBKDF2) e token próprio de playlist.
- **Canais do YouTube** (link, `@canal` ou ID `UC…`) e **vídeo solto**.
- **Cabeçalho VOD** na M3U: os vídeos saem com `vod="1"` no `#EXTINF`, enquanto
  a live `[AO VIVO]` e a entrada do modo canal não recebem o marcador (são fluxos
  contínuos).
- **Download em cache local** com `yt-dlp` — é o que faz tocar em painel IPTV
  (a URL direta do YouTube é bloqueada para IP de datacenter).
- **Remux para MPEG-TS** com `ffmpeg -c copy -bsf:v h264_mp4toannexb` e loop, que
  é o formato que o painel entende.
- **Servidor de faixa (HTTP Range)**, para o VLC dar *seek*.
- **Modo canal**: toca todos os vídeos do canal em sequência, ignorando vídeos
  verticais (que esticam no painel) e re-encodando para normalizar.
- **Download contínuo**: baixa sozinho os uploads novos, com limite de
  downloads simultâneos.
- **Diagnóstico** por vídeo e por canal.

---

## Requisitos

- Python 3.10+
- `ffmpeg` e `ffprobe` no PATH
- `yt-dlp` (pip install -U yt-dlp)
- Uma chave da **YouTube Data API v3** (só para *listar* canais e vídeos; o
  stream em si não usa a API)

## Instalação

```bash
cd py
pip install -r requirements.txt
```

Copie e edite a configuração:

```bash
cp .env.example .env     # Windows: copy .env.example .env
```

```ini
YT_API_KEY=AIza...          # obrigatorio
BASE_URL=http://SEU-IP:8000  # a URL que o painel IPTV vai acessar
```

Se `BASE_URL` ficar vazio, o app deduz a URL do próprio request (funciona, mas
em alguns painéis com proxy é melhor fixar).

## Rodar

```bash
python run.py               # http://localhost:8000
PORT=8080 python run.py     # outra porta
```

No Windows dá para dar duplo clique em `start.bat`.

Na primeira execução é criado o usuário **admin** e a senha é impressa no
console — copie antes de fechar a janela.

Com Docker:

```bash
docker build -t yt-iptv .
docker run -d --name yt-iptv -p 8000:8000 \
  -e YT_API_KEY=AIza... -e BASE_URL=http://SEU-IP:8000 \
  -v ./cache:/srv/yt/cache yt-iptv
```

Como serviço no Linux (systemd): veja `deploy/yt-iptv.service` neste repositório.

---

## Colocando no painel IPTV

1. Logue no painel e abra a aba **Canais**.
2. Copie o link da playlist **IPTV (padrão)**.
3. No painel (Xtream Codes, Perfect Player, KODI…), adicione o link como
   *Live/Playlist* ou via URL. Se o painel pedir *username/password* para
   M3U, use o token: usuário = `u`, senha = `t`.

O **modo canal** é outra playlist: em vez de listar cada vídeo, ela traz **uma
entrada por canal** e o servidor fica passando os vídeos sem parar. Use
`?modo=canal` se preferir um canal contínuo.

O **VLC** usa `?mode=vlc`, que devolve `stream.php?id=` (MP4 com Range, então
dá para arrastar a linha do tempo).

---

## Ajustes úteis (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `MAX_CONCURRENT_DOWNLOADS` | 3 | downloads simultâneos do yt-dlp |
| `IPTV_WAIT_SECONDS` | 12 | quanto esperar o download quando o painel pede um vídeo que não está em cache |
| `VLC_WAIT_SECONDS` | 20 | o mesmo, para VLC |
| `CHANNEL_WAIT_SECONDS` | 25 | espera do primeiro vídeo no modo canal |
| `WATCH_INTERVAL` | 240 | segundos entre as passadas do download contínuo |
| `LOOP_MAX_AGE` | 21600 | depois de 6 h o arquivo é baixado de novo |
| `YTDLP_COOKIES` | — | caminho de um `cookies.txt` (ajuda em vídeo que dá erro de bot) |

`IPTV_WAIT_SECONDS` é o mais sensível: valor alto deixa o painel lento, valor
baixo demais dá "sem sinal" em vídeo ainda não baixado. Com o download
contínuo ligado, quase tudo já está em cache e a espera é irrelevante.

---

## Diagnóstico

```bash
curl "http://localhost:8000/vercheck"
curl "http://localhost:8000/stream_diag.php?why=VIDEOID"   # por que este video falhou
curl "http://localhost:8000/stream_diag.php?c=CHANNELID"   # situacao de todos os videos
```

O `?why=` mostra: se existe arquivo em cache, se o yt-dlp está rodando, as
últimas linhas do log do download e do ffmpeg, um teste real de extração e a
disponibilidade via oEmbed.

---

## Problemas comuns

**Vídeo não baixa (`ERROR: ...` no log).** O YouTube bloqueia IPs de datacenter.
Duas saídas: (1) usar `YTDLP_COOKIES` com um `cookies.txt` do navegador; (2)
rodar o app numa máquina com IP residencial. O app já tenta uma segunda
extração com cliente `android,tv` quando a primeira falha.

**Painel mostra "sem sinal".** Quase sempre é vídeo ainda não baixado no momento
do pedido. Ligue o download contínuo no canal (aba de downloads) ou aumente
`IPTV_WAIT_SECONDS`.

**Imagem esticada / formato estranho no modo canal.** Vídeos verticais são
descartados automaticamente e os demais são re-encodados para 16:9. Se persistir,
rode `?c=CHANNELID` para ver as dimensões de cada arquivo.

**Uso de CPU alto.** O modo canal re-encoda com libx264. Em VPS fraco, reduza a
quantidade de vídeos por canal (`Qtd. videos`) — o concat só entra no grupo
maior de mesma resolução.

**Disco encheu.** O cache guarda os vídeos baixados. Apague `cache/loop_*` a
qualquer momento quando não houver download em andamento.

---

## Segurança

- `.env`, `app.db` e `cache/` estão no `.gitignore` — não versione a chave da API.
- Troque a senha do admin após o primeiro acesso.
- A API do YouTube só é usada para *listar*; os tokens de playlist são
  por usuário e podem ser trocados/desativados em `/admin`.
