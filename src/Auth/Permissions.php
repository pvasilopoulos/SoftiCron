<?php

declare(strict_types=1);

namespace App\Auth;

final class Permissions
{
    /** code => [group, label, description] */
    public const ALL = [
        // Jobs
        'jobs.view' => ['Jobs', 'Προβολή jobs', 'Λίστα jobs, ομάδες και φόρμα σε μορφή ανάγνωσης.'],
        'jobs.create' => ['Jobs', 'Δημιουργία jobs', 'Νέα jobs (HTTP και heartbeat) και αντίγραφα.'],
        'jobs.edit' => ['Jobs', 'Επεξεργασία jobs', 'Αλλαγή αιτήματος, προγράμματος, ειδοποιήσεων και SLA.'],
        'jobs.delete' => ['Jobs', 'Διαγραφή jobs', 'Οριστική διαγραφή job μαζί με το ιστορικό του.'],
        'jobs.toggle' => ['Jobs', 'Παύση / ενεργοποίηση', 'Θέτει jobs σε παύση ή τα ενεργοποιεί ξανά.'],
        'jobs.run' => ['Jobs', 'Χειροκίνητη εκτέλεση', 'Κουμπί «Εκτέλεση τώρα» και δοκιμαστικό αίτημα από τη φόρμα.'],
        'jobs.export' => ['Jobs', 'Εξαγωγή jobs (JSON)', 'Λήψη ρυθμίσεων jobs σε αρχείο JSON (χωρίς secrets).'],
        'jobs.import' => ['Jobs', 'Εισαγωγή jobs (JSON)', 'Δημιουργία jobs από αρχείο JSON.'],
        'jobs.versions' => ['Jobs', 'Ιστορικό εκδόσεων', 'Προβολή παλαιότερων εκδόσεων και αλλαγών ενός job.'],
        'jobs.restore' => ['Jobs', 'Επαναφορά έκδοσης', 'Επαναφέρει ένα job σε παλαιότερη έκδοση.'],

        // Runs
        'runs.view' => ['Εκτελέσεις', 'Προβολή εκτελέσεων', 'Λίστα εκτελέσεων, λεπτομέρειες, απάντηση και logs.'],
        'runs.retry' => ['Εκτελέσεις', 'Επανάληψη εκτέλεσης', 'Ξανατρέχει μια ολοκληρωμένη εκτέλεση.'],
        'runs.export' => ['Εκτελέσεις', 'Εξαγωγή εκτελέσεων (CSV)', 'Λήψη εκτελέσεων σε CSV.'],

        // Reports
        'reports.view' => ['Αναφορές & SLA', 'Προβολή αναφορών', 'Uptime, SLA, χρόνοι απόκρισης ανά job.'],
        'reports.export' => ['Αναφορές & SLA', 'Εξαγωγή αναφορών (CSV)', 'Λήψη αναφοράς σε CSV.'],
        'reports.email' => ['Αναφορές & SLA', 'Αποστολή αναφοράς με email', 'Στέλνει την αναφορά άμεσα με email.'],

        // Notifications
        'notifications.history' => ['Ειδοποιήσεις', 'Ιστορικό ειδοποιήσεων', 'Όλες οι ειδοποιήσεις του οργανισμού (email, push, SMS κ.λπ.).'],

        // Connectors
        'connectors.view' => ['Connectors', 'Προβολή connectors', 'Λίστα connectors χωρίς τα μυστικά τους.'],
        'connectors.manage' => ['Connectors', 'Διαχείριση connectors', 'Προσθήκη, αλλαγή, αντίγραφο, προεπιλογή και διαγραφή.'],
        'connectors.test' => ['Connectors', 'Δοκιμή connectors', 'Δοκιμαστική αποστολή μέσω connector.'],

        // Templates
        'templates.view' => ['Templates', 'Προβολή templates', 'Λίστα και περιεχόμενο templates μηνυμάτων.'],
        'templates.manage' => ['Templates', 'Διαχείριση templates', 'Νέα, αλλαγή, αντίγραφο, προεπιλογή, επαναφορά και διαγραφή.'],
        'templates.test' => ['Templates', 'Δοκιμή templates', 'Προεπισκόπηση και δοκιμαστική αποστολή.'],

        // Variables
        'variables.view' => ['Μεταβλητές & secrets', 'Προβολή μεταβλητών', 'Λίστα μεταβλητών (τα secrets παραμένουν κρυφά).'],
        'variables.manage' => ['Μεταβλητές & secrets', 'Διαχείριση μεταβλητών', 'Προσθήκη, αλλαγή και διαγραφή μεταβλητών και secrets.'],

        // API
        'api.manage' => ['API', 'Διαχείριση API tokens', 'Δημιουργία και ανάκληση API tokens του οργανισμού.'],

        // Settings
        'settings.manage' => ['Ρυθμίσεις', 'Γενικές ρυθμίσεις', 'Όνομα οργανισμού και ζώνη ώρας.'],
        'settings.notifications' => ['Ρυθμίσεις', 'Ρυθμίσεις ειδοποιήσεων', 'Παραλήπτες, ώρες σιγής, όριο SMS, επανάληψη αποτυχίας.'],
        'settings.reports' => ['Ρυθμίσεις', 'Εβδομαδιαία αναφορά', 'Ενεργοποίηση και παραλήπτες της αυτόματης αναφοράς.'],
        'settings.branding' => ['Ρυθμίσεις', 'Branding', 'Χρώμα και λογότυπο.'],
        'settings.menu' => ['Ρυθμίσεις', 'Μενού κινητού', 'Κουμπιά της κάτω μπάρας στο κινητό.'],
        'settings.status_page' => ['Ρυθμίσεις', 'Σελίδα κατάστασης', 'Δημόσια σελίδα κατάστασης: ενεργοποίηση, διεύθυνση, κείμενα.'],

        // Users
        'users.view' => ['Χρήστες & ρόλοι', 'Προβολή χρηστών', 'Λίστα μελών και εκκρεμών προσκλήσεων.'],
        'users.manage' => ['Χρήστες & ρόλοι', 'Διαχείριση χρηστών', 'Πρόσκληση, δημιουργία, επεξεργασία και αλλαγή ρόλου.'],
        'users.delete' => ['Χρήστες & ρόλοι', 'Αφαίρεση χρηστών', 'Αφαίρεση μέλους και ακύρωση προσκλήσεων.'],
        'roles.manage' => ['Χρήστες & ρόλοι', 'Διαχείριση ρόλων', 'Ρόλοι και πίνακας δικαιωμάτων.'],

        // Security
        'audit.view' => ['Ασφάλεια', 'Προβολή audit log', 'Ποιος άλλαξε τι και πότε.'],
        'audit.export' => ['Ασφάλεια', 'Εξαγωγή audit log (CSV)', 'Λήψη του audit log σε CSV.'],
    ];

    /** Permissions that automatically include others. */
    public const REQUIRES = [
        'jobs.create' => ['jobs.view'], 'jobs.edit' => ['jobs.view'], 'jobs.delete' => ['jobs.view'],
        'jobs.toggle' => ['jobs.view'], 'jobs.run' => ['jobs.view'], 'jobs.export' => ['jobs.view'],
        'jobs.import' => ['jobs.view', 'jobs.create'], 'jobs.versions' => ['jobs.view'],
        'jobs.restore' => ['jobs.versions', 'jobs.edit'],
        'runs.retry' => ['runs.view', 'jobs.run'], 'runs.export' => ['runs.view'],
        'reports.export' => ['reports.view'], 'reports.email' => ['reports.view'],
        'connectors.manage' => ['connectors.view'], 'connectors.test' => ['connectors.view'],
        'templates.manage' => ['templates.view'], 'templates.test' => ['templates.view'],
        'variables.manage' => ['variables.view'],
        'users.manage' => ['users.view'], 'users.delete' => ['users.view'], 'roles.manage' => ['users.view'],
        'audit.export' => ['audit.view'],
    ];

    /** Short description of each group (role editor). */
    public const GROUPS = [
        'Jobs' => 'Τι μπορεί να κάνει στα jobs.',
        'Εκτελέσεις' => 'Ιστορικό εκτελέσεων και logs.',
        'Αναφορές & SLA' => 'Uptime, SLA και εξαγωγές.',
        'Ειδοποιήσεις' => 'Ιστορικό απεσταλμένων ειδοποιήσεων.',
        'Connectors' => 'Email, Microsoft 365, Telegram, Slack, Teams, Discord, Webhook, SMS/Viber.',
        'Templates' => 'Templates μηνυμάτων ειδοποιήσεων.',
        'Μεταβλητές & secrets' => 'Τιμές που χρησιμοποιούν τα jobs.',
        'API' => 'Πρόσβαση μέσω REST API.',
        'Ρυθμίσεις' => 'Ρυθμίσεις οργανισμού.',
        'Χρήστες & ρόλοι' => 'Μέλη, προσκλήσεις και δικαιώματα.',
        'Ασφάλεια' => 'Καταγραφή ενεργειών.',
    ];

    /** @return array<string, array<string, array{0: string, 1: string}>> group => code => [label, description] */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$group, $label, $desc]) {
            $out[$group][$code] = [$label, $desc];
        }
        return $out;
    }

    /** Keeps known codes and adds the permissions they depend on. */
    public static function valid(array $codes): array
    {
        $set = [];
        $add = function (string $c) use (&$add, &$set): void {
            if (!isset(self::ALL[$c]) || isset($set[$c])) {
                return;
            }
            $set[$c] = true;
            foreach (self::REQUIRES[$c] ?? [] as $dep) {
                $add($dep);
            }
        };
        foreach ($codes as $c) {
            $add((string) $c);
        }
        return array_values(array_filter(array_keys(self::ALL), fn ($c) => isset($set[$c])));
    }

    public static function label(string $code): string
    {
        return self::ALL[$code][1] ?? $code;
    }
}
