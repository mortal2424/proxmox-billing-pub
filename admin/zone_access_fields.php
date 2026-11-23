<?php
$allowedUsers = $cluster['allowed_user_ids'] ?? null;
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cluster_form']);
$testZone = $submitted ? isset($_POST['test_zone']) : $allowedUsers !== null;
$allowedUserInput = $submitted ? ($_POST['allowed_user_ids'] ?? '') : ($allowedUsers ?? '');
?>
<div class="form-group">
    <label class="checkbox-container">
        <input type="checkbox" name="test_zone" <?= $testZone ? 'checked' : '' ?>>
        <span class="checkmark"></span>
        <span>Тестовая зона доступности</span>
    </label>
</div>
<div class="form-group">
    <label class="form-label" for="allowedUserIds">ID пользователей с доступом</label>
    <textarea id="allowedUserIds" name="allowed_user_ids" class="form-input" rows="3" maxlength="8000"
              aria-describedby="allowedUserIdsHint"><?= htmlspecialchars(is_string($allowedUserInput) ? $allowedUserInput : '') ?></textarea>
    <small id="allowedUserIdsHint">Для тестовой зоны укажите ID из раздела «Пользователи» через запятую или пробел. Пустой список закрывает зону для всех клиентов.</small>
</div>
