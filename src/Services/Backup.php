<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Services\Channels\EmailSender;

/**
 * Full tenant backups (jobs with their secrets, connectors, variables, templates, settings),
 * encrypted with a password chosen by the user, plus the detailed Excel report of the jobs.
 *
 * File format "softicron.backup/v1": a JSON envelope with readable metadata and the payload
 * gzip-compressed and encrypted with libsodium secretbox, using a key derived from the password
 * with Argon2id. Without the password the file is useless, even with the server's APP_KEY.
 */
final class Backup
{
    public const FORMAT = 'softicron.backup/v1';
    /** All tenants in one file (super admin). */
    public const PLATFORM_FORMAT = 'softicron.platform-backup/v1';
    /** Tenant columns carried by a platform backup (to recreate missing tenants on restore). */
    public const TENANT_FIELDS = ['name', 'slug', 'status', 'timezone', 'brand_color', 'max_jobs', 'min_interval_minutes', 'log_retention_days'];
    public const MIN_PASSWORD = 8;
    public const SECTIONS = [
        'jobs' => 'Jobs',
        'connectors' => 'Connectors',
        'variables' => 'Μεταβλητές & secrets',
        'templates' => 'Templates μηνυμάτων',
        'settings' => 'Ρυθμίσεις',
    ];
    public const SCHEDULES = ['off' => 'Ανενεργό', 'daily' => 'Κάθε μέρα', 'weekly' => 'Κάθε εβδομάδα'];
    public const WEEKDAYS = [1 => 'Δευτέρα', 2 => 'Τρίτη', 3 => 'Τετάρτη', 4 => 'Πέμπτη', 5 => 'Παρασκευή', 6 => 'Σάββατο', 7 => 'Κυριακή'];

    /** Settings that describe the state of the installation, not the configuration: never backed up or restored. */
    private static function internalSetting(string $k): bool
    {
        return str_starts_with($k, 'backup.') || str_ends_with($k, '_last') || str_ends_with($k, '.last');
    }

    /**
     * Pseudo-tenant 0 stands for the whole platform: its settings (tenant 0) hold the platform
     * backup configuration and its files live in storage/backups/platform.
     */
    public static function platform(): array
    {
        return ['id' => 0, 'name' => App::name(), 'timezone' => 'Europe/Athens', 'brand_color' => '#4F46E5', 'logo_path' => null];
    }

    public static function isPlatform(array $tenant): bool
    {
        return (int) $tenant['id'] === 0;
    }

    // ---- Full backup ------------------------------------------------------------

    /** Every tenant: its own row plus the same payload as a tenant backup. */
    public static function platformPayload(): array
    {
        $tenants = [];
        foreach (App::db()->all('SELECT * FROM tenants ORDER BY name') as $t) {
            $row = [];
            foreach (self::TENANT_FIELDS as $k) {
                $row[$k] = in_array($k, ['max_jobs', 'min_interval_minutes', 'log_retention_days'], true) ? (int) $t[$k] : $t[$k];
            }
            $tenants[] = ['tenant' => $row] + self::payload($t);
        }
        return ['tenants' => $tenants];
    }

    /** Everything needed to rebuild the tenant's configuration, secrets in plain text. */
    public static function payload(array $tenant): array
    {
        $db = App::db();
        $tid = (int) $tenant['id'];

        $connectors = [];
        $connById = [];
        foreach ($db->all('SELECT * FROM connectors WHERE tenant_id = ? ORDER BY type, name', [$tid]) as $c) {
            $connById[(int) $c['id']] = [$c['type'], $c['name']];
            try {
                $cfg = Connectors::config($c);
            } catch (\Throwable) {
                $cfg = [];
            }
            $connectors[] = ['type' => $c['type'], 'name' => $c['name'], 'is_default' => (bool) $c['is_default'], 'config' => (object) $cfg];
        }

        $templates = [];
        $tplById = [];
        foreach ($db->all('SELECT * FROM message_templates WHERE tenant_id = ? ORDER BY event, channel, name', [$tid]) as $t) {
            $tplById[(int) $t['id']] = ['name' => $t['name'], 'event' => $t['event'], 'channel' => $t['channel']];
            $templates[] = [
                'name' => $t['name'], 'event' => $t['event'], 'channel' => $t['channel'],
                'subject' => $t['subject'], 'body' => $t['body'], 'is_default' => (bool) $t['is_default'],
            ];
        }

        Variables::forget($tid);
        $variables = array_map(fn ($v) => [
            'name' => $v['name'], 'value' => $v['value'], 'is_secret' => (bool) $v['is_secret'], 'description' => $v['description'],
        ], Variables::all($tid));

        $settings = [];
        foreach (Settings::all($tid) as $k => $v) {
            if (!self::internalSetting((string) $k)) {
                $settings[$k] = $v;
            }
        }

        $emails = [];
        foreach ($db->all('SELECT u.id, u.email FROM users u JOIN memberships m ON m.user_id = u.id WHERE m.tenant_id = ?', [$tid]) as $u) {
            $emails[(int) $u['id']] = $u['email'];
        }
        $jobs = [];
        foreach ($db->all('SELECT * FROM jobs WHERE tenant_id = ? ORDER BY group_name IS NULL, group_name, name', [$tid]) as $j) {
            $item = JobIO::toArray($j, false);
            // Secret values, which the plain export leaves out.
            $item['headers'] = array_map(fn ($h) => [
                'name' => (string) $h['name'], 'value' => (string) $h['value'], 'secret' => !empty($h['secret']),
            ], HttpExecutor::headers($j));
            $item['auth'] = ['type' => $j['auth_type'] ?? 'none'] + JobAuth::config($j);
            $pushUsers = [];
            foreach ($db->column('SELECT user_id FROM job_push_targets WHERE job_id = ? AND user_id IS NOT NULL', [$j['id']]) as $uid) {
                if (isset($emails[(int) $uid])) {
                    $pushUsers[] = $emails[(int) $uid];
                }
            }
            $tpl = [];
            foreach ($db->all('SELECT event, channel, template_id FROM job_notifications WHERE job_id = ? AND template_id IS NOT NULL', [$j['id']]) as $n) {
                if (isset($tplById[(int) $n['template_id']])) {
                    $tpl[] = ['event' => $n['event'], 'channel' => $n['channel'], 'template' => $tplById[(int) $n['template_id']]];
                }
            }
            $item['_backup'] = [
                'id' => (int) $j['id'],
                'email_connector' => $connById[(int) $j['email_connector_id']] ?? null,
                'telegram_connector' => $connById[(int) $j['telegram_connector_id']] ?? null,
                'push_target' => $j['push_target'],
                'push_users' => $pushUsers,
                'schedule_ui' => $j['schedule_ui'],
                'ping_token' => $j['ping_token'],
                'templates' => $tpl,
                'created_at' => JobIO::iso($j['created_at']),
            ];
            $jobs[] = $item;
        }

        return [
            'tenant' => ['name' => $tenant['name'], 'timezone' => $tenant['timezone']],
            'jobs' => $jobs,
            'connectors' => $connectors,
            'variables' => $variables,
            'templates' => $templates,
            'settings' => (object) $settings,
        ];
    }

    public static function contents(array $payload): array
    {
        if (isset($payload['tenants'])) {
            $out = ['tenants' => count((array) $payload['tenants'])];
            foreach ((array) $payload['tenants'] as $t) {
                foreach (self::contents($t) as $k => $n) {
                    $out[$k] = ($out[$k] ?? 0) + $n;
                }
            }
            return $out;
        }
        $out = [];
        foreach (array_keys(self::SECTIONS) as $s) {
            $out[$s] = count((array) ($payload[$s] ?? []));
        }
        return $out;
    }

    /** @return string the backup file (JSON envelope) */
    public static function encrypt(array $payload, string $password, array $tenant): string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('Ο κωδικός του backup πρέπει να έχει τουλάχιστον ' . self::MIN_PASSWORD . ' χαρακτήρες.');
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $password, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = (string) gzencode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);
        return (string) json_encode([
            'format' => self::isPlatform($tenant) ? self::PLATFORM_FORMAT : self::FORMAT,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'app' => App::name(),
            'tenant' => self::isPlatform($tenant) ? 'Όλοι οι οργανισμοί' : $tenant['name'],
            'contents' => self::contents($payload),
            'note' => 'Κρυπτογραφημένο backup του SoftiCron. Επαναφορά από Backup & επαναφορά, με τον κωδικό που ορίστηκε κατά τη δημιουργία.',
            'encryption' => [
                'kdf' => 'argon2id13', 'opslimit' => $ops, 'memlimit' => $mem, 'salt' => base64_encode($salt),
                'cipher' => 'xsalsa20poly1305', 'compression' => 'gzip',
            ],
            'nonce' => base64_encode($nonce),
            'data' => base64_encode($cipher),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{0: array, 1: array} [envelope metadata, payload]
     * @throws \RuntimeException
     */
    public static function decrypt(string $raw, string $password): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $env = json_decode($raw, true);
        if (!is_array($env) || !in_array($env['format'] ?? '', [self::FORMAT, self::PLATFORM_FORMAT], true)) {
            throw new \RuntimeException('Το αρχείο δεν είναι backup του SoftiCron (για απλό JSON jobs χρησιμοποίησε την Εισαγωγή jobs).');
        }
        $enc = (array) ($env['encryption'] ?? []);
        $salt = base64_decode((string) ($enc['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($env['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($env['data'] ?? ''), true);
        $ops = (int) ($enc['opslimit'] ?? 0);
        $mem = (int) ($enc['memlimit'] ?? 0);
        if (!$salt || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || !$nonce || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !$cipher
            || $ops < 1 || $ops > 10 || $mem < 8192 || $mem > 268435456) {
            throw new \RuntimeException('Το αρχείο backup είναι κατεστραμμένο.');
        }
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $password, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new \RuntimeException('Λάθος κωδικός ή αλλοιωμένο αρχείο backup.');
        }
        $json = @gzdecode($plain);
        $payload = $json !== false ? json_decode($json, true) : null;
        if (!is_array($payload)) {
            throw new \RuntimeException('Το περιεχόμενο του backup δεν διαβάζεται.');
        }
        unset($env['data'], $env['nonce']);
        return [$env, $payload];
    }

    public static function filename(array $tenant, string $ext = 'json', string $kind = 'backup'): string
    {
        if (self::isPlatform($tenant)) {
            $kind = $kind === 'backup' ? 'platform' : $kind;
            $tenant['name'] = 'all';
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $tenant['name']))), '-');
        $local = new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens'));
        return 'softicron-' . $kind . '-' . ($slug !== '' ? mb_substr($slug, 0, 40) : 't' . $tenant['id']) . '-' . $local->format('Ymd-His') . '.' . $ext;
    }

    // ---- Restore ----------------------------------------------------------------

    /**
     * Restores the chosen sections. $mode: "skip" keeps what already exists (matched by name),
     * "update" overwrites it. Jobs go through the normal validation of the job form.
     *
     * @param callable $importJobs fn(array $items, string $mode, bool $paused, bool $dry, callable $after): array
     * @return array<int, array{0:string,1:string,2:string,3:string}> [section, label, created|updated|skipped|error, message]
     */
    public static function restore(array $tenant, array $payload, array $sections, string $mode, bool $paused, bool $dry, callable $importJobs): array
    {
        $db = App::db();
        $tid = (int) $tenant['id'];
        $log = [];
        $update = $mode === 'update';

        if (in_array('settings', $sections, true)) {
            $n = 0;
            foreach ((array) ($payload['settings'] ?? []) as $k => $v) {
                $k = (string) $k;
                if (self::internalSetting($k) || !preg_match('/^[a-z0-9_.-]{1,80}$/i', $k)) {
                    continue;
                }
                $cur = Settings::get($tid, $k);
                if ($cur !== null && !$update) {
                    continue;
                }
                if (!$dry && $cur !== $v) {
                    Settings::set($tid, $k, $v === null ? null : (string) $v);
                }
                $n++;
            }
            $log[] = ['settings', 'Ρυθμίσεις', $n ? ($update ? 'updated' : 'created') : 'skipped', $n . ' τιμές' . ($update ? '' : ' (μόνο όσες έλειπαν)')];
        }

        if (in_array('variables', $sections, true)) {
            foreach ((array) ($payload['variables'] ?? []) as $v) {
                $name = (string) ($v['name'] ?? '');
                if (!Variables::validName($name)) {
                    $log[] = ['variables', $name ?: '—', 'error', 'Μη έγκυρο όνομα μεταβλητής.'];
                    continue;
                }
                $row = $db->one('SELECT id FROM tenant_variables WHERE tenant_id = ? AND name = ?', [$tid, $name]);
                if ($row && !$update) {
                    $log[] = ['variables', $name, 'skipped', 'Υπάρχει ήδη.'];
                    continue;
                }
                $data = [
                    'value_enc' => Crypto::encrypt((string) ($v['value'] ?? '')),
                    'is_secret' => !empty($v['is_secret']) ? 1 : 0,
                    'description' => mb_substr((string) ($v['description'] ?? ''), 0, 255) ?: null,
                ];
                if (!$dry) {
                    $row ? $db->update('tenant_variables', $data, 'id = :id', ['id' => $row['id']]) : $db->insert('tenant_variables', $data + ['tenant_id' => $tid, 'name' => $name]);
                }
                $log[] = ['variables', $name, $row ? 'updated' : 'created', ''];
            }
            Variables::forget($tid);
        }

        if (in_array('connectors', $sections, true)) {
            foreach ((array) ($payload['connectors'] ?? []) as $c) {
                $type = (string) ($c['type'] ?? '');
                $name = mb_substr(trim((string) ($c['name'] ?? '')), 0, 120);
                if (!isset(Connectors::TYPES[$type]) || $name === '') {
                    $log[] = ['connectors', $name ?: '—', 'error', 'Άγνωστος τύπος connector.'];
                    continue;
                }
                $row = $db->one('SELECT id FROM connectors WHERE tenant_id = ? AND type = ? AND name = ?', [$tid, $type, $name]);
                if ($row && !$update) {
                    $log[] = ['connectors', $name, 'skipped', 'Υπάρχει ήδη.'];
                    continue;
                }
                $data = ['config_enc' => Crypto::encryptJson((array) ($c['config'] ?? []))];
                if (!$dry) {
                    $row
                        ? $db->update('connectors', $data, 'id = :id', ['id' => $row['id']])
                        : $db->insert('connectors', $data + ['tenant_id' => $tid, 'type' => $type, 'name' => $name, 'is_default' => !empty($c['is_default']) ? 1 : 0]);
                }
                $log[] = ['connectors', $name . ' (' . Connectors::TYPES[$type] . ')', $row ? 'updated' : 'created', ''];
            }
        }

        if (in_array('templates', $sections, true)) {
            foreach ((array) ($payload['templates'] ?? []) as $t) {
                $name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 120);
                $event = mb_substr((string) ($t['event'] ?? ''), 0, 30);
                $channel = (string) ($t['channel'] ?? '');
                if ($name === '' || $event === '' || !isset(Notifier::TEMPLATE_CHANNELS[$channel])) {
                    $log[] = ['templates', $name ?: '—', 'error', 'Μη έγκυρο template.'];
                    continue;
                }
                $row = $db->one('SELECT id FROM message_templates WHERE tenant_id = ? AND name = ? AND event = ? AND channel = ?', [$tid, $name, $event, $channel]);
                if ($row && !$update) {
                    $log[] = ['templates', $name, 'skipped', 'Υπάρχει ήδη.'];
                    continue;
                }
                $data = ['subject' => mb_substr((string) ($t['subject'] ?? ''), 0, 255) ?: null, 'body' => (string) ($t['body'] ?? '')];
                if (!$dry) {
                    $row
                        ? $db->update('message_templates', $data, 'id = :id', ['id' => $row['id']])
                        : $db->insert('message_templates', $data + ['tenant_id' => $tid, 'name' => $name, 'event' => $event, 'channel' => $channel, 'is_default' => !empty($t['is_default']) ? 1 : 0]);
                }
                $log[] = ['templates', $name . ' · ' . (Notifier::EVENTS[$event] ?? $event), $row ? 'updated' : 'created', ''];
            }
        }

        if (in_array('jobs', $sections, true)) {
            $items = [];
            $members = [];
            foreach ($db->all('SELECT u.id, LOWER(u.email) AS email FROM users u JOIN memberships m ON m.user_id = u.id WHERE m.tenant_id = ?', [$tid]) as $u) {
                $members[$u['email']] = (int) $u['id'];
            }
            $connId = function (?array $ref) use ($db, $tid): string {
                if (!$ref || count($ref) !== 2) {
                    return '';
                }
                return (string) ($db->value('SELECT id FROM connectors WHERE tenant_id = ? AND type = ? AND name = ? ORDER BY id LIMIT 1', [$tid, (string) $ref[0], (string) $ref[1]]) ?? '');
            };
            foreach ((array) ($payload['jobs'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $b = (array) ($item['_backup'] ?? []);
                $users = [];
                foreach ((array) ($b['push_users'] ?? []) as $email) {
                    if (isset($members[strtolower((string) $email)])) {
                        $users[] = (string) $members[strtolower((string) $email)];
                    }
                }
                $item['_input'] = [
                    'email_connector_id' => $connId($b['email_connector'] ?? null),
                    'telegram_connector_id' => $connId($b['telegram_connector'] ?? null),
                    'schedule_ui' => (string) ($b['schedule_ui'] ?? ''),
                    'push_users' => $users,
                    'push_devices' => [],
                    // Selected recipients that are not members here: fall back to everyone.
                    'push_target' => ($b['push_target'] ?? 'all') === 'selected' && $users ? 'selected' : 'all',
                ];
                $items[] = $item;
            }
            $byName = [];
            foreach ($items as $item) {
                $byName[mb_substr(trim((string) ($item['name'] ?? '')), 0, 150)] = $item;
            }
            $after = function (int $id, array $item) use ($db, $tid): void {
                $b = (array) ($item['_backup'] ?? []);
                // Heartbeat jobs keep their ping URL, so the scripts that call it keep working.
                $token = (string) ($b['ping_token'] ?? '');
                if (preg_match('/^[a-f0-9]{32}$/', $token) && !$db->value('SELECT 1 FROM jobs WHERE ping_token = ? AND id <> ?', [$token, $id])) {
                    $db->exec('UPDATE jobs SET ping_token = ? WHERE id = ?', [$token, $id]);
                }
                foreach ((array) ($b['templates'] ?? []) as $t) {
                    $ref = (array) ($t['template'] ?? []);
                    $tplId = $db->value('SELECT id FROM message_templates WHERE tenant_id = ? AND name = ? AND event = ? AND channel = ? ORDER BY id LIMIT 1', [$tid, (string) ($ref['name'] ?? ''), (string) ($ref['event'] ?? ''), (string) ($ref['channel'] ?? '')]);
                    if ($tplId) {
                        $db->exec('UPDATE job_notifications SET template_id = ? WHERE job_id = ? AND event = ? AND channel = ?', [$tplId, $id, (string) ($t['event'] ?? ''), (string) ($t['channel'] ?? '')]);
                    }
                }
            };
            foreach ($importJobs($items, $update ? 'update' : 'skip', $paused, $dry, $after) as $r) {
                $log[] = ['jobs', $r[0], $r[1], $r[2]];
            }
        }
        return $log;
    }

    /**
     * Restores tenants from a platform backup, matched by slug. $only: a slug, or '' for all.
     * Missing tenants are created when $createMissing is set. Runs as each tenant in turn
     * (Auth::useTenant), so jobs go through the same validation as in the tenant's own restore.
     *
     * @param callable $importJobs fn(array $tenant, array $items, string $mode, bool $paused, bool $dry, callable $after): array
     * @return array<int, array{0:string,1:string,2:string,3:string,4:string}> [tenant, section, label, status, message]
     */
    public static function restorePlatform(array $payload, string $only, bool $createMissing, array $sections, string $mode, bool $paused, bool $dry, callable $importJobs): array
    {
        $db = App::db();
        $log = [];
        $before = \App\Auth\Auth::tenant();
        $found = false;
        try {
            foreach ((array) ($payload['tenants'] ?? []) as $entry) {
                $row = (array) ($entry['tenant'] ?? []);
                $slug = (string) ($row['slug'] ?? '');
                $name = (string) ($row['name'] ?? $slug);
                if ($slug === '' || ($only !== '' && $slug !== $only)) {
                    continue;
                }
                $found = true;
                $tenant = $db->one('SELECT * FROM tenants WHERE slug = ?', [$slug]);
                if (!$tenant) {
                    if (!$createMissing) {
                        $log[] = [$name, 'tenant', $name, 'skipped', 'Δεν υπάρχει οργανισμός με slug «' . $slug . '».'];
                        continue;
                    }
                    if ($dry) {
                        $log[] = [$name, 'tenant', $name, 'created', 'Θα δημιουργηθεί ο οργανισμός και θα επαναφερθεί όλο το περιεχόμενό του.'];
                        continue;
                    }
                    $data = [];
                    foreach (self::TENANT_FIELDS as $k) {
                        if (isset($row[$k])) {
                            $data[$k] = $row[$k];
                        }
                    }
                    $id = Tenants::create($data);
                    if (($row['status'] ?? 'active') === 'suspended') {
                        $db->update('tenants', ['status' => 'suspended'], 'id = :id', ['id' => $id]);
                    }
                    Audit::log('tenant.created', 'tenant', $id, $name . ' (επαναφορά από backup πλατφόρμας)', null, $data, $id);
                    $tenant = $db->one('SELECT * FROM tenants WHERE id = ?', [$id]);
                    $log[] = [$name, 'tenant', $name, 'created', 'Ο οργανισμός δημιουργήθηκε· πρόσθεσε μέλη από τους Χρήστες.'];
                }
                \App\Auth\Auth::useTenant($tenant);
                $items = self::restore($tenant, $entry, $sections, $mode, $paused, $dry, fn (array $i, string $m, bool $p, bool $d, callable $after) => $importJobs($tenant, $i, $m, $p, $d, $after));
                foreach ($items as $r) {
                    $log[] = [$tenant['name'], ...$r];
                }
            }
        } finally {
            \App\Auth\Auth::useTenant($before);
        }
        if (!$found) {
            $log[] = ['—', 'tenant', $only, 'error', 'Ο οργανισμός δεν υπάρχει στο backup.'];
        }
        return $log;
    }
    // ---- Detailed Excel report --------------------------------------------------

    /** Rows of every report sheet for one tenant. */
    private static function sheets(array $tenant, string $group = ''): array
    {
        $db = App::db();
        $tid = (int) $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens');
        $fmt = fn (?string $utc) => $utc ? (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i') : '';
        $sql = 'SELECT * FROM jobs WHERE tenant_id = ?';
        $p = [$tid];
        if ($group === '__none') {
            $sql .= ' AND group_name IS NULL';
        } elseif ($group !== '') {
            $sql .= ' AND group_name = ?';
            $p[] = $group;
        }
        $jobs = $db->all($sql . ' ORDER BY group_name IS NULL, group_name, name', $p);

        $stats = [];
        foreach ($db->all(
            "SELECT job_id, COUNT(*) AS runs, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed, AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms
             FROM job_runs WHERE tenant_id = ? AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) >= UTC_TIMESTAMP() - INTERVAL 30 DAY
             GROUP BY job_id",
            [$tid],
        ) as $s) {
            $stats[(int) $s['job_id']] = $s;
        }
        $users = [];
        foreach ($db->all('SELECT u.id, u.name, u.email FROM users u JOIN memberships m ON m.user_id = u.id WHERE m.tenant_id = ?', [$tid]) as $u) {
            $users[(int) $u['id']] = $u['name'] ?: $u['email'];
        }
        $conns = [];
        foreach ($db->all('SELECT id, name, type FROM connectors WHERE tenant_id = ?', [$tid]) as $c) {
            $conns[(int) $c['id']] = $c['name'] . ' (' . (Connectors::TYPES[$c['type']] ?? $c['type']) . ')';
        }
        $tpls = [];
        foreach ($db->all('SELECT id, name FROM message_templates WHERE tenant_id = ?', [$tid]) as $t) {
            $tpls[(int) $t['id']] = $t['name'];
        }
        $yesNo = fn ($v) => $v ? 'Ναι' : 'Όχι';
        $mask = '••••••';

        $main = $notify = $checks = $headers = $upcoming = [];
        foreach ($jobs as $j) {
            $id = (int) $j['id'];
            $isHttp = $j['type'] === 'http';
            $hdrs = HttpExecutor::headers($j);
            $hdrText = implode("\n", array_map(fn ($h) => $h['name'] . ': ' . (!empty($h['secret']) ? $mask : $h['value']), $hdrs));
            foreach ($hdrs as $h) {
                $headers[] = [$j['name'], $j['group_name'], $h['name'], !empty($h['secret']) ? $mask : $h['value'], $yesNo(!empty($h['secret']))];
            }
            $authType = $j['auth_type'] ?? 'none';
            $authParts = [JobAuth::TYPES[$authType] ?? $authType];
            if ($authType !== 'none') {
                $cfg = JobAuth::config($j);
                foreach (JobAuth::FIELDS[$authType] ?? [] as $k => [$label, , $secret]) {
                    $authParts[] = $label . ': ' . ($secret ? (($cfg[$k] ?? '') !== '' ? $mask : '') : ($cfg[$k] ?? ''));
                }
            }
            $rules = [];
            foreach ($db->all('SELECT event, channel, template_id FROM job_notifications WHERE job_id = ? ORDER BY event, channel', [$id]) as $n) {
                $rules[$n['event']][] = Notifier::CHANNELS[$n['channel']] ?? $n['channel'];
                $notify[] = [$j['name'], $j['group_name'], Notifier::EVENTS[$n['event']] ?? $n['event'], Notifier::CHANNELS[$n['channel']] ?? $n['channel'], $n['template_id'] ? ($tpls[(int) $n['template_id']] ?? '') : 'Προεπιλογή'];
            }
            $ruleText = implode("\n", array_map(fn ($ev, $chs) => (Notifier::EVENTS[$ev] ?? $ev) . ': ' . implode(', ', $chs), array_keys($rules), $rules));
            $asserts = Assertions::decode($j['assertions'] ?? null);
            foreach ($asserts as $a) {
                $checks[] = [$j['name'], $j['group_name'], Assertions::label($a), (string) ($a['type'] ?? ''), (string) ($a['target'] ?? ''), (string) ($a['op'] ?? ''), (string) ($a['value'] ?? '')];
            }
            $push = '';
            if ($j['push_target'] === 'selected') {
                $names = [];
                foreach ($db->column('SELECT user_id FROM job_push_targets WHERE job_id = ? AND user_id IS NOT NULL', [$id]) as $uid) {
                    $names[] = $users[(int) $uid] ?? '#' . $uid;
                }
                $devs = (int) $db->value('SELECT COUNT(*) FROM job_push_targets WHERE job_id = ? AND subscription_id IS NOT NULL', [$id]);
                $push = 'Επιλεγμένοι: ' . implode(', ', $names) . ($devs ? ($names ? ' + ' : '') . $devs . ' συσκευές' : '');
            } else {
                $push = 'Όλα τα μέλη';
            }
            $describe = '';
            if ($isHttp && $j['cron_expr']) {
                try {
                    $describe = CronHelper::describe((string) $j['cron_expr']);
                } catch (\Throwable) {
                }
                if ($j['enabled']) {
                    try {
                        foreach (CronHelper::nextRuns((string) $j['cron_expr'], $j['timezone'] ?: $tz->getName(), 5, $j['active_from'], $j['active_until']) as $i => $dt) {
                            $upcoming[] = [$j['name'], $j['group_name'], $i + 1, $dt->setTimezone($tz)->format('d/m/Y H:i'), self::WEEKDAYS[(int) $dt->setTimezone($tz)->format('N')]];
                        }
                    } catch (\Throwable) {
                    }
                }
            }
            $s = $stats[$id] ?? null;
            $runs = (int) ($s['runs'] ?? 0);
            $main[] = [
                $id, $j['name'], $j['group_name'], $j['description'],
                $isHttp ? 'HTTP' : 'Heartbeat', $yesNo($j['enabled']),
                $j['last_status'] ? (RUN_STATUS[$j['last_status']][0] ?? $j['last_status']) : 'Δεν έχει τρέξει',
                $j['acked_at'] ? 'Ανέλαβε: ' . $j['acked_name'] . ($j['ack_note'] ? ' — ' . $j['ack_note'] : '') : '',
                $isHttp ? $j['cron_expr'] : '', $describe, $j['timezone'],
                $j['active_from'] ? date('d/m/Y', strtotime($j['active_from'])) : '', $j['active_until'] ? date('d/m/Y', strtotime($j['active_until'])) : '',
                $isHttp ? $j['method'] : '', $isHttp ? $j['url'] : '',
                $isHttp && $j['body_type'] !== 'none' ? $j['body_type'] : '', $isHttp ? (string) $j['body'] : '',
                $hdrText, implode("\n", array_filter($authParts)),
                $isHttp ? (int) $j['timeout_sec'] : null, $isHttp ? (int) $j['max_retries'] : null,
                $isHttp && $j['max_retries'] ? ($j['retry_strategy'] === 'fixed' ? 'Σταθερή' : 'Εκθετική') . ' · ' . (int) $j['retry_delay_sec'] . ' s' : '',
                $isHttp ? ['skip' => 'Παράλειψη', 'queue' => 'Αναμονή', 'allow' => 'Επιτρέπεται'][$j['overlap_policy']] ?? $j['overlap_policy'] : '',
                $isHttp ? $j['success_codes'] : '', $isHttp ? (string) $j['success_contains'] : '',
                implode("\n", array_map([Assertions::class, 'label'], $asserts)),
                $j['slow_threshold_ms'] !== null ? (int) $j['slow_threshold_ms'] : null,
                $isHttp ? null : (int) $j['grace_minutes'],
                !$isHttp && $j['ping_token'] ? abs_url('ping/' . $j['ping_token']) : '',
                $ruleText, (int) $j['notify_after_failures'],
                $j['escalate_after_minutes'] !== null ? (int) $j['escalate_after_minutes'] : null,
                implode(', ', array_map(fn ($c) => Notifier::CHANNELS[$c] ?? $c, array_filter(explode(',', (string) $j['escalate_channels'])))),
                (string) $j['notify_emails'], (string) $j['notify_phones'], (string) $j['notify_telegram_chat'],
                $j['email_connector_id'] ? ($conns[(int) $j['email_connector_id']] ?? '') : 'Προεπιλογή',
                $j['telegram_connector_id'] ? ($conns[(int) $j['telegram_connector_id']] ?? '') : 'Προεπιλογή',
                $push,
                $j['sla_target'] !== null ? (float) $j['sla_target'] : null,
                $j['show_on_status'] ? 'Ναι' . ($j['status_name'] ? ' · ' . $j['status_name'] : '') : 'Όχι',
                $fmt($j['last_run_at']), $j['last_duration_ms'] !== null ? (int) $j['last_duration_ms'] : null, $j['enabled'] ? $fmt($j['next_run_at']) : '',
                $runs, (int) ($s['ok'] ?? 0), (int) ($s['failed'] ?? 0),
                $runs ? round(100 * (int) $s['ok'] / $runs, 2) : null,
                $s && $s['avg_ms'] !== null ? (int) round((float) $s['avg_ms']) : null, $s && $s['max_ms'] !== null ? (int) $s['max_ms'] : null,
                $fmt($j['created_at']), $users[(int) $j['created_by']] ?? '', $fmt($j['updated_at']),
            ];
        }

        $header = [
            'ID', 'Όνομα', 'Ομάδα', 'Περιγραφή', 'Τύπος', 'Ενεργό', 'Τελευταία κατάσταση', 'Ανάληψη συμβάντος',
            'Cron', 'Πρόγραμμα (περιγραφή)', 'Ζώνη ώρας', 'Ενεργό από', 'Ενεργό έως',
            'Μέθοδος', 'URL', 'Τύπος body', 'Body', 'Headers', 'Αυθεντικοποίηση',
            'Timeout (s)', 'Επαναλήψεις', 'Στρατηγική επανάληψης', 'Επικάλυψη', 'Κωδικοί επιτυχίας', 'Περιέχει κείμενο',
            'Έλεγχοι απάντησης', 'Όριο αργής απόκρισης (ms)', 'Grace (λεπτά)', 'Ping URL',
            'Ειδοποιήσεις', 'Ειδοποίηση μετά από αποτυχίες', 'Κλιμάκωση μετά (λεπτά)', 'Κανάλια κλιμάκωσης',
            'Emails', 'Τηλέφωνα', 'Telegram chat', 'Email connector', 'Telegram connector', 'Push παραλήπτες',
            'Στόχος SLA (%)', 'Σελίδα κατάστασης',
            'Τελευταία εκτέλεση', 'Διάρκεια τελευταίας (ms)', 'Επόμενη εκτέλεση',
            'Εκτελέσεις 30 ημ.', 'Επιτυχίες 30 ημ.', 'Αποτυχίες 30 ημ.', 'Ποσοστό επιτυχίας 30 ημ. (%)', 'Μέσος χρόνος 30 ημ. (ms)', 'Μέγιστος χρόνος 30 ημ. (ms)',
            'Δημιουργήθηκε', 'Δημιουργός', 'Τελευταία αλλαγή',
        ];
        $widths = [6, 30, 18, 30, 10, 8, 16, 24, 14, 30, 16, 12, 12, 9, 48, 10, 36, 36, 28, 10, 11, 20, 12, 14, 18, 40, 14, 10, 40, 36, 12, 12, 18, 28, 20, 16, 26, 26, 26, 10, 18, 17, 12, 17, 11, 11, 11, 14, 14, 14, 17, 18, 17];

        return [
            'header' => $header, 'widths' => $widths, 'tz' => $tz,
            'main' => $main, 'notify' => $notify, 'checks' => $checks, 'headers' => $headers, 'upcoming' => $upcoming,
            'totals' => [
                'jobs' => count($jobs),
                'active' => count(array_filter($jobs, fn ($j) => $j['enabled'])),
                'paused' => count(array_filter($jobs, fn ($j) => !$j['enabled'])),
                'failing' => count(array_filter($jobs, fn ($j) => $j['failing_since'])),
                'runs' => array_sum(array_map(fn ($s) => (int) $s['runs'], $stats)),
                'failed' => array_sum(array_map(fn ($s) => (int) $s['failed'], $stats)),
            ],
        ];
    }

    private const SECRET_NOTE = 'Οι τιμές των secrets (secret headers, κωδικοί, tokens) εμφανίζονται ως ••••••. Για πλήρη επαναφορά χρησιμοποίησε το κρυπτογραφημένο backup.';

    /** @return array{0:string,1:string,2:string} [filename, contents, mime type] */
    public static function report(array $tenant, string $group = ''): array
    {
        $d = self::sheets($tenant, $group);
        $tz = $d['tz'];
        $t = $d['totals'];
        $summary = [
            ['Οργανισμός', $tenant['name']],
            ['Δημιουργήθηκε', (new \DateTimeImmutable('now', $tz))->format('d/m/Y H:i') . ' (' . $tz->getName() . ')'],
            ['Ομάδα', $group === '' ? 'Όλες' : ($group === '__none' ? 'Χωρίς ομάδα' : $group)],
            ['Jobs', $t['jobs']], ['Ενεργά', $t['active']], ['Σε παύση', $t['paused']], ['Αποτυγχάνουν τώρα', $t['failing']],
            ['Εκτελέσεις 30 ημερών', $t['runs']], ['Αποτυχίες 30 ημερών', $t['failed']],
            ['Σημείωση', self::SECRET_NOTE],
        ];
        return self::workbook($tenant, $d['header'], $d['widths'], $d, ['Στοιχείο', 'Τιμή'], $summary, [24, 90], $tz->getName());
    }

    /** One workbook with every tenant: each sheet gets a leading "Οργανισμός" column. */
    public static function platformReport(): array
    {
        $all = ['main' => [], 'notify' => [], 'checks' => [], 'headers' => [], 'upcoming' => []];
        $summary = [];
        $header = $widths = null;
        foreach (App::db()->all('SELECT * FROM tenants ORDER BY name') as $tenant) {
            $d = self::sheets($tenant);
            $header ??= array_merge(['Οργανισμός'], $d['header']);
            $widths ??= array_merge([24], $d['widths']);
            foreach (array_keys($all) as $k) {
                foreach ($d[$k] as $row) {
                    $all[$k][] = array_merge([$tenant['name']], $row);
                }
            }
            $t = $d['totals'];
            $summary[] = [
                $tenant['name'], $tenant['slug'], $tenant['status'] === 'active' ? 'Ενεργός' : 'Σε αναστολή', $tenant['timezone'],
                $t['jobs'], $t['active'], $t['paused'], $t['failing'], $t['runs'], $t['failed'],
                $t['runs'] ? round(100 * ($t['runs'] - $t['failed']) / $t['runs'], 2) : null, (int) $tenant['max_jobs'],
            ];
        }
        $tz = new \DateTimeZone('Europe/Athens');
        $summaryHeader = ['Οργανισμός', 'Slug', 'Κατάσταση', 'Ζώνη ώρας', 'Jobs', 'Ενεργά', 'Σε παύση', 'Αποτυγχάνουν τώρα', 'Εκτελέσεις 30 ημ.', 'Αποτυχίες 30 ημ.', 'Επιτυχία 30 ημ. (%)', 'Όριο jobs'];
        $summary[] = ['Σύνολο', '', '', '', ...array_map(fn ($i) => array_sum(array_column($summary, $i)), [4, 5, 6, 7, 8, 9]), null, null];
        $summary[] = ['Δημιουργήθηκε ' . (new \DateTimeImmutable('now', $tz))->format('d/m/Y H:i') . '. ' . self::SECRET_NOTE];
        return self::workbook(self::platform(), $header ?? ['Οργανισμός'], $widths ?? [24], $all, $summaryHeader, $summary, [28, 18, 13, 16, 8, 8, 9, 12, 12, 12, 13, 10], 'ώρα κάθε οργανισμού', 'Οργανισμοί', true);
    }

    private static function workbook(array $tenant, array $header, array $widths, array $d, array $summaryHeader, array $summary, array $summaryWidths, string $tzLabel, string $summaryName = 'Σύνοψη', bool $withTenant = false): array
    {
        $pre = $withTenant ? ['Οργανισμός'] : [];
        $preW = $withTenant ? [24] : [];
        if (!XlsxWriter::available()) {
            // Fallback without ext-zip: the main sheet as CSV (UTF-8 with BOM so Excel shows Greek correctly).
            $fh = fopen('php://temp', 'w+');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $header, ';');
            foreach ($d['main'] as $row) {
                fputcsv($fh, array_map(fn ($v) => $v === null ? '' : (string) $v, $row), ';');
            }
            rewind($fh);
            return [self::filename($tenant, 'csv', 'jobs'), (string) stream_get_contents($fh), 'text/csv; charset=utf-8'];
        }
        $x = new XlsxWriter();
        if ($withTenant) {
            $x->addSheet($summaryName, $summaryHeader, $summary, $summaryWidths);
        }
        $x->addSheet('Jobs', $header, $d['main'], $widths)
            ->addSheet('Ειδοποιήσεις', [...$pre, 'Job', 'Ομάδα', 'Γεγονός', 'Κανάλι', 'Template'], $d['notify'], [...$preW, 32, 18, 26, 14, 26])
            ->addSheet('Έλεγχοι απάντησης', [...$pre, 'Job', 'Ομάδα', 'Έλεγχος', 'Τύπος', 'Στόχος', 'Τελεστής', 'Τιμή'], $d['checks'], [...$preW, 32, 18, 44, 10, 24, 12, 24])
            ->addSheet('Headers', [...$pre, 'Job', 'Ομάδα', 'Header', 'Τιμή', 'Secret'], $d['headers'], [...$preW, 32, 18, 26, 48, 8])
            ->addSheet('Επόμενες εκτελέσεις', [...$pre, 'Job', 'Ομάδα', '#', 'Ώρα (' . $tzLabel . ')', 'Ημέρα'], $d['upcoming'], [...$preW, 32, 18, 5, 20, 14]);
        if (!$withTenant) {
            $x->addSheet($summaryName, $summaryHeader, $summary, $summaryWidths);
        }
        return [self::filename($tenant, 'xlsx', 'jobs'), $x->build(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }
    // ---- Stored and automatic backups -------------------------------------------

    public static function dir(int $tenantId): string
    {
        return App::$root . '/storage/backups/' . ($tenantId === 0 ? 'platform' : $tenantId);
    }

    /** @return array<int, array{name:string,size:int,time:int}> newest first */
    public static function files(int $tenantId): array
    {
        $out = [];
        foreach (glob(self::dir($tenantId) . '/softicron-' . ($tenantId === 0 ? 'platform' : 'backup') . '-*.json') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Full path of a stored backup, or null when the name is not one of ours. */
    public static function path(int $tenantId, string $name): ?string
    {
        if (!preg_match('/^softicron-' . ($tenantId === 0 ? 'platform' : 'backup') . '-[a-z0-9-]+-\d{8}-\d{6}\.json$/', $name)) {
            return null;
        }
        $p = self::dir($tenantId) . '/' . $name;
        return is_file($p) ? $p : null;
    }

    public static function password(int $tenantId): ?string
    {
        $enc = Settings::get($tenantId, 'backup.password_enc');
        if (!$enc) {
            return null;
        }
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Writes an encrypted backup to the server (with the stored password), applies the
     * retention and emails it when recipients are set. Returns the file name.
     */
    public static function store(array $tenant, bool $email = true): string
    {
        $tid = (int) $tenant['id'];
        $password = self::password($tid) ?? throw new \RuntimeException('Δεν έχει οριστεί κωδικός για τα αποθηκευμένα backup.');
        $dir = self::dir($tid);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Δεν ήταν δυνατή η δημιουργία του φακέλου storage/backups.');
        }
        $payload = self::isPlatform($tenant) ? self::platformPayload() : self::payload($tenant);
        $name = self::filename($tenant);
        $file = self::encrypt($payload, $password, $tenant);
        if (file_put_contents($dir . '/' . $name, $file) === false) {
            throw new \RuntimeException('Δεν ήταν δυνατή η αποθήκευση του backup.');
        }
        @chmod($dir . '/' . $name, 0600);
        self::prune($tid, max(1, (int) (Settings::get($tid, 'backup.keep') ?: 14)));
        Settings::set($tid, 'backup.last_file', $name);
        Settings::set($tid, 'backup.last_error', null);
        if ($email && trim((string) Settings::get($tid, 'backup.emails')) !== '') {
            try {
                self::email($tenant, $name, $file, self::contents($payload));
            } catch (\Throwable $e) {
                throw new \RuntimeException('Το backup αποθηκεύτηκε, αλλά η αποστολή με email απέτυχε: ' . $e->getMessage(), 0, $e);
            }
        }
        return $name;
    }

    public static function prune(int $tenantId, int $keep): void
    {
        foreach (array_slice(self::files($tenantId), $keep) as $f) {
            @unlink(self::dir($tenantId) . '/' . $f['name']);
        }
    }

    /** @throws \RuntimeException */
    public static function email(array $tenant, string $name, string $file, array $contents): void
    {
        $tid = (int) $tenant['id'];
        $platform = self::isPlatform($tenant);
        $to = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) Settings::get($tid, 'backup.emails')) ?: [])));
        if ($platform) {
            if (!EmailSender::platformConfigured()) {
                throw new \RuntimeException('Δεν έχει ρυθμιστεί ο platform mailer (MAIL_HOST / MAIL_FROM στο .env).');
            }
            $conn = null;
        } else {
            $conn = Connectors::default($tid, 'email') ?? throw new \RuntimeException('Δεν υπάρχει email connector για την αποστολή του backup.');
        }
        $attachments = [$name => [$file, 'application/json']];
        if (Settings::get($tid, 'backup.attach_report') === '1') {
            [$rName, $rData, $rMime] = $platform ? self::platformReport() : self::report($tenant);
            $attachments[$rName] = [$rData, $rMime];
        }
        $size = array_sum(array_map(fn ($a) => strlen($a[0]), $attachments));
        if ($conn && $size > 3 * 1024 * 1024 && $conn['type'] === 'm365') {
            throw new \RuntimeException('Το backup είναι μεγαλύτερο από 3 MB, το όριο συνημμένων του Microsoft 365.');
        }
        $local = (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens')))->format('d/m/Y H:i');
        $title = 'Backup · ' . $tenant['name'] . ' · ' . $local;
        $lines = [];
        foreach (self::SECTIONS as $k => $label) {
            $lines[] = $label . ': ' . (int) ($contents[$k] ?? 0);
        }
        if ($platform) {
            $title = 'Backup πλατφόρμας · ' . $local;
            array_unshift($lines, 'Οργανισμοί: ' . (int) ($contents['tenants'] ?? 0));
        }
        $text = ($platform ? "Συνημμένο το backup όλων των οργανισμών ({$local})." : "Συνημμένο το backup του οργανισμού «{$tenant['name']}» ({$local}).") . "\n\n" . implode("\n", $lines)
            . "\n\nΤο αρχείο είναι κρυπτογραφημένο με τον κωδικό των αποθηκευμένων backup. Επαναφορά από " . ($platform ? 'Backup πλατφόρμας.' : 'Backup & επαναφορά.');
        $html = Notifier::emailHtml($tenant, $title, $text, abs_url($platform ? 'admin/backup' : 'backup'), null);
        if ($platform) {
            EmailSender::sendPlatform($to, $title, $html, $text, $attachments);
        } else {
            EmailSender::send($conn['type'], Connectors::config($conn), $to, $title, $html, $text, $attachments);
        }
    }

    /** Called by the scheduler on every tick. */
    public static function runDue(): void
    {
        $db = App::db();
        foreach (array_merge($db->all("SELECT * FROM tenants WHERE status = 'active'"), [self::platform()]) as $tenant) {
            $tid = (int) $tenant['id'];
            $schedule = Settings::get($tid, 'backup.auto') ?: 'off';
            if ($schedule === 'off') {
                continue;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens'));
            $hour = (int) (Settings::get($tid, 'backup.hour') ?? 3);
            if ($schedule === 'weekly') {
                $key = $now->format('o-W');
                if ((int) $now->format('N') < (int) (Settings::get($tid, 'backup.weekday') ?: 1)) {
                    continue;
                }
                if ((int) $now->format('N') === (int) (Settings::get($tid, 'backup.weekday') ?: 1) && (int) $now->format('G') < $hour) {
                    continue;
                }
            } else {
                $key = $now->format('Y-m-d');
                if ((int) $now->format('G') < $hour) {
                    continue;
                }
            }
            if (Settings::get($tid, 'backup.last_key') === $key) {
                continue;
            }
            Settings::set($tid, 'backup.last_key', $key);
            try {
                $name = self::store($tenant);
                Audit::log('backup.created', 'tenant', $tid ?: null, ($tid ? 'Αυτόματο backup: ' : 'Αυτόματο backup πλατφόρμας: ') . $name, null, null, $tid ?: null);
            } catch (\Throwable $e) {
                Settings::set($tid, 'backup.last_error', mb_substr($now->format('d/m/Y H:i') . ' · ' . $e->getMessage(), 0, 500));
                App::log('backup tenant ' . $tid . ': ' . $e->getMessage());
            }
        }
    }
}
