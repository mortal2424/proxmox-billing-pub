#!/bin/bash

# Ждём, пока БД будет готова
until mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" &> /dev/null; do
    echo "Ожидание подключения к БД..."
    sleep 2
done

# Проверяем, есть ли уже таблицы
TABLE_COUNT=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" -e "SHOW TABLES" | wc -l)

if [ "$TABLE_COUNT" -lt 2 ]; then
    echo "База данных пуста. Импортируем дамп из /var/www/html/install/sql-install.sql"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -D"$DB_NAME" < /var/www/html/install/sql-install.sql
    echo "Импорт завершён."
else
    echo "База данных уже содержит таблицы, импорт пропущен."
fi

# Запуск Composer, если требуется
if [ -f /var/www/html/composer.json ]; then
    cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader
fi

echo "Инициализация завершена."