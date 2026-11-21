#!/bin/bash

if [ "${1:-}" = cloud-images ]; then
    while true; do
        /usr/local/bin/php /var/www/html/cronjobs/process_cloud_image_imports.php
        sleep 60
    done
fi

exec cron -f
