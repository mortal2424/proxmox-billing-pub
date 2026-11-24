<?php
declare(strict_types=1);

function annotateGuestCardPrices(PDO $pdo, array &$guests): void
{
    if (!$guests) return;

    $rates = $pdo->query('SELECT * FROM resource_prices ORDER BY updated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [
        'price_per_hour_cpu'=>0.001, 'price_per_hour_ram'=>0.000010, 'price_per_hour_disk'=>0.000050,
        'price_per_hour_lxc_cpu'=>0.000800, 'price_per_hour_lxc_ram'=>0.000008, 'price_per_hour_lxc_disk'=>0.000030,
    ];
    foreach ($guests as &$guest) {
        $guest['billing_period'] = 'hour';
        $guest['current_price'] = null;
        $prefix = $guest['vm_type'] === 'lxc' ? 'price_per_hour_lxc_' : 'price_per_hour_';
        if (in_array($guest['status'], ['stopped', 'suspended'], true)) {
            $guest['current_price'] = round((float)$guest['disk'] * $rates[$prefix . 'disk'] * 0.1, 6);
        } elseif ($guest['status'] === 'running') {
            if (!empty($guest['is_custom'])) {
                $guest['current_price'] = round(
                    (float)$guest['cpu'] * $rates[$prefix . 'cpu'] +
                    (float)$guest['ram'] * $rates[$prefix . 'ram'] +
                    (float)$guest['disk'] * $rates[$prefix . 'disk'], 6
                );
            } elseif ($guest['tariff_price'] !== null) {
                $guest['current_price'] = (float)$guest['tariff_price'];
                $guest['billing_period'] = 'month';
            }
        }
    }
}
