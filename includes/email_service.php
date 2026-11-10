<?php
function logbook_smtp_config(): ?array
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'smtp.php';
    if (!is_file($path)) {
        return null;
    }

    $config = require $path;
    if (!is_array($config)) {
        error_log('SMTP configuration must return an array.');
        return null;
    }

    foreach (['host', 'from_email'] as $required) {
        if (!isset($config[$required]) || !is_string($config[$required]) || trim($config[$required]) === '') {
            return null;
        }
    }
    $config += [
        'port' => 587,
        'security' => 'starttls',
        'username' => '',
        'password' => '',
        'from_name' => "CEIT Dean's Office Digital Logbook",
        'app_base_url' => '',
        'timeout' => 15,
    ];
    if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
        || !in_array($config['security'], ['starttls', 'ssl', 'none'], true)
        || (int)$config['port'] < 1
        || (int)$config['port'] > 65535
        || preg_match('/[\r\n]/', (string)$config['host'] . (string)$config['username']) === 1
    ) {
        error_log('SMTP configuration contains invalid connection or sender settings.');
        return null;
    }
    return $config;
}

function logbook_email_available(): bool
{
    return logbook_smtp_config() !== null;
}

function logbook_smtp_read($stream): string
{
    $response = '';
    do {
        $line = fgets($stream, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP server closed the connection unexpectedly.');
        }
        $response .= $line;
    } while (strlen($line) >= 4 && $line[3] === '-');
    return $response;
}

function logbook_smtp_expect($stream, array $expectedCodes, string $step): string
{
    $response = logbook_smtp_read($stream);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP ' . $step . ' failed with response ' . trim($response));
    }
    return $response;
}

function logbook_smtp_command($stream, string $command, array $expectedCodes, string $step): string
{
    if (fwrite($stream, $command . "\r\n") === false) {
        throw new RuntimeException('Could not write SMTP ' . $step . ' command.');
    }
    return logbook_smtp_expect($stream, $expectedCodes, $step);
}

function logbook_send_email(string $recipient, string $subject, string $body): bool
{
    $config = logbook_smtp_config();
    if ($config === null || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $subject) === 1
    ) {
        return false;
    }

    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $timeout = max(1, min(60, (int)$config['timeout']));
    $target = $config['security'] === 'ssl' ? 'ssl://' . $host : 'tcp://' . $host;
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
    ]]);
    $stream = @stream_socket_client(
        $target . ':' . $port,
        $errorNumber,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($stream === false) {
        error_log('SMTP connection failed (' . $errorNumber . '): ' . $errorMessage);
        return false;
    }

    stream_set_timeout($stream, $timeout);
    try {
        logbook_smtp_expect($stream, [220], 'greeting');
        $ehloName = preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname()) ?: 'localhost';
        logbook_smtp_command($stream, 'EHLO ' . $ehloName, [250], 'EHLO');
        if ($config['security'] === 'starttls') {
            logbook_smtp_command($stream, 'STARTTLS', [220], 'STARTTLS');
            $cryptoEnabled = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            logbook_smtp_command($stream, 'EHLO ' . $ehloName, [250], 'EHLO after TLS');
        }
        if ((string)$config['username'] !== '') {
            logbook_smtp_command($stream, 'AUTH LOGIN', [334], 'authentication');
            logbook_smtp_command($stream, base64_encode((string)$config['username']), [334], 'username authentication');
            logbook_smtp_command($stream, base64_encode((string)$config['password']), [235], 'password authentication');
        }

        logbook_smtp_command($stream, 'MAIL FROM:<' . $config['from_email'] . '>', [250], 'sender');
        logbook_smtp_command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251], 'recipient');
        logbook_smtp_command($stream, 'DATA', [354], 'message data');

        $encodedName = '=?UTF-8?B?' . base64_encode((string)$config['from_name']) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'From: ' . $encodedName . ' <' . $config['from_email'] . ">\r\n"
            . 'To: <' . $recipient . ">\r\n"
            . 'Subject: ' . $encodedSubject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body));
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/(?m)^\./', '..', $message);
        if (fwrite($stream, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('Could not transmit the SMTP message.');
        }
        logbook_smtp_expect($stream, [250], 'message delivery');
        logbook_smtp_command($stream, 'QUIT', [221], 'quit');
        fclose($stream);
        return true;
    } catch (Throwable $error) {
        fclose($stream);
        error_log('SMTP email delivery failed: ' . $error->getMessage());
        return false;
    }
}

function logbook_app_base_url(): string
{
    $config = logbook_smtp_config();
    $configured = trim((string)($config['app_base_url'] ?? ''));
    if ($configured !== '') {
        $parts = parse_url($configured);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new RuntimeException('SMTP app_base_url must be an absolute HTTP or HTTPS URL without credentials or query parameters.');
        }
        return rtrim($configured, '/');
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?\z/', $host) !== 1) {
        throw new RuntimeException('The application host is invalid; set app_base_url in config/smtp.php.');
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
    return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
}

function logbook_send_password_link(string $email, string $username, string $token, string $purpose): bool
{
    $url = logbook_app_base_url() . '/forgot_password?token=' . rawurlencode($token);
    $setup = $purpose === 'setup';
    $subject = $setup
        ? 'Set up the password for your new CEIT Logbook account'
        : 'Reset your existing CEIT Logbook account password';
    $expiry = $setup ? 'one hour' : '30 minutes';
    $body = "Hello {$username},\r\n\r\n"
        . ($setup
            ? "A new CEIT Dean's Office Digital Logbook account was created for you. Use this link to set the password for that new account. This is account setup, not password recovery.\r\n"
            : "A password recovery was requested for your existing CEIT Dean's Office Digital Logbook account. Use this link to choose a new password.\r\n")
        . "Use this one-time link within {$expiry}:\r\n{$url}\r\n\r\n"
        . "If you did not request this, you can ignore this message.\r\n";
    return logbook_send_email($email, $subject, $body);
}
