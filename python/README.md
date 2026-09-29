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
| Playlist MPEG-TS (formato antigo) | `/lista.php?u=USUARIO&t=TOKEN&formato=ts` |
| Playlist para VLC/players | `/lista.php?u=USUARIO&t=TOKEN&mode=vlc` |
| EPG (XMLTV) | `/epg.php?u=USUARIO&t=TOKEN` |
| Stream IPTV (HLS) | `/stream.php/VIDEOID.m3u8` |
| Stream IPTV (MPEG-TS) | `/stream.php/VIDEOID.ts` |
| Stream VLC (MP4 com seek) | `/stream.php?id=VIDEOID` |
| Modo canal (sequência, HLS) | `/stream.php/c-CHANNELID.m3u8` |
| Modo canal (MPEG-TS) | `/stream.php/c-CHANNELID.ts` |
| Gerenciador de downloads | `/dl/CHANNELID` |
| Diagnóstico | `/stream_diag.php?why=VIDEOID` |
| Status do servidor | `/vercheck` |
| Health check | `/healthz` |

---

## O que ele faz

- **Login por usuário** com senha (hash PBKDF2) e token próprio de playlist.
- **Canais do YouTube** (link, `@canal` ou ID `UC…`) e **vídeo solto**.
- **HLS (`.m3u8`) como formato padrão**: o ffmpeg fatia o vídeo em segmentos de
  poucos segundos e serve uma playlist. O player puxa pedaço a pedaço, o que
  **dá para pular trecho**, para e volta sem recarregar tudo e nunca fica
  "sem sinal" num vídeo longo.
- **Cabeçalho VOD** na M3U: os vídeos saem com `vod="1"` no `#EXTINF`, enquanto
  a live `[AO VIVO]` e a entrada do modo canal não recebem o marcador (são fluxos
  contínuos).
- **Download em cache local** com `yt-dlp` — é o que faz tocar em painel IPTV
  (a URL direta do YouTube é bloqueada para IP de datacenter).
- **MPEG-TS continua disponível** (`.ts` ou `&formato=ts`) para painel que não
  aceita HLS.
- **Servidor de faixa (HTTP Range)**, para o VLC dar *seek* no MP4.
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
em alguns painéis com proxy é melhor fixar). O `iniciar.bat` recusa a subir se a
`YT_API_KEY` estiver vazia, para não ficar um painel mudo sem explicar o motivo.

## Rodar

### Windows — duplo clique em `iniciar.bat`

O `.bat` faz tudo sozinho: acha o Python, avisa se faltar `ffmpeg`/`yt-dlp`,
cria o `.env` se não existir, instala as dependências na primeira vez, vê se a
porta está livre, mostra o endereço da rede e abre o navegador no painel.

| Comando | O que faz |
|---|---|
| `iniciar.bat` | sobe na porta 8000 e abre o navegador |
| `iniciar.bat 8080` | sobe em outra porta |
| `iniciar.bat senha MINHASENHA` | troca a senha do admin e sai |

O login é **admin**. Na primeira execução a senha é gerada e aparece nas duas
primeiras linhas do log. Se esquecer, use `iniciar.bat senha ...`.

### Qualquer sistema

```bash
python run.py               # http://localhost:8000
python run.py 8080          # outra porta
```

### Linha de comando direto

```bash
python run.py --senha MINHASENHA      # redefine a senha do admin
python run.py --criar usuario:senha   # cria um usuario
```

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

## HLS: como o vídeo chega ao painel

A playlist aponta para `.m3u8` e o servidor entrega **segmentos** de 4 s
(`seg_00000.ts`, `seg_00001.ts`, …) gerados pelo ffmpeg em
`cache/hls/<id>/index.m3u8`. Existem dois tipos de fluxo:

| Tipo | Quem usa | Playlist | Comportamento |
|---|---|---|---|
| **VOD** | item de vídeo e `vod="1"` | cresce e termina com `#EXT-X-ENDLIST` | **dá para pular para qualquer ponto**; o player anda na linha do tempo |
| **LIVE** | `[AO VIVO]` e modo canal | janela deslizante de 30 segmentos (2 min) | fluxo nunca acaba, disco não cresce |

O player pede a `.m3u8`, baixa os segmentos, e continua recarregando a playlist
para achar os próximos. É por isso que **avançar trecho funciona** e não
funcionava no MPEG-TS (que é um fluxo único, sem índice).

Detalhes que importam na prática:

- **O primeiro play de um vídeo espera o ffmpeg fatiar.** O download é
  imediato, mas a fatiagem é um encode (em máquina livre, ~5× mais rápido que o
  tempo real). A primeira playlist volta em **2 a 8 s** e o vídeo já toca; o
  `ENDLIST` (e portanto o seek completo) só aparece quando a fatiagem acaba. Os
  segmentos já prontos ficam em disco, então o **segundo play do mesmo vídeo
  responde em ~0,2 s** sem refazer nada.
- **Atenção ao uso de CPU**: encode HLS e download contínuo disputam o mesmo
  processador. Com 3 downloads do yt-dlp rodando, a fatiagem pode cair de 5× para
  ~1× o tempo real e a primeira resposta demorar mais (aí o app devolve 503 com
  "tente de novo" e o painel repete). Se o servidor for fraco, reduza
  `MAX_CONCURRENT_DOWNLOADS`.
- **CPU**: cada transmissão HLS aberta ocupa um núcleo. Por isso existe o teto
  `HLS_MAX_SESSIONS` (4 por padrão) e o `HLS_IDLE_SECONDS` (150 s sem nenhum
  leitor = ffmpeg encerrado e pasta apagada). Transmissão de live parada não
  queima CPU.
- **Disco**: os segmentos de live são apagados conforme a janela anda; os de VOD
  ficam para o próximo play. `HLS_MAX_CACHE_MB` (6 GB) apaga o mais antigo se
  passar do teto.
- **Voltar ao MPEG-TS**: `PLAYLIST_FORMAT=ts` no `.env`, ou `&formato=ts` na
  URL da playlist. As URLs antigas `.ts` continuam funcionando.

---

## Ajustes úteis (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `PLAYLIST_FORMAT` | `hls` | `hls` = `.m3u8`; `ts` = MPEG-TS contínuo |
| `HLS_TIME` | 4 | duração de cada segmento, em segundos |
| `HLS_LIST_SIZE` | 30 | segmentos na janela do modo canal (30 × 4 s = 2 min) |
| `HLS_PRESET` | `veryfast` | `ultrafast` poupa CPU e perde qualidade |
| `HLS_FIRST_WAIT` | 25 | espera o 1º segmento antes de responder |
| `HLS_IDLE_SECONDS` | 150 | encerra o ffmpeg após esse tempo sem nenhum leitor |
| `HLS_MAX_SESSIONS` | 4 | transmissões HLS simultâneas |
| `HLS_MAX_CACHE_MB` | 6000 | teto dos segmentos guardados em disco |
| `HLS_VOD_MAX_AGE` | 86400 | refaz o VOD segmentado depois de 24 h |
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

**Painel recusa o `.m3u8` / só aceita MPEG-TS.** Coloque `PLAYLIST_FORMAT=ts` no
`.env` (ou use `&formato=ts` na URL da playlist) e recarregue a lista no painel.
As URLs `.ts` antigas continuam valendo.

**"Erro interno preparando o HLS" ou 503 na primeira vez.** É o ffmpeg ainda
fatiando o primeiro playback. A primeira resposta leva alguns segundos; se
persistir, veja `cache/ffmpeg_VIDEOID.log` e rode `/stream_diag.php?why=VIDEOID`.
Aumentar `HLS_FIRST_WAIT` (padrão 20 s) resolve em máquinas lentas.

**Avançar trecho não funciona no item novo.** É o único caso em que o vídeo ainda
está sendo fatiado: só existe o que já foi codificado. Depois que o `ENDLIST`
aparece, o seek vai para qualquer ponto. Forçar o download antes
(`/dl/v/VIDEOID`) ou usar o download contínuo evita a espera.

**Uso de CPU alto.** Cada transmissão HLS aberta usa um núcleo, e o modo canal
re-encoda com libx264. Baixe `HLS_MAX_SESSIONS`, `HLS_PRESET=ultrafast` e a
quantidade de vídeos por canal (`Qtd. videos`) — o concat só entra no grupo maior
de mesma resolução.

**Imagem esticada / formato estranho no modo canal.** Vídeos verticais são
descartados automaticamente e os demais são re-encodados para 16:9. Se persistir,
rode `?c=CHANNELID` para ver as dimensões de cada arquivo.

**Disco encheu.** O cache guarda os vídeos baixados e os segmentos HLS. Apague
`cache/loop_*` e `cache/hls/*` a qualquer momento quando não houver transmissão
aberta; `HLS_MAX_CACHE_MB` limita os segmentos por conta própria.

---

## Segurança

- `.env`, `app.db` e `cache/` estão no `.gitignore` — não versione a chave da API.
- Troque a senha do admin após o primeiro acesso.
- A API do YouTube só é usada para *listar*; os tokens de playlist são
  por usuário e podem ser trocados/desativados em `/admin`.
