<?php

declare(strict_types=1);

// A local web server for StreamHttpProbeTest and the run-http-checks command
// test. Prints its port, then answers connections one at a time until it is
// killed. The first argument says how:
//   <status>  reads the request and answers with that status and a large body
//   silent    accepts connections and never answers
//   garbage   answers with something that is not an HTTP status line
//   close     closes each connection without answering
//   host:<name>  answers 200 when the request's Host header is <name>, else 421
// With a certificate file as the second argument it serves TLS with it.

$mode = $argv[1] ?? '200';
$certificate = $argv[2] ?? null;

if ($certificate === null) {
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

    if ($connection === false) {
        continue;
    }

    if ($mode === 'silent') {
        $held[] = $connection;

        continue;
    }

    $request = '';

    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $request .= (string) fread($connection, 8192);
    }

    if (str_starts_with($mode, 'host:')) {
        $wanted = substr($mode, 5);
        $status = preg_match('/^Host: (.*)\r$/mi', $request, $match) === 1 && $match[1] === $wanted ? '200' : '421';
        @fwrite($connection, "HTTP/1.1 {$status} Something\r\nConnection: close\r\n\r\n");
    } elseif ($mode === 'garbage') {
        @fwrite($connection, "SSH-2.0-OpenSSH_9.6\r\n");
    } elseif ($mode !== 'close') {
        @fwrite($connection, "HTTP/1.1 {$mode} Something\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n");
        // More than the probe ever reads; it closes after the status line.
        @fwrite($connection, str_repeat('x', 65536));
    }

    @fclose($connection);
}
