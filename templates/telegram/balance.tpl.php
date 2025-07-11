💰 <b>Ваш баланс:</b> <?= $balance ?> руб.

📝 <b>Последние операции:</b>
<?php foreach ($transactions as $tx): ?>
• <?= date('d.m H:i', strtotime($tx['created_at'])) ?>: 
  <?= $tx['amount'] ?> руб. (<?= $tx['description'] ?>)
<?php endforeach; ?>