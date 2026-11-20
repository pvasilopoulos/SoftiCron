<?php

declare(strict_types=1);

/**
 * One-off repair of stored responses that were decoded as ISO-8859-1 while the server sent
 * Windows-1253 (Greek): "Åíçìåñþèçêáí" becomes "Ενημερώθηκαν" again.
 *
 *   php bin/fix-encoding.php            dry run: shows what would change
 *   php bin/fix-encoding.php --apply    writes the fixes
 *
 * A body is repaired only when every non-ASCII character in it is in U+0080–U+00FF (the
 * signature of that mis-decoding) and the repaired text is mostly Greek letters.
 */

use App\Core\App;

require dirname(__DIR__) . '/vendor/autoload.php';
App::boot(dirname(__DIR__));

$apply = in_array('--apply', $argv, true);
$db = App::db();

function repaired(?string $text): ?string
{
    if ($text === null || $text === '' || !preg_match('/[\x{80}-\x{FF}]/u', $text) || preg_match('/[\x{100}-\x{10FFFF}]/u', $text)) {
        return null;
    }
    $bytes = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    $out = @iconv('CP1253', 'UTF-8//IGNORE', $bytes);
    if (!is_string($out) || $out === '' || !mb_check_encoding($out, 'UTF-8')) {
        return null;
    }
    // Real Greek forms whole words; a Western text (Café, crème) would turn into single
    // Greek letters glued to Latin ones (Cafι, crθme), so that is rejected.
    if (preg_match('/[A-Za-z][\x{0370}-\x{03FF}]|[\x{0370}-\x{03FF}][A-Za-z]/u', $out) || !preg_match('/[\x{0370}-\x{03FF}]{3,}/u', $out)) {
        return null;
    }
    $greek = preg_match_all('/[\x{0370}-\x{03FF}]/u', $out);
    $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $out);
    return $nonAscii > 0 && $greek / $nonAscii >= 0.6 ? $out : null;
}

$last = 0;
$found = $fixed = 0;
$examples = [];
do {
    $rows = $db->all(
        'SELECT id, response_body, error FROM job_runs WHERE id > ? AND (LENGTH(response_body) <> CHAR_LENGTH(response_body) OR LENGTH(error) <> CHAR_LENGTH(error)) ORDER BY id LIMIT 500',
        [$last],
    );
    foreach ($rows as $r) {
        $last = (int) $r['id'];
        $body = repaired($r['response_body']);
        $error = repaired($r['error']);
        if ($body === null && $error === null) {
            continue;
        }
        $found++;
        if (count($examples) < 5) {
            $examples[] = '#' . $r['id'] . ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) ($body ?? $error)), 0, 100);
        }
        if ($apply) {
            $set = [];
            if ($body !== null) {
                $set['response_body'] = $body;
            }
            if ($error !== null) {
                $set['error'] = mb_substr($error, 0, 1000);
            }
            $db->update('job_runs', $set, 'id = :id', ['id' => $r['id']]);
            $fixed++;
        }
    }
} while ($rows);

echo ($apply ? "Διορθώθηκαν $fixed εκτελέσεις." : "Βρέθηκαν $found εκτελέσεις για διόρθωση (δοκιμή, δεν άλλαξε τίποτα· τρέξε με --apply).") . "\n";
foreach ($examples as $e) {
    echo "  $e\n";
}
