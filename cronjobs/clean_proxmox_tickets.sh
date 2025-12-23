#!/bin/bash
# Скрипт очистки устаревших Proxmox тикетов

mysql -u homevlad_ru -p'VvYO1BYAuB74Rbhz' homevlad_ru -e "DELETE FROM proxmox_tickets WHERE expires_at < NOW()"