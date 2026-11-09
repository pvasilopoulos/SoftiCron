<?php

declare(strict_types=1);

namespace App\Auth;

final class Permissions
{
    /** code => [group, label] */
    public const ALL = [
        'jobs.view' => ['Jobs', 'Προβολή jobs'],
        'jobs.manage' => ['Jobs', 'Δημιουργία, αλλαγή και διαγραφή jobs'],
        'jobs.run' => ['Jobs', 'Χειροκίνητη εκτέλεση και test run'],
        'runs.view' => ['Εκτελέσεις', 'Προβολή εκτελέσεων και logs'],
        'connectors.manage' => ['Ρυθμίσεις', 'Διαχείριση connectors (Email, Telegram)'],
        'templates.manage' => ['Ρυθμίσεις', 'Διαχείριση templates μηνυμάτων'],
        'settings.manage' => ['Ρυθμίσεις', 'Ρυθμίσεις tenant και branding'],
        'users.manage' => ['Χρήστες', 'Πρόσκληση και διαχείριση χρηστών'],
        'roles.manage' => ['Χρήστες', 'Διαχείριση ρόλων και δικαιωμάτων'],
        'audit.view' => ['Ασφάλεια', 'Προβολή audit log'],
    ];

    public static function grouped(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$group, $label]) {
            $out[$group][$code] = $label;
        }
        return $out;
    }

    public static function valid(array $codes): array
    {
        return array_values(array_filter($codes, fn ($c) => isset(self::ALL[$c])));
    }
}
