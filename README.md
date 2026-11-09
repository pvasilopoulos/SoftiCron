# CronApp

Πολυ-tenant διαχειριστής cron jobs για HTTP calls και webhooks. Γραμμένο σε καθαρό PHP 8.2+ με MariaDB/MySQL, χωρίς framework.

Περιλαμβάνει:
- tenants, χρήστες, ρόλους και δικαιώματα, audit log, ρυθμίσεις και branding ανά tenant
- πλήρη cron builder και execution logs με retries
- connectors για SMTP, Microsoft 365 και Telegram, templates μηνυμάτων και Web Push (PWA)

## Εγκατάσταση στο Plesk

1. **Domain/subdomain** → Hosting Settings → **Document root**: `cronapp/public`, PHP 8.3 (FPM).
   Ενεργοποίησε SSL (Let's Encrypt), γιατί το Web Push χρειάζεται HTTPS.
2. Ανέβασε τον κώδικα στο `cronapp/` και τρέξε `composer install --no-dev -o` (Plesk → PHP Composer ή SSH).
3. **Βάση**: Databases → Add Database (MariaDB) με νέο χρήστη.
4. `cp .env.example .env` και συμπλήρωσε `APP_URL` και τα στοιχεία της βάσης. Μετά:
   ```
   php bin/keygen.php            # βάλε APP_KEY και VAPID_* στο .env
   php bin/migrate.php
   php bin/create-superadmin.php you@example.gr "Ονοματεπώνυμο"
   ```
   Κράτα αντίγραφο του `APP_KEY`. Χωρίς αυτό δεν αποκρυπτογραφούνται τα αποθηκευμένα secrets.
5. **Scheduled Task** (Websites & Domains → Scheduled Tasks), «Run a command», κάθε λεπτό (`* * * * *`):
   ```
   /opt/plesk/php/8.3/bin/php /var/www/vhosts/<domain>/cronapp/bin/cron.php
   ```
6. Μπες ως super-admin → **Tenants → Νέος tenant**. Δώσε email ιδιοκτήτη για να σταλεί πρόσκληση.
   Στο **Κατάσταση συστήματος** φαίνεται αν όλα είναι σωστά ρυθμισμένα.

Στην παραγωγή άφησε `ALLOW_PRIVATE_TARGETS=false` (προστασία από SSRF). Βάλε επίσης την IP του server στο `BLOCKED_HOSTS`.

## Αρχιτεκτονική

| Φάκελος | Περιεχόμενο |
|---|---|
| `public/` | front controller, assets (CSS, JS, cron builder, service worker) |
| `src/Core` | Db (PDO), Router glue, Request/Response, Session, CSRF, View, Crypto (libsodium) |
| `src/Auth` | login με rate limit, τρέχων tenant, δικαιώματα |
| `src/Services` | Scheduler, HttpExecutor (curl_multi, IP pinning), SsrfGuard, CronHelper, Notifier, κανάλια |
| `src/Controllers` | ένας controller ανά ενότητα |
| `templates/` | PHP views |
| `migrations/` | SQL migrations (`bin/migrate.php`) |
| `bin/` | `cron.php` (worker), `migrate.php`, `keygen.php`, `create-superadmin.php`, `make-icons.php` |

- **Scheduler:** κάθε προγραμματισμένη εκτέλεση είναι μοναδική γραμμή `(job_id, scheduled_at)`, άρα δεν γίνονται διπλές εκτελέσεις. Το `GET_LOCK` αποτρέπει να τρέχουν δύο workers μαζί. Οι χαμένες εκτελέσεις (scheduler εκτός) καταγράφονται και γίνεται μία εκτέλεση αναπλήρωσης. Τα retries γίνονται με backoff και υπάρχει πολιτική επικάλυψης (skip/queue/allow).
- **Secrets** (headers, connectors): κρυπτογράφηση libsodium με το `APP_KEY`. Δεν επιστρέφονται ποτέ στον browser και δεν γράφονται στο audit log.
- **Templates:** Twig sandbox, ώστε οι tenants να μην μπορούν να εκτελέσουν κώδικα.

## Τοπική ανάπτυξη (Windows)

```
php -S 127.0.0.1:8080 -t public public/router.php
```
Στα Windows χρειάζονται `CURL_CA_BUNDLE` (cacert.pem) στο `.env` και `OPENSSL_CONF` στο περιβάλλον (για το Web Push). Στο Linux/Plesk δεν χρειάζονται.
