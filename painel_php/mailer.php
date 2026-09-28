<?php

declare(strict_types=1);

function smtpResponse($socket): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) throw new RuntimeException('O servidor SMTP encerrou a conexão.');
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    return $response;
}

function smtpCommand($socket, string $command, array $expected): void
{
    fwrite($socket, $command . "\r\n");
    $response = smtpResponse($socket);
    if (!in_array((int) substr($response, 0, 3), $expected, true)) throw new RuntimeException('Erro SMTP: ' . trim($response));
}

function smtpSend(string $recipient, string $subject, string $text): void
{
    $smtp = config()['smtp'];
    foreach (['host', 'username', 'password', 'from_email'] as $field) {
        if (trim((string) $smtp[$field]) === '') throw new RuntimeException('Configuração SMTP incompleta.');
    }
    $prefix = $smtp['encryption'] === 'ssl' ? 'ssl://' : '';
    $socket = @stream_socket_client($prefix . $smtp['host'] . ':' . $smtp['port'], $errorNumber, $error, 20, STREAM_CLIENT_CONNECT);
    if ($socket === false) throw new RuntimeException('Não foi possível conectar ao SMTP: ' . $error);
    stream_set_timeout($socket, 20);
    try {
        $greeting = smtpResponse($socket);
        if ((int) substr($greeting, 0, 3) !== 220) throw new RuntimeException('O SMTP não enviou saudação válida.');
        smtpCommand($socket, 'EHLO copa-lago-de-pedra', [250]);
        if ($smtp['encryption'] === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Não foi possível ativar TLS no SMTP.');
            smtpCommand($socket, 'EHLO copa-lago-de-pedra', [250]);
        }
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($smtp['username']), [334]);
        smtpCommand($socket, base64_encode($smtp['password']), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $smtp['from_email'] . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);
        $headers = 'From: ' . $smtp['from_name'] . ' <' . $smtp['from_email'] . ">\r\n" . 'To: <' . $recipient . ">\r\n" . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        smtpCommand($socket, $headers . "\r\n" . str_replace("\n.", "\n..", $text) . "\r\n.", [250]);
        // O servidor já aceitou a mensagem; uma falha ao encerrar não é falha de envio.
        try { smtpCommand($socket, 'QUIT', [221]); } catch (Throwable $ignored) {}
    } finally {
        fclose($socket);
    }
}
