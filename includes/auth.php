<?php
function ensureSessionStarted() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    ensureSessionStarted();
    return isset($_SESSION['user']);
}

function isAdmin() {
    ensureSessionStarted();
    return isset($_SESSION['user']['is_admin']) && $_SESSION['user']['is_admin'] == 1;
}

function checkAuth() {
    if (!isLoggedIn()) {
        header('Location: /login/login.php');
        exit;
    }
}

function validateCaptcha($answer) {
    ensureSessionStarted();
    return isset($_SESSION['captcha']) && $answer === $_SESSION['captcha'];
}
?>