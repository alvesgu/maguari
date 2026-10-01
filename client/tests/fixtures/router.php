<?php

declare(strict_types=1);

// Router for PHP's built-in server, used by StreamTransportTest.

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
        'client' => $_SERVER['HTTP_X_MAGUARI_CLIENT'] ?? null,
        'body' => file_get_contents('php://input'),
    ]);

    return;
}

if ($path === '/no-content') {
    http_response_code(204);

    return;
}

if ($path === '/unauthorized') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"unauthorized"}';

    return;
}

if ($path === '/redirect') {
    header('Location: /echo', true, 302);

    return;
}

http_response_code(404);
