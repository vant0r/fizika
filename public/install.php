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

$rootDir = __DIR__;
// If install.php is in public/ subfolder, go up one level
// If it's in public_html/ (flat layout), stay in same dir
if (basename($rootDir) === 'public' && is_dir(dirname($rootDir) . '/app')) {
    $rootDir = dirname($rootDir);
}
// x10hosting: everything is in public_html — rootDir = __DIR__
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
    <script>tailwind.config={theme:{extend:{colors:{sky:{50:'#f0f9ff',100:'#e0f2fe',200:'#bae6fd',300:'#7dd3fc',400:'#38bdf8',500:'#0ea5e9',600:'#0284c7',700:'#0369a1',800:'#075985',900:'#0c4a6e'}}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#fafbfc;}
        ::selection{background:#0ea5e9;color:#fff;}
        .card{background:#fff;border-radius:16px;border:1px solid #e0f2fe;box-shadow:0 2px 12px rgba(14,165,233,.04);}
        .btn-p{background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;border:none;border-radius:10px;padding:.7rem 1.3rem;font-weight:700;font-size:.85rem;cursor:pointer;transition:all .15s;display:inline-block;text-decoration:none;}
        .btn-p:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(14,165,233,.25);}
        .btn-d{background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;border:none;border-radius:10px;padding:.7rem 1.3rem;font-weight:700;font-size:.85rem;cursor:pointer;transition:all .15s;}
        .btn-d:hover{box-shadow:0 6px 18px rgba(220,38,38,.25);}
        .input-f{border:1.5px solid #e0f2fe;border-radius:10px;padding:.6rem .8rem;width:100%;outline:none;transition:all .15s;font-size:.85rem;background:#fff;}
        .input-f:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.08);}
        .field{display:flex;flex-direction:column;gap:.3rem;}
        .field label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;color:#64748b;}
        .check{display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:500;color:#166534;}
        .check::before{content:'';display:inline-block;width:18px;height:18px;border-radius:50%;background:#dcfce7;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23166534'%3E%3Cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z' clip-rule='evenodd'/%3E%3C/svg%3E");background-size:12px;background-position:center;background-repeat:no-repeat;}
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10">
<div class="w-full max-w-2xl">

    <!-- Header -->
    <div class="mb-8 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-sky-400 to-sky-600 flex items-center justify-center shadow-lg shadow-sky-200 mb-4">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
        <h1 class="text-3xl font-black text-gray-900">Physics Cert</h1>
        <p class="text-sm text-gray-500 mt-1">Milliy Sertifikat Platformasi — O'rnatish</p>
    </div>

    <?php if ($step === 'done'): ?>
    <!-- ============ SUCCESS ============ -->
    <div class="card p-8">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            </div>
            <h2 class="text-xl font-bold text-gray-900">Muvaffaqiyatli o'rnatildi!</h2>
        </div>

        <div class="space-y-2 mb-6">
            <p class="check">Papkalar yaratildi</p>
            <p class="check">.htaccess fayllar joyiga qo'yildi</p>
            <p class="check">.env generatsiya qilindi (APP_KEY: random 64 hex)</p>
            <p class="check">uploads/ papka ruxsatlari sozlandi</p>
        </div>

        <div class="bg-sky-50 rounded-xl p-5 text-sm space-y-3 mb-6">
            <div class="text-xs font-bold text-sky-700 uppercase tracking-wider mb-2">Keyingi qadamlar</div>
            <ol class="list-decimal list-inside space-y-2 text-gray-700">
                <li>Ma'lumotlar bazasini import qiling:<br>
                    <code class="inline-block mt-1 bg-white border border-sky-200 rounded-lg px-3 py-1.5 text-xs">mysql -u <?= htmlspecialchars($dbUser) ?> -p <?= htmlspecialchars($dbName) ?> &lt; database/schema.sql</code>
                </li>
                <li>PHP fayllarni yuklang (app/, bin/, views/, public/index.php, bootstrap.php)</li>
                <li>Telegram webhook:<br>
                    <code class="inline-block mt-1 bg-white border border-sky-200 rounded-lg px-3 py-1.5 text-xs break-all">curl -X POST "https://api.telegram.org/bot<?= htmlspecialchars($tgToken ?: 'TOKEN') ?>/setWebhook" -d "url=https://<?= htmlspecialchars($domain) ?>/api/bot/webhook" -d "secret_token=<?= htmlspecialchars($webhookSecret ?? '') ?>"</code>
                </li>
                <li>Admin:<br><code class="inline-block mt-1 bg-white border border-sky-200 rounded-lg px-3 py-1.5 text-xs">UPDATE users SET role='admin' WHERE phone='+998XXXXXXXXX';</code></li>
                <li class="text-red-600 font-semibold">Bu install.php ni o'chiring!</li>
            </ol>
        </div>

        <div class="bg-gray-50 rounded-xl p-4 text-xs text-gray-600 space-y-1 mb-6">
            <div><strong>Domen:</strong> https://<?= htmlspecialchars($domain) ?></div>
            <div><strong>APP_KEY:</strong> <code class="text-sky-700"><?= htmlspecialchars($appKey ?? '—') ?></code></div>
            <div><strong>WEBHOOK_SECRET:</strong> <code class="text-sky-700"><?= htmlspecialchars($webhookSecret ?? '—') ?></code></div>
        </div>

        <div class="flex items-center gap-3">
            <a href="https://<?= htmlspecialchars($domain) ?>/" class="btn-p">Saytga o'tish →</a>
            <form method="POST" action="" style="display:inline;">
                <input type="hidden" name="self_delete" value="1">
                <button type="submit" class="btn-d">install.php o'chirish</button>
            </form>
        </div>
    </div>

    <?php elseif (isset($_POST['self_delete'])): ?>
        <?php
            @unlink(__FILE__);
            if (!file_exists(__FILE__)) {
                echo '<div class="card p-8 text-center"><div class="w-12 h-12 mx-auto rounded-xl bg-emerald-50 flex items-center justify-center mb-3"><svg class="w-6 h-6 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div><h2 class="text-lg font-bold text-gray-900">install.php o\'chirildi</h2><p class="text-sm text-gray-500 mt-2"><a href="/" class="text-sky-600 font-medium hover:underline">Bosh sahifaga o\'ting →</a></p></div>';
            } else {
                echo '<div class="card p-8 text-center"><h2 class="text-lg font-bold text-red-600">O\'chirib bo\'lmadi</h2><p class="text-sm text-gray-500 mt-2">Qo\'lda: <code class="bg-sky-50 px-2 py-0.5 rounded text-xs">rm public/install.php</code></p></div>';
            }
        ?>

    <?php else: ?>
    <!-- ============ FORM ============ -->
    <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-100 rounded-xl p-4 mb-6">
            <div class="text-xs font-bold text-red-700 uppercase tracking-wider mb-2">Xatolar</div>
            <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="card overflow-hidden">
        <!-- Step 1 -->
        <div class="px-6 py-4 bg-sky-50/50 border-b border-sky-100">
            <div class="text-[10px] text-sky-600 font-bold uppercase tracking-wider">Qadam 1</div>
            <h2 class="text-base font-bold text-gray-900 mt-0.5">Domen sozlamalari</h2>
        </div>
        <div class="p-6">
            <div class="field">
                <label>Domen nomi *</label>
                <input type="text" name="domain" class="input-f" value="<?= htmlspecialchars($domain ?: ($_SERVER['HTTP_HOST'] ?? '')) ?>"
                       placeholder="fizika.uz yoki cert.example.com" required autofocus>
                <span class="text-[10px] text-gray-400">https:// avtomatik. SSL sertifikat sozlangan bo'lishi kerak.</span>
            </div>
        </div>

        <!-- Step 2 -->
        <div class="px-6 py-4 bg-sky-50/50 border-y border-sky-100">
            <div class="text-[10px] text-sky-600 font-bold uppercase tracking-wider">Qadam 2</div>
            <h2 class="text-base font-bold text-gray-900 mt-0.5">Ma'lumotlar bazasi</h2>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="field"><label>DB Host</label><input type="text" name="db_host" class="input-f" value="127.0.0.1"></div>
            <div class="field"><label>DB Port</label><input type="text" name="db_port" class="input-f" value="3306"></div>
            <div class="field"><label>DB Nomi *</label><input type="text" name="db_name" class="input-f" value="physics_cert" required></div>
            <div class="field"><label>DB User</label><input type="text" name="db_user" class="input-f" value="root"></div>
            <div class="field md:col-span-2"><label>DB Parol</label><input type="password" name="db_pass" class="input-f" placeholder="(bo'sh bo'lishi mumkin)"></div>
        </div>

        <!-- Step 3 -->
        <div class="px-6 py-4 bg-sky-50/50 border-y border-sky-100">
            <div class="text-[10px] text-sky-600 font-bold uppercase tracking-wider">Qadam 3</div>
            <h2 class="text-base font-bold text-gray-900 mt-0.5">Telegram &amp; SMS</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="field"><label>Telegram Bot Token</label><input type="text" name="tg_token" class="input-f" placeholder="123456789:ABCdefGHI (BotFather'dan)"><span class="text-[10px] text-gray-400">Keyinroq .env da to'ldirsangiz ham bo'ladi.</span></div>
            <div class="field"><label>SMS Provider</label><select name="sms_provider" class="input-f"><option value="log">Log (dev — kodlar logga yoziladi)</option><option value="eskiz">Eskiz.uz (production)</option></select></div>
            <div class="field"><label>Eskiz Email</label><input type="text" name="eskiz_email" class="input-f" placeholder="admin@example.com"></div>
            <div class="field"><label>Eskiz Password</label><input type="password" name="eskiz_pass" class="input-f"></div>
        </div>

        <!-- Submit -->
        <div class="p-6 border-t border-sky-100">
            <button type="submit" class="btn-p w-full text-center py-4 text-base">O'rnatish →</button>
            <p class="text-center text-[10px] text-gray-400 mt-3">APP_KEY va TG_WEBHOOK_SECRET avtomatik generatsiya qilinadi</p>
        </div>
    </form>
    <?php endif; ?>

</div>
</body>
</html>
