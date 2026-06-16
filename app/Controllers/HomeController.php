<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\AuthManager;
use App\Core\Security;

/**
 * HomeController
 * --------------
 * Public-facing landing page for unauthenticated visitors.
 *
 *  - Logged-in admins   → /admin
 *  - Logged-in students → /profile
 *  - Anonymous          → renders views/landing.phtml
 *
 * The view receives:
 *   $logo           string
 *   $platformName   string
 *   $sliderImages   array<int,string>
 *   $tariffs        array<int,array<string,mixed>>
 *   $botUsername    string
 *   $stats          array<string,int|string>   (cached, anonymous)
 */
final class HomeController
{
    public function landing(): void
    {
        Security::securityHeaders();

        // Logged-in users get routed to their dashboard.
        $u = AuthManager::user();
        if ($u !== null) {
            header('Location: ' . ($u['role'] === 'admin' ? '/admin' : '/profile'));
            return;
        }

        // Public landing data — read-only, cached at HTTP layer
        $settingsRows = Database::select(
            "SELECT `key`, `value` FROM system_settings"
        );
        $settings = [];
        foreach ($settingsRows as $r) {
            $settings[$r['key']] = $r['value'];
        }
        $logo         = (string) ($settings['site_logo']     ?? '/uploads/logo/default.png');
        $banner       = (string) ($settings['site_banner']   ?? '');
        $platformName = (string) ($settings['platform_name'] ?? 'Physics National Certificate');
        $botUsername  = (string) ($settings['bot_username']  ?? 'physics_cert_bot');
        $aboutText    = (string) ($settings['about_text']    ?? 'Physics National Certificate platformasi.');

        $sliderImages = [];
        if (!empty($settings['slider_images'])) {
            $decoded = json_decode((string) $settings['slider_images'], true);
            if (is_array($decoded)) $sliderImages = array_values($decoded);
        }

        $tariffs = Database::select(
            "SELECT id, name, price, mock_count, description
             FROM tariffs
             WHERE is_active = 1
             ORDER BY sort_order ASC, price ASC"
        );

        // Public stats (rounded to nearest 100/k for marketing copy)
        $usersRow   = Database::selectOne("SELECT COUNT(*) c FROM users WHERE is_active = 1");
        $examsRow   = Database::selectOne("SELECT COUNT(*) c FROM exams WHERE status = 'published'");
        $taken      = Database::selectOne(
            "SELECT COUNT(*) c FROM user_exams WHERE status = 'submitted'"
        );

        $stats = [
            'users'  => self::roundDown((int) ($usersRow['c'] ?? 0)),
            'exams'  => (int) ($examsRow['c'] ?? 0),
            'taken'  => self::roundDown((int) ($taken['c'] ?? 0)),
            'uptime' => '99.9%',
        ];

        // Cache anonymous landing for 5 minutes at the edge
        header('Cache-Control: public, max-age=300, s-maxage=600, stale-while-revalidate=60');
        header('Vary: Cookie');

        require __DIR__ . '/../../views/landing.phtml';
    }

    public function about(): void
    {
        Security::securityHeaders();
        $settings = self::loadSettings();
        $logo         = (string) ($settings['site_logo']     ?? '/uploads/logo/default.png');
        $platformName = (string) ($settings['platform_name'] ?? 'Physics National Certificate');
        $aboutText    = (string) ($settings['about_text']    ?? '');
        $botUsername  = (string) ($settings['bot_username']  ?? 'physics_cert_bot');
        require __DIR__ . '/../../views/about.phtml';
    }

    public function tariffs(): void
    {
        Security::securityHeaders();
        $settings = self::loadSettings();
        $logo         = (string) ($settings['site_logo']     ?? '/uploads/logo/default.png');
        $platformName = (string) ($settings['platform_name'] ?? 'Physics National Certificate');
        $botUsername  = (string) ($settings['bot_username']  ?? 'physics_cert_bot');
        $tariffs = Database::select(
            "SELECT id, name, price, mock_count, description FROM tariffs
             WHERE is_active = 1 ORDER BY sort_order ASC, price ASC"
        );
        require __DIR__ . '/../../views/tariffs.phtml';
    }

    public function blog(): void
    {
        Security::securityHeaders();
        $settings = self::loadSettings();
        $logo         = (string) ($settings['site_logo']     ?? '/uploads/logo/default.png');
        $platformName = (string) ($settings['platform_name'] ?? 'Physics National Certificate');
        require __DIR__ . '/../../views/blog.phtml';
    }

    public function contact(): void
    {
        Security::securityHeaders();
        $settings = self::loadSettings();
        $logo         = (string) ($settings['site_logo']     ?? '/uploads/logo/default.png');
        $platformName = (string) ($settings['platform_name'] ?? 'Physics National Certificate');
        $contactEmail = (string) ($settings['contact_email'] ?? '');
        $contactPhone = (string) ($settings['contact_phone'] ?? '');
        $botUsername  = (string) ($settings['bot_username']  ?? 'physics_cert_bot');
        require __DIR__ . '/../../views/contact.phtml';
    }

    /** @return array<string,string> */
    private static function loadSettings(): array
    {
        $rows = Database::select("SELECT `key`, `value` FROM system_settings");
        $out = [];
        foreach ($rows as $r) $out[$r['key']] = $r['value'];
        return $out;
    }

    /**
     * Marketing-style number rounding:
     *   123  → 100
     *   1450 → 1400
     *   55000 → 55000
     */
    private static function roundDown(int $n): int
    {
        if ($n < 100)    return $n;
        if ($n < 1_000)  return ((int) floor($n / 100)) * 100;
        if ($n < 10_000) return ((int) floor($n / 100)) * 100;
        return ((int) floor($n / 1_000)) * 1_000;
    }
}
