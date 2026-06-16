<?php
/**
 * ============================================================
 *  PHYSICS CERT — ONE-CLICK INSTALLER
 * ============================================================
 *
 *  Bu faylni serverga yuklang va brauzerda oching:
 *    https://sizning-domen.uz/install.php
 *
 *  Nima qiladi:
 *    1. Domen nomini so'raydi (formada kiritasiz)
 *    2. Barcha papkalarni yaratadi (app/, bin/, database/, views/, public/uploads/...)
 *    3. .htaccess fayllarini to'g'ri joyiga yozadi
 *    4. .env faylini generatsiya qiladi (random APP_KEY, random TG_WEBHOOK_SECRET)
 *    5. Siz faqat DB va TG_BOT_TOKEN ni to'ldirasiz
 *    6. O'zini o'chiradi (xavfsizlik)
 *
 *  MUHIM: Bu fayl PUBLIC papkada turadi.
 *         Install tugagach, avtomatik o'chiriladi.
 * ============================================================
 */

// Xavfsizlik: faqat birinchi marta ishlaydi
if (file_exists(__DIR__ . '/../.env') && filesize(__DIR__ . '/../.env') > 50) {
    http_response_code(403);
    die('<h1>Already installed</h1><p>.env allaqachon mavjud. Qayta install qilish uchun .env ni o\'chiring.</p>');
}

$rootDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$errors  = [];
$success = false;
$domain  = '';

// ============================================================
//  POST — Install jarayoni
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain       = trim($_POST['domain'] ?? '');
    $dbHost       = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort       = trim($_POST['db_port'] ?? '3306');
    $dbName       = trim($_POST['db_name'] ?? 'physics_cert');
    $dbUser       = trim($_POST['db_user'] ?? 'root');
    $dbPass       = $_POST['db_pass'] ?? '';
    $tgToken      = trim($_POST['tg_token'] ?? '');
    $smsProvider  = trim($_POST['sms_provider'] ?? 'log');
    $eskizEmail   = trim($_POST['eskiz_email'] ?? '');
    $eskizPass    = $_POST['eskiz_pass'] ?? '';

    // Validatsiya
    if ($domain === '') {
        $errors[] = 'Domen nomini kiriting (masalan: fizika.uz yoki cert.example.com)';
    }
    if ($dbName === '') {
        $errors[] = 'Ma\'lumotlar bazasi nomini kiriting';
    }

    if (empty($errors)) {
        // 1) Papkalar
        $dirs = [
            $rootDir . '/app/Bot',
            $rootDir . '/app/Config',
            $rootDir . '/app/Controllers',
            $rootDir . '/app/Core',
            $rootDir . '/app/Queue/Handlers',
            $rootDir . '/app/Sms',
            $rootDir . '/bin',
            $rootDir . '/database',
            $rootDir . '/views',
            $rootDir . '/public/uploads/logo',
            $rootDir . '/public/uploads/slider',
            $rootDir . '/public/uploads/screenshots',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    $errors[] = "Papka yaratib bo'lmadi: $dir";
                }
            }
        }

        // 2) .gitkeep fayllar (uploads bo'sh qolmasligi uchun)
        foreach (['logo', 'slider', 'screenshots'] as $sub) {
            $gk = $rootDir . "/public/uploads/$sub/.gitkeep";
            if (!file_exists($gk)) @file_put_contents($gk, '');
        }

        // 3) uploads/.htaccess (PHP execution bloklash)
        $uploadsHtaccess = $rootDir . '/public/uploads/.htaccess';
        if (!file_exists($uploadsHtaccess)) {
            @file_put_contents($uploadsHtaccess, <<<'HTACCESS'
php_flag engine off
Options -ExecCGI -Indexes
AddType text/plain .php .php3 .php4 .php5 .phtml .phar .pht
<FilesMatch "\.(php|php3|php4|php5|phtml|phar|pht)$">
    Require all denied
</FilesMatch>
HTACCESS
            );
        }

        // 4) Root .htaccess (public ga yo'naltirish)
        $rootHtaccess = $rootDir . '/.htaccess';
        if (!file_exists($rootHtaccess)) {
            @file_put_contents($rootHtaccess, <<<'HTACCESS'
RewriteEngine On
RewriteRule ^(app|database|views|bin)(/|$) - [F,L]
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
<FilesMatch "^(\.env|\.git|composer\.(json|lock)|.*\.sql|.*\.md|bootstrap\.php)$">
    Require all denied
</FilesMatch>
HTACCESS
            );
        }

        // 5) public/.htaccess (front-controller)
        $pubHtaccess = $rootDir . '/public/.htaccess';
        if (!file_exists($pubHtaccess)) {
            $htContent = <<<HTACCESS
RewriteEngine On

# HTTPS redirect
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

# Existing files/dirs served as-is
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Everything else → index.php
RewriteRule ^ index.php [QSA,L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header unset Server
    Header unset X-Powered-By
</IfModule>

Options -Indexes
DirectoryIndex index.php
HTACCESS;
            @file_put_contents($pubHtaccess, $htContent);
        }

        // 6) APP_KEY va TG_WEBHOOK_SECRET generatsiya
        $appKey       = bin2hex(random_bytes(32));
        $webhookSecret= bin2hex(random_bytes(32));

        // 7) .env yozish
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $envContent = <<<ENV
# =====================================================================
# Physics National Certificate — Environment
# Generated by install.php on {$domain}
# =====================================================================

APP_ENV=production
APP_TIMEZONE=Asia/Tashkent
APP_KEY={$appKey}

# Database
DB_MASTER_DSN="{$dsn}"
DB_MASTER_USER={$dbUser}
DB_MASTER_PASS={$dbPass}
DB_REPLICA_DSN=
DB_REPLICA_USER=
DB_REPLICA_PASS=

# Telegram Bot
TG_BOT_TOKEN={$tgToken}
TG_WEBHOOK_SECRET={$webhookSecret}

# SMS (OTP)
SMS_PROVIDER={$smsProvider}
SMS_ESKIZ_EMAIL={$eskizEmail}
SMS_ESKIZ_PASSWORD={$eskizPass}
SMS_FROM=4546
SMS_PROVIDER_FORCE_LOG=

# Domain (for reference)
APP_DOMAIN={$domain}

ENV;
        $envPath = $rootDir . '/.env';
        if (@file_put_contents($envPath, $envContent, LOCK_EX) === false) {
            $errors[] = '.env faylini yozib bo\'lmadi. Papka yozish huquqini tekshiring (chmod 755).';
        }

        // 8) Permissions
        @chmod($rootDir . '/public/uploads', 0775);
        @chmod($rootDir . '/public/uploads/logo', 0775);
        @chmod($rootDir . '/public/uploads/slider', 0775);
        @chmod($rootDir . '/public/uploads/screenshots', 0775);

        if (empty($errors)) {
            $success = true;
        }
    }
}

// ============================================================
//  RENDER
// ============================================================
$step = $success ? 'done' : 'form';
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — Physics National Certificate</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;800&family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --bd: 1px solid #000; }
        html, body { background:#fff; color:#000; font-family:'Inter',sans-serif; }
        .mono { font-family:'JetBrains Mono', monospace; letter-spacing:-.02em; }
        .brutal { border: var(--bd); }
        .brutal-thick { border: 2px solid #000; }
        .btn-brutal { border: 2px solid #000; padding:.7rem 1.25rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; transition: all .12s; cursor:pointer; }
        .btn-brutal:hover { background:#000; color:#fff; }
        .field { display:flex; flex-direction:column; gap:.3rem; }
        .field label { font-size:10px; text-transform:uppercase; letter-spacing:.08em; font-family:'JetBrains Mono', monospace; font-weight:600; }
        .field input, .field select { border: var(--bd); padding:.6rem .75rem; background:#fff; outline:none; }
        .field input:focus { background:#fafafa; }
        ::selection { background:#000; color:#fff; }
        .check { color: #000; font-weight:700; }
        .check::before { content:"✓ "; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10">
<div class="w-full max-w-2xl">

    <!-- Header -->
    <div class="mb-8">
        <div class="mono text-xs uppercase tracking-widest opacity-60">[ INSTALLER ]</div>
        <h1 class="mono text-4xl font-black leading-none mt-2">PHYSICS // CERT</h1>
        <p class="mt-3 text-sm leading-relaxed opacity-80">
            Fizika fanidan Milliy Sertifikat platformasi — bir martalik sozlash.
        </p>
    </div>

    <?php if ($step === 'done'): ?>
    <!-- ============ SUCCESS ============ -->
    <div class="brutal-thick p-8">
        <div class="mono text-2xl font-extrabold mb-4">✓ O'RNATILDI</div>

        <div class="space-y-2 text-sm">
            <p class="check">Papkalar yaratildi</p>
            <p class="check">.htaccess fayllar joyiga qo'yildi</p>
            <p class="check">.env generatsiya qilindi (APP_KEY: random 64 hex)</p>
            <p class="check">uploads/ papka ruxsatlari sozlandi</p>
        </div>

        <div class="mt-6 brutal p-4 mono text-xs leading-relaxed">
            <div class="font-bold uppercase tracking-widest mb-2">Keyingi qadamlar:</div>
            <ol class="list-decimal list-inside space-y-1.5">
                <li>Ma'lumotlar bazasini import qiling:<br>
                    <code class="brutal px-2 py-0.5 bg-white">mysql -u <?= htmlspecialchars($dbUser) ?> -p <?= htmlspecialchars($dbName) ?> &lt; database/schema.sql</code>
                </li>
                <li>PHP fayllarni yuklang (app/, bin/, views/, public/index.php, bootstrap.php, composer.json)</li>
                <li>Telegram webhook o'rnating:<br>
                    <code class="brutal px-2 py-0.5 bg-white">curl -X POST "https://api.telegram.org/bot<?= htmlspecialchars($tgToken ?: 'TOKEN') ?>/setWebhook" \<br>
                    &nbsp;&nbsp;-d "url=https://<?= htmlspecialchars($domain) ?>/api/bot/webhook" \<br>
                    &nbsp;&nbsp;-d "secret_token=<?= htmlspecialchars($webhookSecret ?? '') ?>"</code>
                </li>
                <li>Admin yarating:<br>
                    <code class="brutal px-2 py-0.5 bg-white">UPDATE users SET role='admin' WHERE phone='+998XXXXXXXXX';</code>
                </li>
                <li><strong class="text-red-600">Bu install.php faylini o'chiring!</strong><br>
                    <code class="brutal px-2 py-0.5 bg-white">rm public/install.php</code>
                </li>
            </ol>
        </div>

        <div class="mt-6 brutal p-4 mono text-xs">
            <span class="font-bold uppercase tracking-widest">Domen:</span> https://<?= htmlspecialchars($domain) ?><br>
            <span class="font-bold uppercase tracking-widest">APP_KEY:</span> <?= htmlspecialchars($appKey ?? '—') ?><br>
            <span class="font-bold uppercase tracking-widest">WEBHOOK_SECRET:</span> <?= htmlspecialchars($webhookSecret ?? '—') ?>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <a href="https://<?= htmlspecialchars($domain) ?>/" class="btn-brutal">→ Saytga o'tish</a>
            <form method="POST" action="" style="display:inline;">
                <input type="hidden" name="self_delete" value="1">
                <button type="submit" class="btn-brutal" style="background:#000;color:#fff;">⊘ install.php ni o'chirish</button>
            </form>
        </div>
    </div>

    <?php elseif (isset($_POST['self_delete'])): ?>
        <?php
            @unlink(__FILE__);
            if (!file_exists(__FILE__)) {
                echo '<div class="brutal-thick p-8"><div class="mono text-2xl font-extrabold">✓ install.php o\'chirildi</div>';
                echo '<p class="mt-3">Endi <a href="/" class="underline font-bold">bosh sahifaga</a> o\'ting.</p></div>';
            } else {
                echo '<div class="brutal-thick p-8"><div class="mono text-2xl font-extrabold">✗ O\'chirib bo\'lmadi</div>';
                echo '<p class="mt-3">Qo\'lda o\'chiring: <code>rm public/install.php</code></p></div>';
            }
        ?>

    <?php else: ?>
    <!-- ============ FORM ============ -->
    <?php if (!empty($errors)): ?>
        <div class="brutal p-4 mb-6">
            <div class="mono text-xs uppercase tracking-widest font-bold mb-2">Xatolar:</div>
            <ul class="list-disc list-inside text-sm space-y-1">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="brutal-thick">
        <div class="p-6 brutal border-l-0 border-r-0 border-t-0">
            <div class="mono text-xs uppercase tracking-widest opacity-60 mb-1">Qadam 1</div>
            <h2 class="mono text-xl font-extrabold">Domen sozlamalari</h2>
        </div>

        <div class="p-6 space-y-4">
            <div class="field">
                <label>Domen nomi *</label>
                <input type="text" name="domain" value="<?= htmlspecialchars($domain ?: ($_SERVER['HTTP_HOST'] ?? '')) ?>"
                       placeholder="fizika.uz yoki cert.example.com" required autofocus>
                <span class="text-[10px] mono opacity-60">https:// avtomatik. SSL sertifikat sozlangan bo'lishi kerak.</span>
            </div>
        </div>

        <div class="p-6 brutal border-l-0 border-r-0 border-t-0 border-b-0">
            <div class="mono text-xs uppercase tracking-widest opacity-60 mb-1">Qadam 2</div>
            <h2 class="mono text-xl font-extrabold">Ma'lumotlar bazasi</h2>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="field">
                <label>DB Host</label>
                <input type="text" name="db_host" value="127.0.0.1" placeholder="127.0.0.1 yoki localhost">
            </div>
            <div class="field">
                <label>DB Port</label>
                <input type="text" name="db_port" value="3306">
            </div>
            <div class="field">
                <label>DB Nomi *</label>
                <input type="text" name="db_name" value="physics_cert" placeholder="physics_cert" required>
            </div>
            <div class="field">
                <label>DB Foydalanuvchi</label>
                <input type="text" name="db_user" value="root" placeholder="root">
            </div>
            <div class="field md:col-span-2">
                <label>DB Parol</label>
                <input type="password" name="db_pass" value="" placeholder="(bo'sh bo'lishi mumkin)">
            </div>
        </div>

        <div class="p-6 brutal border-l-0 border-r-0 border-t-0 border-b-0">
            <div class="mono text-xs uppercase tracking-widest opacity-60 mb-1">Qadam 3</div>
            <h2 class="mono text-xl font-extrabold">Telegram &amp; SMS</h2>
        </div>

        <div class="p-6 space-y-4">
            <div class="field">
                <label>Telegram Bot Token</label>
                <input type="text" name="tg_token" value="" placeholder="123456789:ABCdefGHIjklMNOpqrs (BotFather'dan)">
                <span class="text-[10px] mono opacity-60">Hozir bo'sh qoldirsangiz keyinroq .env da to'ldirasiz.</span>
            </div>

            <div class="field">
                <label>SMS Provider</label>
                <select name="sms_provider">
                    <option value="log">Log (dev — kodlar server logga yoziladi)</option>
                    <option value="eskiz">Eskiz.uz (production)</option>
                </select>
            </div>

            <div class="field">
                <label>Eskiz Email (faqat eskiz tanlansangiz)</label>
                <input type="text" name="eskiz_email" value="" placeholder="admin@example.com">
            </div>
            <div class="field">
                <label>Eskiz Password</label>
                <input type="password" name="eskiz_pass" value="">
            </div>
        </div>

        <div class="p-6 brutal border-l-0 border-r-0 border-b-0">
            <button type="submit" class="btn-brutal w-full text-center text-lg py-4">
                → O'RNATISH
            </button>
            <div class="mt-3 text-[10px] mono uppercase tracking-widest opacity-60 text-center">
                APP_KEY va TG_WEBHOOK_SECRET avtomatik generatsiya qilinadi (64 hex char).
            </div>
        </div>
    </form>

    <div class="mt-6 brutal p-4 mono text-[10px] uppercase tracking-widest opacity-60 leading-relaxed">
        Bu skript: papkalar yaratadi &middot; .htaccess yozadi &middot; .env generatsiya qiladi &middot; uploads ruxsatlarini sozlaydi.<br>
        PHP fayllar (app/, views/, ...) allaqachon serverda bo'lishi kerak. Faqat config qismini avtomatlashtiradi.<br>
        Install tugagach O'ZI O'CHISHI tavsiya etiladi (xavfsizlik).
    </div>
    <?php endif; ?>

</div>
</body>
</html>
