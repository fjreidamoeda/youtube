#!/bin/bash
# Atualização automática do yt-dlp (executar via cron semanal)
# 0 3 * * 0 /var/www/yt-iptv/scripts/update-yt-dlp.sh

set -e

echo "[$(date)] Atualizando yt-dlp..."
pip3 install --break-system-packages --upgrade yt-dlp
yt-dlp --version
echo "[$(date)] Concluído."