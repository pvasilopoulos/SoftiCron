<?php

declare(strict_types=1);

// Creates (or promotes) a platform super-admin.
// Usage: php bin/create-superadmin.php email@example.gr "Full Name" [password]
// If the password is omitted a random one is generated and printed.

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;

App::boot(dirname(__DIR__));
$db = App::db();

$email = strtolower(trim($argv[1] ?? ''));
$name = trim($argv[2] ?? '');
$password = $argv[3] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "Usage: php bin/create-superadmin.php email \"Full Name\" [password]\n");
    exit(1);
}
$generated = false;
if ($password === '') {
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Ab'), '=');
    $generated = true;
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $db->one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    $db->update('users', ['is_super_admin' => 1, 'password_hash' => $hash, 'status' => 'active'], 'id = :id', ['id' => $existing['id']]);
    echo "Updated existing user #{$existing['id']} as super-admin.\n";
} else {
    $id = $db->insert('users', ['email' => $email, 'name' => $name, 'password_hash' => $hash, 'is_super_admin' => 1]);
    echo "Created super-admin #$id.\n";
}
if ($generated) {
    echo "Password: $password\n";
}
