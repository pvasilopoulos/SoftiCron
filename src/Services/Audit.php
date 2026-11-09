<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\App;

final class Audit
{
    /** Keys whose values must never be written to the audit log. */
    private const SECRET_KEYS = ['password', 'password_hash', 'secret', 'client_secret', 'token', 'bot_token', 'config_enc', 'headers_enc', 'pass', 'auth', 'p256dh'];

    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $summary = null,
        ?array $before = null,
        ?array $after = null,
        ?int $tenantId = null,
    ): void {
        $changes = null;
        if ($before !== null || $after !== null) {
            $changes = self::diff(self::redact($before ?? []), self::redact($after ?? []));
        }
        try {
            App::db()->insert('audit_logs', [
                'tenant_id' => $tenantId ?? Auth::tenantId(),
                'user_id' => Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? (string) $entityId : null,
                'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
            ]);
        } catch (\Throwable $e) {
            App::log('audit write failed: ' . $e->getMessage());
        }
    }

    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), self::SECRET_KEYS, true)) {
                $data[$k] = '••••••';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }

    /** @return array<string, array{0:mixed,1:mixed}> */
    private static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            if (in_array($k, ['updated_at', 'created_at', 'next_run_at', 'last_run_at', 'last_status', 'last_duration_ms'], true)) {
                continue;
            }
            $b = $before[$k] ?? null;
            $a = $after[$k] ?? null;
            if ((string) json_encode($b) !== (string) json_encode($a)) {
                $out[$k] = [$b, $a];
            }
        }
        return $out;
    }

    public const ACTIONS = [
        'auth.login' => 'Σύνδεση',
        'auth.logout' => 'Αποσύνδεση',
        'auth.login_failed' => 'Αποτυχημένη σύνδεση',
        'job.created' => 'Δημιουργία job',
        'job.updated' => 'Αλλαγή job',
        'job.deleted' => 'Διαγραφή job',
        'job.enabled' => 'Ενεργοποίηση job',
        'job.disabled' => 'Παύση job',
        'job.run_now' => 'Χειροκίνητη εκτέλεση',
        'run.retried' => 'Επανάληψη εκτέλεσης',
        'connector.created' => 'Νέος connector',
        'connector.updated' => 'Αλλαγή connector',
        'connector.deleted' => 'Διαγραφή connector',
        'template.created' => 'Νέο template',
        'template.updated' => 'Αλλαγή template',
        'template.deleted' => 'Διαγραφή template',
        'user.invited' => 'Πρόσκληση χρήστη',
        'user.joined' => 'Αποδοχή πρόσκλησης',
        'user.role_changed' => 'Αλλαγή ρόλου',
        'user.created' => 'Δημιουργία χρήστη',
        'user.updated' => 'Αλλαγή χρήστη',
        'user.deleted' => 'Διαγραφή χρήστη',
        'user.removed' => 'Αφαίρεση χρήστη',
        'invite.revoked' => 'Ακύρωση πρόσκλησης',
        'role.created' => 'Νέος ρόλος',
        'role.updated' => 'Αλλαγή ρόλου',
        'role.deleted' => 'Διαγραφή ρόλου',
        'settings.updated' => 'Αλλαγή ρυθμίσεων',
        'branding.updated' => 'Αλλαγή branding',
        'profile.updated' => 'Αλλαγή προφίλ',
        'profile.password' => 'Αλλαγή κωδικού',
        'audit.exported' => 'Εξαγωγή audit log',
        'tenant.created' => 'Νέος tenant',
        'tenant.updated' => 'Αλλαγή tenant',
    ];
}
