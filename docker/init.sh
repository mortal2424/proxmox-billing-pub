#!/bin/bash

set -e

echo "⏳ Waiting for database connection..."

MAX_TRIES=30
COUNT=0
until mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --ssl=0 -e "SELECT 1" &>/dev/null; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
        echo "❌ Database not available after $MAX_TRIES attempts. Exiting."
        exit 1
    fi
    echo "⏳ Waiting for database... (${COUNT}/${MAX_TRIES})"
    sleep 2
done

echo "✅ Database is ready."

TABLE_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --ssl=0 -D"$DB_NAME" -e "SHOW TABLES" | wc -l)
if [ "$TABLE_COUNT" -lt 2 ]; then
    echo "📦 Database is empty. Importing /var/www/html/install/sql-install.sql"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --ssl=0 -D"$DB_NAME" < /var/www/html/install/sql-install.sql
    echo "✅ Import finished."
else
    echo "ℹ️ Database already has tables, skipping import."
fi

if [ -f /var/www/html/composer.json ]; then
    echo "📦 Installing Composer dependencies..."
    cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader
fi

CONFIG_FILE=/var/www/html/includes/config.php
if [ ! -f "$CONFIG_FILE" ]; then
    echo "⚙️ Generating includes/config.php from config.php.simple..."
    cp /var/www/html/includes/config.php.simple "$CONFIG_FILE"
    ENCRYPTION_KEY=$(php -r "echo bin2hex(random_bytes(16));")
    sed -i \
        -e "s|define('DB_HOST', '')|define('DB_HOST', '${DB_HOST}')|" \
        -e "s|define('DB_NAME', '')|define('DB_NAME', '${DB_NAME}')|" \
        -e "s|define('DB_USER', '')|define('DB_USER', '${DB_USER}')|" \
        -e "s|define('DB_PASS', '')|define('DB_PASS', '${DB_PASSWORD}')|" \
        -e "s|define('ENCRYPTION_KEY', '')|define('ENCRYPTION_KEY', '${ENCRYPTION_KEY}')|" \
        "$CONFIG_FILE"
    chown www-data:www-data "$CONFIG_FILE"
    echo "✅ includes/config.php created."
else
    echo "ℹ️ includes/config.php already exists, skipping generation."
fi

echo "✅ Init script finished."