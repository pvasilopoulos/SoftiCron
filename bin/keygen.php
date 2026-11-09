<?php

declare(strict_types=1);

// Generates secrets for .env: APP_KEY and the VAPID key pair for Web Push.
//
//   php bin/keygen.php                 print new keys (nothing is changed)
//   php bin/keygen.php --write         write into .env: VAPID keys always, APP_KEY only if it is empty
//   php bin/keygen.php --vapid --write replace only the VAPID keys in .env
//
// --write keeps a backup of the previous file as .env.bak-YYYYmmdd-HHMMSS and then
// checks that the VAPID keys can sign a Web Push request.
// Never replace an APP_KEY that is already in use: stored secrets would become unreadable.

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Core\Env;
use App\Services\Channels\PushSender;
use Minishlink\WebPush\VAPID;

$root = dirname(__DIR__);
App::boot($root);

$write = in_array('--write', $argv, true);
$vapidOnly = in_array('--vapid', $argv, true);

try {
    $vapid = VAPID::createVapidKeys();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create VAPID keys: ' . $e->getMessage() . PHP_EOL);
    while (($err = openssl_error_string()) !== false) {
        fwrite(STDERR, '  OpenSSL: ' . $err . PHP_EOL);
    }
    exit(1);
}
$appKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

if (!$write) {
    if (!$vapidOnly) {
        echo 'APP_KEY=' . $appKey . PHP_EOL;
    }
    echo 'VAPID_PUBLIC_KEY=' . $vapid['publicKey'] . PHP_EOL;
    echo 'VAPID_PRIVATE_KEY=' . $vapid['privateKey'] . PHP_EOL;
    echo PHP_EOL . 'Tip: php bin/keygen.php --write  (writes them into .env for you)' . PHP_EOL;
    exit(0);
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    if (!is_file($root . '/.env.example')) {
        fwrite(STDERR, ".env not found.\n");
        exit(1);
    }
    copy($root . '/.env.example', $envFile);
    echo "Created .env from .env.example\n";
}

$content = (string) file_get_contents($envFile);
$backup = $envFile . '.bak-' . date('Ymd-His');
file_put_contents($backup, $content);
@chmod($backup, 0600);

$set = function (string $key, string $value) use (&$content): void {
    $line = $key . '=' . $value;
    if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=.*$/m', $content)) {
        $content = (string) preg_replace('/^\s*' . preg_quote($key, '/') . '\s*=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1);
    } else {
        $content = rtrim($content, "\r\n") . PHP_EOL . $line . PHP_EOL;
    }
};

$currentAppKey = trim((string) preg_replace('/\s+#.*$/', '', (string) (preg_match('/^\s*APP_KEY\s*=(.*)$/m', $content, $m) ? $m[1] : '')));
if (!$vapidOnly) {
    if ($currentAppKey === '') {
        $set('APP_KEY', $appKey);
        echo "APP_KEY: new key written.\n";
    } else {
        echo "APP_KEY: already set, left unchanged.\n";
    }
}
$set('VAPID_PUBLIC_KEY', $vapid['publicKey']);
$set('VAPID_PRIVATE_KEY', $vapid['privateKey']);
if (!preg_match('/^\s*VAPID_SUBJECT\s*=\s*mailto:\S+@\S+/m', $content)) {
    $set('VAPID_SUBJECT', 'mailto:admin@' . (parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST) ?: 'example.com'));
}

file_put_contents($envFile, $content);
@chmod($envFile, 0600);
echo "VAPID keys written to .env (backup: " . basename($backup) . ")\n";

// Re-read the file and test signing with the new keys.
App::boot($root);
$err = PushSender::selfTest();
if ($err === null) {
    echo "Web Push self-test: OK\n";
    echo "Now open Notifications in the app, press Disable and then Enable on each device.\n";
    exit(0);
}
echo "Web Push self-test FAILED: $err\n";
exit(2);
