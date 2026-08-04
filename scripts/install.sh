#!/bin/bash
set -e

REPO_URL="https://github.com/fjreidamoeda/youtube.git"
APP_DIR="/opt/yt-iptv"
APP_USER="www-data"

echo "=== YouTube IPTV - Instalação Automática no VPS ==="

# Parse --api-key argument
API_KEY=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --api-key) API_KEY="$2"; shift 2;;
        *) shift;;
    esac
done

# 1. Sistema
apt-get update && apt-get upgrade -y
apt-get install -y python3 python3-pip python3-venv nginx git curl ffmpeg

# 2. Clona repositório
if [ -d "$APP_DIR" ]; then
    cd "$APP_DIR" && git pull
else
    git clone "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"

# 3. Virtualenv + dependências
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
deactivate

# 4. Cria .env com a chave fornecida ou do template
if [ -n "$API_KEY" ]; then
    echo "YOUTUBE_API_KEY=$API_KEY" > .env
    echo "BASE_URL=http://$(curl -s ifconfig.me)" >> .env
elif [ ! -f .env ]; then
    cp .env.example .env
fi

# 5. Systemd service
cat > /etc/systemd/system/yt-iptv.service << 'EOF'
[Unit]
Description=YouTube IPTV FastAPI
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/yt-iptv
Environment=PATH=/opt/yt-iptv/venv/bin:/usr/bin
ExecStart=/opt/yt-iptv/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable yt-iptv
systemctl start yt-iptv

# 6. Nginx reverse proxy
cat > /etc/nginx/sites-available/yt-iptv << 'EOF'
server {
    listen 80;
    server_name _;

    client_max_body_size 10M;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_connect_timeout 10s;
    }
}
EOF

if [ -f /etc/nginx/sites-enabled/default ]; then
    rm /etc/nginx/sites-enabled/default
fi
ln -sf /etc/nginx/sites-available/yt-iptv /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

echo ""
echo "============================================"
echo " Instalação concluída!"
echo ""
echo " Acesse: http://$(curl -s ifconfig.me)"
echo ""
echo " Para configurar HTTPS:"
echo "   apt-get install -y certbot python3-certbot-nginx"
echo "   certbot --nginx -d seu-dominio.com"
echo "============================================"
echo ""
echo "comando para ver logs: journalctl -u yt-iptv -f"
