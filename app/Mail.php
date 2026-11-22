<?php
declare(strict_types=1);

function deliverMail(array $config, string $recipient, string $subject, string $body): void
{
    $smtp = $config['smtp'] ?? null;
    $from = $smtp['from'] ?? $config['mail_from'] ?? '';
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject)) {
        throw new RuntimeException('Mail requires a valid sender and recipient.');
    }
    $encoded = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    $headers = "From: <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";

    if (!$smtp) {
        if (!mail($recipient, $subject, $encoded, $headers)) {
            throw new RuntimeException('The system mail transport rejected the message.');
        }
        return;
    }
    $security = $smtp['security'] ?? 'tls';
    $host = $smtp['host'];
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || !in_array($security, ['tls', 'ssl'], true)) {
        throw new RuntimeException('SMTP requires a hostname and TLS.');
    }
    $address = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) ($smtp['port'] ?? ($security === 'ssl' ? 465 : 587));
    $socket = @stream_socket_client($address, $code, $reason, 8);
    if (!$socket) {
        throw new RuntimeException('Could not reach the SMTP host.');
    }
    stream_set_timeout($socket, 8);
    $expect = static function (array $codes) use ($socket): void {
        do {
            $line = fgets($socket, 1024);
            if ($line === false || !preg_match('/^\d{3}[ -]/', $line)) {
                throw new RuntimeException('Invalid SMTP response.');
            }
        } while ($line[3] === '-');
        if (!in_array((int) substr($line, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP server rejected a command.');
        }
    };
    $send = static function (string $command, int ...$codes) use ($socket, $expect): void {
        $payload = $command . "\r\n";
        while ($payload !== '') {
            $sent = fwrite($socket, $payload);
            if (!$sent) {
                throw new RuntimeException('SMTP connection closed.');
            }
            $payload = substr($payload, $sent);
        }
        $expect($codes);
    };
    try {
        $expect([220]);
        $send('EHLO localhost', 250);
        if ($security === 'tls') {
            $send('STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not negotiate SMTP TLS.');
            }
            $send('EHLO localhost', 250);
        }
        if (($smtp['username'] ?? '') !== '') {
            $send('AUTH LOGIN', 334);
            $send(base64_encode($smtp['username']), 334);
            $send(base64_encode($smtp['password'] ?? ''), 235);
        }
        $send("MAIL FROM:<$from>", 250);
        $send("RCPT TO:<$recipient>", 250, 251);
        $send('DATA', 354);
        $send("Date: " . gmdate(DATE_RFC2822) . "\r\nTo: <$recipient>\r\nSubject: $subject\r\n$headers\r\n\r\n$encoded\r\n.", 250);
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}
