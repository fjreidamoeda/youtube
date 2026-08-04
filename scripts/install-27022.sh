#!/bin/bash
set -e

echo "=== YouTube IPTV - Instalacao segura na porta 27022 ==="

API_KEY=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --api-key) API_KEY="$2"; shift 2;;
        *) shift;;
    esac
done

PORT="27022"
PUBLIC_IP=$(curl -s ifconfig.me || curl -s -4 icanhazip.com || echo "localhost")

# 1. Sistema
apt-get update
apt-get install -y python3 python3-venv python3-pip ffmpeg curl git

# 2. Clona / atualiza
if [ -d /opt/yt-iptv ]; then
    cd /opt/yt-iptv && git pull
else
    git clone https://github.com/fjreidamoeda/youtube.git /opt/yt-iptv
fi
cd /opt/yt-iptv

# 3. Virtualenv + dependencias
python3 -m venv venv
./venv/bin/pip install --upgrade pip
./venv/bin/pip install -r requirements.txt

# 4. .env
if [ -n "$API_KEY" ]; then
    echo "YOUTUBE_API_KEY=$API_KEY" > .env
    echo "BASE_URL=http://$PUBLIC_IP:$PORT" >> .env
fi

# 5. Servico systemd (porta 27022, nao mexe no nginx/StreamFlow)
cat > /etc/systemd/system/yt-iptv.service << 'EOF'
[Unit]
Description=YouTube IPTV
After=network.target
[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/yt-iptv
Environment=PATH=/opt/yt-iptv/venv/bin:/usr/bin
ExecStart=/opt/yt-iptv/venv/bin/uvicorn main:app --host 0.0.0.0 --port 27022
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable yt-iptv
systemctl start yt-iptv
chown -R www-data:www-data /opt/yt-iptv

# 6. Abre a porta no firewall, se existir ufw
if command -v ufw >/dev/null 2>&1; then
    ufw allow $PORT/tcp >/dev/null 2>&1 || true
fi

echo ""
echo "============================================"
echo " PRONTO!"
echo ""
echo " Interface: http://$PUBLIC_IP:$PORT"
echo " Playlist:  http://$PUBLIC_IP:$PORT/playlist.m3u8"
echo ""
echo " Logs: journalctl -u yt-iptv -f"
echo "============================================"
