<?php
declare(strict_types=1);

require_once __DIR__.'/guest_card_pricing.php';

function resourceUsageBreakdown(array $guests, array $rates, int $hours): array
{
    $groups = [];
    foreach ($guests as $guest) {
        $kind = $guest['vm_type'];
        $prefix = $kind === 'lxc' ? 'price_per_hour_lxc_' : 'price_per_hour_';
        $running = $guest['status'] === 'running';
        if (!$running && !in_array($guest['status'], ['stopped','suspended'], true)) continue;
        if ($running && empty($guest['is_custom'])) {
            if ($guest['tariff_price'] === null) continue;
            $metrics = ['bundle'=>[1,(float)$guest['tariff_price'] * $hours / 720]];
        } else {
            $disk = (float)$guest['disk'];
            $metrics = ['disk'=>[$disk, $disk * (float)$rates[$prefix.'disk'] * $hours * ($running ? 1 : .1)]];
            if ($running) {
                foreach (['cpu','ram'] as $key) {
                    $qty = (float)$guest[$key];
                    $metrics[$key] = [$qty, $qty * (float)$rates[$prefix.$key] * $hours];
                }
            }
        }
        foreach ($metrics as $key => [$quantity, $cost]) {
            $groups[$kind][$key] ??= ['quantity'=>0.0, 'cost'=>0.0];
            $groups[$kind][$key]['quantity'] += $quantity;
            $groups[$kind][$key]['cost'] += $cost;
        }
    }
    return $groups;
}
