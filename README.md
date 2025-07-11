Billing Panel Proxmox 



The first public release of the billing panel for Proxmox, the code is still under development, but many of the panel's features have already been implemented.
 1. Creating a cluster (as a logical unit)
2. Adding individual servers to them, as well as adding Proxmox cluster systems
3. Creating virtual machines and containers
4. Using the billing system, replenishing the balance, creating tariffs, and charging for resources once an hour or once a month, depending on the created tariff
5. Implementing monitoring of VM and Container resources
6. Proxmox node monitoring is implemented
7. A ticket system for support is implemented with email and telegram notifications 

Installation is simple:
Copy all the contents of the repository to your hosting, add the sql dump to the database
Create cron jobs 


1. Debiting (billing work) once per hour or per month if you need both then you need to create two tasks

/admin/cron_charge.php

2. Update node stats every 5 minutes

/admin/update_node_stats.php

3. Delete old metrics at 3 am every day

/api/clean_old_metrics.php

4. Collect metrics from LXC every 5 minutes

/api/collect_lxc_metrics.php

5. Update PVETicket every 2 hours

/api/update_proxmox_tickets.php

6. Collect VM and LXC IP addresses every minute

/includes/vm_ip_updater.php

7. Collect VM metrics every 5 minutes

/api/collect_vm_metrics.php

The code is under development and may contain errors. If you want to help with the development, please contact me at mortal24@yandex.ru or via Telegram at 331473849. I will add you as a developer, and together we can create the panel more quickly.



Это первый публичный релиз биллинговой панели для Proxmox, код все еще находится в стадии разработки, но многие функции панели уже реализованы.

1. Создание кластера (как логической единицы)
2. Добавление к ним отдельных серверов, а также добавление кластерных систем Proxmox
3. Создание виртуальных машин и контейнеров
4. Использование биллинговой системы, пополнение баланса, создание тарифов и взимание платы за ресурсы раз в час или раз в месяц, в зависимости от созданного тарифа
5. Внедрение мониторинга ресурсов виртуальной машины и контейнера
6. Реализован мониторинг узла Proxmox
7. Реализована система тикетов для поддержки с уведомлениями по электронной почте и telegram

Установка проста: Скопируйте все содержимое репозитория на свой хостинг, добавьте sql-дамп в базу данных, создайте cron-задания

1. Списание средств (выставление счетов) раз в час или в месяц, если вам нужно и то, и другое, то вам нужно создать две задачи
/admin/cron_charge.php

2. Обновлять статистику узлов каждые 5 минут
/admin/update_node_stats.php

3. Удалять старые показатели в 3 часа ночи каждый день
/api/clean_old_metrics.php

4. Собирать показатели из LXC каждые 5 минут
/api/collect_lxc_metrics.php

5. Обновляйте PVETicket каждые 2 часа
/api/update_proxmox_tickets.php

6. Собирайте IP-адреса виртуальных машин и LXC-серверов каждую минуту
/includes/vm_ip_updater.php

7. Собирайте показатели виртуальных машин каждые 5 минут
/api/collect_vm_metrics.php

Код находится в стадии разработки и может содержать ошибки. Если вы хотите помочь с разработкой, пожалуйста, свяжитесь со мной по адресу mortal24@yandex.ru или через Telegram по номеру 331473849. Я добавлю вас в качестве разработчика, и вместе мы сможем быстрее создать панель.
