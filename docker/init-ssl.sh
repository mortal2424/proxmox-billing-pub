#!/bin/bash
# Пытаемся получить реальный сертификат от Let's Encrypt
if [ -n "$DOMAIN" ] && [ "$DOMAIN" != "localhost" ]; then
    echo "Пытаемся получить Let's Encrypt сертификат для $DOMAIN"
    certbot certonly --webroot -w /var/www/html -d $DOMAIN \
        --non-interactive --agree-tos --email ${EMAIL:-admin@$DOMAIN} \
        --keep-until-expiring --webroot-path=/var/www/html
    if [ $? -eq 0 ]; then
        echo "Сертификат успешно получен. Копируем в /etc/nginx/ssl"
        cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem /etc/nginx/ssl/
        cp /etc/letsencrypt/live/$DOMAIN/privkey.pem /etc/nginx/ssl/
    else
        echo "Не удалось получить сертификат, будет использован самоподписанный."
    fi
else
    echo "DOMAIN не задан или равен localhost, используем самоподписанный сертификат."
fi