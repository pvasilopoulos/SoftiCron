<?php

declare(strict_types=1);

// Prints fresh secrets for .env: APP_KEY and VAPID keys for Web Push.
// Usage: php bin/keygen.php

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use Minishlink\WebPush\VAPID;

App::boot(dirname(__DIR__));

echo 'APP_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . PHP_EOL;

try {
    $keys = VAPID::createVapidKeys();
    echo 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . PHP_EOL;
    echo 'VAPID_PRIVATE_KEY=' . $keys['privateKey'] . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create VAPID keys: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
