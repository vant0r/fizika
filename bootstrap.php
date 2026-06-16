<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 *  PHP 7.4 polyfills for PHP 8.0+ string functions
 * ------------------------------------------------------------------ */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * bootstrap.php
 * -------------
 * Two-mode bootstrap:
 *
 * Path-agnostic: works whether this file sits at:
 *   /project/bootstrap.php  (standard)
 *   /public_html/bootstrap.php  (x10hosting flat layout)
 */

// APP_ROOT = the directory where this bootstrap.php lives
const APP_ROOT = __DIR__;

/* ------------------------------------------------------------------
 *  Mode A: prefer Composer if vendor/autoload.php is present
 * ------------------------------------------------------------------ */
$composerAutoload = APP_ROOT . '/vendor/autoload.php';
$composerLoaded   = false;

if (is_readable($composerAutoload)) {
    require $composerAutoload;
    $composerLoaded = true;
}

/* ------------------------------------------------------------------
 *  Mode B: fall back to in-house PSR-4 autoloader
 * ------------------------------------------------------------------ */
if (!$composerLoaded) {
    spl_autoload_register(function (string $class): void {
        $prefix = 'App\\';
        $base   = APP_ROOT . '/app/';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $rel  = substr($class, strlen($prefix));
        $file = $base . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) require $file;
    });
}

/* ------------------------------------------------------------------
 *  .env loader  —  prefer vlucas/phpdotenv when available
 * ------------------------------------------------------------------ */
(function (): void {
    $envFile = APP_ROOT . '/.env';
    if (!is_readable($envFile)) return;

    if (class_exists(\Dotenv\Dotenv::class)) {
        // phpdotenv path: handles multiline, interpolation, escaping
        \Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
        return;
    }

    // In-house fallback parser
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && (
            ($val[0] === '"' && $val[-1] === '"') ||
            ($val[0] === "'" && $val[-1] === "'")
        )) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
})();

/* ------------------------------------------------------------------
 *  Required-env validation
 *  If .env is missing or APP_KEY not set → redirect to install.php
 *  (user-friendly instead of cryptic 500)
 * ------------------------------------------------------------------ */
$_envMissing = false;
foreach (['APP_KEY', 'DB_MASTER_DSN'] as $required) {
    if (((string) getenv($required)) === '') {
        $_envMissing = true;
        break;
    }
}
if ($_envMissing) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing required env var. Run install.php or create .env\n");
        exit(1);
    }
    // If install.php exists, redirect there; otherwise show friendly error
    $installPath = (is_file(__DIR__ . '/public/install.php'))
        ? '/install.php'
        : null;
    if ($installPath !== null) {
        // Don't redirect if already on install.php (infinite loop guard)
        $currentUri = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        if (strpos($currentUri, 'install.php') === false) {
            header('Location: ' . $installPath);
            exit;
        }
        // If we ARE on install.php, let it proceed without bootstrap checks
        return;
    }
    // No install.php — show plain error
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Configuration Error</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;">';
    echo '<h1>Configuration Error</h1>';
    echo '<p>.env fayli topilmadi yoki APP_KEY/DB sozlanmagan.</p>';
    echo '<p>Iltimos, <code>.env.example</code> faylini <code>.env</code> ga nusxalab, sozlamalarni kiriting.</p>';
    echo '<p>Yoki <code>public/install.php</code> faylini serverga yuklang.</p>';
    echo '</body></html>';
    exit;
}
unset($_envMissing);

/* ------------------------------------------------------------------
 *  Runtime configuration
 * ------------------------------------------------------------------ */
$env = (string) (getenv('APP_ENV') ?: 'production');

date_default_timezone_set((string) (getenv('APP_TIMEZONE') ?: 'Asia/Tashkent'));
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

if ($env === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

/* ------------------------------------------------------------------
 *  Global exception handler — never leak internals
 * ------------------------------------------------------------------ */
set_exception_handler(function (\Throwable $e) use ($env): void {
    error_log('[FATAL] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'  => 'Internal Server Error',
        'detail' => $env === 'production' ? null : $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

/* ------------------------------------------------------------------
 *  Session bootstrap (uses hardened settings from Security)
 * ------------------------------------------------------------------ */
\App\Core\Security::ensureSession();
