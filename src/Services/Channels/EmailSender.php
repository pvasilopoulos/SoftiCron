<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Core\Env;
use App\Services\SsrfGuard;
use PHPMailer\PHPMailer\PHPMailer;

/** Sends email through an SMTP or Microsoft 365 (Graph) connector config. */
final class EmailSender
{
    /**
     * @param string[] $to
     * @param array<string, array{0:string,1:string}> $attachments filename => [contents, mime type]
     * @throws \RuntimeException
     */
    public static function send(string $type, array $cfg, array $to, string $subject, string $html, string $text, array $attachments = []): void
    {
        $to = array_values(array_filter(array_map('trim', $to), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            throw new \RuntimeException('Δεν υπάρχουν έγκυροι παραλήπτες email.');
        }
        match ($type) {
            'smtp' => self::smtp($cfg, $to, $subject, $html, $text, true, $attachments),
            'm365' => self::graph($cfg, $to, $subject, $html, $attachments),
            default => throw new \RuntimeException("Άγνωστος τύπος email connector: $type"),
        };
    }

    private static function smtp(array $cfg, array $to, string $subject, string $html, string $text, bool $checkHost = true, array $attachments = []): void
    {
        if ($checkHost) {
            try {
                SsrfGuard::checkHost((string) ($cfg['host'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('SMTP: ' . $e->getMessage());
            }
        }
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->CharSet = 'UTF-8';
        $m->Host = (string) $cfg['host'];
        $m->Port = (int) ($cfg['port'] ?? 587);
        $enc = $cfg['encryption'] ?? 'tls';
        if ($enc === 'ssl') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
        }
        if (!empty($cfg['username'])) {
            $m->SMTPAuth = true;
            $m->Username = (string) $cfg['username'];
            $m->Password = (string) ($cfg['password'] ?? '');
        }
        $m->Timeout = 20;
        $m->setFrom((string) $cfg['from_email'], (string) ($cfg['from_name'] ?? ''));
        foreach ($to as $addr) {
            $m->addAddress($addr);
        }
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        foreach ($attachments as $fname => [$data, $mime]) {
            $m->addStringAttachment($data, (string) $fname, PHPMailer::ENCODING_BASE64, $mime);
        }
        try {
            $m->send();
        } catch (\Throwable $e) {
            throw new \RuntimeException('SMTP: ' . ($m->ErrorInfo ?: $e->getMessage()));
        }
    }

    private static function graph(array $cfg, array $to, string $subject, string $html, array $attachments = []): void
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'sender'] as $k) {
            if (empty($cfg[$k])) {
                throw new \RuntimeException("Microsoft 365: λείπει το πεδίο $k.");
            }
        }
        $token = self::graphToken($cfg);
        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => ['contentType' => 'HTML', 'content' => $html],
                'toRecipients' => array_map(fn ($a) => ['emailAddress' => ['address' => $a]], $to),
            ],
            'saveToSentItems' => false,
        ];
        foreach ($attachments as $fname => [$data, $mime]) {
            $payload['message']['attachments'][] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string) $fname, 'contentType' => $mime, 'contentBytes' => base64_encode($data)];
        }
        [$code, $body] = Http::request(
            'POST',
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode((string) $cfg['sender']) . '/sendMail',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        );
        if ($code !== 202) {
            $err = json_decode($body, true)['error']['message'] ?? $body;
            throw new \RuntimeException("Microsoft 365 ($code): " . mb_substr((string) $err, 0, 300));
        }
    }

    private static function graphToken(array $cfg): string
    {
        [$code, $body] = Http::request(
            'POST',
            'https://login.microsoftonline.com/' . rawurlencode((string) $cfg['tenant_id']) . '/oauth2/v2.0/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
        );
        $data = json_decode($body, true);
        if ($code !== 200 || empty($data['access_token'])) {
            throw new \RuntimeException('Microsoft 365: αποτυχία λήψης token — ' . mb_substr((string) ($data['error_description'] ?? $body), 0, 300));
        }
        return (string) $data['access_token'];
    }

    /** Platform mailer from .env (used for invitations when the tenant has no connector). */
    public static function platformConfigured(): bool
    {
        return (string) Env::get('MAIL_HOST', '') !== '' && (string) Env::get('MAIL_FROM', '') !== '';
    }

    /** @param array<string, array{0:string,1:string}> $attachments filename => [contents, mime type] */
    public static function sendPlatform(array $to, string $subject, string $html, string $text, array $attachments = []): void
    {
        self::smtp([
            'host' => Env::get('MAIL_HOST'),
            'port' => (int) Env::get('MAIL_PORT', '587'),
            'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
            'username' => Env::get('MAIL_USER'),
            'password' => Env::get('MAIL_PASS'),
            'from_email' => Env::get('MAIL_FROM'),
            'from_name' => Env::get('MAIL_FROM_NAME', 'CronApp'),
        ], $to, $subject, $html, $text, false, $attachments);
    }
}
