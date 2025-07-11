👋 Добро пожаловать, <b><?= htmlspecialchars($user['username']) ?></b>!

💵 Ваш баланс: <b><?= $user['balance'] ?> руб.</b>
🖥 Активных ВМ: <b><?= rand(1, 5) ?></b>

Выберите действие: