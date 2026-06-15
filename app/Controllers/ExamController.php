<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\AuthManager;
use App\Core\Security;
use RuntimeException;
use Throwable;

/**
 * ExamController
 * --------------
 * Exam lifecycle:
 *   - GET  /exam/{id}           → render fullscreen exam view
 *   - POST /api/exam/{id}/start → create user_exams row, deduct mock_quota
 *   - GET  /api/exam/{id}/state → questions (no answers), remaining seconds, snapshot
 *   - POST /api/exam/{id}/save  → upsert one answer (autosave, throttled)
 *   - POST /api/exam/{id}/submit→ grade with DTM methodology, persist analysis
 *   - GET  /api/exam/{id}/result→ result + per-section breakdown
 *
 * DTM (BMBA) methodology:
 *   score = Σ (correct_i × weight_i) / Σ (weight_i) × 100
 *   per-section accuracy = correct_in_section / total_in_section
 *   final grade band:  A:≥90, B:75-89, C:55-74, F:<55
 */
final class ExamController
{
    /** Render the fullscreen exam UI */
    public function show(int $examId): void
    {
        $user = AuthManager::requireUser();
        $exam = Database::selectOne(
            "SELECT id, title, duration, total_qty, status FROM exams WHERE id = :id",
            [':id' => $examId]
        );
        if ($exam === null || $exam['status'] !== 'published') {
            http_response_code(404);
            echo 'Imtihon topilmadi';
            return;
        }

        // Ensure session exists (or create) — we lazily create on /start API call
        $session = Database::selectOne(
            "SELECT id, started_at, finished_at, status
             FROM user_exams
             WHERE user_id = :u AND exam_id = :e
             ORDER BY id DESC LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );

        $csrfToken = Security::csrfToken(
            'exam:' . $examId,
            ((int) $exam['duration']) * 60 + 900   // exam duration + 15 min buffer
        );

        Security::securityHeaders();
        require __DIR__ . '/../../views/exam.phtml';
    }

    /* ============================================================
     *  POST /api/exam/{id}/start
     * ============================================================ */
    public function start(int $examId): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('exam:' . $examId, singleUse: false);
        Security::enforceRateLimit('exam:start:' . $user['id'], 10, 60);

        $exam = Database::selectOne(
            "SELECT id, duration, total_qty, status FROM exams WHERE id = :id",
            [':id' => $examId]
        );
        if ($exam === null || $exam['status'] !== 'published') {
            self::json(['error' => 'Imtihon topilmadi'], 404);
        }

        // Re-use existing in_progress session if any
        $existing = Database::selectOne(
            "SELECT id, started_at FROM user_exams
             WHERE user_id = :u AND exam_id = :e AND status = 'in_progress'
             LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );
        if ($existing !== null) {
            $remaining = self::remainingSeconds(
                (string) $existing['started_at'],
                (int) $exam['duration']
            );
            self::json([
                'session_id'        => (int) $existing['id'],
                'remaining_seconds' => $remaining,
                'resumed'           => true,
            ]);
        }

        // Deduct mock quota atomically (or set is_paid=0 if free)
        try {
            $sessionId = Database::transaction(function ($pdo) use ($user, $examId) {
                $row = $pdo->prepare("SELECT mock_quota FROM users WHERE id = :id FOR UPDATE");
                $row->execute([':id' => $user['id']]);
                $quota = (int) ($row->fetchColumn() ?: 0);
                if ($quota <= 0) {
                    throw new RuntimeException('Imtihon kvotangiz tugagan. Tarif sotib oling.');
                }
                $pdo->prepare("UPDATE users SET mock_quota = mock_quota - 1 WHERE id = :id")
                    ->execute([':id' => $user['id']]);

                $stmt = $pdo->prepare(
                    "INSERT INTO user_exams (user_id, exam_id, is_paid, status, started_at)
                     VALUES (:u, :e, 1, 'in_progress', NOW())"
                );
                $stmt->execute([':u' => $user['id'], ':e' => $examId]);
                return (int) $pdo->lastInsertId();
            });
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 402);
        }

        self::json([
            'session_id'        => $sessionId,
            'remaining_seconds' => (int) $exam['duration'] * 60,
            'resumed'           => false,
        ]);
    }

    /* ============================================================
     *  GET /api/exam/{id}/state
     * ============================================================ */
    public function state(int $examId): void
    {
        $user = AuthManager::requireUser();

        $exam = Database::selectOne(
            "SELECT id, title, duration, total_qty FROM exams WHERE id = :id",
            [':id' => $examId]
        );
        if ($exam === null) self::json(['error' => 'Not found'], 404);

        $session = Database::selectOne(
            "SELECT id, started_at, answers_snapshot, status
             FROM user_exams
             WHERE user_id = :u AND exam_id = :e AND status = 'in_progress'
             ORDER BY id DESC LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );
        if ($session === null) self::json(['error' => 'Sessiya yo\'q'], 409);

        $remaining = self::remainingSeconds((string) $session['started_at'], (int) $exam['duration']);
        if ($remaining <= 0) {
            // Auto-submit on expiry
            $this->doGrade((int) $session['id'], (int) $user['id'], $examId, true);
            self::json(['expired' => true]);
        }

        // Load questions WITHOUT correct_answer / solution
        $rows = Database::select(
            "SELECT id, section, type, difficulty, weight, question_text, options
             FROM questions WHERE exam_id = :e
             ORDER BY id ASC",
            [':e' => $examId]
        );
        foreach ($rows as &$q) {
            $q['options'] = $q['options'] ? json_decode((string) $q['options'], true) : null;
        }
        unset($q);

        $answers = $session['answers_snapshot']
            ? json_decode((string) $session['answers_snapshot'], true) ?: []
            : [];

        self::json([
            'session_id'        => (int) $session['id'],
            'exam'              => $exam,
            'questions'         => $rows,
            'answers'           => $answers,
            'remaining_seconds' => $remaining,
        ]);
    }

    /* ============================================================
     *  POST /api/exam/{id}/save  — autosave one answer
     * ============================================================ */
    public function saveAnswer(int $examId): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('exam:' . $examId, singleUse: false);
        Security::enforceRateLimit('exam:save:' . $user['id'], 240, 60);

        $body = self::readJson();
        $qid    = (int)    ($body['question_id'] ?? 0);
        $answer = $body['answer'] ?? null;
        $flag   = (bool) ($body['flag'] ?? false);
        if ($qid <= 0) self::json(['error' => 'question_id majburiy'], 422);

        $session = Database::selectOne(
            "SELECT id, started_at, answers_snapshot
             FROM user_exams
             WHERE user_id = :u AND exam_id = :e AND status = 'in_progress'
             LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );
        if ($session === null) self::json(['error' => 'Sessiya topilmadi'], 409);

        $exam = Database::selectOne("SELECT duration FROM exams WHERE id = :id", [':id' => $examId]);
        if (self::remainingSeconds((string) $session['started_at'], (int) $exam['duration']) <= 0) {
            $this->doGrade((int) $session['id'], (int) $user['id'], $examId, true);
            self::json(['error' => 'Vaqt tugagan', 'expired' => true], 410);
        }

        $snapshot = $session['answers_snapshot']
            ? json_decode((string) $session['answers_snapshot'], true) ?: []
            : [];
        $snapshot[(string) $qid] = ['answer' => $answer, 'flag' => $flag, 'ts' => time()];

        Database::execute(
            "UPDATE user_exams SET answers_snapshot = :s WHERE id = :id",
            [
                ':s'  => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                ':id' => (int) $session['id'],
            ]
        );
        self::json(['ok' => true, 'saved' => $qid]);
    }

    /* ============================================================
     *  POST /api/exam/{id}/submit
     * ============================================================ */
    public function submit(int $examId): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('exam:' . $examId, singleUse: false);
        Security::enforceRateLimit('exam:submit:' . $user['id'], 5, 60);

        $session = Database::selectOne(
            "SELECT id FROM user_exams
             WHERE user_id = :u AND exam_id = :e AND status = 'in_progress'
             LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );
        if ($session === null) self::json(['error' => 'Sessiya topilmadi'], 409);

        $result = $this->doGrade((int) $session['id'], (int) $user['id'], $examId, false);
        self::json($result);
    }

    /* ============================================================
     *  GET /api/exam/{id}/result
     * ============================================================ */
    public function result(int $examId): void
    {
        $user = AuthManager::requireUser();
        $row = Database::selectOne(
            "SELECT id, score, correct_count, wrong_count, skipped_count,
                    section_analysis, started_at, finished_at, status
             FROM user_exams
             WHERE user_id = :u AND exam_id = :e
             ORDER BY id DESC LIMIT 1",
            [':u' => $user['id'], ':e' => $examId]
        );
        if ($row === null) self::json(['error' => 'Natija yo\'q'], 404);
        $row['section_analysis'] = $row['section_analysis']
            ? json_decode((string) $row['section_analysis'], true)
            : [];
        $row['grade'] = self::gradeBand((float) ($row['score'] ?? 0));
        self::json($row);
    }

    /* ============================================================
     *  CORE GRADING (DTM/BMBA methodology)
     * ============================================================ */
    /**
     * @return array<string,mixed>
     */
    private function doGrade(int $sessionId, int $userId, int $examId, bool $expired): array
    {
        return Database::transaction(function ($pdo) use ($sessionId, $userId, $examId, $expired) {
            $stmt = $pdo->prepare(
                "SELECT answers_snapshot FROM user_exams WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $sessionId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('Session not found');
            }
            $answers = $row['answers_snapshot']
                ? json_decode((string) $row['answers_snapshot'], true) ?: []
                : [];

            $qStmt = $pdo->prepare(
                "SELECT id, section, type, weight, correct_answer
                 FROM questions WHERE exam_id = :e"
            );
            $qStmt->execute([':e' => $examId]);
            $questions = $qStmt->fetchAll();

            $correct = 0;
            $wrong   = 0;
            $skipped = 0;
            $totalWeight   = 0.0;
            $earnedWeight  = 0.0;

            /** @var array<string,array{correct:int,total:int,weight:float,earned:float}> $bySection */
            $bySection = [];

            foreach ($questions as $q) {
                $section = (string) $q['section'];
                $bySection[$section] ??= ['correct' => 0, 'total' => 0, 'weight' => 0.0, 'earned' => 0.0];
                $bySection[$section]['total']  += 1;
                $bySection[$section]['weight'] += (float) $q['weight'];
                $totalWeight += (float) $q['weight'];

                $userAns = $answers[(string) $q['id']]['answer'] ?? null;
                if ($userAns === null || $userAns === '' || $userAns === []) {
                    $skipped++;
                    continue;
                }
                $correctAns = json_decode((string) $q['correct_answer'], true);
                if (self::compareAnswers((string) $q['type'], $correctAns, $userAns)) {
                    $correct++;
                    $earnedWeight += (float) $q['weight'];
                    $bySection[$section]['correct'] += 1;
                    $bySection[$section]['earned']  += (float) $q['weight'];
                } else {
                    $wrong++;
                }
            }

            $score = $totalWeight > 0 ? round($earnedWeight / $totalWeight * 100, 2) : 0.0;

            // Build section analysis (percentage per section)
            $analysis = [];
            foreach ($bySection as $name => $s) {
                $analysis[] = [
                    'section'   => $name,
                    'correct'   => $s['correct'],
                    'total'     => $s['total'],
                    'percent'   => $s['weight'] > 0 ? round($s['earned'] / $s['weight'] * 100, 1) : 0.0,
                    'weakness'  => $s['weight'] > 0 ? round($s['earned'] / $s['weight'] * 100, 1) < 60 : false,
                ];
            }

            $upd = $pdo->prepare(
                "UPDATE user_exams SET
                    status = 'submitted',
                    score = :sc,
                    correct_count = :c,
                    wrong_count = :w,
                    skipped_count = :s,
                    section_analysis = :a,
                    finished_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                ':sc' => $score,
                ':c'  => $correct,
                ':w'  => $wrong,
                ':s'  => $skipped,
                ':a'  => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                ':id' => $sessionId,
            ]);

            return [
                'session_id'      => $sessionId,
                'score'           => $score,
                'correct_count'   => $correct,
                'wrong_count'     => $wrong,
                'skipped_count'   => $skipped,
                'section_analysis'=> $analysis,
                'grade'           => self::gradeBand($score),
                'expired'         => $expired,
            ];
        });
    }

    /* ============================================================
     *  ANSWER COMPARISON (per type)
     * ============================================================ */
    private static function compareAnswers(string $type, mixed $correct, mixed $user): bool
    {
        if ($type === 'mcq') {
            return self::norm($correct) === self::norm($user);
        }
        if ($type === 'open') {
            $a = is_string($correct) ? trim(mb_strtolower($correct)) : '';
            $b = is_string($user)    ? trim(mb_strtolower($user))    : '';
            // Allow comma/dot ambiguity in numerics
            $a = str_replace(',', '.', $a);
            $b = str_replace(',', '.', $b);
            return $a !== '' && $a === $b;
        }
        if ($type === 'matching') {
            // Both should be assoc arrays {left=>right}
            if (!is_array($correct) || !is_array($user)) return false;
            ksort($correct);
            ksort($user);
            return $correct == $user;
        }
        return false;
    }

    private static function norm(mixed $v): string
    {
        if (is_array($v)) {
            $v = array_map(fn($x) => is_scalar($x) ? (string) $x : '', $v);
            sort($v);
            return implode('|', $v);
        }
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function remainingSeconds(string $startedAt, int $durationMinutes): int
    {
        $start = strtotime($startedAt) ?: time();
        $end   = $start + ($durationMinutes * 60);
        return max(0, $end - time());
    }

    private static function gradeBand(float $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
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
