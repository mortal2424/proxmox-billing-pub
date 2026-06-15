#!/bin/bash
set -e

# Каталог для сертификатов (том, общий с nginx)
SSL_DIR="/etc/nginx/ssl"
mkdir -p "$SSL_DIR"

# Если сертификаты уже существуют – ничего не делаем
if [ -f "$SSL_DIR/fullchain.pem" ] && [ -f "$SSL_DIR/privkey.pem" ]; then
    echo "✅ SSL certificates already exist, skipping generation."
    exit 0
fi

# Если домен задан и не localhost – пробуем получить Let's Encrypt
if [ -n "$DOMAIN" ] && [ "$DOMAIN" != "localhost" ]; then
    echo "🔄 Trying to obtain Let's Encrypt certificate for $DOMAIN..."

    # Временно запускаем простой веб-сервер для верификации (через python)
    # Альтернатива – использовать certbot в standalone режиме, но нужен свободный порт 80.
    # Лучше запустить certbot с --standalone и временно остановить nginx.
    # Упростим: предполагаем, что nginx ещё не запущен, и порт 80 свободен.
    certbot certonly --standalone -d "$DOMAIN" \
        --non-interactive --agree-tos --email "${EMAIL:-admin@$DOMAIN}" \
        --keep-until-expiring

    if [ $? -eq 0 ]; then
        echo "✅ Let's Encrypt certificate obtained."
        cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$SSL_DIR/"
        cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$SSL_DIR/"
        exit 0
    else
        echo "⚠️ Failed to obtain Let's Encrypt certificate, falling back to self-signed."
    fi
else
    echo "DOMAIN not set or equals localhost, using self-signed certificate."
fi

# Создаём самоподписанный сертификат
echo "🔐 Generating self-signed SSL certificate for ${DOMAIN:-localhost}"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$SSL_DIR/privkey.pem" \
    -out "$SSL_DIR/fullchain.pem" \
    -subj "/CN=${DOMAIN:-localhost}/O=SelfSigned/C=RU"

echo "✅ Self-signed certificate created."