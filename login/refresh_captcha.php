<?php
session_start();
header('Content-Type: application/json');

// Генерируем новую капчу
$num1 = rand(1, 10);
$num2 = rand(1, 10);
$_SESSION['captcha'] = $num1 + $num2;
$_SESSION['captcha_question'] = "$num1 + $num2";

echo json_encode([
    'success' => true,
    'question' => $_SESSION['captcha_question']
]);
exit;