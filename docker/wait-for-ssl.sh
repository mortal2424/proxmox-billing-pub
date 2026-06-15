#!/bin/bash
set -e

echo "Waiting for SSL certificates in /etc/nginx/ssl..."
while [ ! -f /etc/nginx/ssl/fullchain.pem ] || [ ! -f /etc/nginx/ssl/privkey.pem ]; do
    sleep 2
done

echo "SSL certificates found. Starting Nginx..."
nginx -g 'daemon off;'