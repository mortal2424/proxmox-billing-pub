<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'Endpoint removed']);
