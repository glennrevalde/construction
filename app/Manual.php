<?php
declare(strict_types=1);

/**
 * The User's Manual inside the program (manual.php).
 *
 * The chapters are ready-made HTML in views/manual/chapters, listed in views/manual/manual.json with their
 * sections and with the manual section of every page of the program; the screenshots are in
 * assets/manual/img. Every page's Help button links to its own section (Manual::forPage).
 *
 * Who may read what: the first chapter (logging in and the screen) and the chapter on joining a company
 * can be read before logging in; the rest is for users who are logged in, into a company or as a Super Admin.
 */
final class Manual
{
    private static ?array $index = null;

    public static function index(): array
    {
        if (self::$index === null) {
            $file = APP_ROOT . '/views/manual/manual.json';
            $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            self::$index = is_array($data) ? $data : ['chapters' => [], 'pages' => [], 'platform' => [], 'special' => []];
        }
        return self::$index;
    }

    public static function available(): bool
    {
        return (bool)self::index()['chapters'];
    }

    public static function chapters(): array
    {
        return self::index()['chapters'];
    }

    public static function chapter(string $slug): ?array
    {
        foreach (self::chapters() as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }
        return null;
    }

    /** The chapter's HTML (made by the manual's build, never from user input) */
    public static function chapterHtml(array $chapter): string
    {
        $file = APP_ROOT . '/views/manual/chapters/' . basename((string)$chapter['file']);
        return is_file($file) ? (string)file_get_contents($file) : '';
    }

    /** Address of the manual, a chapter, or a section of a chapter */
    public static function url(?string $chapter = null, ?string $anchor = null): string
    {
        $u = base_url() . '/manual.php';
        if ($chapter !== null && $chapter !== '') {
            $u .= '?c=' . rawurlencode($chapter);
        }
        if ($anchor !== null && $anchor !== '') {
            $u .= '#' . rawurlencode($anchor);
        }
        return $u;
    }

    /** The manual section of a page of the program (index.php?p=...), or null */
    public static function forPage(string $pageKey): ?string
    {
        $i = self::index();
        $t = $i['pages'][$pageKey] ?? $i['special'][$pageKey] ?? null;
        return $t ? self::url($t[0], $t[1]) : null;
    }

    /** The manual section of a platform page (platform.php?p=...), or null */
    public static function forPlatform(string $page): ?string
    {
        $t = self::index()['platform'][$page] ?? null;
        return $t ? self::url($t[0], $t[1]) : self::forPage('platform.login');
    }

    /**
     * Who is reading: 'user' (logged into a company, or the only company), 'platform' (a Super Admin on the
     * platform pages) or null (not logged in, or the session has timed out). Reading the manual does not keep
     * a session alive and never logs anyone out.
     */
    public static function reader(): ?string
    {
        if (Tenant::isMulti() && !Tenant::id()) {
            $sa = Platform::admin();
            $timeout = max(5, (int)Master::setting('session_timeout_minutes')) * 60;
            return $sa && time() - (int)($_SESSION['platform_last'] ?? 0) <= $timeout ? 'platform' : null;
        }
        if (DB::isBlocked() || !Schema::ready() || !Auth::user()) {
            return null;
        }
        $timeout = max(1, Settings::int('session_timeout_minutes')) * 60;
        if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $timeout) {
            return null;
        }
        return 'user';
    }

    public static function canRead(array $chapter, ?string $reader): bool
    {
        return $reader !== null || !empty($chapter['public']);
    }

    /** The search list of the chapters this reader may open */
    public static function searchData(?string $reader): array
    {
        $file = APP_ROOT . '/views/manual/search.json';
        $rows = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        $titles = [];
        foreach (self::chapters() as $c) {
            if (self::canRead($c, $reader)) {
                $titles[$c['slug']] = $c['num'] . '. ' . $c['title'];
            }
        }
        $out = [];
        foreach ((array)$rows as $r) {
            if (isset($titles[$r['c']])) {
                $r['ct'] = $titles[$r['c']];
                $out[] = $r;
            }
        }
        return $out;
    }

    /** The Help button of a page bar */
    public static function helpButton(?string $href): string
    {
        if ($href === null) {
            return '';
        }
        return '<a class="btn btn-sm btn-outline-secondary help-btn" href="' . h($href) . '" target="_blank" rel="noopener" title="Open the User\'s Manual at this page">'
            . '<i class="bi bi-question-circle"></i><span class="help-btn-text">Help</span></a>';
    }
}
