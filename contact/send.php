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

// Reject oversized automated submissions before doing any SMTP work.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 50000) {
    respond(413, false, 'Request too large.');
}

// Honeypots: silently accept obvious bots so they do not keep retrying.
if (!empty($_POST['website_url'] ?? '') || !empty($_POST['company_website'] ?? '')) {
    smtp_debug('SPAM BLOCKED: honeypot triggered');
    respond(200, true, 'OK');
}

// Legitimate visitors need a few seconds to read and complete the form.
// The timestamp is populated client-side when the page is mounted.
$formStarted = (int)($_POST['form_started'] ?? 0);
$elapsed = $formStarted > 0 ? time() - $formStarted : 0;
if ($formStarted <= 0 || $elapsed < 3) {
    smtp_debug('SPAM BLOCKED: form submitted too quickly or missing timing token');
    respond(200, true, 'OK');
}

// If Referer is present, require it to originate from Panorama.
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    $refererHost = parse_url($referer, PHP_URL_HOST);
    if (!$refererHost || !in_array(strtolower($refererHost), $allowedHosts, true)) {
        smtp_debug('SPAM BLOCKED: invalid referer');
        respond(403, false, 'Invalid request source.');
    }
}

// Lightweight IP rate limiting: max 8 form attempts per 15 minutes.
$clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$rateDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'panorama-contact-rate';
if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}
$rateFile = $rateDir . DIRECTORY_SEPARATOR . hash('sha256', $clientIp) . '.json';
$now = time();
$windowStart = $now - 900;
$attempts = [];
if (is_file($rateFile)) {
    $decoded = json_decode((string)@file_get_contents($rateFile), true);
    if (is_array($decoded)) {
        $attempts = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && $ts >= $windowStart));
    }
}
if (count($attempts) >= 8) {
    smtp_debug('SPAM BLOCKED: rate limit exceeded for IP hash ' . substr(hash('sha256', $clientIp), 0, 12));
    header('Retry-After: 900');
    respond(429, false, 'Too many attempts. Please try again later.');
}
$attempts[] = $now;
@file_put_contents($rateFile, json_encode($attempts), LOCK_EX);

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

// The public form only offers these topics. Reject injected values.
$allowedTopics = [
    'Appel d’offres ou approvisionnement',
    'Conseil et gestion',
    'Immobilier autochtone',
    'Courtage',
    'Autre',
    'Public tender or procurement',
    'Advisory and management',
    'Indigenous real estate',
    'Brokerage',
    'Other',
];
if ($topic !== '' && !in_array($topic, $allowedTopics, true)) {
    smtp_debug('SPAM BLOCKED: invalid topic');
    respond(422, false, 'Invalid subject.');
}

// Most legitimate inquiries contain zero or one link. Bulk-link messages are
// overwhelmingly automated spam, so quietly discard messages containing >2.
$urlCount = preg_match_all('~(?:https?://|www\.)[^\s<]+~iu', $message, $urlMatches);
if ($urlCount !== false && $urlCount > 2) {
    smtp_debug('SPAM BLOCKED: excessive URLs');
    respond(200, true, 'OK');
}

// Block a few high-confidence machine-generated patterns without penalizing
// normal business language.
$spamCorpus = mb_strtolower($name . ' ' . $organization . ' ' . $message);
$highConfidencePatterns = [
    '/\bseo\s+(?:service|services|agency|expert)\b/u',
    '/\bguest\s+post(?:ing)?\b/u',
    '/\bbacklinks?\b/u',
    '/\bcrypto(?:currency)?\s+(?:investment|promotion|offer)\b/u',
    '/\b(?:casino|gambling)\s+(?:links?|promotion|offer)\b/u',
];
$spamHits = 0;
foreach ($highConfidencePatterns as $pattern) {
    if (preg_match($pattern, $spamCorpus)) $spamHits++;
}
if ($spamHits >= 2) {
    smtp_debug('SPAM BLOCKED: high-confidence spam patterns');
    respond(200, true, 'OK');
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

// Safety recipients: always keep Panorama's principal mailbox and Avi copied
// even if the server-only smtp-config.php still contains an outdated recipient list.
$requiredRecipients = [
    ['name' => 'Benoit Loyer', 'email' => 'ben@panoramaadvisory.ca'],
];

$optionalRecipients = [
    ['name' => 'Avi Yansah', 'email' => 'aviyansah@gmail.com'],
];

$seenRecipients = [];
$normalizedRecipients = [];
foreach (array_merge($recipients, $requiredRecipients) as $recipient) {
    $to = strtolower(trim((string)($recipient['email'] ?? '')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || isset($seenRecipients[$to])) continue;
    $seenRecipients[$to] = true;
    $normalizedRecipients[] = [
        'name' => trim((string)($recipient['name'] ?? '')),
        'email' => $to,
    ];
}
$recipients = $normalizedRecipients;

if (!$recipients) respond(500, false, 'No recipients configured.');

$errno = 0;
$errstr = '';
smtp_debug('Connecting to SMTP host: ' . $host . ':' . $port);
smtp_debug('SMTP username: ' . $username);
smtp_debug('From address: ' . $fromEmail);
$transportHost = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
$socket = @stream_socket_client(
    $transportHost,
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
    $smtpStage = 'greeting';
    smtp_debug('Waiting for SMTP greeting...');
    smtp_expect($socket, [220]);
    $serverName = $_SERVER['SERVER_NAME'] ?? 'panoramaadvisory.ca';
    $smtpStage = 'EHLO';
    smtp_cmd($socket, 'EHLO ' . $serverName, [250]);

    // Port 587/25 requires STARTTLS; port 465 is implicit TLS.
    if ($port !== 465) {
        $smtpStage = 'STARTTLS';
        smtp_cmd($socket, 'STARTTLS', [220]);
        $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            throw new RuntimeException('Unable to enable TLS on SMTP connection.');
        }
        smtp_debug('SMTP STARTTLS ENABLED');
        $smtpStage = 'EHLO after STARTTLS';
        smtp_cmd($socket, 'EHLO ' . $serverName, [250]);
    }

    $smtpStage = 'AUTH LOGIN';
    smtp_cmd($socket, 'AUTH LOGIN', [334]);
    $smtpStage = 'AUTH username';
    smtp_cmd($socket, base64_encode($username), [334], true);
    $smtpStage = 'AUTH password';
    smtp_cmd($socket, base64_encode($password), [235], true);
    smtp_debug('SMTP AUTHENTICATION SUCCESSFUL');
    $smtpStage = 'MAIL FROM';
    smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);

    $acceptedRecipients = [];

    foreach ($recipients as $recipient) {
        $to = $recipient['email'] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        smtp_debug('Adding required recipient: ' . $to);
        $smtpStage = 'RCPT TO required recipient';
        smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        $acceptedRecipients[] = $recipient;
    }

    // External copy is best-effort only. Some Exchange SMTP relays reject
    // external recipients with 550 while still accepting local recipients.
    foreach ($optionalRecipients as $recipient) {
        $to = $recipient['email'] ?? '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        try {
            smtp_debug('Adding optional recipient: ' . $to);
            $smtpStage = 'RCPT TO optional recipient';
            smtp_cmd($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $acceptedRecipients[] = $recipient;
        } catch (Throwable $copyError) {
            smtp_debug('OPTIONAL RECIPIENT REJECTED: ' . $to . ' — ' . $copyError->getMessage());
        }
    }

    if (!$acceptedRecipients) {
        throw new RuntimeException('No recipients accepted by SMTP server.');
    }

    $smtpStage = 'DATA';
    smtp_cmd($socket, 'DATA', [354]);

    $toHeaderParts = [];
    foreach ($acceptedRecipients as $recipient) {
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
    $smtpStage = 'message body acceptance';
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
    $publicMessage = preg_match('/SMTP error (\d{3})/', $e->getMessage(), $m)
        ? 'Mail server rejected the message (SMTP ' . $m[1] . ' at ' . ($smtpStage ?? 'unknown stage') . ').'
        : 'Unable to send message through the mail server at ' . ($smtpStage ?? 'unknown stage') . '.';
    respond(502, false, $publicMessage);
}
