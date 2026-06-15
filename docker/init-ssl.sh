#!/bin/bash

SSL_DIR="/etc/nginx/ssl"
WEBROOT_DIR="/var/www/html"
mkdir -p "$SSL_DIR"

if [ -f "$SSL_DIR/fullchain.pem" ] && [ -f "$SSL_DIR/privkey.pem" ]; then
    echo "✅ SSL certificates already exist, skipping generation."
    exit 0
fi

if [ -n "$DOMAIN" ] && [ "$DOMAIN" != "localhost" ]; then
    echo "🔄 Trying to obtain Let's Encrypt certificate for $DOMAIN via webroot..."
    certbot certonly --webroot -w "$WEBROOT_DIR" -d "$DOMAIN" \
        --non-interactive --agree-tos --email "${EMAIL:-admin@$DOMAIN}" \
        --keep-until-expiring
    if [ $? -eq 0 ] && [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
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

echo "🔐 Generating self-signed SSL certificate for ${DOMAIN:-localhost}"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$SSL_DIR/privkey.pem" \
    -out "$SSL_DIR/fullchain.pem" \
    -subj "/CN=${DOMAIN:-localhost}/O=SelfSigned/C=RU"

echo "✅ Self-signed certificate created."