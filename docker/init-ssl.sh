#!/bin/bash

SSL_DIR="/etc/nginx/ssl"
mkdir -p "$SSL_DIR"

if [ -f "$SSL_DIR/fullchain.pem" ] && [ -f "$SSL_DIR/privkey.pem" ]; then
    echo "✅ SSL certificates already exist, skipping generation."
    exit 0
fi

echo "🔐 Generating self-signed SSL certificate for ${DOMAIN:-localhost}"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$SSL_DIR/privkey.pem" \
    -out "$SSL_DIR/fullchain.pem" \
    -subj "/CN=${DOMAIN:-localhost}/O=SelfSigned/C=RU"

echo "✅ Self-signed certificate created."