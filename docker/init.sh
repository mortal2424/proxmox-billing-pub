#!/bin/bash

# Ждём готовности БД
until mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" &> /dev/null; do
    echo "⏳ Waiting for database connection..."
    sleep 2
done

# Импортируем дамп, если таблиц нет
TABLE_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" -e "SHOW TABLES" | wc -l)
if [ "$TABLE_COUNT" -lt 2 ]; then
    echo "📦 Database is empty. Importing /var/www/html/install/sql-install.sql"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" < /var/www/html/install/sql-install.sql
    echo "✅ Import finished."
else
    echo "ℹ️ Database already has tables, skipping import."
fi

# Устанавливаем зависимости Composer (если есть composer.json)
if [ -f /var/www/html/composer.json ]; then
    echo "📦 Installing Composer dependencies..."
    cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader
fi

echo "✅ Init script finished."