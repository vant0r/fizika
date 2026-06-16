<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Bot\TelegramWebhook;
use App\Config\Database;
use App\Core\AuthManager;
use App\Core\Security;
use App\Core\SvgSanitizer;
use App\Queue\Queue;
use RuntimeException;
use Throwable;

/**
 * AdminController
 * ---------------
 * - Payments verification (approve / reject) with TX safety
 * - Tariffs CRUD
 * - Site settings: logo, slider images (multiple), Humo/Visa cards
 * - Notifies user via Telegram bot on approval
 *
 * All endpoints require role=admin and a CSRF token (intent='admin').
 */
final class AdminController
{
    private const UPLOAD_LOGO_DIR    = '/uploads/logo/';
    private const UPLOAD_SLIDER_DIR  = '/uploads/slider/';
    /** Raster MIME types are accepted everywhere. */
    private const RASTER_IMG_MIMES   = ['image/png', 'image/jpeg', 'image/webp'];
    /** SVG is accepted ONLY where explicitly enabled (logo), and always sanitized. */
    private const SVG_MIME           = 'image/svg+xml';
    private const MAX_IMG_BYTES      = 5 * 1024 * 1024; // 5 MB

    /* ============================================================
     *  ADMIN PANEL (HTML)
     * ============================================================ */
    public function panel(): void
    {
        $admin = AuthManager::requireRole('admin');
        $csrf  = Security::csrfToken('admin', 7200);

        // Logo for header (best-effort)
        $logoRow = Database::selectOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'site_logo'"
        );
        $logo = (string) ($logoRow['value'] ?? '/uploads/logo/default.png');

        Security::securityHeaders();
        require __DIR__ . '/../../views/admin.phtml';
    }

    /* ============================================================
     *  MAINTENANCE / GC
     * ============================================================ */

    public function maintenance(): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        self::json(['stats' => Security::maintenanceStats()]);
    }

    public function runMaintenance(): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        Security::enforceRateLimit('admin:gc', 10, 60);   // protect against abuse
        $result = Security::gcAll();
        self::json([
            'ok'       => true,
            'cleaned'  => $result,
            'stats'    => Security::maintenanceStats(),
        ]);
    }

    /* ============================================================
     *  DASHBOARD STATS
     * ============================================================ */
    public function dashboard(): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');

        $stats = [
            'pending_payments' => (int) (Database::selectOne(
                "SELECT COUNT(*) AS c FROM payments WHERE status = 'pending'"
            )['c'] ?? 0),
            'total_users'      => (int) (Database::selectOne(
                "SELECT COUNT(*) AS c FROM users"
            )['c'] ?? 0),
            'active_exams'     => (int) (Database::selectOne(
                "SELECT COUNT(*) AS c FROM exams WHERE status = 'published'"
            )['c'] ?? 0),
            'today_revenue'    => (float) (Database::selectOne(
                "SELECT COALESCE(SUM(amount),0) AS s FROM payments
                 WHERE status = 'approved' AND DATE(reviewed_at) = CURDATE()"
            )['s'] ?? 0),
        ];
        self::json($stats);
    }

    /* ============================================================
     *  PAYMENTS
     * ============================================================ */

    public function listPayments(): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        $status = (string) ($_GET['status'] ?? 'pending');
        $status = Security::safeIdentifier($status, ['pending', 'approved', 'rejected'], 'pending');
        $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $rows = Database::select(
            "SELECT p.id, p.user_id, p.tariff_id, p.amount, p.screenshot_path,
                    p.status, p.created_at, p.note,
                    u.fullname, u.phone, u.tg_user_id,
                    t.name AS tariff_name, t.mock_count
             FROM payments p
             JOIN users u   ON u.id = p.user_id
             JOIN tariffs t ON t.id = p.tariff_id
             WHERE p.status = :s
             ORDER BY p.created_at DESC
             LIMIT $limit OFFSET $offset",
            [':s' => $status]
        );
        self::json(['items' => $rows]);
    }

    public function approvePayment(int $paymentId): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);

        try {
            $context = Database::transaction(function ($pdo) use ($paymentId, $admin) {
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.user_id, p.tariff_id, p.amount, p.status,
                            t.name AS tariff_name, t.mock_count, t.price,
                            u.tg_user_id
                     FROM payments p
                     JOIN tariffs t ON t.id = p.tariff_id
                     JOIN users   u ON u.id = p.user_id
                     WHERE p.id = :id FOR UPDATE"
                );
                $stmt->execute([':id' => $paymentId]);
                $pay = $stmt->fetch();
                if ($pay === false) {
                    throw new RuntimeException('To\'lov topilmadi');
                }
                if ($pay['status'] !== 'pending') {
                    throw new RuntimeException('To\'lov holati pending emas');
                }

                $pdo->prepare(
                    "UPDATE payments
                       SET status = 'approved', reviewed_by = :rb, reviewed_at = NOW()
                     WHERE id = :id"
                )->execute([':rb' => $admin['id'], ':id' => $paymentId]);

                $pdo->prepare(
                    "UPDATE users
                       SET balance = balance + :amt,
                           mock_quota = mock_quota + :mc
                     WHERE id = :uid"
                )->execute([
                    ':amt' => (float) $pay['amount'],
                    ':mc'  => (int)   $pay['mock_count'],
                    ':uid' => (int)   $pay['user_id'],
                ]);

                return [
                    'tg_user_id' => (int) ($pay['tg_user_id'] ?? 0),
                    'tariff'     => (string) $pay['tariff_name'],
                    'mocks'      => (int) $pay['mock_count'],
                    'amount'     => (float) $pay['amount'],
                ];
            });
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }

        if ($context['tg_user_id'] > 0) {
            $msg = "✅ *To'lovingiz muvaffaqiyatli tasdiqlandi!*\n\n"
                 . "Tarif: *" . str_replace('*', '', $context['tariff']) . "*\n"
                 . "Mock kvota: *" . $context['mocks'] . "*\n"
                 . "Endi siz imtihon topshirishingiz mumkin. Omad! 🎓";
            // Async dispatch via the notification queue — admin doesn't wait for TG.
            Queue::enqueue('tg_send', [
                'chat_id' => $context['tg_user_id'],
                'text'    => $msg,
            ]);
        }
        self::json(['ok' => true]);
    }

    public function rejectPayment(int $paymentId): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $body = self::readJson();
        $note = mb_substr((string) ($body['note'] ?? ''), 0, 500);

        try {
            $context = Database::transaction(function ($pdo) use ($paymentId, $admin, $note) {
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.status, u.tg_user_id
                       FROM payments p
                       JOIN users u ON u.id = p.user_id
                      WHERE p.id = :id FOR UPDATE"
                );
                $stmt->execute([':id' => $paymentId]);
                $pay = $stmt->fetch();
                if ($pay === false) {
                    throw new RuntimeException('To\'lov topilmadi');
                }
                if ($pay['status'] !== 'pending') {
                    throw new RuntimeException('To\'lov holati pending emas');
                }
                $pdo->prepare(
                    "UPDATE payments
                       SET status = 'rejected', reviewed_by = :rb, reviewed_at = NOW(), note = :n
                     WHERE id = :id"
                )->execute([
                    ':rb' => $admin['id'],
                    ':n'  => $note ?: null,
                    ':id' => $paymentId,
                ]);
                return ['tg_user_id' => (int) ($pay['tg_user_id'] ?? 0)];
            });
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }

        if ($context['tg_user_id'] > 0) {
            $msg = "❌ *Afsus, to'lovingiz rad etildi.*\n"
                 . ($note !== '' ? "Sabab: " . str_replace(['*','_','`'], '', $note) . "\n" : '')
                 . "Iltimos, qayta urinib ko'ring yoki administrator bilan bog'laning.";
            Queue::enqueue('tg_send', [
                'chat_id' => $context['tg_user_id'],
                'text'    => $msg,
            ]);
        }
        self::json(['ok' => true]);
    }

    /* ============================================================
     *  TARIFFS
     * ============================================================ */

    public function listTariffs(): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        $rows = Database::select(
            "SELECT id, name, price, mock_count, description, is_active, sort_order
             FROM tariffs ORDER BY sort_order ASC, id ASC"
        );
        self::json(['items' => $rows]);
    }

    public function saveTariff(): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $b = self::readJson();

        $id          = (int)   ($b['id'] ?? 0);
        $name        = trim((string) ($b['name'] ?? ''));
        $price       = (float) ($b['price'] ?? 0);
        $mockCount   = (int)   ($b['mock_count'] ?? 0);
        $description = (string)($b['description'] ?? '');
        $isActive    = !empty($b['is_active']) ? 1 : 0;
        $sort        = (int)   ($b['sort_order'] ?? 0);

        if ($name === '' || $price < 0 || $mockCount < 0) {
            self::json(['error' => 'Maydonlar noto\'g\'ri'], 422);
        }

        if ($id > 0) {
            Database::execute(
                "UPDATE tariffs
                   SET name = :n, price = :p, mock_count = :mc, description = :d,
                       is_active = :a, sort_order = :s
                 WHERE id = :id",
                [
                    ':n' => $name, ':p' => $price, ':mc' => $mockCount,
                    ':d' => $description, ':a' => $isActive, ':s' => $sort,
                    ':id'=> $id,
                ]
            );
            self::json(['ok' => true, 'id' => $id]);
        }
        Database::execute(
            "INSERT INTO tariffs (name, price, mock_count, description, is_active, sort_order)
             VALUES (:n, :p, :mc, :d, :a, :s)",
            [
                ':n' => $name, ':p' => $price, ':mc' => $mockCount,
                ':d' => $description, ':a' => $isActive, ':s' => $sort,
            ]
        );
        self::json(['ok' => true, 'id' => (int) Database::master()->lastInsertId()]);
    }

    public function deleteTariff(int $id): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        Database::execute("UPDATE tariffs SET is_active = 0 WHERE id = :id", [':id' => $id]);
        self::json(['ok' => true]);
    }

    /* ============================================================
     *  PUBLIC TARIFFS  (used by /views/profile.phtml mock modal)
     * ============================================================ */
    public function publicTariffs(): void
    {
        AuthManager::requireUser();
        $rows = Database::select(
            "SELECT id, name, price, mock_count, description
             FROM tariffs WHERE is_active = 1
             ORDER BY sort_order ASC, price ASC"
        );
        $bot = Database::selectOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'bot_username'"
        );
        self::json([
            'items'        => $rows,
            'bot_username' => (string) ($bot['value'] ?? 'physics_cert_bot'),
        ]);
    }

    /* ============================================================
     *  EXAMS  (CRUD)
     * ============================================================ */

    public function listExams(): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        $rows = Database::select(
            "SELECT e.id, e.title, e.subject, e.duration, e.total_qty, e.status,
                    e.created_at, e.updated_at,
                    (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS questions_count,
                    (SELECT COUNT(*) FROM user_exams ue
                       WHERE ue.exam_id = e.id AND ue.status = 'submitted')   AS submissions_count
             FROM exams e
             ORDER BY e.id DESC"
        );
        self::json(['items' => $rows]);
    }

    public function saveExam(): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $b = self::readJson();

        $id        = (int)    ($b['id']        ?? 0);
        $title     = trim((string) ($b['title']    ?? ''));
        $subject   = trim((string) ($b['subject']  ?? 'Fizika'));
        $duration  = (int)    ($b['duration']  ?? 120);
        $totalQty  = (int)    ($b['total_qty'] ?? 30);
        $status    = (string) ($b['status']    ?? 'draft');
        $status    = in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';

        if ($title === '' || $duration < 1 || $totalQty < 1) {
            self::json(['error' => 'Sarlavha, davomiylik va savollar soni majburiy'], 422);
        }
        if (mb_strlen($title) > 255) {
            self::json(['error' => 'Sarlavha 255 belgidan oshmasin'], 422);
        }

        if ($id > 0) {
            Database::execute(
                "UPDATE exams
                    SET title = :t, subject = :s, duration = :d,
                        total_qty = :tq, status = :st
                  WHERE id = :id",
                [
                    ':t'  => $title, ':s' => $subject,
                    ':d'  => $duration, ':tq' => $totalQty,
                    ':st' => $status,  ':id' => $id,
                ]
            );
            self::json(['ok' => true, 'id' => $id]);
        }

        Database::execute(
            "INSERT INTO exams (title, subject, duration, total_qty, status, created_by)
             VALUES (:t, :s, :d, :tq, :st, :cb)",
            [
                ':t'  => $title, ':s'  => $subject,
                ':d'  => $duration, ':tq' => $totalQty,
                ':st' => $status, ':cb' => (int) $admin['id'],
            ]
        );
        self::json(['ok' => true, 'id' => (int) Database::master()->lastInsertId()]);
    }

    public function deleteExam(int $id): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        // Soft delete: archive the exam (CASCADE on questions would lose work)
        Database::execute("UPDATE exams SET status = 'archived' WHERE id = :id", [':id' => $id]);
        self::json(['ok' => true]);
    }

    /* ============================================================
     *  QUESTIONS  (CRUD)
     *
     *  payload schema:
     *    common:  exam_id, section, type, difficulty (1..5), weight,
     *             question_text, solution_text, video_url
     *    mcq:     options = [{key, text}, ...], correct_answer = "key" | ["k1","k2"]
     *    open:    options = null, correct_answer = "string"
     *    matching:options = {left:[...], right:[...]},
     *             correct_answer = {leftValue: rightValue, ...}
     * ============================================================ */

    public function listQuestions(int $examId): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        $rows = Database::select(
            "SELECT id, exam_id, section, type, difficulty, weight,
                    question_text, options, correct_answer,
                    solution_text, video_url, created_at
             FROM questions
             WHERE exam_id = :id
             ORDER BY id ASC",
            [':id' => $examId]
        );
        foreach ($rows as &$r) {
            $r['options']        = $r['options']        ? json_decode((string) $r['options'], true)        : null;
            $r['correct_answer'] = $r['correct_answer'] ? json_decode((string) $r['correct_answer'], true) : null;
        }
        unset($r);
        self::json(['items' => $rows]);
    }

    public function getQuestion(int $id): void
    {
        AuthManager::requireRole('admin');
        Security::rotateCsrfHeader('admin');
        $q = Database::selectOne(
            "SELECT id, exam_id, section, type, difficulty, weight,
                    question_text, options, correct_answer,
                    solution_text, video_url
             FROM questions WHERE id = :id",
            [':id' => $id]
        );
        if ($q === null) self::json(['error' => 'Savol topilmadi'], 404);
        $q['options']        = $q['options']        ? json_decode((string) $q['options'], true)        : null;
        $q['correct_answer'] = $q['correct_answer'] ? json_decode((string) $q['correct_answer'], true) : null;
        self::json($q);
    }

    public function saveQuestion(): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $b = self::readJson();

        $id            = (int)    ($b['id']            ?? 0);
        $examId        = (int)    ($b['exam_id']       ?? 0);
        $section       = trim((string) ($b['section']  ?? 'Umumiy'));
        $type          = (string) ($b['type']          ?? 'mcq');
        $type          = in_array($type, ['mcq', 'open', 'matching'], true) ? $type : 'mcq';
        $difficulty    = max(1, min(5, (int) ($b['difficulty'] ?? 1)));
        $weight        = max(0.1, (float) ($b['weight'] ?? 1.0));
        $questionText  = trim((string) ($b['question_text'] ?? ''));
        $solutionText  = trim((string) ($b['solution_text'] ?? ''));
        $videoUrl      = trim((string) ($b['video_url'] ?? ''));
        $options       = $b['options']        ?? null;
        $correctAnswer = $b['correct_answer'] ?? null;

        if ($examId <= 0) {
            self::json(['error' => 'exam_id majburiy'], 422);
        }
        if ($questionText === '') {
            self::json(['error' => 'Savol matnini kiriting'], 422);
        }
        if ($correctAnswer === null || $correctAnswer === '' || $correctAnswer === []) {
            self::json(['error' => 'To\'g\'ri javobni kiriting'], 422);
        }
        if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
            self::json(['error' => 'Video havolasi noto\'g\'ri'], 422);
        }
        if (mb_strlen($questionText) > 8000) {
            self::json(['error' => 'Savol matni 8000 belgidan oshmasin'], 422);
        }

        // Type-specific validation
        if ($type === 'mcq') {
            if (!is_array($options) || count($options) < 2) {
                self::json(['error' => 'MCQ uchun kamida 2 ta variant kerak'], 422);
            }
            $keys = [];
            foreach ($options as &$opt) {
                if (!is_array($opt)) self::json(['error' => 'Variantlar noto\'g\'ri formatda'], 422);
                $key  = trim((string) ($opt['key']  ?? ''));
                $text = trim((string) ($opt['text'] ?? ''));
                if ($key === '' || $text === '') {
                    self::json(['error' => 'Har bir variantda key va text bo\'lishi shart'], 422);
                }
                if (in_array($key, $keys, true)) {
                    self::json(['error' => 'Variant kalitlari (key) takrorlanmasin'], 422);
                }
                $keys[] = $key;
                $opt = ['key' => $key, 'text' => $text];
            }
            unset($opt);

            // correct_answer must reference an existing key (or array of keys)
            $check = is_array($correctAnswer) ? $correctAnswer : [$correctAnswer];
            foreach ($check as $k) {
                if (!in_array((string) $k, $keys, true)) {
                    self::json(['error' => "To'g'ri javob kalit ro'yxatda yo'q: $k"], 422);
                }
            }
        } elseif ($type === 'open') {
            $options = null;
            if (!is_string($correctAnswer)) {
                self::json(['error' => 'Open type uchun to\'g\'ri javob matn bo\'lishi kerak'], 422);
            }
        } elseif ($type === 'matching') {
            if (!is_array($options)
                || empty($options['left'])  || !is_array($options['left'])
                || empty($options['right']) || !is_array($options['right'])) {
                self::json(['error' => 'Matching uchun left va right ro\'yxatlari kerak'], 422);
            }
            $options['left']  = array_values(array_map('strval', $options['left']));
            $options['right'] = array_values(array_map('strval', $options['right']));

            if (!is_array($correctAnswer)) {
                self::json(['error' => 'Matching uchun mapping object kerak'], 422);
            }
            foreach ($correctAnswer as $left => $right) {
                if (!in_array((string) $left,  $options['left'],  true)
                    || !in_array((string) $right, $options['right'], true)) {
                    self::json(['error' => 'Mapping qiymatlari ro\'yxatdan tashqarida'], 422);
                }
            }
        }

        // Verify exam exists
        $exam = Database::selectOne(
            "SELECT id FROM exams WHERE id = :id",
            [':id' => $examId]
        );
        if ($exam === null) self::json(['error' => 'Imtihon topilmadi'], 404);

        $optionsJson = $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
        $correctJson = json_encode($correctAnswer, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            // Verify ownership of question
            $existing = Database::selectOne("SELECT exam_id FROM questions WHERE id = :id", [':id' => $id]);
            if ($existing === null) self::json(['error' => 'Savol topilmadi'], 404);

            Database::execute(
                "UPDATE questions
                    SET exam_id = :e, section = :s, type = :t,
                        difficulty = :d, weight = :w,
                        question_text = :qt, options = :opt, correct_answer = :ca,
                        solution_text = :st, video_url = :vu
                  WHERE id = :id",
                [
                    ':e' => $examId, ':s' => $section, ':t' => $type,
                    ':d' => $difficulty, ':w' => $weight,
                    ':qt' => $questionText, ':opt' => $optionsJson, ':ca' => $correctJson,
                    ':st' => $solutionText !== '' ? $solutionText : null,
                    ':vu' => $videoUrl     !== '' ? $videoUrl     : null,
                    ':id' => $id,
                ]
            );
            self::json(['ok' => true, 'id' => $id]);
        }

        Database::execute(
            "INSERT INTO questions
                (exam_id, section, type, difficulty, weight,
                 question_text, options, correct_answer, solution_text, video_url)
             VALUES (:e, :s, :t, :d, :w, :qt, :opt, :ca, :st, :vu)",
            [
                ':e' => $examId, ':s' => $section, ':t' => $type,
                ':d' => $difficulty, ':w' => $weight,
                ':qt' => $questionText, ':opt' => $optionsJson, ':ca' => $correctJson,
                ':st' => $solutionText !== '' ? $solutionText : null,
                ':vu' => $videoUrl     !== '' ? $videoUrl     : null,
            ]
        );
        self::json(['ok' => true, 'id' => (int) Database::master()->lastInsertId()]);
    }

    public function deleteQuestion(int $id): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        Database::execute("DELETE FROM questions WHERE id = :id", [':id' => $id]);
        self::json(['ok' => true]);
    }

    /* ============================================================
     *  SITE SETTINGS
     * ============================================================ */

    public function getSettings(): void
    {
        AuthManager::requireRole('admin');
        // Issue a fresh admin CSRF token so the panel can mutate state next.
        Security::rotateCsrfHeader('admin');
        $rows = Database::select(
            "SELECT `key`, `value` FROM system_settings"
        );
        $map = [];
        foreach ($rows as $r) $map[$r['key']] = $r['value'];
        // Decode JSON-array fields where needed
        if (isset($map['slider_images'])) {
            $decoded = json_decode((string) $map['slider_images'], true);
            $map['slider_images'] = is_array($decoded) ? $decoded : [];
        }
        self::json($map);
    }

    public function updateCards(): void
    {
        AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $b = self::readJson();
        $humo   = trim((string) ($b['humo_card']   ?? ''));
        $visa   = trim((string) ($b['visa_card']   ?? ''));
        $holder = trim((string) ($b['card_holder'] ?? ''));

        foreach (['humo_card' => $humo, 'visa_card' => $visa, 'card_holder' => $holder] as $k => $v) {
            if ($v === '') continue;
            self::upsertSetting($k, $v);
        }
        self::json(['ok' => true]);
    }

    public function uploadLogo(): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        // Logo allows SVG (sanitized). Slider/screenshots do not.
        $path = self::storeImage($_FILES['logo'] ?? null, self::UPLOAD_LOGO_DIR, true);
        self::upsertSetting('site_logo', $path, (int) $admin['id']);
        self::json(['ok' => true, 'path' => $path]);
    }

    public function uploadSliderImage(): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        // Slider images: raster only — defense in depth (SVG not needed for marketing slides).
        $path  = self::storeImage($_FILES['slide'] ?? null, self::UPLOAD_SLIDER_DIR, false);
        $cur   = Database::selectOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'slider_images'"
        );
        $list  = $cur ? (json_decode((string) $cur['value'], true) ?: []) : [];
        $list[] = $path;
        self::upsertSetting(
            'slider_images',
            json_encode($list, JSON_UNESCAPED_UNICODE),
            (int) $admin['id']
        );
        self::json(['ok' => true, 'images' => $list]);
    }

    public function deleteSliderImage(): void
    {
        $admin = AuthManager::requireRole('admin');
        Security::requireCsrf('admin', false);
        $b = self::readJson();
        $target = (string) ($b['path'] ?? '');
        if ($target === '') self::json(['error' => 'path required'], 422);

        $cur = Database::selectOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'slider_images'"
        );
        $list = $cur ? (json_decode((string) $cur['value'], true) ?: []) : [];
        $list = array_values(array_filter($list, fn($p) => $p !== $target));

        // Remove file from disk if local
        $abs = realpath(__DIR__ . '/../../public') . $target;
        if (is_file($abs)) @unlink($abs);

        self::upsertSetting('slider_images', json_encode($list, JSON_UNESCAPED_UNICODE), (int) $admin['id']);
        self::json(['ok' => true, 'images' => $list]);
    }

    /* ============================================================
     *  IMAGE STORAGE  (validated + SVG sanitized)
     *
     *  $allowSvg=true   → image/svg+xml accepted but routed through
     *                     SvgSanitizer; if sanitization fails, upload rejected.
     *  $allowSvg=false  → SVG rejected outright (raster-only paths).
     * ============================================================ */

    /** @param array<string,mixed>|null $file */
    private static function storeImage(?array $file, string $relDir, bool $allowSvg = false): string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::json(['error' => 'Fayl yuklanmadi'], 422);
        }
        if (($file['size'] ?? 0) > self::MAX_IMG_BYTES) {
            self::json(['error' => 'Fayl hajmi 5MB dan oshmasin'], 413);
        }
        $tmp = (string) $file['tmp_name'];

        // 1) Reject by file extension early (cheap)
        $origName = (string) ($file['name'] ?? '');
        $origExt  = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (in_array($origExt, ['php', 'phtml', 'phar', 'phps', 'pht',
                                 'htm', 'html', 'js', 'svgz'], true)) {
            self::json(['error' => 'Bunday fayl turi taqiqlangan'], 415);
        }

        // 2) Detect MIME by magic bytes
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        // 3) Reject anything outside our raster + (optionally) SVG whitelist
        $allowed = self::RASTER_IMG_MIMES;
        if ($allowSvg) $allowed[] = self::SVG_MIME;

        if (!in_array($mime, $allowed, true)) {
            $list = $allowSvg ? 'png, jpg, webp, svg' : 'png, jpg, webp';
            self::json(['error' => "Faqat $list ruxsat etilgan"], 415);
        }

        $ext = isset($mime) ? (
            $mime === 'image/png'     ? 'png' : (
            $mime === 'image/jpeg'    ? 'jpg' : (
            $mime === 'image/webp'    ? 'webp' : (
            $mime === 'image/svg+xml' ? 'svg' : 'bin'
        )))) : 'bin';

        // 4) For raster: re-decode to make sure it's actually a valid image
        //    (defeats polyglot files: a JPEG that's also valid HTML/JS).
        if (in_array($mime, self::RASTER_IMG_MIMES, true)) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                self::json(['error' => 'Tasvir buzilgan yoki noto\'g\'ri formatda'], 422);
            }
            // Reject ridiculously large dimensions (decompression bombs)
            if (($info[0] ?? 0) > 8000 || ($info[1] ?? 0) > 8000) {
                self::json(['error' => 'Tasvir o\'lchami 8000×8000 dan katta'], 422);
            }
        }

        // 5) Build target path
        $name = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $ext);
        $absDir = realpath(__DIR__ . '/../../public') . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            self::json(['error' => 'Saqlash papkasi yaratilmadi'], 500);
        }
        $abs = $absDir . $name;

        // 6) Branch on type:
        //    - SVG: read source, sanitize, write the sanitized version
        //    - Raster: move uploaded file as-is
        if ($mime === self::SVG_MIME) {
            $raw = @file_get_contents($tmp);
            if ($raw === false || $raw === '') {
                self::json(['error' => 'SVG faylni o\'qib bo\'lmadi'], 500);
            }
            try {
                $clean = SvgSanitizer::sanitize($raw);
            } catch (Throwable $e) {
                self::json(['error' => 'SVG xavfli yoki noto\'g\'ri: ' . $e->getMessage()], 422);
            }
            if (file_put_contents($abs, $clean, LOCK_EX) === false) {
                self::json(['error' => 'Sanitizatsiyalangan SVG ni saqlab bo\'lmadi'], 500);
            }
            @chmod($abs, 0644);
        } else {
            if (!move_uploaded_file($tmp, $abs)) {
                self::json(['error' => 'Faylni saqlab bo\'lmadi'], 500);
            }
            @chmod($abs, 0644);
        }

        return $relDir . $name;
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function upsertSetting(string $key, string $value, ?int $by = null): void
    {
        Database::execute(
            "INSERT INTO system_settings (`key`, `value`, `updated_by`)
             VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`)",
            [':k' => $key, ':v' => $value, ':u' => $by]
        );
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $code = 200): never
    {
        Security::securityHeaders();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /** @return array<string,mixed> */
    private static function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
