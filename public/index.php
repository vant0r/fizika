<?php
declare(strict_types=1);

/**
 * /public/index.php — Front Controller
 * ------------------------------------
 * Single entry point. All requests routed through here via .htaccess.
 * Loads bootstrap (env, autoloader), dispatches to a controller method.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ExamController;
use App\Controllers\HomeController;
use App\Bot\TelegramWebhook;
use App\Core\Security;

Security::securityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
$uri    = '/' . trim($uri, '/');
if ($uri === '') $uri = '/';

/* ------------------------------------------------------------------
 *  Router  —  pattern: METHOD path  =>  callable
 * ------------------------------------------------------------------ */
$routes = [
    /* PUBLIC PAGES */
    'GET /'                  => [HomeController::class,   'landing'],
    'GET /auth'              => [AuthController::class,   'showAuthPage'],
    'GET /profile'           => [AuthController::class,   'showProfile'],
    'GET /admin'             => [AdminController::class,  'panel'],
    'GET /exam'              => function () {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(404); echo 'Not Found'; return; }
        (new ExamController())->show($id);
    },

    /* AUTH API */
    'POST /api/auth/login'              => [AuthController::class, 'login'],
    'POST /api/auth/login/verify-2fa'   => [AuthController::class, 'verify2fa'],
    'POST /api/auth/register'           => [AuthController::class, 'register'],
    'POST /api/auth/register/verify'    => [AuthController::class, 'verifyRegistration'],
    'POST /api/auth/register/resend'    => [AuthController::class, 'resendRegistrationOtp'],
    'POST /api/auth/logout'             => [AuthController::class, 'logout'],
    'POST /api/auth/refresh'            => [AuthController::class, 'refresh'],
    'GET  /api/auth/me'                 => [AuthController::class, 'me'],
    'GET  /api/auth/2fa/status'         => [AuthController::class, 'tfaStatus'],
    'POST /api/auth/2fa/begin'          => [AuthController::class, 'tfaBegin'],
    'POST /api/auth/2fa/confirm'        => [AuthController::class, 'tfaConfirm'],
    'POST /api/auth/2fa/disable'        => [AuthController::class, 'tfaDisable'],
    'POST /api/auth/2fa/regenerate'     => [AuthController::class, 'tfaRegenerate'],

    /* PUBLIC TARIFFS for profile modal */
    'GET /api/tariffs'        => [AdminController::class, 'publicTariffs'],

    /* TELEGRAM WEBHOOK */
    'POST /api/bot/webhook'   => [TelegramWebhook::class, 'handle'],

    /* EXAM API */
    /* GET  /api/exam/{id}/state  */
    /* POST /api/exam/{id}/start  */
    /* POST /api/exam/{id}/save   */
    /* POST /api/exam/{id}/submit */
    /* GET  /api/exam/{id}/result */

    /* ADMIN API */
    'GET  /api/admin/dashboard'         => [AdminController::class, 'dashboard'],
    'GET  /api/admin/maintenance'       => [AdminController::class, 'maintenance'],
    'POST /api/admin/maintenance/gc'    => [AdminController::class, 'runMaintenance'],
    'GET  /api/admin/payments'          => [AdminController::class, 'listPayments'],
    'GET  /api/admin/tariffs'           => [AdminController::class, 'listTariffs'],
    'POST /api/admin/tariffs'           => [AdminController::class, 'saveTariff'],
    'GET  /api/admin/exams'             => [AdminController::class, 'listExams'],
    'POST /api/admin/exams'             => [AdminController::class, 'saveExam'],
    'POST /api/admin/questions'         => [AdminController::class, 'saveQuestion'],
    'GET  /api/admin/settings'          => [AdminController::class, 'getSettings'],
    'POST /api/admin/cards'             => [AdminController::class, 'updateCards'],
    'POST /api/admin/logo'              => [AdminController::class, 'uploadLogo'],
    'POST /api/admin/slider'            => [AdminController::class, 'uploadSliderImage'],
    'POST /api/admin/slider/delete'     => [AdminController::class, 'deleteSliderImage'],
];

/* Try direct match first */
$key = strtoupper($method) . ' ' . $uri;
$keyAlt = strtoupper($method) . '  ' . $uri;
foreach ([$key, $keyAlt] as $k) {
    if (isset($routes[$k])) {
        dispatch($routes[$k]);
        exit;
    }
}

/* Pattern routes (with positional params) */
$patterns = [
    ['POST', '#^/api/exam/(\d+)/start$#',   [ExamController::class, 'start']],
    ['GET',  '#^/api/exam/(\d+)/state$#',   [ExamController::class, 'state']],
    ['POST', '#^/api/exam/(\d+)/save$#',    [ExamController::class, 'saveAnswer']],
    ['POST', '#^/api/exam/(\d+)/submit$#',  [ExamController::class, 'submit']],
    ['GET',  '#^/api/exam/(\d+)/result$#',  [ExamController::class, 'result']],

    ['POST', '#^/api/admin/payments/(\d+)/approve$#', [AdminController::class, 'approvePayment']],
    ['POST', '#^/api/admin/payments/(\d+)/reject$#',  [AdminController::class, 'rejectPayment']],
    ['POST', '#^/api/admin/tariffs/(\d+)/delete$#',   [AdminController::class, 'deleteTariff']],
    ['POST', '#^/api/admin/exams/(\d+)/delete$#',     [AdminController::class, 'deleteExam']],
    ['GET',  '#^/api/admin/exams/(\d+)/questions$#',  [AdminController::class, 'listQuestions']],
    ['GET',  '#^/api/admin/questions/(\d+)$#',        [AdminController::class, 'getQuestion']],
    ['POST', '#^/api/admin/questions/(\d+)/delete$#', [AdminController::class, 'deleteQuestion']],
];

foreach ($patterns as [$m, $regex, $handler]) {
    if ($m === $method && preg_match($regex, $uri, $matches)) {
        array_shift($matches);
        $args = array_map('intval', $matches);
        dispatchWithArgs($handler, $args);
        exit;
    }
}

/* No match */
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not Found', 'path' => $uri], JSON_UNESCAPED_UNICODE);

/* ------------------------------------------------------------------
 *  Dispatch helpers
 * ------------------------------------------------------------------ */
function dispatch(callable|array $handler): void
{
    if (is_array($handler)) {
        [$class, $method] = $handler;
        $instance = is_string($class) ? new $class() : $class;
        $instance->{$method}();
        return;
    }
    $handler();
}

/** @param array<int,int> $args */
function dispatchWithArgs(array $handler, array $args): void
{
    [$class, $method] = $handler;
    $instance = is_string($class) ? new $class() : $class;
    $instance->{$method}(...$args);
}
