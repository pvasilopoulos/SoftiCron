<?php

declare(strict_types=1);

namespace App\Services;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;

/** Validation, next-run calculation and Greek descriptions for cron expressions. */
final class CronHelper
{
    private const DOW = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    private const DOW_SHORT = ['Κυρ', 'Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ'];
    private const MON = ['Ιανουάριο', 'Φεβρουάριο', 'Μάρτιο', 'Απρίλιο', 'Μάιο', 'Ιούνιο', 'Ιούλιο', 'Αύγουστο', 'Σεπτέμβριο', 'Οκτώβριο', 'Νοέμβριο', 'Δεκέμβριο'];
    private const MON_SHORT = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
    public const FIELD_LABELS = ['Λεπτό', 'Ώρα', 'Ημέρα μήνα', 'Μήνας', 'Ημέρα εβδομάδας'];

    public static function normalize(string $expr): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $expr));
    }

    public static function isValid(string $expr): bool
    {
        $expr = self::normalize($expr);
        return count(explode(' ', $expr)) === 5 && CronExpression::isValidExpression($expr);
    }

    /**
     * Next run times (UTC DateTimeImmutable), respecting optional active window (Y-m-d in job tz).
     * @return DateTimeImmutable[]
     */
    public static function nextRuns(string $expr, string $tz, int $count = 5, ?string $from = null, ?string $until = null, ?DateTimeImmutable $now = null): array
    {
        $zone = new DateTimeZone($tz);
        $cron = new CronExpression(self::normalize($expr));
        $cursor = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
        if ($from) {
            $start = new DateTimeImmutable($from . ' 00:00:00', $zone);
            if ($start > $cursor) {
                $cursor = $start->modify('-1 second');
            }
        }
        $limit = $until ? new DateTimeImmutable($until . ' 23:59:59', $zone) : null;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $next = DateTimeImmutable::createFromMutable($cron->getNextRunDate($cursor, 0, false, $tz));
            if ($limit && $next > $limit) {
                break;
            }
            $out[] = $next->setTimezone(new DateTimeZone('UTC'));
            $cursor = $next;
        }
        return $out;
    }

    /** Shortest gap (minutes) between upcoming runs — used to enforce tenant limits. */
    public static function minGapMinutes(string $expr, string $tz): ?int
    {
        $runs = self::nextRuns($expr, $tz, 6);
        $min = null;
        for ($i = 1; $i < count($runs); $i++) {
            $gap = (int) (($runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp()) / 60);
            $min = $min === null ? $gap : min($min, $gap);
        }
        return $min;
    }

    /** Full preview payload used by the cron builder. */
    public static function preview(string $expr, string $tz, int $count = 5, ?string $from = null, ?string $until = null, int $minInterval = 1): array
    {
        $expr = self::normalize($expr);
        $parts = $expr === '' ? [] : explode(' ', $expr);
        $fields = [];
        foreach (self::FIELD_LABELS as $i => $label) {
            $fields[] = ['label' => $label, 'raw' => $parts[$i] ?? '—', 'text' => isset($parts[$i]) ? self::explainField($parts[$i], $i) : '—'];
        }
        if (count($parts) !== 5) {
            return ['valid' => false, 'error' => 'Η έκφραση χρειάζεται 5 πεδία (βρέθηκαν ' . count($parts) . ').', 'fields' => $fields, 'next' => [], 'warnings' => []];
        }
        if (!CronExpression::isValidExpression($expr)) {
            return ['valid' => false, 'error' => 'Μη έγκυρη έκφραση cron. ' . self::guessError($parts), 'fields' => $fields, 'next' => [], 'warnings' => []];
        }
        try {
            $zone = new DateTimeZone($tz);
        } catch (\Throwable) {
            return ['valid' => false, 'error' => 'Άγνωστη ζώνη ώρας.', 'fields' => $fields, 'next' => [], 'warnings' => []];
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $next = [];
        foreach (self::nextRuns($expr, $tz, $count, $from ?: null, $until ?: null, $now) as $d) {
            $local = $d->setTimezone($zone);
            $next[] = [
                'iso' => $d->format(DATE_ATOM),
                'date' => self::DOW_SHORT[(int) $local->format('w')] . ' ' . $local->format('j') . ' ' . self::MON_SHORT[(int) $local->format('n') - 1] . ($local->format('Y') !== $now->setTimezone($zone)->format('Y') ? ' ' . $local->format('Y') : ''),
                'time' => $local->format('H:i'),
                'rel' => self::relative($d->getTimestamp() - $now->getTimestamp()),
            ];
        }
        $warnings = self::warnings($parts, $tz);
        $gap = self::minGapMinutes($expr, $tz);
        $error = null;
        if ($gap !== null && $gap < $minInterval) {
            $error = "Το πλάνο σου επιτρέπει εκτέλεση το πολύ κάθε $minInterval λεπτά.";
        }
        return [
            'valid' => $error === null,
            'error' => $error,
            'expr' => $expr,
            'description' => self::describe($expr) . ' · ' . $tz,
            'fields' => $fields,
            'next' => $next,
            'warnings' => $warnings,
        ];
    }

    private static function relative(int $sec): string
    {
        $m = (int) round($sec / 60);
        if ($m < 60) {
            return 'σε ' . max($m, 1) . ($m === 1 ? ' λεπτό' : ' λεπτά');
        }
        $h = (int) round($m / 60);
        if ($h < 24) {
            return 'σε ' . $h . ($h === 1 ? ' ώρα' : ' ώρες');
        }
        $d = (int) round($h / 24);
        return 'σε ' . $d . ($d === 1 ? ' ημέρα' : ' ημέρες');
    }

    private static function guessError(array $parts): string
    {
        $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        foreach ($parts as $i => $p) {
            foreach (preg_split('/[,\-\/#]/', $p) ?: [] as $n) {
                if (ctype_digit($n) && ((int) $n < $limits[$i][0] || (int) $n > $limits[$i][1])) {
                    return self::FIELD_LABELS[$i] . ': εκτός ορίων ' . $limits[$i][0] . '–' . $limits[$i][1] . '.';
                }
            }
        }
        return 'Έλεγξε τη σύνταξη των πεδίων.';
    }

    private static function warnings(array $p, string $tz): array
    {
        $w = [];
        if (preg_match('/(^|,)(29|30|31)(,|$|-)/', $p[2]) || preg_match('/-(29|30|31)/', $p[2])) {
            $w[] = 'Οι ημερομηνίες 29–31 δεν υπάρχουν σε όλους τους μήνες, και εκείνοι οι μήνες παραλείπονται. Για «τέλος μήνα» χρησιμοποίησε το «Τελευταία» (L).';
        }
        if ($p[1] !== '*' && in_array(3, self::expandSimple($p[1], 0, 23), true) && $tz !== 'UTC') {
            $w[] = "Η ώρα 03:00–03:59 επηρεάζεται από την αλλαγή ώρας ($tz): εκείνες τις ημέρες μια εκτέλεση μπορεί να παραλειφθεί ή να γίνει δύο φορές.";
        }
        if (!in_array($p[2], ['*', '?'], true) && !in_array($p[4], ['*', '?'], true)) {
            $w[] = 'Έχεις ορίσει και ημέρα μήνα και ημέρα εβδομάδας. Κατά το πρότυπο cron, το job τρέχει όταν ισχύει οποιοδήποτε από τα δύο.';
        }
        if ($p[0] === '*' && $p[1] === '*') {
            $w[] = 'Εκτέλεση κάθε λεπτό: 1.440 κλήσεις την ημέρα. Βεβαιώσου ότι το endpoint το αντέχει.';
        }
        return $w;
    }

    /** Expand a numeric field (no L/#) into values; returns [] when it cannot. */
    private static function expandSimple(string $field, int $min, int $max): array
    {
        $out = [];
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $s] = explode('/', $part, 2);
                $step = max(1, (int) $s);
            }
            if ($part === '*') {
                [$lo, $hi] = [$min, $max];
            } elseif (str_contains($part, '-')) {
                [$lo, $hi] = array_map('intval', explode('-', $part, 2));
            } elseif (ctype_digit($part)) {
                $lo = (int) $part;
                $hi = $step > 1 ? $max : $lo;
            } else {
                return [];
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    public static function describe(string $expr): string
    {
        $p = explode(' ', self::normalize($expr));
        if (count($p) !== 5) {
            return $expr;
        }
        [$m, $h, $dom, $mon, $dow] = $p;
        $two = fn ($n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $isNum = fn ($x) => ctype_digit($x);
        $isStep = fn ($x) => (bool) preg_match('/^\*\/\d+$/', $x);
        $hours = self::expandSimple($h, 0, 23);
        sort($hours);
        $hoursPhrase = function (string $hh) use ($two, $hours): string {
            if ($hh === '*') {
                return '';
            }
            if (preg_match('/^(\d+)-(\d+)$/', $hh, $r)) {
                return 'από ' . $two($r[1]) . ':00 έως ' . $two($r[2]) . ':59';
            }
            return 'τις ώρες ' . implode(', ', array_map($two, $hours));
        };

        if ($m === '*') {
            $time = 'Κάθε λεπτό' . ($h !== '*' ? ', ' . $hoursPhrase($h) : '');
        } elseif ($isStep($m)) {
            $time = 'Κάθε ' . substr($m, 2) . ' λεπτά' . ($h !== '*' ? ', ' . $hoursPhrase($h) : '');
        } elseif ($isNum($m) && $h === '*') {
            $time = 'Κάθε ώρα στο :' . $two($m);
        } elseif ($isNum($m) && $isStep($h)) {
            $time = 'Κάθε ' . substr($h, 2) . ' ώρες στο :' . $two($m);
        } elseif ($isNum($m) && preg_match('/^(\d+-\d+)\/(\d+)$/', $h, $r)) {
            $time = 'Κάθε ' . $r[2] . ' ώρες στο :' . $two($m) . ', ' . $hoursPhrase($r[1]);
        } elseif ($isNum($m) && preg_match('/^\d+-\d+$/', $h)) {
            $time = 'Κάθε ώρα στο :' . $two($m) . ', ' . $hoursPhrase($h);
        } elseif ($isNum($m) && $hours && count($hours) <= 8) {
            $time = 'Στις ' . implode(', ', array_map(fn ($x) => $two($x) . ':' . $two($m), $hours));
        } else {
            $time = "Λεπτά $m, ώρες $h";
        }

        $domP = '';
        if (!in_array($dom, ['*', '?'], true)) {
            $nums = [];
            $last = false;
            foreach (explode(',', $dom) as $d) {
                if (strtoupper($d) === 'L') {
                    $last = true;
                } else {
                    $nums = array_merge($nums, self::expandSimple($d, 1, 31) ?: [$d]);
                }
            }
            $bits = [];
            if ($nums) {
                $bits[] = 'στις ' . implode(', ', $nums) . ' του μήνα';
            }
            if ($last) {
                $bits[] = 'την τελευταία ημέρα του μήνα';
            }
            $domP = implode(' και ', $bits);
        }

        $dowP = '';
        if (!in_array($dow, ['*', '?'], true)) {
            $bits = [];
            $plain = [];
            foreach (explode(',', strtoupper($dow)) as $d) {
                if (preg_match('/^(\d)#(\d)$/', $d, $r)) {
                    $bits[] = 'την ' . $r[2] . 'η ' . self::DOW[(int) $r[1] % 7] . ' του μήνα';
                } elseif (preg_match('/^(\d)L$/', $d, $r)) {
                    $bits[] = 'την τελευταία ' . self::DOW[(int) $r[1] % 7] . ' του μήνα';
                } else {
                    foreach (self::expandSimple($d, 0, 7) as $v) {
                        $plain[] = $v % 7;
                    }
                }
            }
            $plain = array_values(array_unique($plain));
            usort($plain, fn ($a, $b) => (($a + 6) % 7) <=> (($b + 6) % 7));
            if ($plain === [1, 2, 3, 4, 5]) {
                array_unshift($bits, 'Δευτέρα έως Παρασκευή');
            } elseif ($plain === [6, 0]) {
                array_unshift($bits, 'Σάββατο και Κυριακή');
            } elseif ($plain) {
                array_unshift($bits, 'κάθε ' . implode(', ', array_map(fn ($x) => self::DOW[$x], $plain)));
            }
            $dowP = implode(' και ', $bits);
        }

        if ($domP && $dowP) {
            $day = "$domP ή $dowP";
        } else {
            $day = $domP ?: $dowP ?: (str_starts_with($time, 'Στις') ? 'κάθε ημέρα' : '');
        }
        $monP = '';
        if (!in_array($mon, ['*', '?'], true)) {
            $ms = self::expandSimple($mon, 1, 12);
            $monP = $ms ? 'τον ' . implode(', ', array_map(fn ($x) => self::MON[$x - 1], $ms)) : "μήνες $mon";
        }
        return implode(', ', array_filter([$time, $day, $monP]));
    }

    public static function explainField(string $raw, int $i): string
    {
        $unit = ['κάθε λεπτό', 'κάθε ώρα', 'κάθε ημέρα', 'κάθε μήνα', 'κάθε ημέρα'];
        if ($raw === '*' || $raw === '?') {
            return $unit[$i];
        }
        $name = function (int $v) use ($i): string {
            if ($i === 4) {
                return self::DOW_SHORT[$v % 7];
            }
            if ($i === 3) {
                return self::MON_SHORT[$v - 1] ?? (string) $v;
            }
            return (string) $v;
        };
        $out = [];
        foreach (explode(',', $raw) as $p) {
            if (strtoupper($p) === 'L') {
                $out[] = 'τελευταία';
            } elseif (preg_match('/^(\d+)#(\d)$/', $p, $r)) {
                $out[] = $r[2] . 'η ' . $name((int) $r[1]);
            } elseif (preg_match('/^(\d+)L$/i', $p, $r)) {
                $out[] = 'τελευταία ' . $name((int) $r[1]);
            } elseif (preg_match('/^\*\/(\d+)$/', $p, $r)) {
                $out[] = 'κάθε ' . $r[1];
            } elseif (preg_match('/^(\d+)-(\d+)\/(\d+)$/', $p, $r)) {
                $out[] = $name((int) $r[1]) . '–' . $name((int) $r[2]) . ', ανά ' . $r[3];
            } elseif (preg_match('/^(\d+)-(\d+)$/', $p, $r)) {
                $out[] = $name((int) $r[1]) . '–' . $name((int) $r[2]);
            } elseif (ctype_digit($p)) {
                $out[] = $name((int) $p);
            } else {
                $out[] = $p;
            }
        }
        return implode(', ', $out);
    }

    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}
