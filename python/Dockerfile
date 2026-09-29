FROM python:3.12-slim

# ffmpeg para remux/concat, yt-dlp para baixar do YouTube
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg ca-certificates && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir -U yt-dlp

WORKDIR /srv/yt
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .

ENV HOST=0.0.0.0 PORT=8000 CACHE_DIR=/srv/yt/cache DB_PATH=/srv/yt/app.db
VOLUME ["/srv/yt/cache"]
EXPOSE 8000

HEALTHCHECK --interval=60s --timeout=10s --start-period=20s \
    CMD python -c "import httpx,os;httpx.get('http://127.0.0.1:'+os.getenv('PORT','8000')+'/healthz',timeout=8)"

CMD ["python", "run.py"]
