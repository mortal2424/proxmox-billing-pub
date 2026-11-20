<?php

function calculateNextRun($type, $time, $day = null, ?DateTimeImmutable $now = null): string
{
    $now ??= new DateTimeImmutable();
    if ($type === 'hourly') {
        $next = $now->modify('+1 hour');
        return $next->setTime((int)$next->format('H'), (int)$next->format('i'))->format('Y-m-d H:i:s');
    }
    if (!in_array($type, ['daily', 'weekly', 'monthly'], true) || !is_string($time) ||
        !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) throw new InvalidArgumentException('Некорректное расписание или время');
    $parts = array_map('intval', explode(':', $time));
    $next = $now->setTime($parts[0], $parts[1], $parts[2] ?? 0);
    if ($type === 'daily') {
        if ($next <= $now) $next = $next->modify('+1 day');
    } else {
        $day = filter_var($day ?? 1, FILTER_VALIDATE_INT);
        if ($day === false || $day < 1 || $day > ($type === 'weekly' ? 7 : 31)) throw new InvalidArgumentException('Некорректный день расписания');
        if ($type === 'weekly') {
            $offset = ($day - (int)$now->format('N') + 7) % 7;
            $next = $next->modify('+' . $offset . ' days');
            if ($next <= $now) $next = $next->modify('+7 days');
        } else {
            $next = $next->setDate((int)$now->format('Y'), (int)$now->format('n'), min($day, (int)$now->format('t')));
            if ($next <= $now) {
                $month = $now->modify('first day of next month');
                $next = $next->setDate((int)$month->format('Y'), (int)$month->format('n'), min($day, (int)$month->format('t')));
            }
        }
    }
    return $next->format('Y-m-d H:i:s');
}
