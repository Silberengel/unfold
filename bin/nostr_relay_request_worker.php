<?php

/**
 * One isolated Nostr REQ/EOSE over a single relay (used for parallel article discussion).
 * Invoked as: php bin/nostr_relay_request_worker.php <wss-url> <file-with-serialized-RequestMessage>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
if ($argc < 3) {
    fwrite(STDERR, "Usage: nostr_relay_request_worker.php <wss-url> <serialized-request-file>\n");
    exit(1);
}

$relayUrl = $argv[1];
$path = $argv[2];
if (!\is_file($path) || !\is_readable($path)) {
    fwrite(STDERR, "Payload file missing or unreadable\n");
    exit(1);
}

$root = \dirname(__DIR__);
require $root.'/vendor/autoload.php';

$raw = file_get_contents($path);
if ($raw === false || $raw === '') {
    exit(1);
}

try {
    /** @var mixed $msg */
    $msg = unserialize($raw, ['allowed_classes' => true]);
} catch (\Throwable $e) {
    fwrite(STDERR, 'unserialize: '.$e->getMessage()."\n");
    exit(1);
}

if (!\is_object($msg) || !($msg instanceof \swentel\nostr\Message\RequestMessage)) {
    fwrite(STDERR, "Invalid request message\n");
    exit(1);
}

$relaySet = new \swentel\nostr\Relay\RelaySet();
$relaySet->addRelay(new \swentel\nostr\Relay\Relay($relayUrl));
$request = new \swentel\nostr\Request\Request($relaySet, $msg);
$relayTimeout = (int) (getenv('NOSTR_RELAY_REQUEST_TIMEOUT') ?: 12);
if ($relayTimeout < 1) {
    $relayTimeout = 12;
}
if (method_exists($request, 'setTimeout')) {
    $request->setTimeout($relayTimeout);
}

try {
    $out = $request->send();
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, base64_encode(serialize($out)));
