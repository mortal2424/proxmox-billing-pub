#!/bin/bash

# Проверяем, есть ли уже сертификаты от Let's Encrypt
if [ -f /etc/nginx/ssl/fullchain.pem ] && [ -f /etc/nginx/ssl/privkey.pem ]; then
    echo "Сертификаты уже существуют, пропускаем генерацию."
    exit 0
fi

# Если нет – создаём самоподписанный
echo "Создаём самоподписанный SSL-сертификат для домена ${DOMAIN:-localhost}"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/nginx/ssl/privkey.pem \
    -out /etc/nginx/ssl/fullchain.pem \
    -subj "/CN=${DOMAIN:-localhost}/O=SelfSigned/C=RU"

echo "Самоподписанный сертификат создан."