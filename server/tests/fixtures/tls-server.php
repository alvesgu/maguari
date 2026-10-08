<?php

declare(strict_types=1);

// A local server for StreamTlsCertificateReaderTest. Prints its port, then
// accepts connections until it is killed. With a certificate file it serves
// TLS with it; with "silent" it accepts TCP connections and never answers.

$certificate = $argv[1] ?? '';

if ($certificate === 'silent') {
    $server = stream_socket_server('tcp://127.0.0.1:0');
} else {
    $server = stream_socket_server('ssl://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
        'ssl' => ['local_cert' => $certificate, 'verify_peer' => false],
    ]));
}

$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1) . "\n";
fflush(STDOUT);
$held = [];

while (true) {
    // A failed handshake is the client's choice in these tests, not an error here.
    $connection = @stream_socket_accept($server, -1);

    if ($connection !== false) {
        $held[] = $connection;
    }
}
