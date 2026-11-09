<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

final class Tenants
{
    public static function create(array $data): int
    {
        $db = App::db();
        return $db->transaction(function () use ($db, $data): int {
            $id = $db->insert('tenants', [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'timezone' => $data['timezone'] ?? 'Europe/Athens',
                'brand_color' => $data['brand_color'] ?? '#4F46E5',
                'max_jobs' => (int) ($data['max_jobs'] ?? 50),
                'min_interval_minutes' => (int) ($data['min_interval_minutes'] ?? 1),
                'log_retention_days' => (int) ($data['log_retention_days'] ?? 30),
            ]);
            Notifier::seedTemplates($id);
            return $id;
        });
    }

    public static function slugify(string $name): string
    {
        $map = ['α' => 'a', 'ά' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'έ' => 'e', 'ζ' => 'z', 'η' => 'i', 'ή' => 'i', 'θ' => 'th', 'ι' => 'i', 'ί' => 'i', 'ϊ' => 'i', 'ΐ' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'ό' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'ύ' => 'y', 'ϋ' => 'y', 'ΰ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o', 'ώ' => 'o'];
        $s = strtr(mb_strtolower($name), $map);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: 'tenant';
    }

    public static function logoFile(array $tenant): ?string
    {
        if (empty($tenant['logo_path'])) {
            return null;
        }
        $path = App::storage('uploads/' . basename($tenant['logo_path']));
        return is_file($path) ? $path : null;
    }
}
