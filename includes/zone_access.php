<?php

// NULL is public. An empty list makes a test zone unavailable to every customer.
const ZONE_ACCESS_FILTER = '(z.allowed_user_ids IS NULL OR FIND_IN_SET(?, z.allowed_user_ids) > 0)';

function zoneAllowsUser(PDO $pdo, int $zoneId, int $userId): bool
{
    $query = $pdo->prepare('SELECT 1 FROM proxmox_clusters z WHERE z.id = ? AND ' . ZONE_ACCESS_FILTER);
    $query->execute([$zoneId, $userId]);
    return (bool)$query->fetchColumn();
}

function zoneAccessFromForm(PDO $pdo, array $input): ?string
{
    if (!isset($input['test_zone'])) {
        return null;
    }
    $raw = $input['allowed_user_ids'] ?? '';
    if (!is_string($raw) || strlen($raw) > 8000) {
        throw new InvalidArgumentException('Список ID пользователей слишком длинный или некорректный.');
    }
    $ids = [];
    foreach (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($id === false) {
            throw new InvalidArgumentException('Укажите числовые ID пользователей через запятую или пробел.');
        }
        $ids[$id] = $id;
    }
    if (!$ids) {
        return '';
    }
    $query = $pdo->prepare('SELECT id FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $query->execute(array_values($ids));
    if (count($query->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
        throw new InvalidArgumentException('Один или несколько ID пользователей не найдены.');
    }
    sort($ids, SORT_NUMERIC);
    return implode(',', $ids);
}
