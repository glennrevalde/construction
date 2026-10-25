<?php
declare(strict_types=1);

/**
 * Designing the reports.
 *
 * Every report can be laid out without touching the program, by the users whose role holds the
 * "Can design report layouts" permission (the Administrator role always does):
 *
 *   Columns  which ones print, their order, the words over each, how wide and which way they line up
 *   Rows     rows of zeros, detail or only the group headings and subtotals, the subtotals and the grand
 *            total, only the rows containing some words, the first so many rows, rows taken out one by one
 *   Heading  the title, a line and extra lines under it, the company name, address, TIN and logo, where
 *            the heading sits, and the dates-and-filters line
 *   Fonts    the font, size, weight and colour of the heading, of the column headings and of the rows,
 *            shading, lines between the columns, a border round the table, row height
 *   Figures  negatives, zeros, decimals, the thousands separator, figures in thousands or millions,
 *            the currency sign
 *   Footer   text of your own, signature lines with their captions and names, the "Printed ..." line,
 *            page numbers
 *   Page     paper size, upright or sideways, margins, the title on every printed page
 *
 * A set of those choices can be saved as a layout, kept to yourself or shared, and made the one the
 * report opens with. Everyone can open a report in any layout shared with them.
 *
 * The choices travel in the address of the page, so a designed report can be memorized, printed,
 * exported and e-mailed like any other. A saved layout is a stored set of the same choices.
 */
final class ReportLayout
{
    /** Every option: key => [what it is, kind, what it is when nothing was chosen] */
    public const OPTIONS = [
        // heading
        'title'          => ['Report title', 'text', ''],
        'subtitle'       => ['Line under the title', 'text', ''],
        'header_lines'   => ['More lines under the title', 'lines', ''],
        'show_company'   => ['Company name', 'bool', 1],
        'show_address'   => ['Company address', 'bool', 0],
        'show_tin'       => ['Company TIN', 'bool', 0],
        'show_logo'      => ['Company logo', 'bool', 1],
        'show_basis'     => ['Dates and filters line', 'bool', 1],
        'show_prepared'  => ['"Prepared on ..." line under the heading', 'bool', 0],
        'head_align'     => ['The heading sits', 'choice', 'center'],
        'head_rule'      => ['A line under the heading', 'bool', 1],
        'title_upper'    => ['Title in capital letters', 'bool', 0],
        // fonts and look
        'head_font'      => ['Font', 'font', 'sans'],
        'title_size'     => ['Title size', 'choice', 'normal'],
        'company_size'   => ['Company name size', 'choice', 'normal'],
        'head_color'     => ['Colour', 'choice', 'ink'],
        'col_font'       => ['Font', 'font', 'sans'],
        'col_size'       => ['Size', 'choice', 'normal'],
        'col_bold'       => ['In bold', 'bool', 1],
        'col_upper'      => ['In capital letters', 'bool', 0],
        'col_shade'      => ['Shading', 'choice', 'light'],
        'col_align'      => ['Line up', 'choice', 'auto'],
        'col_wrap'       => ['Long headings may take two lines', 'bool', 0],
        'body_font'      => ['Font', 'font', 'sans'],
        'font'           => ['Size', 'choice', 'normal'],
        'density'        => ['Row height', 'choice', 'normal'],
        'zebra'          => ['Shade every other row', 'bool', 0],
        'grid'           => ['Lines between the columns', 'bool', 0],
        'box'            => ['A border round the table', 'bool', 0],
        'group_shade'    => ['Shade the group headings', 'bool', 1],
        'total_style'    => ['The grand total', 'choice', 'double'],
        // figures
        'negatives'      => ['Negative figures', 'choice', 'minus'],
        'zeros'          => ['Zero figures', 'choice', 'show'],
        'decimals'       => ['Decimal places on money', 'choice', '2'],
        'thousands'      => ['Thousands separator (1,234)', 'bool', 1],
        'scale'          => ['Show the money figures', 'choice', 'units'],
        'currency'       => ['Currency sign', 'choice', 'none'],
        // rows
        'rows_zero'      => ['Rows whose figures are all zero', 'choice', 'show'],
        'rows_detail'    => ['Which rows', 'choice', 'all'],
        'rows_subtotals' => ['Subtotal rows', 'bool', 1],
        'rows_total'     => ['Grand total row', 'bool', 1],
        'rows_match'     => ['Only rows containing', 'text', ''],
        'rows_limit'     => ['At most this many rows (0 = all of them)', 'int', '0'],
        'rows_hidden'    => ['Rows taken out one by one', 'lines', ''],
        // footer
        'footer_text'    => ['Text at the foot of the report', 'lines', ''],
        'footer_align'   => ['The foot text sits', 'choice', 'left'],
        'show_printed'   => ['"Printed on ... by ..." line', 'bool', 1],
        'footer_rule'    => ['A line above the foot', 'bool', 0],
        'prepared_by'    => ['Signature lines at the foot', 'bool', 0],
        'sign_labels'    => ['Caption under each signature line', 'lines', "Prepared by\nChecked by\nApproved by"],
        'sign_names'     => ['Names printed above the captions', 'lines', ''],
        'page_numbers'   => ['Page numbers on the printed pages', 'bool', 1],
        // page
        'paper'          => ['Paper', 'choice', 'auto'],
        'orientation'    => ['Printed page', 'choice', 'portrait'],
        'margins'        => ['Margins', 'choice', 'normal'],
        'repeat_title'   => ['Report title at the top of every printed page', 'bool', 0],
        // columns, one by one (from cw[key] and ca[key] in the address)
        'colw'           => ['Width of each column', 'map', ''],
        'cola'           => ['How each column lines up', 'map', ''],
    ];

    public const CHOICES = [
        'negatives'    => ['minus' => 'With a minus sign  -1,234.00', 'brackets' => 'In brackets  (1,234.00)', 'red' => 'In red  -1,234.00', 'redbrackets' => 'In red brackets  (1,234.00)'],
        'zeros'        => ['show' => 'Show 0.00', 'blank' => 'Leave the cell empty', 'dash' => 'Show a dash'],
        'decimals'     => ['2' => 'Two (1,234.56)', '0' => 'None (1,235)'],
        'density'      => ['normal' => 'Normal', 'tight' => 'Tight (more rows to a page)', 'roomy' => 'Roomy'],
        'orientation'  => ['portrait' => 'Upright (portrait)', 'landscape' => 'Sideways (landscape)'],
        'font'         => ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large'],
        'head_align'   => ['left' => 'On the left', 'center' => 'In the middle', 'right' => 'On the right'],
        'title_size'   => ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large', 'xlarge' => 'Extra large'],
        'company_size' => ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large'],
        'head_color'   => ['ink' => 'Black', 'grey' => 'Dark grey', 'blue' => 'Blue', 'green' => 'Green'],
        'col_size'     => ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large'],
        'col_shade'    => ['none' => 'None', 'light' => 'Light grey', 'dark' => 'Dark, white letters', 'blue' => 'Blue, white letters'],
        'col_align'    => ['auto' => 'Over their figures (text left, amounts right)', 'left' => 'All on the left', 'center' => 'All in the middle'],
        'total_style'  => ['double' => 'Line above, double line below', 'single' => 'A line above', 'bold' => 'In bold only', 'shaded' => 'Shaded band'],
        'scale'        => ['units' => 'As they are (1,234,567.00)', 'thousands' => 'In thousands (1,235)', 'millions' => 'In millions (1.2)'],
        'currency'     => ['none' => 'None', 'totals' => 'On the totals', 'all' => 'On every amount'],
        'rows_zero'    => ['show' => 'Show them', 'hide' => 'Leave them out'],
        'rows_detail'  => ['all' => 'Every row', 'summary' => 'Only the group headings, subtotals and totals'],
        'footer_align' => ['left' => 'On the left', 'center' => 'In the middle', 'right' => 'On the right'],
        'paper'        => ['auto' => 'The printer\'s own', 'a4' => 'A4 (210 x 297 mm)', 'letter' => 'Letter (8.5 x 11 in)', 'folio' => 'Long / Folio (8.5 x 13 in)', 'legal' => 'Legal (8.5 x 14 in)'],
        'margins'      => ['narrow' => 'Narrow (6 mm)', 'normal' => 'Normal (12 mm)', 'wide' => 'Wide (20 mm)'],
    ];

    /** Fonts on offer. All of them are on a Windows computer already, so nothing is downloaded. */
    public const FONTS = [
        'sans'     => ['Standard (Segoe UI)', '"Segoe UI Variable Text", "Segoe UI", system-ui, -apple-system, Arial, sans-serif'],
        'arial'    => ['Arial', 'Arial, "Helvetica Neue", Helvetica, sans-serif'],
        'calibri'  => ['Calibri', 'Calibri, Carlito, "Segoe UI", sans-serif'],
        'tahoma'   => ['Tahoma', 'Tahoma, Verdana, sans-serif'],
        'verdana'  => ['Verdana', 'Verdana, Geneva, sans-serif'],
        'century'  => ['Century Gothic', '"Century Gothic", "URW Gothic", "Avant Garde", sans-serif'],
        'times'    => ['Times New Roman', '"Times New Roman", Times, serif'],
        'georgia'  => ['Georgia', 'Georgia, "Times New Roman", serif'],
        'cambria'  => ['Cambria', 'Cambria, Caladea, Georgia, serif'],
        'garamond' => ['Garamond', 'Garamond, "EB Garamond", "Palatino Linotype", Georgia, serif'],
        'bookman'  => ['Bookman Old Style', '"Bookman Old Style", "URW Bookman", Georgia, serif'],
        'courier'  => ['Courier New', '"Courier New", Courier, monospace'],
        'consolas' => ['Consolas', 'Consolas, "Cascadia Mono", "Courier New", monospace'],
    ];

    /** What the choices of the look come to as CSS values */
    public const SIZES = [
        'title_size'   => ['small' => '1.05rem', 'normal' => '1.3rem', 'large' => '1.6rem', 'xlarge' => '1.95rem'],
        'company_size' => ['small' => '.84rem', 'normal' => '.95rem', 'large' => '1.15rem'],
        'col_size'     => ['small' => '.68rem', 'normal' => '.76rem', 'large' => '.86rem'],
        'head_color'   => ['ink' => '#111827', 'grey' => '#46515d', 'blue' => '#0b5ed7', 'green' => '#146c43'],
    ];

    public const COL_WIDTHS = ['' => 'Auto', 'narrow' => 'Narrow', 'normal' => 'Medium', 'wide' => 'Wide'];
    public const COL_ALIGNS = ['' => 'Auto', 'left' => 'Left', 'center' => 'Middle', 'right' => 'Right'];

    /** Only the roles given "Can design report layouts" change how a report looks, or save a layout */
    public static function canDesign(): bool
    {
        return (bool)Access::special('design_reports');
    }

    /** The options as they are when nothing has been chosen */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::OPTIONS as $k => [$label, $kind, $default]) {
            $out[$k] = $default;
        }
        return $out;
    }

    /** A value as the option's kind wants it; anything it cannot be is the default */
    private static function clean(string $k, $v)
    {
        [$label, $kind, $default] = self::OPTIONS[$k];
        switch ($kind) {
            case 'bool':
                return (int)((string)$v === '1');
            case 'int':
                return (string)max(0, min(100000, (int)$v));
            case 'choice':
                return isset(self::CHOICES[$k][(string)$v]) ? (string)$v : $default;
            case 'font':
                return isset(self::FONTS[(string)$v]) ? (string)$v : $default;
            case 'lines':
                $lines = array_slice(array_values(array_filter(array_map(
                    fn($x) => mb_substr(trim($x), 0, 200),
                    preg_split('/\r\n|\r|\n/', (string)$v) ?: []
                ), fn($x) => $x !== '')), 0, $k === 'rows_hidden' ? 200 : 8);
                return implode("\n", $lines);
            case 'map':
                return (string)$v;
            default:
                return mb_substr(trim((string)$v), 0, 160);
        }
    }

    /**
     * The choices in force for this request: what is in the address, on top of the layout that was
     * asked for (?layout=n) or the default layout of this report.
     *
     * Returns ['options' => [...], 'order' => [key, ...], 'hidden' => [key, ...], 'hidden_explicit' => bool,
     *          'labels' => [key => heading], 'layout' => the saved row or null]
     */
    /** Report key => options a report starts with until a layout is saved (Report::define 'layout_defaults') */
    public static array $reportDefaults = [];

    public static function current(string $reportKey): array
    {
        $layout = null;
        $id = gint('layout');
        if ($id) {
            $layout = self::load($id, $reportKey);
        } elseif (g('layout') !== '0') {
            $layout = self::defaultFor($reportKey);
        }
        $c = $layout ? self::unpack($layout)
            : ['options' => array_merge(self::defaults(), array_intersect_key(self::$reportDefaults[$reportKey] ?? [], self::OPTIONS)), 'order' => [], 'hidden' => [], 'labels' => [], 'hidden_explicit' => false];

        // anything in the address wins, so a change can be seen before it is saved
        foreach (self::OPTIONS as $k => [$label, $kind]) {
            if ($kind === 'map') {
                continue;
            }
            $v = $_GET['opt'][$k] ?? null;
            if ($v === null || is_array($v)) {
                continue;
            }
            $c['options'][$k] = self::clean($k, $v);
        }
        foreach (['colw' => ['cw', self::COL_WIDTHS], 'cola' => ['ca', self::COL_ALIGNS]] as $k => [$param, $allowed]) {
            if (!isset($_GET[$param]) || !is_array($_GET[$param])) {
                continue;
            }
            $map = [];
            foreach ($_GET[$param] as $col => $v) {
                $col = preg_replace('/[^A-Za-z0-9_]/', '', (string)$col);
                if ($col !== '' && is_string($v) && $v !== '' && isset($allowed[$v])) {
                    $map[] = $col . ':' . $v;
                }
            }
            $c['options'][$k] = implode(',', $map);
        }
        if (isset($_GET['hide'])) {
            $c['hidden'] = array_values(array_filter(array_map('strval', (array)$_GET['hide']), fn($x) => $x !== ''));
            $c['hidden_explicit'] = true;
        }
        if (isset($_GET['order']) && is_string($_GET['order']) && $_GET['order'] !== '') {
            $c['order'] = array_values(array_filter(array_map('trim', explode(',', (string)$_GET['order']))));
        }
        foreach ((array)($_GET['head'] ?? []) as $k => $v) {
            $k = (string)$k;
            $v = trim((string)$v);
            if ($v === '') {
                unset($c['labels'][$k]);
            } else {
                $c['labels'][$k] = mb_substr($v, 0, 60);
            }
        }
        $c['layout'] = $layout;
        return $c;
    }

    /** "key:value,key:value" as [key => value] */
    public static function map(string $v): array
    {
        $out = [];
        foreach (explode(',', $v) as $pair) {
            [$k, $x] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($k !== '' && $x !== '') {
                $out[$k] = $x;
            }
        }
        return $out;
    }

    /** The lines of a 'lines' option */
    public static function lines($v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$v) ?: []), fn($x) => $x !== ''));
    }

    /**
     * Puts the columns of a report in the order, with the headings, widths and alignment the user
     * chose. Visibility is decided by the caller (the hidden list).
     */
    public static function apply(array $cols, array $c): array
    {
        if ($c['order']) {
            $by = [];
            foreach ($cols as $col) {
                $by[$col['key']] = $col;
            }
            $sorted = [];
            foreach ($c['order'] as $k) {
                if (isset($by[$k])) {
                    $sorted[] = $by[$k];
                    unset($by[$k]);
                }
            }
            $cols = array_merge($sorted, array_values($by));   // anything new keeps its place at the end
        }
        $widths = self::map((string)($c['options']['colw'] ?? ''));
        $aligns = self::map((string)($c['options']['cola'] ?? ''));
        foreach ($cols as &$col) {
            $col['_orig_label'] = $col['label'];
            if (isset($c['labels'][$col['key']]) && $c['labels'][$col['key']] !== '') {
                $col['label'] = $c['labels'][$col['key']];
            }
            $col['_width'] = $widths[$col['key']] ?? '';
            $col['_align'] = $aligns[$col['key']] ?? '';
        }
        unset($col);
        return $cols;
    }

    // ------------------------------------------------------------------ stored layouts

    public static function load(int $id, ?string $reportKey = null): ?array
    {
        $row = DB::row('SELECT * FROM report_layouts WHERE id = :id', ['id' => $id]);
        if (!$row || ($reportKey !== null && $row['report_key'] !== $reportKey)) {
            return null;
        }
        return self::mine($row) ? $row : null;
    }

    /** Layouts this user may use on this report: their own, and the ones others shared */
    public static function forReport(string $reportKey): array
    {
        return DB::all('SELECT l.*, u.username FROM report_layouts l LEFT JOIN users u ON u.id = l.created_by
                         WHERE l.report_key = :k AND (l.created_by = :u OR l.is_shared = 1)
                         ORDER BY l.is_default DESC, l.name', ['k' => $reportKey, 'u' => Auth::id()]);
    }

    public static function defaultFor(string $reportKey): ?array
    {
        foreach (self::forReport($reportKey) as $l) {
            if ((int)$l['is_default'] === 1) {
                return $l;
            }
        }
        return null;
    }

    private static function mine(array $row): bool
    {
        return (int)$row['created_by'] === Auth::id() || (int)$row['is_shared'] === 1;
    }

    /** Turns a stored row into the same shape current() returns */
    public static function unpack(array $row): array
    {
        $c = ['options' => self::defaults(), 'order' => [], 'hidden' => [], 'labels' => [], 'hidden_explicit' => false];
        foreach (explode("\n", (string)($row['columns'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $c['hidden_explicit'] = true;
            $hidden = str_starts_with($line, '-');
            $line = ltrim($line, '-');
            [$key, $label] = array_pad(explode(':', $line, 2), 2, '');
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            $c['order'][] = $key;
            if ($hidden) {
                $c['hidden'][] = $key;
            }
            if (trim($label) !== '') {
                $c['labels'][$key] = trim($label);
            }
        }
        foreach (explode("\n", (string)($row['options'] ?? '')) as $line) {
            [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
            $k = trim($k);
            if ($k === '' || !isset(self::OPTIONS[$k])) {
                continue;
            }
            // a line break inside a value is stored as \n, and a backslash as \\
            $v = preg_replace_callback('/\\\\(\\\\|n)/', fn($m) => $m[1] === 'n' ? "\n" : '\\', trim($v));
            $c['options'][$k] = self::clean($k, $v);
        }
        if (!empty($row['title']) && ($c['options']['title'] ?? '') === '') {
            $c['options']['title'] = (string)$row['title'];
        }
        return $c;
    }

    /** Turns the current choices back into the two text fields the table holds */
    public static function pack(array $cols, array $c): array
    {
        $lines = [];
        $order = $c['order'] ?: array_column($cols, 'key');
        $keys = array_column($cols, 'key');
        $optional = array_column(array_filter($cols, fn($col) => !empty($col['optional'])), 'key');
        foreach (array_merge($order, array_diff($keys, $order)) as $k) {
            if (!in_array($k, $keys, true)) {
                continue;
            }
            // a column the report only offers (an extra, a related field) is out unless the choices named it
            $hidden = in_array($k, $c['hidden'], true) || (in_array($k, $optional, true) && !in_array($k, $c['order'], true));
            $lines[] = ($hidden ? '-' : '') . $k
                . (isset($c['labels'][$k]) && $c['labels'][$k] !== '' ? ':' . $c['labels'][$k] : '');
        }
        $opts = [];
        foreach (self::OPTIONS as $k => [$label, $kind, $default]) {
            $v = $c['options'][$k] ?? $default;
            if ((string)$v === (string)$default) {
                continue;                       // only what differs is stored, so later changes to the
            }                                   // program's own defaults still reach old layouts
            $opts[] = $k . '=' . str_replace(['\\', "\n"], ['\\\\', '\\n'], (string)$v);
        }
        return [implode("\n", $lines), implode("\n", $opts)];
    }

    public static function save(string $reportKey, string $name, array $cols, array $c, bool $shared, bool $isDefault, ?int $id = null): int
    {
        if (!self::canDesign()) {
            fail('Saving a report layout needs the "Can design report layouts" permission. An administrator can give it to your role.');
        }
        $name = trim($name);
        if ($name === '') {
            fail('Give the layout a name.');
        }
        [$columns, $options] = self::pack($cols, $c);
        $d = ['report_key' => $reportKey, 'name' => mb_substr($name, 0, 120), 'title' => $c['options']['title'] ?: null,
            'columns' => $columns, 'options' => $options, 'is_shared' => $shared ? 1 : 0, 'is_default' => $isDefault ? 1 : 0];
        return DB::tx(function () use ($d, $id, $reportKey, $isDefault) {
            if ($isDefault) {
                DB::run('UPDATE report_layouts SET is_default = 0 WHERE report_key = :k AND (created_by = :u OR is_shared = 1)',
                    ['k' => $reportKey, 'u' => Auth::id()]);
            }
            if ($id) {
                $own = DB::row('SELECT * FROM report_layouts WHERE id = :id', ['id' => $id]);
                if (!$own || ((int)$own['created_by'] !== Auth::id() && !Access::isAdmin())) {
                    fail('That layout belongs to another user, so it cannot be changed here. Save it under a new name instead.');
                }
                DB::update('report_layouts', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                return $id;
            }
            return DB::insert('report_layouts', $d + ['created_by' => Auth::id()]);
        });
    }

    public static function delete(int $id): void
    {
        $row = DB::row('SELECT * FROM report_layouts WHERE id = :id', ['id' => $id]);
        if (!$row) {
            fail('That layout is no longer there.');
        }
        if ((int)$row['created_by'] !== Auth::id() && !Access::isAdmin()) {
            fail('That layout belongs to another user.');
        }
        DB::run('DELETE FROM report_layouts WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ the rows

    /** The columns a row is recognised by when it is taken out: the first two text columns of the report */
    public static function keyColumns(array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            if (!in_array($c['type'] ?? 'text', ['money', 'qty', 'pct', 'int'], true)) {
                $out[] = $c['key'];
            }
            if (count($out) === 2) {
                break;
            }
        }
        return $out ?: [array_column($cols, 'key')[0] ?? ''];
    }

    /** How a row is recognised: the words in its first two text columns */
    public static function rowKey(array $r, array $keyCols): string
    {
        $parts = [];
        foreach ($keyCols as $k) {
            $v = $r[$k] ?? '';
            if (is_scalar($v) && trim((string)$v) !== '') {
                $parts[] = trim(preg_replace('/\s+/', ' ', (string)$v));
            }
        }
        return mb_substr(implode(' | ', $parts), 0, 200);
    }

    private static function kind(array $r): string
    {
        $cls = ' ' . (string)($r['_class'] ?? '') . ' ';
        if (str_contains($cls, ' total-row ')) {
            return 'total';
        }
        if (str_contains($cls, ' subtotal-row ')) {
            return 'subtotal';
        }
        if (str_contains($cls, ' group-row ')) {
            return 'group';
        }
        return 'detail';
    }

    /**
     * The rows that print, after the row choices: rows of zeros, detail or summary, subtotals,
     * words to look for, the first so many, and rows taken out one by one.
     * Returns [rows, info] where info says what the report has, for the Rows tab.
     */
    public static function rows(array $rows, array $cols, array $o): array
    {
        $numKeys = array_keys(array_filter(array_column($cols, 'type', 'key'), fn($t) => in_array($t, ['money', 'qty', 'pct', 'int'], true)));
        $keyCols = self::keyColumns($cols);
        $hidden = array_flip(self::lines($o['rows_hidden'] ?? ''));
        $match = mb_strtolower(trim((string)($o['rows_match'] ?? '')));
        $limit = (int)($o['rows_limit'] ?? 0);
        $info = ['groups' => false, 'subtotals' => false, 'taken_out' => 0, 'left_out' => 0];
        foreach ($rows as $r) {
            $k = self::kind($r);
            $info['groups'] = $info['groups'] || $k === 'group';
            $info['subtotals'] = $info['subtotals'] || $k === 'subtotal';
        }
        $summary = ($o['rows_detail'] ?? 'all') === 'summary' && ($info['groups'] || $info['subtotals']);

        $out = [];
        $details = 0;
        foreach ($rows as $r) {
            $kind = self::kind($r);
            $key = self::rowKey($r, $keyCols);
            $r['_key'] = $key;
            if ($kind !== 'total' && $key !== '' && isset($hidden[$key])) {
                $info['taken_out']++;
                continue;
            }
            if ($kind === 'subtotal' && empty($o['rows_subtotals'])) {
                continue;
            }
            if ($kind === 'detail') {
                if ($summary) {
                    continue;
                }
                if (($o['rows_zero'] ?? 'show') === 'hide' && $numKeys) {
                    $zero = true;
                    foreach ($numKeys as $nk) {
                        $v = $r[$nk] ?? '';
                        if (is_numeric($v) && abs((float)$v) >= 0.005) {
                            $zero = false;
                            break;
                        }
                    }
                    if ($zero) {
                        $info['left_out']++;
                        continue;
                    }
                }
                if ($match !== '') {
                    $hay = '';
                    foreach ($r as $ck => $cv) {
                        if ($ck !== '' && $ck[0] !== '_' && is_scalar($cv)) {
                            $hay .= ' ' . mb_strtolower((string)$cv);
                        }
                    }
                    if (!str_contains($hay, $match)) {
                        $info['left_out']++;
                        continue;
                    }
                }
                if ($limit > 0 && $details >= $limit) {
                    $info['left_out']++;
                    continue;
                }
                $details++;
            }
            $out[] = $r;
        }
        return [$out, $info];
    }

    // ------------------------------------------------------------------ what the choices do to the page

    /** A money, quantity or percentage figure, shown the way the user asked for */
    public static function number($v, string $type, array $o, bool $total = false): string
    {
        if (!is_numeric($v)) {
            return h((string)$v);
        }
        $n = (float)$v;
        if ($type === 'money') {
            $n /= ['thousands' => 1000, 'millions' => 1000000][$o['scale'] ?? 'units'] ?? 1;
        }
        $dec = $type === 'money'
            ? (($o['scale'] ?? 'units') === 'thousands' ? 0 : (($o['scale'] ?? 'units') === 'millions' ? 1 : (int)($o['decimals'] ?? 2)))
            : ($type === 'pct' ? 1 : 2);
        if (abs($n) < (0.5 / (10 ** max($dec, 0)))) {
            if (($o['zeros'] ?? 'show') === 'blank') {
                return '';
            }
            if (($o['zeros'] ?? 'show') === 'dash') {
                return '<span class="text-muted">-</span>';
            }
        }
        if ($type === 'qty') {
            return h(qty($n));
        }
        $sep = !isset($o['thousands']) || !empty($o['thousands']) ? ',' : '';
        $text = $type === 'int' ? number_format(abs($n), 0, '.', $sep) : number_format(abs($n), $dec, '.', $sep);
        if ($type === 'pct') {
            $text .= '%';
        }
        if ($type === 'money' && (($o['currency'] ?? 'none') === 'all' || (($o['currency'] ?? 'none') === 'totals' && $total))) {
            $text = h((string)($GLOBALS['CONFIG']['app']['currency'] ?? '')) . $text;
        }
        if ($n >= 0) {
            return $text;
        }
        return match ($o['negatives'] ?? 'minus') {
            'brackets' => '(' . $text . ')',
            'red' => '<span class="text-danger">-' . $text . '</span>',
            'redbrackets' => '<span class="text-danger">(' . $text . ')</span>',
            default => '-' . $text,
        };
    }

    /** The extra classes the table carries */
    public static function tableClass(array $o): string
    {
        $cls = 'report-table';
        $cls .= ' rt-' . preg_replace('/[^a-z]/', '', (string)($o['density'] ?? 'normal'));
        if (!empty($o['zebra'])) {
            $cls .= ' table-striped';
        }
        if (!empty($o['grid'])) {
            $cls .= ' rt-grid';
        }
        $cls .= ' rt-font-' . preg_replace('/[^a-z]/', '', (string)($o['font'] ?? 'normal'));
        return $cls;
    }

    /** The classes of the report as a whole: heading, column headings, table and footer choices */
    public static function docClass(array $o): string
    {
        $c = ['rpt-doc'];
        $c[] = 'rh-align-' . ($o['head_align'] ?? 'center');
        $c[] = 'rf-align-' . ($o['footer_align'] ?? 'left');
        $c[] = 'rc-shade-' . ($o['col_shade'] ?? 'light');
        $c[] = 'rc-align-' . ($o['col_align'] ?? 'auto');
        $c[] = 'rt-total-' . ($o['total_style'] ?? 'double');
        foreach (['title_upper' => 'rt-upper', 'col_upper' => 'rc-upper', 'col_wrap' => 'rc-wrap', 'box' => 'rt-box', 'footer_rule' => 'rf-rule'] as $k => $cls) {
            if (!empty($o[$k])) {
                $c[] = $cls;
            }
        }
        if (empty($o['head_rule'])) {
            $c[] = 'rh-norule';
        }
        if (empty($o['col_bold'])) {
            $c[] = 'rc-light';
        }
        if (empty($o['group_shade'])) {
            $c[] = 'rt-plain-groups';
        }
        return implode(' ', array_map(fn($x) => preg_replace('/[^a-z0-9_-]/', '', $x), $c));
    }

    /** Fonts, sizes and the colour, as CSS variables on the report (so they can be changed while you watch) */
    public static function docStyle(array $o): string
    {
        $font = fn($k) => self::FONTS[$o[$k] ?? 'sans'][1] ?? self::FONTS['sans'][1];
        $vars = [
            '--rh-font' => $font('head_font'),
            '--rc-font' => $font('col_font'),
            '--rb-font' => $font('body_font'),
            '--rt-size' => self::SIZES['title_size'][$o['title_size'] ?? 'normal'] ?? '1.3rem',
            '--rco-size' => self::SIZES['company_size'][$o['company_size'] ?? 'normal'] ?? '.95rem',
            '--rc-size' => self::SIZES['col_size'][$o['col_size'] ?? 'normal'] ?? '.76rem',
            '--rh-color' => self::SIZES['head_color'][$o['head_color'] ?? 'ink'] ?? '#111827',
        ];
        $out = [];
        foreach ($vars as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return implode('; ', $out);
    }

    /**
     * The printed page: paper, which way up, margins, page numbers, the title on every page.
     * Page numbers and the running title are printed by the browser in the page margins; Chrome and
     * Edge do that, and a browser that cannot simply leaves them off.
     */
    public static function pageCss(array $o, string $title): string
    {
        $sizes = ['a4' => ['210mm', '297mm'], 'letter' => ['8.5in', '11in'], 'folio' => ['8.5in', '13in'], 'legal' => ['8.5in', '14in']];
        $land = ($o['orientation'] ?? 'portrait') === 'landscape';
        $paper = $o['paper'] ?? 'auto';
        if (isset($sizes[$paper])) {
            [$w, $h] = $sizes[$paper];
            $size = $land ? "$h $w" : "$w $h";
        } else {
            $size = $land ? 'landscape' : 'auto';
        }
        $margin = ['narrow' => '6mm', 'normal' => '12mm', 'wide' => '20mm'][$o['margins'] ?? 'normal'] ?? '12mm';
        $quote = fn(string $s) => '"' . str_replace(['\\', '"', "\n", '<'], ['\\\\', '\\"', ' ', '\\3c '], $s) . '"';
        $css = '@page { size: ' . $size . '; margin: ' . $margin . ';';
        if (!empty($o['page_numbers'])) {
            $css .= ' @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 8pt ' . self::FONTS['sans'][1] . '; color: #555; }';
        }
        if (!empty($o['repeat_title'])) {
            $css .= ' @top-center { content: ' . $quote($title) . '; font: 8pt ' . self::FONTS['sans'][1] . '; color: #555; }';
        }
        $css .= ' }';
        if (!empty($o['repeat_title'])) {
            $css .= ' @page :first { @top-center { content: none; } }';
        }
        return '<style>' . $css . '</style>';
    }
}
