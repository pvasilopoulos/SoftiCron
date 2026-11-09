<?php

declare(strict_types=1);

namespace App\Services;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders tenant-authored templates ({{ job.name }} etc.) inside the Twig sandbox,
 * so a template can never call PHP code or reach other data.
 */
final class TemplateRenderer
{
    private static ?Environment $twig = null;

    private static function twig(): Environment
    {
        if (self::$twig === null) {
            $twig = new Environment(new ArrayLoader(), ['autoescape' => false, 'strict_variables' => false, 'cache' => false]);
            $policy = new SecurityPolicy(
                ['if', 'for', 'set'],
                ['escape', 'e', 'upper', 'lower', 'default', 'date', 'length', 'join', 'round', 'number_format', 'trim', 'replace', 'json_encode', 'first', 'last', 'slice', 'title', 'capitalize', 'abs', 'format', 'url_encode', 'striptags', 'nl2br'],
                [],
                [],
                ['range', 'date', 'max', 'min'],
            );
            $twig->addExtension(new SandboxExtension($policy, true));
            self::$twig = $twig;
        }
        return self::$twig;
    }

    /** @throws \Throwable on syntax / sandbox errors */
    public static function render(string $source, array $context): string
    {
        if (!str_contains($source, '{')) {
            return $source;
        }
        return self::twig()->createTemplate($source)->render($context);
    }

    /** Render, falling back to the raw source on errors (used for job requests). */
    public static function renderSafe(string $source, array $context): string
    {
        try {
            return self::render($source, $context);
        } catch (\Throwable) {
            return $source;
        }
    }

    /** @return string|null error message */
    public static function validate(string $source): ?string
    {
        try {
            self::twig()->createTemplate($source)->render(self::sampleContext());
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Variables available to request bodies / URLs / headers. */
    public static function requestContext(array $job, array $tenant, ?array $run = null, ?string $previousAt = null): array
    {
        $tz = new \DateTimeZone($job['timezone'] ?: $tenant['timezone']);
        $now = new \DateTimeImmutable('now', $tz);
        $scheduled = isset($run['scheduled_at']) ? (new \DateTimeImmutable($run['scheduled_at'], new \DateTimeZone('UTC')))->setTimezone($tz) : $now;
        return [
            'job' => ['id' => (int) ($job['id'] ?? 0), 'name' => $job['name'] ?? ''],
            'tenant' => ['id' => (int) $tenant['id'], 'name' => $tenant['name'], 'slug' => $tenant['slug']],
            'run' => [
                'id' => (int) ($run['id'] ?? 0),
                'scheduled_at' => $scheduled->format(DATE_ATOM),
                'previous_at' => $previousAt ? (new \DateTimeImmutable($previousAt, new \DateTimeZone('UTC')))->setTimezone($tz)->format(DATE_ATOM) : '',
                'timestamp' => $scheduled->getTimestamp(),
            ],
            'now' => $now->format(DATE_ATOM),
        ] + Variables::context((int) $tenant['id']);
    }

    public static function sampleContext(): array
    {
        return [
            'event' => 'failed',
            'event_label' => 'Αποτυχία',
            'job' => ['id' => 42, 'name' => 'Αναφορά πωλήσεων ERP', 'url' => 'https://erp.example.gr/api/reports', 'method' => 'POST', 'link' => abs_url('jobs/42/edit')],
            'run' => [
                'id' => 18234, 'status' => 'failed', 'status_label' => 'Αποτυχία', 'http_status' => 503, 'duration_ms' => 1240,
                'duration' => '1,24 s', 'attempts' => 3, 'error' => 'Μη αναμενόμενο HTTP status 503.', 'started_at' => '09/11/2026 10:00',
                'scheduled_at' => '09/11/2026 10:00', 'link' => abs_url('runs/18234'), 'response_excerpt' => '{"error":"Service Unavailable"}',
                'previous_at' => '2026-11-09T09:00:00+02:00', 'timestamp' => 1794218400,
            ],
            'tenant' => ['id' => 1, 'name' => 'Acme Α.Ε.', 'slug' => 'acme'],
            'app' => ['name' => \App\Core\App::name(), 'url' => abs_url('')],
            'now' => date(DATE_ATOM),
        ];
    }

    public const VARIABLES = [
        'job.name' => 'Όνομα job',
        'job.url' => 'URL του job',
        'job.method' => 'HTTP μέθοδος',
        'job.link' => 'Σύνδεσμος στο job',
        'run.id' => 'Αριθμός εκτέλεσης',
        'run.status_label' => 'Κατάσταση (κείμενο)',
        'run.http_status' => 'HTTP status',
        'run.duration' => 'Διάρκεια',
        'run.attempts' => 'Προσπάθειες',
        'run.error' => 'Μήνυμα σφάλματος',
        'run.started_at' => 'Ώρα έναρξης',
        'run.link' => 'Σύνδεσμος στην εκτέλεση',
        'run.response_excerpt' => 'Αρχή του response',
        'event_label' => 'Γεγονός',
        'tenant.name' => 'Όνομα οργανισμού',
        'app.url' => 'URL εφαρμογής',
    ];
}
