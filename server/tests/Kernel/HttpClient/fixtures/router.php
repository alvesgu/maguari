<?php

declare(strict_types=1);

// Router for PHP's built-in server, used by StreamHttpClientTest.

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    header('Content-Type: application/json');
    header('X-Fixture: yes');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
        'body' => file_get_contents('php://input'),
    ]);

    return;
}

if ($path === '/forbidden') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"error":{"code":403}}';

    return;
}

if ($path === '/redirect') {
    header('Location: /echo', true, 302);

    return;
}

if ($path === '/slow') {
    sleep(2);
    echo 'too late';

    return;
}

http_response_code(404);
