<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Response assertions: extra success rules beyond the HTTP status.
 * A rule is ['type' => json|header|body|time|size, 'target' => path/header, 'op' => ..., 'value' => ...].
 */
final class Assertions
{
    public const MAX = 20;

    /** type => [label, target placeholder (null = no target), ops] */
    public const TYPES = [
        'json' => ['Πεδίο JSON', '$.status ή data.items.length', ['eq', 'ne', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'exists', 'not_exists', 'empty', 'not_empty', 'matches']],
        'header' => ['Header', 'Content-Type', ['exists', 'not_exists', 'eq', 'ne', 'contains', 'not_contains', 'matches']],
        'body' => ['Κείμενο απάντησης', null, ['contains', 'not_contains', 'matches', 'not_matches']],
        'time' => ['Χρόνος απόκρισης (ms)', null, ['lt', 'lte']],
        'size' => ['Μέγεθος απάντησης (bytes)', null, ['gt', 'gte', 'lt', 'lte']],
    ];

    public const OPS = [
        'eq' => 'ισούται με', 'ne' => 'διαφέρει από', 'contains' => 'περιέχει', 'not_contains' => 'δεν περιέχει',
        'gt' => 'μεγαλύτερο από', 'gte' => 'τουλάχιστον', 'lt' => 'μικρότερο από', 'lte' => 'το πολύ',
        'exists' => 'υπάρχει', 'not_exists' => 'δεν υπάρχει', 'empty' => 'είναι κενό', 'not_empty' => 'δεν είναι κενό',
        'matches' => 'ταιριάζει με regex', 'not_matches' => 'δεν ταιριάζει με regex',
    ];

    /** Ops that need no value. */
    public const NO_VALUE = ['exists', 'not_exists', 'empty', 'not_empty'];

    /**
     * Normalises rules posted by the form / API. Returns [rules, errors].
     * @return array{0: array, 1: string[]}
     */
    public static function parse(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input)) {
            return [[], []];
        }
        $rules = [];
        $errors = [];
        foreach (array_values($input) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $type = (string) ($r['type'] ?? '');
            $op = (string) ($r['op'] ?? '');
            $target = trim((string) ($r['target'] ?? ''));
            $value = trim((string) ($r['value'] ?? ''));
            if (!isset(self::TYPES[$type])) {
                continue;
            }
            $n = $i + 1;
            if (self::TYPES[$type][1] === null) {
                $target = '';
            } elseif ($target === '') {
                if ($value === '' && !in_array($op, self::NO_VALUE, true)) {
                    continue; // completely empty row
                }
                $errors[] = "Έλεγχος $n: συμπλήρωσε " . ($type === 'json' ? 'το πεδίο JSON (π.χ. $.status).' : 'το όνομα του header.');
                continue;
            }
            if (!in_array($op, self::TYPES[$type][2], true)) {
                $op = self::TYPES[$type][2][0];
            }
            if (in_array($op, self::NO_VALUE, true)) {
                $value = '';
            } elseif ($value === '') {
                $errors[] = "Έλεγχος $n: συμπλήρωσε την τιμή σύγκρισης.";
                continue;
            }
            if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && !is_numeric(str_replace(',', '.', $value))) {
                $errors[] = "Έλεγχος $n: η τιμή πρέπει να είναι αριθμός.";
                continue;
            }
            if (in_array($op, ['matches', 'not_matches'], true) && @preg_match(self::regex($value), '') === false) {
                $errors[] = "Έλεγχος $n: μη έγκυρο regex.";
                continue;
            }
            $rules[] = ['type' => $type, 'target' => mb_substr($target, 0, 200), 'op' => $op, 'value' => mb_substr($value, 0, 500)];
            if (count($rules) >= self::MAX) {
                break;
            }
        }
        return [$rules, $errors];
    }

    public static function decode(?string $json): array
    {
        $rules = $json ? json_decode($json, true) : null;
        return is_array($rules) ? $rules : [];
    }

    /** Human label, e.g. «JSON $.status ισούται με ok». */
    public static function label(array $r): string
    {
        $what = match ($r['type']) {
            'json' => 'JSON ' . $r['target'],
            'header' => 'Header ' . $r['target'],
            'body' => 'Η απάντηση',
            'time' => 'Χρόνος απόκρισης',
            'size' => 'Μέγεθος απάντησης',
            default => $r['type'],
        };
        $val = in_array($r['op'], self::NO_VALUE, true) ? '' : ' ' . ($r['type'] === 'time' ? $r['value'] . ' ms' : ($r['type'] === 'size' ? $r['value'] . ' bytes' : (in_array($r['op'], ['gt', 'gte', 'lt', 'lte'], true) ? $r['value'] : '«' . $r['value'] . '»')));
        return $what . ' ' . (self::OPS[$r['op']] ?? $r['op']) . $val;
    }

    /**
     * Evaluates all rules against an HTTP result.
     * @return array{0: bool, 1: ?string, 2: array} [ok, first failure reason, per-rule results]
     */
    public static function check(array $rules, array $result): array
    {
        if (!$rules) {
            return [true, null, []];
        }
        $json = null;
        $jsonLoaded = false;
        $headers = null;
        $out = [];
        $firstFail = null;
        foreach ($rules as $r) {
            $exists = true;
            $actual = null;
            switch ($r['type']) {
                case 'json':
                    if (!$jsonLoaded) {
                        $json = json_decode((string) $result['body'], true);
                        $jsonLoaded = true;
                    }
                    if ($json === null && trim((string) $result['body']) !== 'null') {
                        $ok = $r['op'] === 'not_exists';
                        $out[] = ['label' => self::label($r), 'ok' => $ok, 'actual' => 'η απάντηση δεν είναι JSON'];
                        if (!$ok && $firstFail === null) {
                            $firstFail = self::label($r) . ' — η απάντηση δεν είναι έγκυρο JSON.';
                        }
                        continue 2;
                    }
                    [$exists, $actual] = self::path($json, (string) $r['target']);
                    break;
                case 'header':
                    $headers ??= self::parseHeaders((string) $result['headers']);
                    $key = strtolower((string) $r['target']);
                    $exists = array_key_exists($key, $headers);
                    $actual = $headers[$key] ?? null;
                    break;
                case 'body':
                    $actual = (string) $result['body'];
                    break;
                case 'time':
                    $actual = (int) $result['duration_ms'];
                    break;
                case 'size':
                    $actual = (int) ($result['size'] ?? strlen((string) $result['body']));
                    break;
            }
            $ok = self::compare($r['op'], $exists, $actual, (string) $r['value']);
            $shown = self::show($r['type'], $exists, $actual);
            $out[] = ['label' => self::label($r), 'ok' => $ok, 'actual' => $shown];
            if (!$ok && $firstFail === null) {
                $firstFail = 'Απέτυχε ο έλεγχος: ' . self::label($r) . ' (βρέθηκε: ' . $shown . ').';
            }
        }
        return [$firstFail === null, $firstFail, $out];
    }

    /**
     * Reads a value by a simple JSON path: $.a.b[0].c, a.b.0.c, items.length / items[*] not supported.
     * @return array{0: bool, 1: mixed} [exists, value]
     */
    public static function path(mixed $data, string $path): array
    {
        $path = trim($path);
        $path = (string) preg_replace('/^\$\.?/', '', $path);
        if ($path === '') {
            return [true, $data];
        }
        preg_match_all('/\[(\d+)\]|\[["\']([^"\']+)["\']\]|([^.\[\]]+)/u', $path, $m, PREG_SET_ORDER);
        $cur = $data;
        $count = count($m);
        foreach ($m as $i => $tok) {
            $key = ($tok[1] ?? '') !== '' ? (int) $tok[1] : (($tok[2] ?? '') !== '' ? $tok[2] : $tok[3]);
            $last = $i === $count - 1;
            if ($last && in_array($key, ['length', 'count', 'size()'], true) && !(is_array($cur) && array_key_exists($key, $cur))) {
                if (is_array($cur)) {
                    return [true, count($cur)];
                }
                if (is_string($cur)) {
                    return [true, mb_strlen($cur)];
                }
                return [false, null];
            }
            if (is_array($cur) && array_key_exists($key, $cur)) {
                $cur = $cur[$key];
            } elseif (is_array($cur) && is_string($key) && ctype_digit($key) && array_key_exists((int) $key, $cur)) {
                $cur = $cur[(int) $key];
            } else {
                return [false, null];
            }
        }
        return [true, $cur];
    }

    private static function compare(string $op, bool $exists, mixed $actual, string $expected): bool
    {
        if ($op === 'exists') {
            return $exists;
        }
        if ($op === 'not_exists') {
            return !$exists;
        }
        if (!$exists) {
            return in_array($op, ['ne', 'not_contains', 'not_matches', 'empty'], true);
        }
        $str = is_string($actual) ? $actual : (is_scalar($actual) || $actual === null ? json_encode($actual) : json_encode($actual, JSON_UNESCAPED_UNICODE));
        $str = (string) $str;
        $num = str_replace(',', '.', $expected);
        return match ($op) {
            'eq' => self::equals($actual, $expected),
            'ne' => !self::equals($actual, $expected),
            'contains' => is_array($actual) ? self::inArray($actual, $expected) : mb_stripos($str, $expected) !== false,
            'not_contains' => is_array($actual) ? !self::inArray($actual, $expected) : mb_stripos($str, $expected) === false,
            'gt' => is_numeric(self::num($actual)) && (float) self::num($actual) > (float) $num,
            'gte' => is_numeric(self::num($actual)) && (float) self::num($actual) >= (float) $num,
            'lt' => is_numeric(self::num($actual)) && (float) self::num($actual) < (float) $num,
            'lte' => is_numeric(self::num($actual)) && (float) self::num($actual) <= (float) $num,
            'empty' => $actual === null || $actual === '' || $actual === [] || $actual === false,
            'not_empty' => !($actual === null || $actual === '' || $actual === [] || $actual === false),
            'matches' => (bool) @preg_match(self::regex($expected), $str),
            'not_matches' => !@preg_match(self::regex($expected), $str),
            default => false,
        };
    }

    private static function num(mixed $v): mixed
    {
        if (is_array($v)) {
            return count($v);
        }
        return is_string($v) ? str_replace(',', '.', trim($v)) : $v;
    }

    private static function equals(mixed $actual, string $expected): bool
    {
        if (is_bool($actual)) {
            return in_array(strtolower($expected), $actual ? ['true', '1', 'yes'] : ['false', '0', 'no'], true);
        }
        if ($actual === null) {
            return in_array(strtolower($expected), ['null', ''], true);
        }
        if ((is_int($actual) || is_float($actual)) && is_numeric(str_replace(',', '.', $expected))) {
            return (float) $actual === (float) str_replace(',', '.', $expected);
        }
        if (is_array($actual)) {
            return json_encode($actual, JSON_UNESCAPED_UNICODE) === $expected;
        }
        return (string) $actual === $expected;
    }

    private static function inArray(array $arr, string $needle): bool
    {
        foreach ($arr as $v) {
            if (is_scalar($v) && self::equals($v, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Pattern from user text: /…/flags kept as is, otherwise wrapped and made case-insensitive. */
    public static function regex(string $p): string
    {
        if (preg_match('#^/.+/[imsux]*$#s', $p)) {
            return $p;
        }
        return '~' . str_replace('~', '\~', $p) . '~iu';
    }

    private static function show(string $type, bool $exists, mixed $actual): string
    {
        if (!$exists) {
            return 'δεν υπάρχει';
        }
        if ($type === 'body') {
            return mb_strlen((string) $actual) . ' χαρακτήρες';
        }
        if ($type === 'time') {
            return $actual . ' ms';
        }
        if ($type === 'size') {
            return $actual . ' bytes';
        }
        $s = is_string($actual) ? $actual : (string) json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strimwidth($s, 0, 80, '…');
    }

    /** Last header block (after redirects) as lowercase name => value. */
    public static function parseHeaders(string $raw): array
    {
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw)) ?: [];
        $block = (string) end($blocks);
        $out = [];
        foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return $out;
    }
}
