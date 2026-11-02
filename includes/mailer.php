<?php
/**
 * Client SMTP minimal (aucune bibliothèque externe) : SSL (port 465) ou STARTTLS (port 587),
 * authentification LOGIN, messages texte UTF-8. Compatible Gmail (avec un « mot de passe d'application »).
 */
require_once __DIR__ . '/settings.php';

function smtp_config(): array
{
    return [
        'enabled'   => setting('smtp_enabled', '0') === '1',
        'host'      => setting('smtp_host'),
        'port'      => (int)setting('smtp_port', '587'),
        'security'  => setting('smtp_security', 'tls'),   // tls | ssl | none
        'user'      => setting('smtp_user'),
        'pass'      => setting('smtp_pass'),
        'from'      => setting('mail_from'),
        'from_name' => setting('mail_from_name', 'SuiviLTP'),
    ];
}

function mail_header_encode(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

/** Envoie un e-mail texte. Lève une RuntimeException en cas d'échec. */
function smtp_send(array $cfg, string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresse e-mail invalide (destinataire ou expéditeur).');
    }
    if ($cfg['host'] === '') {
        throw new RuntimeException('Serveur SMTP non configuré.');
    }

    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    $caFile = ini_get('openssl.cafile');
    if (!$caFile && is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) {
        $ssl['cafile'] = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    }
    $remote = ($cfg['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    if (!$fp) {
        throw new RuntimeException("Connexion au serveur SMTP impossible ($errstr).");
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (?string $command, array $expected) use ($fp, $read): string {
        if ($command !== null) {
            fwrite($fp, $command . "\r\n");
        }
        $reply = $read();
        if (!in_array((int)substr($reply, 0, 3), $expected, true)) {
            $shown = $command !== null && strpos($command, 'AUTH') === false && strlen($command) < 60 ? $command : 'commande';
            throw new RuntimeException('Le serveur SMTP a refusé (' . $shown . ') : ' . trim($reply ?: 'pas de réponse'));
        }
        return $reply;
    };

    try {
        $cmd(null, [220]);
        $helo = 'suiviltp.local';
        $cmd("EHLO $helo", [250]);
        if ($cfg['security'] === 'tls') {
            $cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $method)) {
                throw new RuntimeException('Échec du chiffrement TLS avec le serveur SMTP.');
            }
            $cmd("EHLO $helo", [250]);
        }
        if ($cfg['user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($cfg['user']), [334]);
            try {
                $cmd(base64_encode($cfg['pass']), [235]);
            } catch (RuntimeException $e) {
                throw new RuntimeException("Identifiant ou mot de passe SMTP refusé. Pour Gmail, utilisez un « mot de passe d'application ».");
            }
        }
        $cmd('MAIL FROM:<' . $cfg['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_header_encode($cfg['from_name']) . ' <' . $cfg['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . mail_header_encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($cfg['from'], '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: SuiviLTP',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n") . ".\r\n");
        $cmd(null, [250]);
        try {
            $cmd('QUIT', [221]);
        } catch (RuntimeException $e) {
            // sans importance : le message est accepté
        }
    } finally {
        fclose($fp);
    }
}
