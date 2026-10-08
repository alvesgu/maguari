<?php

declare(strict_types=1);

// A scripted SMTP server for SmtpMailerTest. Prints its port, then serves one
// connection at a time until it is killed. Its only argument is JSON:
//
//   mode         "starttls", "implicit" (TLS from the start), "plain" or
//                "silent" (accepts connections and never answers)
//   certificate  PEM file with certificate and key, for the TLS modes
//   offer_tls    whether EHLO lists STARTTLS (mode starttls)
//   replies      replies replacing the default ones, by key: greeting,
//                starttls, auth, mail, rcpt, data (after the message)
//   log          file that receives every line the client sends, prefixed
//                with "[plain] " or "[tls] ". The AUTH PLAIN credentials are
//                logged decoded, as "CREDENTIALS <username> <password>".
//
// Each line is logged before it is answered, so the log is complete when the
// client has its answer.

$config = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$mode = $config['mode'];
$replies = ($config['replies'] ?? []) + [
    'greeting' => '220 smtp.test ESMTP ready',
    'starttls' => '220 2.0.0 Ready to start TLS',
    'auth' => '235 2.7.0 Accepted',
    'mail' => '250 2.1.0 OK',
    'rcpt' => '250 2.1.5 OK',
    'data' => '250 2.0.0 OK queued',
];
$tlsContext = ['local_cert' => $config['certificate'] ?? '', 'verify_peer' => false];

if ($mode === 'implicit') {
    $server = stream_socket_server('ssl://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => $tlsContext]));
} else {
    $server = stream_socket_server('tcp://127.0.0.1:0');
}

$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1) . "\n";
fflush(STDOUT);
$held = [];

$log = static function (bool $tls, string $line) use ($config): void {
    file_put_contents($config['log'], ($tls ? '[tls] ' : '[plain] ') . $line . "\n", FILE_APPEND);
};
$send = static function ($connection, string $reply): void {
    // The client may already be gone (a refused certificate).
    @fwrite($connection, $reply . "\r\n");
};

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

    $tls = $mode === 'implicit';
    $send($connection, $replies['greeting']);
    $authenticating = false;

    while (($line = fgets($connection)) !== false) {
        $line = rtrim($line, "\r\n");

        if ($authenticating) {
            $authenticating = false;
            $log($tls, 'CREDENTIALS ' . str_replace("\0", ' ', ltrim((string) base64_decode($line), "\0")));
            $send($connection, $replies['auth']);

            continue;
        }

        $log($tls, $line);
        $command = strtoupper(strtok($line, ' :') ?: '');

        if ($command === 'EHLO') {
            $lines = ['smtp.test'];

            if ($mode === 'starttls' && !$tls && ($config['offer_tls'] ?? true)) {
                $lines[] = 'STARTTLS';
            }

            if ($tls || $mode === 'plain') {
                $lines[] = 'AUTH PLAIN';
            }

            $lines[] = '8BITMIME';

            foreach ($lines as $index => $text) {
                $send($connection, '250' . ($index === count($lines) - 1 ? ' ' : '-') . $text);
            }
        } elseif ($command === 'HELO' || $command === 'RSET' || $command === 'NOOP') {
            $send($connection, '250 OK');
        } elseif ($command === 'STARTTLS') {
            $send($connection, $replies['starttls']);

            if (!str_starts_with($replies['starttls'], '220')) {
                continue;
            }

            stream_context_set_option($connection, ['ssl' => $tlsContext]);

            if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                break;
            }

            $tls = true;
        } elseif ($command === 'AUTH') {
            $authenticating = true;
            $send($connection, '334 ');
        } elseif ($command === 'MAIL') {
            $send($connection, $replies['mail']);
        } elseif ($command === 'RCPT') {
            $send($connection, $replies['rcpt']);
        } elseif ($command === 'DATA') {
            $send($connection, '354 Go ahead');

            while (($dataLine = fgets($connection)) !== false && rtrim($dataLine, "\r\n") !== '.') {
                $log($tls, '| ' . rtrim($dataLine, "\r\n"));
            }

            $send($connection, $replies['data']);
        } elseif ($command === 'QUIT') {
            $send($connection, '221 2.0.0 Bye');

            break;
        } else {
            $send($connection, '502 5.5.1 Unrecognized command');
        }
    }

    fclose($connection);
}
