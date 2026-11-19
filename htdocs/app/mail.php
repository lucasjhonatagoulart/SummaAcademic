<?php
// Envio de e-mail por SMTP externo (o InfinityFree gratuito bloqueia a função mail() do PHP).
// Configure em Painel de controle › Instituição › E-mail (ex.: Gmail com senha de app, Brevo, SMTP2GO).

function mail_configured(): bool { return (bool) setting('smtp_host'); }

/** Retorna [ok, erro] */
function send_mail(string $to, string $subject, string $text): array {
    if (!mail_configured()) return [false, 'E-mail não configurado.'];
    $text = rtrim($text) . "\n\n—\n" . BRAND['full'] . (APP_SLOGAN ? ' · ' . APP_SLOGAN : '');
    $host = setting('smtp_host'); $port = (int) setting('smtp_port', 587); $secure = setting('smtp_secure', 'tls');
    $user = setting('smtp_user'); $pass = setting('smtp_pass');
    $from = setting('mail_from') ?: $user; $fromName = setting('mail_from_name') ?: APP_NAME;
    try {
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException("Conexão com $host:$port falhou ($errstr).");
        stream_set_timeout($s, 15);
        $read = function () use ($s) { $data = ''; while (($line = fgets($s, 515)) !== false) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; } return $data; };
        $cmd = function (string $c, array $okCodes) use ($s, $read) { fwrite($s, $c . "\r\n"); $r = $read(); if (!in_array((int) substr($r, 0, 3), $okCodes, true)) throw new RuntimeException('SMTP: ' . trim($r)); return $r; };
        $greet = $read(); if ((int) substr($greet, 0, 3) !== 220) throw new RuntimeException('SMTP: ' . trim($greet));
        $ehlo = 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'academus.local');
        $cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('Falha ao iniciar TLS.');
            $cmd($ehlo, [250]);
        }
        if ($user) { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($user), [334]); $cmd(base64_encode($pass), [235]); }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
        $headers = 'From: ' . $enc($fromName) . " <$from>\r\nTo: <$to>\r\nSubject: " . $enc($subject) . "\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'academus') . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $body = chunk_split(base64_encode($text));
        $cmd($headers . "\r\n" . $body . "\r\n.", [250]);
        fwrite($s, "QUIT\r\n"); fclose($s);
        return [true, null];
    } catch (Throwable $e) {
        error_log('[mail] ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}
