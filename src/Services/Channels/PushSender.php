<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Core\App;
use App\Core\Env;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushSender
{
    public static function configured(): bool
    {
        return (string) Env::get('VAPID_PUBLIC_KEY', '') !== '' && (string) Env::get('VAPID_PRIVATE_KEY', '') !== '';
    }

    /**
     * Sends a notification to every device of the given users.
     * @param int[] $userIds
     * @return array{sent:int, failed:int, errors:string[]}
     */
    public static function sendToUsers(array $userIds, array $payload): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'errors' => []];
        if (!$userIds || !self::configured()) {
            if (!self::configured()) {
                $out['errors'][] = 'Τα κλειδιά VAPID δεν έχουν ρυθμιστεί.';
            }
            return $out;
        }
        $db = App::db();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $subs = $db->all("SELECT * FROM push_subscriptions WHERE user_id IN ($in)", array_values($userIds));
        if (!$subs) {
            return $out;
        }
        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject' => trim((string) Env::get('VAPID_SUBJECT', 'mailto:admin@example.com')),
                    'publicKey' => trim((string) Env::get('VAPID_PUBLIC_KEY')),
                    'privateKey' => trim((string) Env::get('VAPID_PRIVATE_KEY')),
                ],
            ], ['TTL' => 3600, 'urgency' => 'high'], 20, Env::get('CURL_CA_BUNDLE') ? ['verify' => Env::get('CURL_CA_BUNDLE')] : []);
            $push->setReuseVAPIDHeaders(true);
        } catch (\Throwable $e) {
            App::log('push: invalid VAPID configuration: ' . $e->getMessage());
            $out['failed'] = count($subs);
            $out['errors'][] = 'Λάθος ρύθμιση VAPID στο .env: ' . $e->getMessage();
            return $out;
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byEndpoint = [];
        foreach ($subs as $s) {
            $byEndpoint[$s['endpoint']] = $s;
            try {
                $push->queueNotification(Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'publicKey' => $s['p256dh'],
                    'authToken' => $s['auth'],
                    'contentEncoding' => 'aes128gcm',
                ]), $json);
            } catch (\Throwable $e) {
                $out['failed']++;
                $out['errors'][] = 'Μη έγκυρη συνδρομή συσκευής: ' . $e->getMessage();
            }
        }
        try {
            $reports = iterator_to_array($push->flush(), false);
        } catch (\Throwable $e) {
            App::log('push: send failed: ' . $e->getMessage());
            $out['failed'] += count($byEndpoint);
            $out['errors'][] = 'Αποτυχία αποστολής: ' . $e->getMessage();
            return $out;
        }
        foreach ($reports as $report) {
            $endpoint = $report->getEndpoint();
            $sub = $byEndpoint[$endpoint] ?? null;
            if ($report->isSuccess()) {
                $out['sent']++;
                if ($sub) {
                    $db->update('push_subscriptions', ['last_success_at' => $db->now()], 'id = :id', ['id' => $sub['id']]);
                }
            } else {
                $out['failed']++;
                $out['errors'][] = $report->getReason();
                if ($sub && $report->isSubscriptionExpired()) {
                    $db->exec('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
                }
            }
        }
        return $out;
    }
}
