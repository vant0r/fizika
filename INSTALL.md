# Installation Guide — Physics National Certificate Platform

## Two deployment modes

The application supports **two installation paths**. Pick the one that matches your hosting environment.

### Mode A — VPS / Dedicated / Modern shared host (recommended)

Requires shell access and Composer. Gets you battle-tested cryptography
(`firebase/php-jwt`) and a richer `.env` parser (`vlucas/phpdotenv`).

```bash
# 1. Clone/upload the project
cd /var/www/physics-cert

# 2. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Configure environment
cp .env.example .env
# generate APP_KEY:
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;" >> .env
# edit DB_MASTER_DSN, TG_BOT_TOKEN, TG_WEBHOOK_SECRET, ...

# 4. Apply database schema
mysql -u root -p < database/schema.sql

# 5. Set permissions
chown -R www-data:www-data public/uploads
chmod 0775 public/uploads/{logo,slider,screenshots}

# 6. Set up cron for GC (every 5 minutes)
crontab -e
# add this line (replace ASTERISK with literal *):
# ASTERISK/5 ASTERISK ASTERISK ASTERISK ASTERISK   /usr/bin/php /var/www/physics-cert/bin/gc.php >> /var/log/physics-cert-gc.log 2>&1

# 7. Point web server document root to /public
# Apache: see public/.htaccess (already provided)
# Nginx:  try_files $uri $uri/ /index.php?$query_string;
```

### Mode B — Restricted shared host (cPanel, x10hosting, etc.)

If you can't run `composer install`, the application **still works** out of the box. It uses:

- An in-house PSR-4 autoloader (registered in `bootstrap.php`)
- An in-house `.env` parser (handles quoted strings, comments, blank lines)
- An in-house JWT (HS256) implementation (uses the same wire format as `firebase/php-jwt`)

Just upload the files, configure `.env`, import `database/schema.sql`, and you're done.

The root `.htaccess` rewrites every request to `/public/`, so document root pointed
at the project root is fine.

---

## Switching modes

You can switch from Mode B to Mode A at any time by running `composer install`.
The bootstrap automatically detects `vendor/autoload.php` and prefers it.
JWT tokens issued under one mode remain valid under the other (same HS256 standard).

---

## Telegram Bot setup

```bash
# Once .env has TG_BOT_TOKEN and TG_WEBHOOK_SECRET set:
curl -X POST "https://api.telegram.org/bot<TG_BOT_TOKEN>/setWebhook" \
     -d "url=https://your-domain.com/api/bot/webhook" \
     -d "secret_token=<TG_WEBHOOK_SECRET>" \
     -d "drop_pending_updates=true"
```

---

## Production checklist

- [ ] HTTPS is enforced (Apache mod_ssl + redirect rule already in `.htaccess`)
- [ ] `APP_ENV=production` set in `.env` (suppresses error display)
- [ ] `APP_KEY` is at least 32 random bytes (64 hex chars)
- [ ] `TG_WEBHOOK_SECRET` is at least 32 random chars
- [ ] Database user has only the privileges it needs (no SUPER, FILE, PROCESS)
- [ ] Cron job for `bin/gc.php` is active
- [ ] First admin account promoted via SQL:
  ```sql
  UPDATE users SET role = 'admin' WHERE phone = '+998901234567';
  ```
- [ ] Tariffs (`SELECT * FROM tariffs`) reviewed, prices match real card holder
- [ ] `system_settings.humo_card`, `visa_card`, `card_holder` updated through admin panel

---

## Health check

```bash
# Should return 200 with JSON
curl -i https://your-domain.com/api/auth/me
# unauthenticated: {"error":"Unauthorized"}

# Should return the auth page HTML
curl -i https://your-domain.com/auth
```
