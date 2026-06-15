#!/bin/bash

set -e

echo "⏳ Waiting for database connection..."

MAX_TRIES=30
COUNT=0
until mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" &>/dev/null; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
        echo "❌ Database not available after $MAX_TRIES attempts. Exiting."
        exit 1
    fi
    echo "⏳ Waiting for database... (${COUNT}/${MAX_TRIES})"
    sleep 2
done

echo "✅ Database is ready."

TABLE_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" -e "SHOW TABLES" | wc -l)
if [ "$TABLE_COUNT" -lt 2 ]; then
    echo "📦 Database is empty. Importing /var/www/html/install/sql-install.sql"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" < /var/www/html/install/sql-install.sql
    echo "✅ Import finished."
else
    echo "ℹ️ Database already has tables, skipping import."
fi

if [ -f /var/www/html/composer.json ]; then
    echo "📦 Installing Composer dependencies..."
    cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader
fi

echo "✅ Init script finished."