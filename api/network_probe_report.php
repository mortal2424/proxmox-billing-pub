<?php

require_once __DIR__ . '/../includes/guest_network_probe.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 513);
http_response_code(guestNetworkProbeRecord($body ?: '', $_SERVER['HTTP_X_HOMEVLAD_SIGNATURE'] ?? '', time()));
