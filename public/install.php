<?php
/**
 * ============================================================
 *  PHYSICS CERT — TO'LIQ INSTALLER (v2)
 * ============================================================
 *
 *  Bu installer:
 *    1. Domen va DB ma'lumotlarini so'raydi
 *    2. Mavjud DB jadvallarini DROP qiladi (toza boshlash)
 *    3. Yangi jadvallarni yaratadi
 *    4. Admin foydalanuvchini qo'shadi (admin / admin1234!)
 *    5. Default tariflar va sozlamalarni qo'shadi
 *    6. .env faylini yaratadi
 *    7. .htaccess fayllar va papkalarni sozlaydi
 *    8. O'zini o'chirish tugmasini ko'rsatadi
 * ============================================================
 */

// Re-install allowed: this version overwrites previous .env

$rootDir = __DIR__;
if (basename($rootDir) === 'public' && is_dir(dirname($rootDir) . '/app')) {
    $rootDir = dirname($rootDir);
}

$errors  = [];
$success = false;
$logs    = [];
$domain  = '';
$dbName  = '';
$dbUser  = '';
$tgToken = '';
$appKey  = '';
$webhookSecret = '';


// ============================================================
//  POST — Install jarayoni
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['self_delete'])) {
    $domain     = trim($_POST['domain']     ?? '');
    $dbHost     = trim($_POST['db_host']    ?? '127.0.0.1');
    $dbPort     = trim($_POST['db_port']    ?? '3306');
    $dbName     = trim($_POST['db_name']    ?? 'physics_cert');
    $dbUser     = trim($_POST['db_user']    ?? 'root');
    $dbPass     = $_POST['db_pass'] ?? '';
    $tgToken    = trim($_POST['tg_token']   ?? '');
    $adminLogin = trim($_POST['admin_login'] ?? 'admin');
    $adminPass  = $_POST['admin_pass'] ?? 'admin1234!';

    if ($domain === '')      $errors[] = 'Domen kiriting';
    if ($dbName === '')      $errors[] = 'DB nomini kiriting';
    if ($adminLogin === '')  $errors[] = 'Admin login kiriting';
    if (strlen($adminPass) < 6) $errors[] = 'Admin parol kamida 6 ta belgi';

    if (empty($errors)) {
        // 1) Test DB connection
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            $logs[] = '✓ DB ulanishi muvaffaqiyatli';
        } catch (PDOException $e) {
            $errors[] = 'DB ulanish xatosi: ' . $e->getMessage();
        }
    }

    if (empty($errors) && isset($pdo)) {
        // 2) DROP existing tables (clean install)
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $tables = [
                'user_answer_reviews', 'notification_jobs', 'otp_challenges',
                'tg_sessions', 'rate_limit_buckets', 'system_settings',
                'questions', 'user_exams', 'payments', 'tariffs',
                'exams', 'users'
            ];
            foreach ($tables as $t) {
                $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $logs[] = '✓ Eski jadvallar o\'chirildi';
        } catch (PDOException $e) {
            $errors[] = 'Jadvallarni o\'chirishda xato: ' . $e->getMessage();
        }
    }

    if (empty($errors) && isset($pdo)) {
        // 3) Create fresh tables
        try {
            createSchema($pdo);
            $logs[] = '✓ Yangi jadvallar yaratildi (12 ta jadval)';
        } catch (PDOException $e) {
            $errors[] = 'Jadval yaratishda xato: ' . $e->getMessage();
        }
    }

    if (empty($errors) && isset($pdo)) {
        // 4) Create admin user
        try {
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare(
                "INSERT INTO users (fullname, phone, password, role, balance, mock_quota, is_active, created_at)
                 VALUES ('Administrator', :login, :pw, 'admin', 0, 999, 1, NOW())"
            );
            $stmt->execute([':login' => $adminLogin, ':pw' => $hash]);
            $logs[] = "✓ Admin foydalanuvchi yaratildi (login: {$adminLogin})";
        } catch (PDOException $e) {
            $errors[] = 'Admin yaratishda xato: ' . $e->getMessage();
        }
    }

    if (empty($errors) && isset($pdo)) {
        // 5) Insert default settings & tariffs
        try {
            seedDefaults($pdo, $domain);
            $logs[] = '✓ Default sozlamalar va tariflar qo\'shildi';
        } catch (PDOException $e) {
            $errors[] = 'Defaults yozishda xato: ' . $e->getMessage();
        }
    }


    if (empty($errors)) {
        // 6) Create directories
        $dirs = [
            $rootDir . '/app/Bot', $rootDir . '/app/Config', $rootDir . '/app/Controllers',
            $rootDir . '/app/Core', $rootDir . '/app/Queue/Handlers', $rootDir . '/app/Sms',
            $rootDir . '/bin', $rootDir . '/database', $rootDir . '/views',
            $rootDir . '/public/uploads/logo',
            $rootDir . '/public/uploads/slider',
            $rootDir . '/public/uploads/screenshots',
            $rootDir . '/public/uploads/banner',
        ];
        // For flat layout (x10hosting), uploads are in __DIR__/uploads
        if ($rootDir === __DIR__) {
            $dirs = array_merge($dirs, [
                $rootDir . '/uploads/logo',
                $rootDir . '/uploads/slider',
                $rootDir . '/uploads/screenshots',
                $rootDir . '/uploads/banner',
            ]);
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
        }
        $logs[] = '✓ Papkalar yaratildi';

        // 7) .gitkeep files
        $uploadDir = is_dir($rootDir . '/public/uploads') ? $rootDir . '/public/uploads' : $rootDir . '/uploads';
        foreach (['logo', 'slider', 'screenshots', 'banner'] as $sub) {
            $gk = $uploadDir . "/{$sub}/.gitkeep";
            if (!file_exists($gk)) @file_put_contents($gk, '');
        }

        // 8) uploads/.htaccess
        $htContent = <<<'HTACCESS'
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
Options -ExecCGI -Indexes
AddType text/plain .php .php3 .php4 .php5 .phtml .phar
<FilesMatch "\.(php|phtml|phar)$">
    <IfModule mod_authz_core.c>Require all denied</IfModule>
    <IfModule !mod_authz_core.c>Order deny,allow
Deny from all</IfModule>
</FilesMatch>
HTACCESS;
        @file_put_contents($uploadDir . '/.htaccess', $htContent);

        // 9) Generate APP_KEY and webhook secret
        $appKey       = bin2hex(random_bytes(32));
        $webhookSecret= bin2hex(random_bytes(32));

        // 10) Write .env
        $envDsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $envContent = <<<ENV
# =====================================================================
# Physics Cert — Generated by install.php on {$domain}
# =====================================================================

APP_ENV=production
APP_TIMEZONE=Asia/Tashkent
APP_KEY={$appKey}
APP_DOMAIN={$domain}

# Database
DB_MASTER_DSN="{$envDsn}"
DB_MASTER_USER={$dbUser}
DB_MASTER_PASS={$dbPass}
DB_REPLICA_DSN=
DB_REPLICA_USER=
DB_REPLICA_PASS=

# Telegram Bot
TG_BOT_TOKEN={$tgToken}
TG_WEBHOOK_SECRET={$webhookSecret}

# SMS (kerak emas — log mode)
SMS_PROVIDER=log
SMS_PROVIDER_FORCE_LOG=1
SMS_ESKIZ_EMAIL=
SMS_ESKIZ_PASSWORD=
SMS_FROM=4546

ENV;
        $envPath = $rootDir . '/.env';
        if (@file_put_contents($envPath, $envContent, LOCK_EX) !== false) {
            $logs[] = '✓ .env fayli yaratildi';
        } else {
            $errors[] = '.env yozib bo\'lmadi (chmod 755 papkaga)';
        }
    }

    if (empty($errors)) {
        $success = true;
    }
}


// ============================================================
//  Self-delete
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['self_delete'])) {
    $deleted = @unlink(__FILE__);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>install.php</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-white">';
    if ($deleted) {
        echo '<div class="max-w-md mx-auto mt-20 p-8 text-center"><h2 class="text-2xl font-bold text-emerald-600">✓ install.php o\'chirildi</h2><p class="mt-3 text-gray-600">Endi <a href="/" class="text-sky-600 font-semibold underline">bosh sahifaga</a> o\'ting va <code class="bg-sky-50 px-2 py-1 rounded">admin</code> sifatida kiring.</p></div>';
    } else {
        echo '<div class="max-w-md mx-auto mt-20 p-8 text-center"><h2 class="text-2xl font-bold text-red-600">O\'chirib bo\'lmadi</h2><p class="mt-3 text-gray-600">Qo\'lda o\'chiring: <code class="bg-sky-50 px-2 py-1 rounded">install.php</code></p></div>';
    }
    echo '</body></html>';
    exit;
}

// ============================================================
//  HELPER FUNCTIONS
// ============================================================
function createSchema(PDO $pdo): void {
    $sql = [];

    $sql[] = "CREATE TABLE `users` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `fullname` VARCHAR(150) NOT NULL,
        `phone` VARCHAR(50) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('student','admin','moderator') NOT NULL DEFAULT 'student',
        `tg_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
        `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `mock_quota` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_login_ip` VARBINARY(16) NULL,
        `last_login_at` TIMESTAMP NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `totp_secret` VARCHAR(255) NULL,
        `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `totp_recovery` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_users_phone` (`phone`),
        KEY `idx_users_role` (`role`),
        KEY `idx_users_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `exams` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(255) NOT NULL,
        `subject` VARCHAR(100) NOT NULL DEFAULT 'Fizika',
        `duration` INT UNSIGNED NOT NULL DEFAULT 120,
        `total_qty` INT UNSIGNED NOT NULL DEFAULT 30,
        `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
        `created_by` BIGINT UNSIGNED NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_exams_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `tariffs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(120) NOT NULL,
        `price` DECIMAL(12,2) NOT NULL,
        `mock_count` INT UNSIGNED NOT NULL DEFAULT 1,
        `description` TEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `payments` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `tariff_id` BIGINT UNSIGNED NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `screenshot_path` VARCHAR(500) NOT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `reviewed_by` BIGINT UNSIGNED NULL,
        `reviewed_at` TIMESTAMP NULL,
        `note` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pay_status` (`status`),
        KEY `idx_pay_user` (`user_id`),
        CONSTRAINT `fk_pay_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_pay_tariff` FOREIGN KEY (`tariff_id`) REFERENCES `tariffs`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `user_exams` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `exam_id` BIGINT UNSIGNED NOT NULL,
        `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
        `score` DECIMAL(6,2) NULL,
        `correct_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `wrong_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `section_analysis` TEXT NULL,
        `answers_snapshot` MEDIUMTEXT NULL,
        `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `finished_at` TIMESTAMP NULL,
        `status` ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
        PRIMARY KEY (`id`),
        KEY `idx_uex_user` (`user_id`),
        KEY `idx_uex_exam` (`exam_id`),
        CONSTRAINT `fk_uex_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_uex_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `questions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `exam_id` BIGINT UNSIGNED NOT NULL,
        `parent_id` BIGINT UNSIGNED NULL,
        `sub_label` VARCHAR(20) NULL,
        `section` VARCHAR(100) NOT NULL DEFAULT 'Umumiy',
        `type` ENUM('mcq','open','matching','closed','combined') NOT NULL DEFAULT 'mcq',
        `difficulty` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        `max_points` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        `question_text` MEDIUMTEXT NOT NULL,
        `options` TEXT NULL,
        `correct_answer` TEXT NULL,
        `sample_answer` MEDIUMTEXT NULL,
        `solution_text` MEDIUMTEXT NULL,
        `video_url` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_q_exam` (`exam_id`),
        KEY `idx_q_parent` (`parent_id`),
        CONSTRAINT `fk_q_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_q_parent` FOREIGN KEY (`parent_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `system_settings` (
        `key` VARCHAR(120) NOT NULL,
        `value` TEXT NULL,
        `updated_by` BIGINT UNSIGNED NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `rate_limit_buckets` (
        `bucket_key` VARCHAR(190) NOT NULL,
        `hits` INT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`bucket_key`),
        KEY `idx_rl_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql[] = "CREATE TABLE `tg_sessions` (
        `tg_user_id` BIGINT UNSIGNED NOT NULL,
        `state` VARCHAR(60) NOT NULL DEFAULT 'idle',
        `payload` TEXT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`tg_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql[] = "CREATE TABLE `otp_challenges` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `phone` VARCHAR(50) NOT NULL,
        `code_hash` CHAR(64) NOT NULL,
        `purpose` VARCHAR(40) NOT NULL DEFAULT 'register',
        `payload` TEXT NULL,
        `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
        `consumed_at` TIMESTAMP NULL,
        `expires_at` TIMESTAMP NOT NULL,
        `ip` VARCHAR(45) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_otp_phone` (`phone`),
        KEY `idx_otp_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `notification_jobs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `job_type` VARCHAR(50) NOT NULL,
        `payload` TEXT NOT NULL,
        `status` ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
        `available_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `locked_at` TIMESTAMP NULL,
        `locked_by` VARCHAR(64) NULL,
        `last_error` TEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_jobs_status_avail` (`status`, `available_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE `user_answer_reviews` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_exam_id` BIGINT UNSIGNED NOT NULL,
        `question_id` BIGINT UNSIGNED NOT NULL,
        `user_answer` MEDIUMTEXT NULL,
        `auto_score` DECIMAL(5,2) NULL,
        `admin_score` DECIMAL(5,2) NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `reviewed_by` BIGINT UNSIGNED NULL,
        `reviewed_at` TIMESTAMP NULL,
        `note` VARCHAR(500) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_uar_status` (`status`),
        CONSTRAINT `fk_uar_userexam` FOREIGN KEY (`user_exam_id`) REFERENCES `user_exams`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_uar_question` FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($sql as $stmt) {
        $pdo->exec($stmt);
    }
}

function seedDefaults(PDO $pdo, string $domain): void {
    $stmt = $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)");
    $defaults = [
        ['site_logo',     '/uploads/logo/default.png'],
        ['site_banner',   ''],
        ['slider_images', '[]'],
        ['humo_card',     '9860 0101 0000 0000'],
        ['visa_card',     '4000 0000 0000 0000'],
        ['card_holder',   'PHYSICS CERT LLC'],
        ['bot_username',  'physics_cert_bot'],
        ['platform_name', 'Physics National Certificate'],
        ['about_text',    'Physics Cert — Fizika fanidan Milliy Sertifikat olishga professional tarzda tayyorlovchi platforma. BMBA metodologiyasi, virtual qoralama, MathJax formulalar va Telegram bot orqali oson to\'lov tizimi.'],
        ['contact_email', 'info@' . $domain],
        ['contact_phone', '+998 90 000 00 00'],
    ];
    foreach ($defaults as $row) $stmt->execute($row);

    $stmt = $pdo->prepare(
        "INSERT INTO tariffs (name, price, mock_count, description, sort_order)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute(['Boshlang\'ich', 49000, 3, '3 ta to\'liq mock imtihon', 1]);
    $stmt->execute(['Standart', 129000, 10, '10 ta mock + bo\'limlar tahlili', 2]);
    $stmt->execute(['PRO', 249000, 30, '30 ta mock + video yechimlar + ustuvor qo\'llab-quvvatlash', 3]);
}


?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — Physics Cert</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Inter',sans-serif;background:#fafbfc;}
        ::selection{background:#0ea5e9;color:#fff;}
        .card{background:#fff;border-radius:16px;border:1px solid #e0f2fe;box-shadow:0 2px 12px rgba(14,165,233,.04);}
        .btn-p{background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;border:none;border-radius:10px;padding:.7rem 1.3rem;font-weight:700;font-size:.85rem;cursor:pointer;transition:all .15s;display:inline-block;text-decoration:none;}
        .btn-p:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(14,165,233,.25);}
        .btn-d{background:linear-gradient(135deg,#dc2626,#991b1b);color:#fff;border:none;border-radius:10px;padding:.7rem 1.3rem;font-weight:700;font-size:.85rem;cursor:pointer;transition:all .15s;}
        .input-f{border:1.5px solid #e0f2fe;border-radius:10px;padding:.6rem .8rem;width:100%;outline:none;transition:all .15s;font-size:.85rem;background:#fff;}
        .input-f:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.08);}
        .field{display:flex;flex-direction:column;gap:.3rem;}
        .field label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;color:#64748b;}
    </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-10">
<div class="w-full max-w-2xl">

    <div class="mb-8 text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-sky-400 to-sky-600 flex items-center justify-center shadow-lg shadow-sky-200 mb-4">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
        <h1 class="text-3xl font-black text-gray-900">Physics Cert</h1>
        <p class="text-sm text-gray-500 mt-1">To'liq o'rnatish</p>
    </div>

    <?php if ($success): ?>
    <div class="card p-8">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center">
                <svg class="w-6 h-6 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900">Muvaffaqiyatli o'rnatildi!</h2>
                <p class="text-xs text-gray-500">Tizim ishga tayyor</p>
            </div>
        </div>

        <div class="space-y-2 mb-6 bg-emerald-50 rounded-xl p-4">
            <?php foreach ($logs as $log): ?>
                <p class="text-sm text-emerald-800"><?= htmlspecialchars($log) ?></p>
            <?php endforeach; ?>
        </div>

        <div class="bg-sky-50 rounded-xl p-5 mb-6 border border-sky-100">
            <div class="text-xs font-bold text-sky-700 uppercase tracking-wider mb-3">Admin kirish ma'lumoti</div>
            <div class="space-y-2 text-sm">
                <div><strong class="text-gray-700">Login:</strong> <code class="bg-white px-2 py-0.5 rounded border border-sky-200"><?= htmlspecialchars($_POST['admin_login'] ?? 'admin') ?></code></div>
                <div><strong class="text-gray-700">Parol:</strong> <code class="bg-white px-2 py-0.5 rounded border border-sky-200"><?= htmlspecialchars($_POST['admin_pass'] ?? 'admin1234!') ?></code></div>
                <div><strong class="text-gray-700">Sayt:</strong> <a href="https://<?= htmlspecialchars($domain) ?>" class="text-sky-600 underline">https://<?= htmlspecialchars($domain) ?></a></div>
            </div>
        </div>

        <div class="bg-amber-50 rounded-xl p-4 mb-6 border border-amber-200">
            <p class="text-sm text-amber-800">
                <strong>⚠️ MUHIM:</strong> install.php ni HOZIROQ o'chiring. Aks holda kimdir DB ni qaytadan o'rnatib, ma'lumotlarni o'chirib yuborishi mumkin.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="/auth" class="btn-p flex-1 text-center">Saytga o'tish →</a>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="self_delete" value="1">
                <button type="submit" class="btn-d">install.php o'chirish</button>
            </form>
        </div>
    </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-100 rounded-xl p-4 mb-6">
            <div class="text-xs font-bold text-red-700 uppercase tracking-wider mb-2">Xatolar</div>
            <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($logs)): ?>
        <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 mb-6">
            <div class="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-2">Bajarildi</div>
            <ul class="text-sm text-emerald-700 space-y-1">
                <?php foreach ($logs as $log): ?><li><?= htmlspecialchars($log) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="card overflow-hidden">
        <div class="px-6 py-4 bg-sky-50/50 border-b border-sky-100">
            <h2 class="text-base font-bold text-gray-900">1. Domen</h2>
        </div>
        <div class="p-6">
            <div class="field">
                <label>Domen</label>
                <input type="text" name="domain" class="input-f" value="<?= htmlspecialchars($domain ?: ($_SERVER['HTTP_HOST'] ?? '')) ?>" required autofocus>
            </div>
        </div>

        <div class="px-6 py-4 bg-sky-50/50 border-y border-sky-100">
            <h2 class="text-base font-bold text-gray-900">2. Ma'lumotlar bazasi</h2>
            <p class="text-xs text-amber-600 mt-1">⚠️ Mavjud jadvallar O'CHIRILADI va yangidan yaratiladi</p>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="field"><label>DB Host</label><input type="text" name="db_host" class="input-f" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"></div>
            <div class="field"><label>DB Port</label><input type="text" name="db_port" class="input-f" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>"></div>
            <div class="field"><label>DB Nomi</label><input type="text" name="db_name" class="input-f" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required></div>
            <div class="field"><label>DB User</label><input type="text" name="db_user" class="input-f" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"></div>
            <div class="field md:col-span-2"><label>DB Parol</label><input type="password" name="db_pass" class="input-f" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>"></div>
        </div>

        <div class="px-6 py-4 bg-sky-50/50 border-y border-sky-100">
            <h2 class="text-base font-bold text-gray-900">3. Admin foydalanuvchi</h2>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="field"><label>Admin login</label><input type="text" name="admin_login" class="input-f" value="<?= htmlspecialchars($_POST['admin_login'] ?? 'admin') ?>" required></div>
            <div class="field"><label>Admin parol</label><input type="text" name="admin_pass" class="input-f" value="<?= htmlspecialchars($_POST['admin_pass'] ?? 'admin1234!') ?>" required></div>
        </div>

        <div class="px-6 py-4 bg-sky-50/50 border-y border-sky-100">
            <h2 class="text-base font-bold text-gray-900">4. Telegram (ixtiyoriy)</h2>
        </div>
        <div class="p-6">
            <div class="field"><label>Bot Token</label><input type="text" name="tg_token" class="input-f" value="<?= htmlspecialchars($_POST['tg_token'] ?? '') ?>" placeholder="Keyinroq .env da ham qo'shsa bo'ladi"></div>
        </div>

        <div class="p-6 border-t border-sky-100">
            <button type="submit" class="btn-p w-full text-center py-4 text-base">
                ⚡ To'liq o'rnatish
            </button>
            <p class="text-center text-[10px] text-gray-400 mt-3">Eski jadvallar o'chiriladi va yangisi yaratiladi</p>
        </div>
    </form>
    <?php endif; ?>

</div>
</body>
</html>
