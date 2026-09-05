<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$debugFile = __DIR__ . '/smtp-debug.log';

function smtp_debug(string $message): void {
    global $debugFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($debugFile, $line, FILE_APPEND | LOCK_EX);
}

smtp_debug('=== NEW CONTACT FORM REQUEST ===');

function respond(int $status, bool $success, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

// Basic same-site protection. Some browsers/proxies omit Origin, so only reject when present and wrong.
$allowedHosts = ['panoramaadvisory.ca', 'www.panoramaadvisory.ca'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if (!$originHost || !in_array(strtolower($originHost), $allowedHosts, true)) {
        respond(403, false, 'Invalid origin.');
    }
}

// Honeypot: silently accept bots so they do not retry.
if (!empty($_POST['website_url'] ?? '')) {
    respond(200, true, 'OK');
}

$configFile = __DIR__ . '/smtp-config.php';
if (!is_file($configFile)) {
    respond(500, false, 'SMTP configuration missing.');
}
$config = require $configFile;
if (($config['password'] ?? '') === '' || ($config['password'] ?? '') === 'REPLACE_WITH_EMAIL_PASSWORD') {
    respond(500, false, 'SMTP password has not been configured.');
}

function clean_text(string $value, int $max = 2000): string {
    $value = trim(strip_tags($value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

$name = clean_text((string)($_POST['nom'] ?? ''), 160);
$organization = clean_text((string)($_POST['organisation'] ?? ''), 200);
$email = trim((string)($_POST['courriel'] ?? ''));
$phone = clean_text((string)($_POST['telephone'] ?? ''), 80);
$topic = clean_text((string)($_POST['objet'] ?? ''), 250);
$message = clean_text((string)($_POST['message'] ?? ''), 6000);
$language = (($_POST['language'] ?? '') === 'en') ? 'en' : 'fr';

if ($name === '' || $email === '' || $message === '') {
    respond(422, false, 'Please complete the required fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
    respond(422, false, 'Invalid email address.');
}

$subject = $language === 'en'
    ? 'New website inquiry — panoramaadvisory.ca'
    : 'Nouveau message — panoramaadvisory.ca';

$bodyLines = [
    $language === 'en' ? 'New contact form submission from panoramaadvisory.ca' : 'Nouveau message reçu depuis panoramaadvisory.ca',
    '',
    ($language === 'en' ? 'Name: ' : 'Nom : ') . $name,
    ($language === 'en' ? 'Organization: ' : 'Organisation : ') . ($organization !== '' ? $organization : '—'),
    ($language === 'en' ? 'Email: ' : 'Courriel : ') . $email,
    ($language === 'en' ? 'Phone: ' : 'Téléphone : ') . ($phone !== '' ? $phone : '—'),
    ($language === 'en' ? 'Subject: ' : 'Objet : ') . ($topic !== '' ? $topic : '—'),
    '',
    ($language === 'en' ? 'Message:' : 'Message :'),
    $message,
    '',
    '---',
    'Sent from Panorama Advisory website contact form.',
];
$body = implode("\r\n", $bodyLines);

function smtp_read($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $response;
}

function smtp_expect($socket, array $codes): string {
    $response = smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    smtp_debug('SMTP RESPONSE: ' . trim($response));
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error ' . $code . ': ' . trim($response));
    }
    return $response;
}

function smtp_cmd($socket, string $command, array $codes, bool $sensitive = false): string {
    smtp_debug('SMTP COMMAND: ' . ($sensitive ? '[AUTH DATA HIDDEN]' : $command));
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes);
}

function header_encode(string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

$host = (string)$config['host'];
$port = (int)$config['port'];
$username = (string)$config['username'];
$password = (string)$config['password'];
$fromEmail = (string)$config['from_email'];
$fromName = (string)$config['from_name'];
$recipients = $config['recipients'] ?? [];

if (!$recipients) respond(500, false, 'No recipients configured.');

$errno = 0;
$errstr = '';
smtp_debug('Connecting to SMTP host: ' . $host . ':' . $port);
smtp_debug('SMTP username: ' . $username);
smtp_debug('From address: ' . $fromEmail);
$socket = @stream_socket_client(
    'ssl://' . $host . ':' . $port,
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT
);
if (!$socket) {
    smtp_debug("SMTP CONNECTION FAILED: errno=$errno error=$errstr");
    error_log("Panorama SMTP connection failed: $errno $errstr");
    respond(502, false, 'Unable to connect to mail server.');
}
smtp_debug('SMTP CONNECTION SUCCESSFUL');
stream_set_timeout($socket, 15);

try {
    smtp_debug('Waiting for SMTP greeting...');
    smtp_expect($socket, [220]);
    $serverName = $_SERVER['SERVER_NAME'] ?? 'panoramaadvisory.ca';
    smtp_cmd($socket, 'EHLO ' . $serverName, [250]);
    smtp_cmd($socket, 'AUTH LOGIN', [334]);
    smtp_cmd($socket, base64_encode($username), [334], true);
    smtp_cmd($socket, base64_encode($password), [235], true);
    smtp_debug('SMTP AUTHENTICATION SUCCESSFUL');
    smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);

    foreach ($recipients as $recipient) {
        $to = $recipient['email'] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        smtp_debug('Adding recipient: ' . $to);
        smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    }

    smtp_cmd($socket, 'DATA', [354]);

    $toHeaderParts = [];
    foreach ($recipients as $recipient) {
        $to = $recipient['email'] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        $rname = trim((string)($recipient['name'] ?? ''));
        $toHeaderParts[] = $rname !== '' ? header_encode($rname) . ' <' . $to . '>' : $to;
    }

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . header_encode($fromName) . ' <' . $fromEmail . '>',
        'To: ' . implode(', ', $toHeaderParts),
        'Reply-To: ' . header_encode($name) . ' <' . $email . '>',
        'Subject: ' . header_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@panoramaadvisory.ca>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Panorama Advisory Website',
    ];

    // SMTP dot-stuffing.
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $payload = preg_replace('/(^|\r\n)\./', '$1..', $payload);
    fwrite($socket, $payload . "\r\n.\r\n");
    smtp_expect($socket, [250]);
    smtp_debug('MESSAGE ACCEPTED BY SMTP SERVER');
    smtp_cmd($socket, 'QUIT', [221]);
    fclose($socket);

    respond(200, true, 'Message sent.');
} catch (Throwable $e) {
    smtp_debug('SMTP FAILED: ' . $e->getMessage());
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
    error_log('Panorama SMTP error: ' . $e->getMessage());
    respond(502, false, 'Unable to send message.');
}
