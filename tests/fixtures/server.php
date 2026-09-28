<?php

// Router for `php -S` used by CurlTransportTest.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$record = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'body' => file_get_contents('php://input'),
];
if ($path === '/v1/messages/msg_ok') {
    header('Content-Type: application/json');
    header('X-Request-Id: req_ok');
    echo '{"id":"msg_ok"}';
    return true;
}
if ($path === '/v1/messages/slow') {
    usleep(1_000_000);
    echo '{}';
    return true;
}
if ($path === '/v1/messages/redirect') {
    header('Location: /v1/messages/msg_ok', true, 302);
    return true;
}
if ($path === '/v1/echo') {
    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode($record);
    return true;
}
http_response_code(404);
header('Content-Type: application/json');
header('x-request-id: req_404');
echo '{"error":"not_found","message":"no such message"}';
return true;
