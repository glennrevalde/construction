<?php
declare(strict_types=1);

/**
 * Report engine shared by every report: filters, column customization,
 * sorting, drill-down links, totals, CSV export, print and memorized reports.
 *
 * A report definition returns:
 *   columns: [ ['key','label','type'=>text|money|qty|pct|date|int, 'sensitive'=>'cost'|'bank'] ]
 *   rows:    [ [...values, '_class'=>group-row|subtotal-row|total-row|indent-1, '_links'=>[col=>url], '_html'=>[col=>html]] ]
 *   totals:  optional row
 *   chart:   optional ['bars' => [label => value]] and/or ['pie' => [label => value]]
 */
final class Report
{
    private static array $defs = [];
    private static bool $loaded = false;

    public static function define(string $key, array $def): void
    {
        self::$defs[$key] = $def + ['filters' => [], 'sortable' => true, 'periods' => false];
    }

    public static function get(string $key): ?array
    {
        self::load();
        return self::$defs[$key] ?? null;
    }

    public static function all(): array
    {
        self::load();
        return self::$defs;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        foreach (glob(APP_ROOT . '/app/reports/*.php') as $f) {
            require $f;
        }
    }

    // ------------------------------------------------------------------ filters

    public static function filters(array $def): array
    {
        $f = [];
        $want = $def['filters'];
        if (in_array('asof', $want, true)) {
            $f['asof'] = gdate('asof', today());
        }
        if (in_array('range', $want, true)) {
            [$df, $dt] = self::preset((string)g('dates', $def['default_dates'] ?? 'this_month'));
            $f['from'] = gdate('from', $df);
            $f['to'] = gdate('to', $dt);
        }
        foreach (['customer_id', 'vendor_id', 'item_id', 'site_id', 'rep_id', 'account_id', 'terminal_id', 'user_id', 'rec_id', 'asset_id', 'category_id', 'class_id',
                  'pref_vendor_id', 'customer_type_id'] as $k) {
            $f[$k] = gint($k);
        }
        $f['item_type'] = isset(Importer::ITEM_TYPES[(string)g('item_type')]) ? (string)g('item_type') : '';
        if (!Classes::ready()) {
            $f['class_id'] = null;
        }
        foreach (['txn_type', 'module', 'q', 'columns', 'blind', 'include_nonposting', 'cost', 'salvage', 'life'] as $k) {
            $f[$k] = g($k);
        }
        // report-specific drop-downs: 'extra_filters' => [name => [label, fn() => [value => label], blank label or null]]
        foreach ($def['extra_filters'] ?? [] as $k => $_) {
            $f[$k] = is_string(g($k)) ? g($k) : '';
        }
        // report-specific tick boxes: 'checks' => [name => label]
        foreach ($def['checks'] ?? [] as $k => $_) {
            $f[$k] = g($k) === '1' ? '1' : '';
        }
        // report-specific dates: 'date_inputs' => [name => [label, default date or fn() => date, or null for none]] (v29)
        foreach ($def['date_inputs'] ?? [] as $k => $spec) {
            $dflt = $spec[1] ?? null;
            if (is_callable($dflt)) {
                $dflt = $dflt();
            }
            // a box emptied on purpose stays empty; a box never sent takes its default
            $f[$k] = array_key_exists($k, $_GET) ? (gdate($k) ?? '') : (string)($dflt ?? '');
        }
        // a time after each date ('times' => true): from the start of the From minute to the end of the To minute
        if (!empty($def['times']) && isset($f['from'])) {
            $f['from_time'] = self::timeValue(g('from_time'));
            $f['to_time'] = self::timeValue(g('to_time'));
        }
        // the report's own last word on its filters (a POS shift sets the dates, times, site and cashier)
        if (isset($def['filter_hook']) && is_callable($def['filter_hook'])) {
            $f = ($def['filter_hook'])($f);
        }
        if (!empty($def['times']) && isset($f['from'])) {
            self::stampTimes($f);
        }
        return $f;
    }

    /** "HH:MM" or "HH:MM:SS" as typed in a time box, or '' */
    public static function timeValue($v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) {
            return '';
        }
        return $m[1] . ':' . $m[2] . (isset($m[3]) && $m[3] !== '' ? ':' . $m[3] : '');
    }

    /**
     * from_dt / to_dt for a report with times: the From time counts from the start of its minute, the To time to
     * the end of its minute, so 12:01 AM - 8:00 AM and 8:01 AM - 2:00 PM follow each other without a gap. 'timed'
     * says a time was given (a report without one keeps to whole days).
     */
    public static function stampTimes(array &$f): void
    {
        $ft = (string)($f['from_time'] ?? '');
        $tt = (string)($f['to_time'] ?? '');
        $f['from_dt'] = $f['from'] . ' ' . ($ft === '' ? '00:00:00' : (strlen($ft) === 5 ? $ft . ':00' : $ft));
        $f['to_dt'] = $f['to'] . ' ' . ($tt === '' ? '23:59:59' : (strlen($tt) === 5 ? $tt . ':59' : $tt));
        $f['timed'] = $ft !== '' || $tt !== '';
    }

    /** "10/15/2026 08:00 AM" for a date and time of a report with times */
    public static function fdt(string $dt): string
    {
        $t = strtotime($dt);
        return $t ? date('m/d/Y h:i' . ((int)date('s', $t) !== 0 && (int)date('s', $t) !== 59 ? ':s' : '') . ' A', $t) : '';
    }

    public static function preset(string $p): array
    {
        $y = (int)date('Y');
        $m = (int)date('n');
        $q0 = intdiv($m - 1, 3) * 3 + 1;
        $fyStart = (int)Settings::get('fiscal_year_start_month', '1') ?: 1;
        $fy = $m >= $fyStart ? $y : $y - 1;
        return match ($p) {
            'today' => [today(), today()],
            'this_week' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
            'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            'this_quarter' => [sprintf('%04d-%02d-01', $y, $q0), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $q0 + 2)))],
            'last_quarter' => [date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $y, $q0) . ' -3 months')), date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $y, $q0) . ' -1 day'))],
            'this_year' => [sprintf('%04d-%02d-01', $fy, $fyStart), date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $fy + 1, $fyStart) . ' -1 day'))],
            'last_year' => [sprintf('%04d-%02d-01', $fy - 1, $fyStart), date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $fy, $fyStart) . ' -1 day'))],
            'year_to_date' => [sprintf('%04d-%02d-01', $fy, $fyStart), today()],
            'all' => ['2000-01-01', date('Y-m-d', strtotime('+1 year'))],
            default => [date('Y-m-01'), date('Y-m-t')],
        };
    }

    public static function fiscalYearStart(string $date): string
    {
        $fs = (int)Settings::get('fiscal_year_start_month', '1') ?: 1;
        $y = (int)substr($date, 0, 4);
        $m = (int)substr($date, 5, 2);
        return sprintf('%04d-%02d-01', $m >= $fs ? $y : $y - 1, $fs);
    }

    /** Period buckets for "columns by month / quarter / year" */
    public static function periods(string $from, string $to, string $mode): array
    {
        $out = [];
        if (!in_array($mode, ['month', 'quarter', 'year'], true)) {
            return $out;
        }
        $cur = strtotime(date('Y-m-01', strtotime($from)));
        $end = strtotime($to);
        $n = 0;
        while ($cur <= $end && $n++ < 60) {
            if ($mode === 'month') {
                $key = date('Y-m', $cur);
                $label = date('M Y', $cur);
                $next = strtotime('+1 month', $cur);
            } elseif ($mode === 'quarter') {
                $q = intdiv((int)date('n', $cur) - 1, 3) + 1;
                $key = date('Y', $cur) . '-Q' . $q;
                $label = 'Q' . $q . ' ' . date('Y', $cur);
                $next = strtotime(date('Y', $cur) . '-' . sprintf('%02d', $q * 3 + 1) . '-01');
                if ($q === 4) {
                    $next = strtotime((date('Y', $cur) + 1) . '-01-01');
                }
            } else {
                $key = date('Y', $cur);
                $label = $key;
                $next = strtotime((date('Y', $cur) + 1) . '-01-01');
            }
            $out[$key] = $label;
            $cur = $next;
        }
        return $out;
    }

    public static function periodKey(string $date, string $mode): string
    {
        if ($mode === 'month') {
            return substr($date, 0, 7);
        }
        if ($mode === 'quarter') {
            return substr($date, 0, 4) . '-Q' . (intdiv((int)substr($date, 5, 2) - 1, 3) + 1);
        }
        return substr($date, 0, 4);
    }

    // ------------------------------------------------------------------ shared data sources

    // ------------------------------------------------------------------ filters on the records a report is about
    // A report about items can be narrowed by the item's category, type and preferred supplier; one about customers
    // by the customer type. The report names them in its 'filters' (category, item_type, pref_vendor, customer_type)
    // and applies them with itemCond / customerCond in its query, or itemOk / customerOk on rows it already has.

    private static array $idCache = [];

    /** The items the item filters let through, or null when no item filter is set */
    public static function itemIds(array $f): ?array
    {
        $w = [];
        $p = [];
        if (!empty($f['category_id'])) {
            $w[] = 'category_id = :rc';
            $p['rc'] = (int)$f['category_id'];
        }
        if (!empty($f['item_type'])) {
            $w[] = 'item_type = :rt';
            $p['rt'] = (string)$f['item_type'];
        }
        if (!empty($f['pref_vendor_id'])) {
            $w[] = 'preferred_vendor_id = :rv';
            $p['rv'] = (int)$f['pref_vendor_id'];
        }
        if (!$w) {
            return null;
        }
        $key = 'i' . json_encode($p);
        return self::$idCache[$key] ??= array_map('intval', DB::column('SELECT id FROM items WHERE ' . implode(' AND ', $w), $p));
    }

    /** The customers (with their jobs) the customer filters let through, or null when none is set */
    public static function customerIds(array $f): ?array
    {
        if (empty($f['customer_type_id'])) {
            return null;
        }
        $key = 'c' . (int)$f['customer_type_id'];
        return self::$idCache[$key] ??= array_map('intval', DB::column(
            'SELECT c.id FROM customers c LEFT JOIN customers pc ON pc.id = c.parent_id
              WHERE c.customer_type_id = :t OR (c.customer_type_id IS NULL AND pc.customer_type_id = :t2)',
            ['t' => (int)$f['customer_type_id'], 't2' => (int)$f['customer_type_id']]
        ));
    }

    /** " AND <col> IN (...)" for the item filters, or '' when none is set */
    public static function itemCond(array $f, string $col, array &$p, string $tag = 'rfi'): string
    {
        return self::idCond(self::itemIds($f), $col, $p, $tag);
    }

    public static function customerCond(array $f, string $col, array &$p, string $tag = 'rfc'): string
    {
        return self::idCond(self::customerIds($f), $col, $p, $tag);
    }

    private static function idCond(?array $ids, string $col, array &$p, string $tag): string
    {
        if ($ids === null) {
            return '';
        }
        if (!$ids) {
            return ' AND 1 = 0';
        }
        [$in, $ip] = DB::in($tag, $ids);
        $p += $ip;
        return " AND $col IN $in";
    }

    public static function itemOk(array $f, $itemId): bool
    {
        $ids = self::itemIds($f);
        return $ids === null || ((int)$itemId > 0 && in_array((int)$itemId, $ids, true));
    }

    public static function customerOk(array $f, $customerId): bool
    {
        $ids = self::customerIds($f);
        return $ids === null || ((int)$customerId > 0 && in_array((int)$customerId, $ids, true));
    }

    /** Is an item, customer or site filter of this kind set? (a report can then leave out its empty groups) */
    public static function narrowed(array $f): bool
    {
        return self::itemIds($f) !== null || self::customerIds($f) !== null;
    }

    /**
     * Signed sales lines (invoices, sales receipts, POS sales; minus credit memos and POS returns).
     * Returns rows with txn and line data.
     */
    public static function salesLines(string $from, string $to, array $opt = []): array
    {
        $sql = "SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.customer_id, t.sales_rep_id, t.terminal_id, t.created_by,
                       COALESCE(l.site_id, t.site_id) AS site_id, l.item_id, l.description, l.qty, l.rate, l.amount, l.cost_amount, l.discount_amount,
                       l.unit, l.uom_qty, l.uom_rate, l.uom_factor,
                       i.code AS item_code, i.name AS item_name, i.item_type, i.category_id,
                       COALESCE(pc.name, c.name) AS parent_name, c.name AS customer_name, c.parent_id,
                       CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END AS sgn
                  FROM transaction_lines l
                  JOIN transactions t ON t.id = l.txn_id
                  LEFT JOIN items i ON i.id = l.item_id
                  LEFT JOIN customers c ON c.id = t.customer_id
                  LEFT JOIN customers pc ON pc.id = c.parent_id
                 WHERE t.txn_type IN ('invoice','sales_receipt','pos_sale','credit_memo','pos_return')
                   AND t.is_voided = 0 AND t.is_pending = 0 AND t.status <> 'suspended'
                   AND t.txn_date BETWEEN :f AND :t
                   AND COALESCE(i.item_type, '') NOT IN ('subtotal','payment')";
        $p = ['f' => $from, 't' => $to];
        if (!empty($opt['customer_id'])) {
            $sql .= ' AND (c.id = :cid OR c.parent_id = :cid)';
            $p['cid'] = $opt['customer_id'];
        }
        if (!empty($opt['item_id'])) {
            $sql .= ' AND l.item_id = :iid';
            $p['iid'] = $opt['item_id'];
        }
        if (!empty($opt['rep_id'])) {
            $sql .= ' AND t.sales_rep_id = :rid';
            $p['rid'] = $opt['rep_id'];
        }
        if (!empty($opt['site_id'])) {
            $sql .= ' AND COALESCE(l.site_id, t.site_id) = :sid';
            $p['sid'] = $opt['site_id'];
        }
        // the item's category, type and preferred supplier, and the customer type
        $sql .= self::itemCond($opt, 'l.item_id', $p) . self::customerCond($opt, 't.customer_id', $p);
        if (!empty($opt['pos_only'])) {
            $sql .= " AND t.txn_type IN ('pos_sale','pos_return')";
        }
        if (!empty($opt['terminal_id'])) {
            $sql .= ' AND t.terminal_id = :tid';
            $p['tid'] = $opt['terminal_id'];
        }
        if (!empty($opt['created_by'])) {
            $sql .= ' AND t.created_by = :uid';
            $p['uid'] = $opt['created_by'];
        }
        if (!empty($opt['class_id']) && Classes::ready()) {
            [$csql, $cp] = Classes::sqlFilter((int)$opt['class_id'], 't', 'sclass');
            $sql .= $csql;
            $p += $cp;
        }
        [$sf, $sp] = Access::siteFilter('COALESCE(l.site_id, t.site_id)', 'rs');
        $rows = DB::all($sql . $sf . ' ORDER BY t.txn_date, t.id, l.line_no', $p + $sp);
        $reps = DB::pairs('SELECT id, name FROM sales_reps');
        foreach ($rows as &$r) {
            $s = (int)$r['sgn'];
            $r['s_qty'] = $s * (float)$r['qty'];
            $r['s_amount'] = $s * (float)$r['amount'];
            $r['s_cost'] = $s * (float)$r['cost_amount'];
            $r['customer_key'] = (int)($r['customer_id'] ?? 0);
            $r['customer_label'] = $r['customer_id']
                ? ($r['parent_id'] ? $r['parent_name'] . ':' . $r['customer_name'] : $r['customer_name'])
                : 'Walk-in / cash sales';
            $r['item_label'] = $r['item_code'] ? $r['item_code'] . ' - ' . $r['item_name'] : ($r['description'] ?: 'Other');
            $r['rep_label'] = $reps[$r['sales_rep_id']] ?? '(no sales rep)';
            $r['sales_rep_id'] = (int)($r['sales_rep_id'] ?? 0);
        }
        return $rows;
    }

    /**
     * Runs a report and returns [rows, its own columns, every column it can print (own + related fields),
     * the data]. Columns the user may not see (costs, bank balances) are already left out.
     */
    public static function prepare(array $def, array $f): array
    {
        $data = ($def['run'])($f);
        $cols = array_values(array_filter($data['columns'], [self::class, 'maySee']));
        [$rows, $related] = ReportFields::extend($data['rows']);
        $related = array_values(array_filter($related, [self::class, 'maySee']));
        self::applyPicker($def, $cols);
        return [$rows, $cols, array_merge($cols, $related), $data];
    }

    /**
     * A report with its own Columns box ('column_picker'): the columns ticked there (show[]) are the ones that
     * print, in the report's order unless a layout put them in another one. Everybody who may open the report
     * can pick its columns; changing how it looks is still for the users who may design reports.
     */
    /** The order the address gave before the Columns box turned the ticks into one (null: the box was not used) */
    private static ?string $pickerOrder = null;

    private static function applyPicker(array $def, array $cols): void
    {
        if (empty($def['column_picker']) || !isset($_GET['show']) || !is_array($_GET['show'])) {
            return;
        }
        $keys = array_column($cols, 'key');
        $chosen = array_values(array_intersect($keys, array_map('strval', $_GET['show'])));
        if (!$chosen) {
            return;                          // nothing ticked: the standard columns
        }
        self::$pickerOrder = isset($_GET['order']) && is_string($_GET['order']) ? $_GET['order'] : '';
        $order = self::$pickerOrder !== '' ? array_values(array_filter(array_map('trim', explode(',', self::$pickerOrder)))) : [];
        // the order of a layout first, then whatever else was ticked, in the report's order
        $_GET['order'] = implode(',', array_merge(array_intersect($order, $chosen), array_diff($chosen, $order), array_diff($order, $keys)));
        $_GET['hide'] = array_merge([''], array_values(array_diff($keys, $chosen)));
    }

    /** Columns that carry costs or bank balances are only for the users allowed to see them */
    public static function maySee(array $c): bool
    {
        if (($c['sensitive'] ?? '') === 'cost' && !Access::viewCosts()) {
            return false;
        }
        if (($c['sensitive'] ?? '') === 'bank' && !Access::special('view_bank_balances')) {
            return false;
        }
        return true;
    }

    /**
     * The columns that print: the layout's order, headings, widths and alignment on every column, and the
     * hidden ones taken out. A column a report only offers (an 'optional' one, every related field) prints
     * only when a layout or the address switched it on by name.
     */
    public static function visibleColumns(array $allCols, array $lay): array
    {
        $allCols = ReportLayout::apply($allCols, $lay);
        $hidden = $lay['hidden'];
        $known = [];
        if ($lay['hidden_explicit']) {
            // an address or layout that names the columns knows which extras it wants; one that only
            // says what to hide (an old memorized address) gets the report's own extras, not the related fields
            $known = $lay['order'] ? array_flip($lay['order'])
                : array_flip(array_column(array_filter($allCols, fn($c) => !ReportFields::isRelated($c)), 'key'));
        }
        foreach ($allCols as $c) {
            if (!empty($c['optional']) && !isset($known[$c['key']])) {
                $hidden[] = $c['key'];
            }
        }
        $visible = array_values(array_filter($allCols, fn($c) => !in_array($c['key'], $hidden, true)));
        if (!$visible) {
            $visible = array_values(array_filter($allCols, fn($c) => empty($c['optional'])));   // never print an empty table because every column was switched off
        }
        return [$allCols, $visible, $hidden];
    }

    /** Report data as plain rows (used by the scheduled e-mail sender). Reads filters and layout from $_GET. */
    public static function csvFor(string $reportKey): array
    {
        $def = self::get($reportKey);
        if (!$def) {
            return [[], []];
        }
        [$rows, $cols, $allCols, $data] = self::prepare($def, self::filters($def));
        $pageKey = (string)($_GET['p'] ?? '');
        $lay = $pageKey !== '' ? ReportLayout::current($pageKey) : ['options' => ReportLayout::defaults(), 'order' => [], 'hidden' => [], 'labels' => [], 'hidden_explicit' => false, 'layout' => null];
        [, $visible] = self::visibleColumns($allCols, $lay);
        $rows = ReportFields::fill($rows, $visible);
        $out = [];
        foreach (array_merge($rows, !empty($data['totals']) ? [$data['totals']] : []) as $r) {
            $out[] = array_map(fn($c) => self::rawValue($r[$c['key']] ?? '', $c), $visible);
        }
        return [array_column($visible, 'label'), $out];
    }

    // ------------------------------------------------------------------ rendering

    public static function render(string $key, array $def, array $pageDef): void
    {
        $pageKey = $pageDef['key'];
        // a report can start with a layout of its own (signature captions, a subtitle) until one is saved
        if (!empty($def['layout_defaults'])) {
            ReportLayout::$reportDefaults[$pageKey] = (array)$def['layout_defaults'];
        }
        $f = self::filters($def);
        [$rows, $cols, $allCols, $data] = self::prepare($def, $f);
        $print = g('print') === '1';
        $canDesign = ReportLayout::canDesign();

        // the layout: the one saved as the default (or asked for), with whatever the address says on top
        $lay = ReportLayout::current($pageKey);
        $opt = $lay['options'];
        [$allCols, $visible, $hidden] = self::visibleColumns($allCols, $lay);
        // the related fields that were added are looked up only now, and only the ones that print
        $rows = ReportFields::fill($rows, $visible);
        $sort = g('sort');
        // 'sort_by' => [column => label]: the report offers a Sort by box (Increasing / Decreasing), also on grouped reports (v29)
        $sortBy = self::sortChoices($def, $allCols);
        $headSort = ($def['sortable'] ?? true) && empty($data['grouped']);
        if ($sort !== '' && (isset($sortBy[$sort]) || ($headSort && in_array($sort, array_column($allCols, 'key'), true)))) {
            $rows = self::sortRows($rows, $sort, g('dir') === 'desc' ? -1 : 1, !empty($data['grouped']));
            // the heading says so on the reports that offer Sort by
            self::$sortedBy = $sortBy[$sort] ?? null;
        }
        // the rows that print: zeros, detail or summary, subtotals, words to look for, rows taken out
        [$rows, $rowInfo] = ReportLayout::rows($rows, $cols, $opt);
        $showTotal = !empty($data['totals']) && !empty($opt['rows_total']);
        $title = trim((string)($opt['title'] ?? '')) !== '' ? trim((string)$opt['title']) : $pageDef['name'];
        $basis = self::basis($def, $f);
        $scale = ['thousands' => 'amounts in thousands', 'millions' => 'amounts in millions'][$opt['scale'] ?? 'units'] ?? '';
        if ($scale !== '') {
            $basis .= ($basis !== '' ? ', ' : '') . $scale;
        }

        if (g('export') === 'csv') {
            Access::require($pageKey, 'print');
            Audit::log('export', $pageDef['module'], 'report', null, $title, ['page_key' => $pageKey, 'reason' => self::basis($def, $f)]);
            $out = [];
            foreach ($rows as $r) {
                $out[] = array_map(fn($c) => self::rawValue($r[$c['key']] ?? '', $c), $visible);
            }
            if ($showTotal) {
                $out[] = array_map(fn($c) => self::rawValue($data['totals'][$c['key']] ?? '', $c), $visible);
            }
            csv_download(preg_replace('/\W+/', '_', strtolower($title)) . '.csv', array_map(fn($c) => (!empty($c['band']) ? $c['band'] . ' - ' : '') . $c['label'], $visible), $out);
        }
        if ($print) {
            Access::require($pageKey, 'print');
            Audit::log('print', $pageDef['module'], 'report', null, $title, ['page_key' => $pageKey, 'reason' => self::basis($def, $f)]);
        }

        // a report that also prints on a POS roll printer ('pos_print' => fn(...) that writes the slip)
        if (g('print') === 'pos' && isset($def['pos_print']) && is_callable($def['pos_print'])) {
            Access::require($pageKey, 'print');
            Audit::log('print', $pageDef['module'], 'report', null, $title, ['page_key' => $pageKey, 'reason' => 'POS printer: ' . self::basis($def, $f)]);
            $btOn = Receipt::btEnabled();
            layout_start($title, ['bare' => true, 'no_title' => true, 'js' => $btOn ? ['js/btprint.js'] : []]);
            ($def['pos_print'])($f, $rows, $visible, $data, $opt, $title, $btOn);
            layout_end();
            return;
        }

        $saved = ReportLayout::forReport($pageKey);
        $actions = '';
        if (Access::can($pageKey, 'print') && isset($def['pos_print'])) {
            $actions .= btn_link(current_url(['print' => 'pos']), 'Print to POS printer', 'btn-outline-secondary', 'receipt') . ' ';
        }
        if (Access::can($pageKey, 'print')) {
            $actions .= btn_link(current_url(['print' => '1']), 'Print / PDF', 'btn-outline-secondary', 'printer') . ' '
                . btn_link(current_url(['export' => 'csv']), 'Export to spreadsheet', 'btn-outline-secondary', 'file-earmark-spreadsheet') . ' '
                . '<a class="btn btn-sm btn-outline-secondary" href="mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode(($_SERVER['HTTP_HOST'] ?? '') ? 'http://' . $_SERVER['HTTP_HOST'] . current_url() : current_url()) . '"><i class="bi bi-envelope"></i> Email link</a> ';
        }
        if (!$print && $canDesign) {
            $actions .= '<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#rptCustomize" aria-expanded="false" id="rptDesignBtn">'
                . '<i class="bi bi-sliders"></i> Modify Report' . ($lay['layout'] ? ': ' . h($lay['layout']['name']) : '') . '</button> ';
        } elseif (!$print && $saved) {
            // no permission to design, but the layouts shared with them can still be used
            $actions .= '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">'
                . '<i class="bi bi-layout-text-window"></i> ' . ($lay['layout'] ? h($lay['layout']['name']) : 'Layouts') . '</button><ul class="dropdown-menu">'
                . '<li><a class="dropdown-item" href="' . h(current_url(['layout' => '0'])) . '">Standard</a></li>';
            foreach ($saved as $l) {
                $actions .= '<li><a class="dropdown-item" href="' . h(current_url(['layout' => (int)$l['id']])) . '">' . h($l['name']) . '</a></li>';
            }
            $actions .= '</ul></div> ';
        }
        if (Access::page('home.memorized')) {
            $actions .= '<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#memModal"><i class="bi bi-bookmark-plus"></i> Memorize</button>';
        }
        layout_start($title, ['actions' => $actions, 'bare' => $print, 'no_title' => $print,
            'body_class' => ($opt['orientation'] ?? 'portrait') === 'landscape' ? 'print-landscape' : '']);
        echo ReportLayout::pageCss($opt, $title);
        if ($print) {
            echo '<div class="no-print text-center mb-2"><button class="btn btn-primary btn-sm" onclick="window.print()">Print / Save as PDF</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(current_url(['print' => null])) . '">Back</a></div>';
        } else {
            self::filterForm($def, $f, $pageKey, $allCols, $hidden, $canDesign);
            if ($canDesign) {
                ReportDesigner::panel($pageKey, $allCols, $hidden, $lay, $title, $rowInfo, $saved);
            }
        }

        $s = Settings::all();
        $designing = !$print && $canDesign;
        echo '<div class="card rpt-card"><div class="card-body">';
        echo '<div id="rptDoc" class="' . h(ReportLayout::docClass($opt)) . '" style="' . h(ReportLayout::docStyle($opt)) . '">';

        // ---------------- the heading
        // the logo is drawn while designing even when switched off, so ticking it shows it straight away
        $logo = (!empty($opt['show_logo']) || $designing) ? Logo::img($pageKey, 'report-logo') : '';
        if ($logo !== '' && empty($opt['show_logo'])) {
            $logo = str_replace('<img ', '<img hidden ', $logo);
        }
        $headLines = ReportLayout::lines($opt['header_lines'] ?? '');
        echo '<div class="report-head' . ($logo !== '' && !empty($opt['show_logo']) ? ' has-logo' : '') . '">' . $logo . '<div class="report-head-text">'
            . '<div class="company" id="rptCompany"' . (empty($opt['show_company']) ? ' hidden' : '') . '>' . h($s['company_name']) . '</div>'
            . '<div class="company-line" id="rptAddress"' . (empty($opt['show_address']) || trim((string)$s['company_address']) === '' ? ' hidden' : '') . '>' . h((string)$s['company_address']) . '</div>'
            . '<div class="company-line" id="rptTin"' . (empty($opt['show_tin']) || trim((string)$s['company_tin']) === '' ? ' hidden' : '') . '>TIN ' . h((string)$s['company_tin']) . '</div>'
            . '<div class="title" id="rptTitle" data-standard="' . h($pageDef['name']) . '">' . h($title) . '</div>'
            . '<div class="subtitle" id="rptSub"' . (trim((string)($opt['subtitle'] ?? '')) === '' ? ' hidden' : '') . '>' . h((string)$opt['subtitle']) . '</div>'
            . '<div class="head-lines" id="rptHeadLines"' . (!$headLines ? ' hidden' : '') . '>' . implode('', array_map(fn($x) => '<div>' . h($x) . '</div>', $headLines)) . '</div>'
            . '<div class="basis small" id="rptBasis"' . (empty($opt['show_basis']) || $basis === '' ? ' hidden' : '') . '>' . h($basis) . '</div>'
            . '<div class="basis small" id="rptPrepared"' . (empty($opt['show_prepared']) ? ' hidden' : '') . '>Prepared ' . h(date('m/d/Y h:i A')) . ' by ' . h(Auth::user()['full_name']) . '</div>'
            . '</div></div>';
        if (!empty($data['notes'])) {
            echo '<div class="small text-muted mb-2 no-print-notes">' . $data['notes'] . '</div>';
        }
        if ($rowInfo['taken_out'] || $rowInfo['left_out']) {
            echo '<div class="small text-muted mb-2 no-print">'
                . ($rowInfo['taken_out'] ? $rowInfo['taken_out'] . ' row' . ($rowInfo['taken_out'] === 1 ? ' is' : 's are') . ' taken out by the layout. ' : '')
                . ($rowInfo['left_out'] ? $rowInfo['left_out'] . ' row' . ($rowInfo['left_out'] === 1 ? ' is' : 's are') . ' left out by the row choices. ' : '')
                . 'The totals are those of the whole report.</div>';
        }
        if (!empty($data['chart'])) {
            self::chart($data['chart']);
        }

        // ---------------- the table
        $banded = (bool)array_filter($visible, fn($c) => !empty($c['band']));
        echo '<div class="table-responsive"><table class="table table-sm ' . ReportLayout::tableClass($opt) . ($banded ? ' rpt-banded' : '') . ' mb-0" id="rptTable"><thead>';
        // column bands (e.g. IN and OUT over their columns): a heading row above the column labels
        if ($banded) {
            echo '<tr class="rpt-band-row">';
            $vl = array_values($visible);
            $n = count($vl);
            for ($i = 0; $i < $n; $i++) {
                $band = (string)($vl[$i]['band'] ?? '');
                $span = 1;
                while ($i + $span < $n && (string)($vl[$i + $span]['band'] ?? '') === $band) {
                    $span++;
                }
                echo '<th' . ($span > 1 ? ' colspan="' . $span . '"' : '') . ' class="' . ($band !== '' ? 'rpt-band' : 'rpt-band-empty') . '">' . h($band) . '</th>';
                $i += $span - 1;
            }
            echo '</tr>';
        }
        echo '<tr>';
        foreach ($visible as $c) {
            $num = in_array($c['type'] ?? 'text', ['money', 'qty', 'pct', 'int'], true);
            $label = h($c['label']);
            if (!$print && ($headSort || isset($sortBy[$c['key']]))) {
                $dir = ($sort === $c['key'] && g('dir') !== 'desc') ? 'desc' : 'asc';
                $label = '<a class="text-reset text-decoration-none" href="' . h(current_url(['sort' => $c['key'], 'dir' => $dir])) . '">' . $label . ($sort === $c['key'] ? (g('dir') === 'desc' ? ' ▼' : ' ▲') : '') . '</a>';
            }
            echo '<th class="' . trim(($num ? 'num' : '') . ' ' . self::colClass($c)) . '" data-col="' . h($c['key']) . '">' . $label . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . count($visible) . '" class="text-center text-muted py-3">No data for these settings.</td></tr>';
        }
        foreach ($rows as $r) {
            echo self::rowHtml($r, $visible, $opt, $designing);
        }
        if ($showTotal) {
            echo self::rowHtml($data['totals'] + ['_class' => 'total-row'], $visible, $opt, false);
        }
        echo '</tbody></table></div>';

        // ---------------- the foot
        if (!empty($data['footer'])) {
            echo '<div class="mt-3">' . $data['footer'] . '</div>';
        }
        $labels = ReportLayout::lines($opt['sign_labels'] ?? '') ?: ['Prepared by', 'Checked by', 'Approved by'];
        $names = ReportLayout::lines($opt['sign_names'] ?? '');
        echo '<div class="row g-4 mt-3 sign-lines" id="rptSign"' . (empty($opt['prepared_by']) ? ' hidden' : '') . '>';
        $w = max(2, (int)floor(12 / max(1, min(4, count($labels)))));
        foreach (array_slice($labels, 0, 4) as $i => $who) {
            echo '<div class="col-' . $w . '"><div class="sign-line"></div>'
                . (isset($names[$i]) ? '<div class="sign-name">' . h($names[$i]) . '</div>' : '')
                . '<div class="small text-muted">' . h($who) . '</div></div>';
        }
        echo '</div>';
        $footLines = ReportLayout::lines($opt['footer_text'] ?? '');
        echo '<div class="report-foot" id="rptFoot"' . (!$footLines ? ' hidden' : '') . '>' . implode('', array_map(fn($x) => '<div>' . h($x) . '</div>', $footLines)) . '</div>';
        echo '<div class="small text-muted mt-2 report-printed" id="rptPrinted"' . (empty($opt['show_printed']) ? ' hidden' : '') . '>Printed ' . h(date('m/d/Y h:i A')) . ' by ' . h(Auth::user()['full_name']) . '</div>';
        echo '</div></div></div>';

        if (!$print && Access::page('home.memorized')) {
            $qs = $_GET;
            unset($qs['p']);
            echo '<div class="modal fade" id="memModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="' . h(url('home.memorized', ['a' => 'save'])) . '">'
                . csrf_field() . '<input type="hidden" name="report_key" value="' . h($pageKey) . '"><input type="hidden" name="query_string" value="' . h(http_build_query($qs)) . '">'
                . '<div class="modal-header"><h5 class="modal-title">Memorize report</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                . '<div class="modal-body row g-2">'
                . '<div class="col-12"><label class="form-label">Name</label><input class="form-control" name="name" value="' . h($title) . '" required></div>'
                . '<div class="col-6"><label class="form-label">Group</label><input class="form-control" name="group_name" placeholder="e.g. Month-end"></div>'
                . '<div class="col-6 d-flex align-items-end"><label><input type="checkbox" name="is_shared" value="1"> Share with other users</label></div>'
                . '<div class="col-6"><label class="form-label">Send automatically</label><select class="form-select" name="schedule"><option value="">No</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div>'
                . '<div class="col-6"><label class="form-label">Email to</label><input class="form-control" name="email_to" placeholder="name@company.com"></div>'
                . '</div><div class="modal-footer"><button class="btn btn-primary">Save</button></div></form></div></div>';
        }
        layout_end();
    }

    /** The Sort by label of the sort applied to the report on screen, for its heading (v29) */
    private static ?string $sortedBy = null;

    /** The Sort by choices of a report ('sort_by'), less the columns this user may not see (costs) */
    public static function sortChoices(array $def, array $cols): array
    {
        $out = [];
        $byKey = array_column($cols, null, 'key');
        foreach ((array)($def['sort_by'] ?? []) as $k => $label) {
            // a column the report does not have for this user (a cost column without the cost permission) is not offered
            if (!isset($byKey[$k]) || !self::maySee($byKey[$k])) {
                continue;
            }
            $out[$k] = $label;
        }
        return $out;
    }

    /**
     * Rows sorted on one column ($dir 1 increasing, -1 decreasing); empty figures go last either way.
     * A grouped report keeps its groups: a heading row (group-row) with no figure of its own keeps its place and its
     * lines are sorted under it, above its subtotal; a heading row that carries the figure (an item group with its
     * items under it) moves with everything under it, down to its subtotal.
     */
    public static function sortRows(array $rows, string $key, int $dir, bool $grouped): array
    {
        $blank = fn($v) => $v === '' || $v === null;
        $cmp = function (array $a, array $b) use ($key, $dir, $blank): int {
            $x = $a[$key] ?? '';
            $y = $b[$key] ?? '';
            if ($blank($x) || $blank($y)) {
                return $blank($x) <=> $blank($y);
            }
            if (is_numeric($x) && is_numeric($y)) {
                return $dir * ((float)$x <=> (float)$y);
            }
            return $dir * strcasecmp((string)$x, (string)$y);
        };
        $has = fn(array $r, string $c) => str_contains(' ' . ($r['_class'] ?? '') . ' ', ' ' . $c . ' ');
        if (!$grouped) {
            $fixed = array_filter($rows, fn($r) => $has($r, 'total-row'));
            $body = array_values(array_filter($rows, fn($r) => !$has($r, 'total-row')));
            usort($body, $cmp);
            return array_merge($body, array_values($fixed));
        }
        // cut the rows into units: single lines, and blocks from a heading down to its subtotal
        $units = [];
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            $r = $rows[$i];
            if (!$has($r, 'group-row')) {
                $units[] = ['rows' => [$r], 'sort' => !$has($r, 'total-row') && !$has($r, 'subtotal-row'), 'head' => $r];
                continue;
            }
            $block = [$r];
            $j = $i + 1;
            while ($j < $n && !$has($rows[$j], 'group-row') && !$has($rows[$j], 'total-row')) {
                $block[] = $rows[$j];
                if ($has($rows[$j], 'subtotal-row')) {
                    $j++;
                    break;
                }
                $j++;
            }
            $i = $j - 1;
            if (!$blank($r[$key] ?? '')) {
                $units[] = ['rows' => $block, 'sort' => true, 'head' => $r];
                continue;
            }
            // a heading only: sort its lines, keep the heading on top and the subtotal at the foot
            $tail = [];
            if (count($block) > 1 && $has(end($block), 'subtotal-row')) {
                $tail[] = array_pop($block);
            }
            $head = array_shift($block);
            usort($block, $cmp);
            $units[] = ['rows' => array_merge([$head], $block, $tail), 'sort' => false, 'head' => $r];
        }
        // the units that may move are sorted among themselves; the others keep their places
        $movable = array_values(array_filter($units, fn($u) => $u['sort']));
        usort($movable, fn($a, $b) => $cmp($a['head'], $b['head']));
        $out = [];
        $m = 0;
        foreach ($units as $u) {
            $use = $u['sort'] ? $movable[$m++] : $u;
            foreach ($use['rows'] as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** The width and alignment classes of a column, from the layout */
    public static function colClass(array $c): string
    {
        $cls = [];
        if (($c['_width'] ?? '') !== '') {
            $cls[] = 'rcw-' . preg_replace('/[^a-z]/', '', (string)$c['_width']);
        }
        if (($c['_align'] ?? '') !== '') {
            $cls[] = 'rca-' . preg_replace('/[^a-z]/', '', (string)$c['_align']);
        }
        return implode(' ', $cls);
    }

    private static function rowHtml(array $r, array $cols, array $opt = [], bool $designing = false): string
    {
        $cls = $r['_class'] ?? '';
        $isTotal = str_contains(' ' . $cls . ' ', ' total-row ') || str_contains(' ' . $cls . ' ', ' subtotal-row ');
        $key = (string)($r['_key'] ?? '');
        $html = '<tr class="' . h($cls) . '"' . ($key !== '' ? ' data-rowkey="' . h($key) . '"' : '') . '>';
        // the words of a group heading, a subtotal or the total ("Total ABC Trading Corp.") run on over the empty
        // cells that follow them instead of being squeezed into the first column
        $spans = [];
        if (preg_match('/(^| )(group-row|subtotal-row|total-row)( |$)/', $cls) && count($cols) > 1 && (string)($r[$cols[0]['key']] ?? '') !== '') {
            $n = 1;
            while ($n < count($cols)) {
                $k = $cols[$n]['key'];
                $v = $r[$k] ?? '';
                if (($v !== '' && $v !== null) || isset($r['_html'][$k])) {
                    break;
                }
                $n++;
            }
            if ($n > 1) {
                $spans[0] = $n;
                $cols = array_merge([$cols[0]], array_slice($cols, $n));
            }
        }
        foreach ($cols as $i => $c) {
            $type = $c['type'] ?? 'text';
            $v = $r[$c['key']] ?? '';
            if (isset($r['_html'][$c['key']])) {
                $cell = $r['_html'][$c['key']];
            } else {
                $cell = $opt && in_array($type, ['money', 'qty', 'pct', 'int'], true) && $v !== '' && $v !== null
                    ? ReportLayout::number($v, $type, $opt, $isTotal) : self::fmt($v, $type);
                if (isset($r['_links'][$c['key']]) && $r['_links'][$c['key']]) {
                    $cell = '<a href="' . h($r['_links'][$c['key']]) . '">' . $cell . '</a>';
                }
            }
            // while designing, every row that can be recognised gets a button to take it out
            if ($i === 0 && $designing && $key !== '' && !str_contains(' ' . $cls . ' ', ' total-row ')) {
                $cell = '<button type="button" class="rpt-rowhide no-print" title="Take this row out of the report" aria-label="Take this row out"><i class="bi bi-eye-slash"></i></button>' . $cell;
            }
            $num = in_array($type, ['money', 'qty', 'pct', 'int'], true);
            $neg = $num && is_numeric($v) && (float)$v < 0 ? ' neg' : '';
            // long free text (memos, descriptions) may wrap on a small screen; everything else keeps to one line
            $long = !$num && mb_strlen(html_entity_decode(strip_tags($cell), ENT_QUOTES)) > 38 ? ' rc-long' : '';
            $html .= '<td class="' . trim(($num ? 'num' : '') . $neg . $long . ' ' . self::colClass($c)) . '"' . (isset($spans[$i]) ? ' colspan="' . $spans[$i] . '" data-span="' . $spans[$i] . '"' : '') . '>' . $cell . '</td>';
        }
        return $html . '</tr>';
    }

    public static function fmt($v, string $type): string
    {
        if ($v === '' || $v === null) {
            return '';
        }
        return match ($type) {
            'money' => is_numeric($v) ? money($v) : h($v),
            'qty' => is_numeric($v) ? qty($v) : h($v),
            'int' => is_numeric($v) ? number_format((float)$v) : h($v),
            'pct' => is_numeric($v) ? number_format((float)$v, 1) . '%' : h($v),
            'date' => valid_date(substr((string)$v, 0, 10)) ? h(fdate($v)) : h($v),
            'datetime' => h(fdatetime($v)),
            default => h($v),
        };
    }

    private static function rawValue($v, array $c)
    {
        if (($c['type'] ?? '') === 'money' && is_numeric($v)) {
            return round((float)$v, 2);
        }
        return is_scalar($v) || $v === null ? $v : json_encode($v);
    }

    public static function basis(array $def, array $f): string
    {
        // a report can say in its own words what it was run for ('basis' => fn($f) => '...')
        if (isset($def['basis']) && is_callable($def['basis'])) {
            return (string)($def['basis'])($f);
        }
        $parts = [];
        if (isset($f['asof'])) {
            $parts[] = 'As of ' . fdate($f['asof']);
        }
        if (isset($f['from'])) {
            $parts[] = !empty($f['timed'])
                ? self::fdt($f['from_dt']) . ' through ' . self::fdt($f['to_dt'])
                : fdate($f['from']) . ' through ' . fdate($f['to']);
        }
        foreach (['customer_id' => 'customers', 'vendor_id' => 'vendors', 'item_id' => 'items', 'site_id' => 'sites', 'account_id' => 'accounts', 'terminal_id' => 'pos_terminals'] as $k => $tbl) {
            if (!empty($f[$k])) {
                $col = $tbl === 'items' || $tbl === 'accounts' || $tbl === 'pos_terminals' ? "CONCAT(code, ' ', name)" : 'name';
                $parts[] = DB::value("SELECT $col FROM $tbl WHERE id = :id", ['id' => $f[$k]]);
            }
        }
        if (!empty($f['class_id'])) {
            $parts[] = 'Class ' . Classes::name($f['class_id']);
        }
        $want = $def['filters'] ?? [];
        if (!empty($f['category_id']) && in_array('category', $want, true)) {
            $parts[] = 'Category ' . DB::value('SELECT name FROM item_categories WHERE id = :id', ['id' => $f['category_id']]);
        }
        if (!empty($f['item_type']) && in_array('item_type', $want, true)) {
            $parts[] = Importer::ITEM_TYPES[$f['item_type']] ?? $f['item_type'];
        }
        if (!empty($f['pref_vendor_id']) && in_array('pref_vendor', $want, true)) {
            $parts[] = 'Preferred supplier ' . DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $f['pref_vendor_id']]);
        }
        if (!empty($f['customer_type_id']) && in_array('customer_type', $want, true)) {
            $parts[] = 'Customer type ' . DB::value('SELECT name FROM customer_types WHERE id = :id', ['id' => $f['customer_type_id']]);
        }
        if (!empty($f['user_id'])) {
            $parts[] = (in_array('cashier', $def['filters'] ?? [], true) ? 'Cashier ' : 'User ')
                . DB::value('SELECT username FROM users WHERE id = :id', ['id' => $f['user_id']]);
        }
        foreach ($def['extra_filters'] ?? [] as $k => $spec) {
            [$label, $opts] = $spec;
            if (($f[$k] ?? '') !== '' && empty($spec[3])) {
                $parts[] = $label . ': ' . ($opts()[$f[$k]] ?? $f[$k]);
            }
        }
        foreach ($def['checks'] ?? [] as $k => $label) {
            if (($f[$k] ?? '') === '1') {
                $parts[] = $label;
            }
        }
        if (self::$sortedBy !== null) {
            $parts[] = 'Sorted by ' . self::$sortedBy . (g('dir') === 'desc' ? ', decreasing' : ', increasing');
        }
        return implode(', ', array_filter($parts));
    }

    private static function filterForm(array $def, array $f, string $pageKey, array $cols, array $hidden, bool $canDesign = false): void
    {
        $want = $def['filters'];
        echo '<form class="card card-body mb-3 filters" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">';
        foreach ((array)($f['_hidden'] ?? []) as $hk => $hv) {
            echo '<input type="hidden" name="' . h((string)$hk) . '" value="' . h((string)$hv) . '">';
        }
        echo '<div class="row g-2 align-items-end">';
        if (in_array('asof', $want, true)) {
            echo '<div class="col-auto"><label class="form-label">As of</label><input class="form-control form-control-sm" type="date" name="asof" value="' . h($f['asof']) . '"></div>';
        }
        if (in_array('range', $want, true)) {
            $timed = !empty($def['times']);
            echo '<div class="col-auto"><label class="form-label">Dates</label><select class="form-select form-select-sm" name="dates" onchange="this.form.from.value=\'\';this.form.to.value=\'\';'
                . ($timed ? 'this.form.from_time.value=\'\';this.form.to_time.value=\'\';if(this.form.shift_id)this.form.shift_id.value=\'\';' : '') . 'this.form.submit()">'
                . options(['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter',
                    'this_year' => 'This fiscal year', 'year_to_date' => 'Fiscal year to date', 'last_year' => 'Last fiscal year', 'all' => 'All dates', 'custom' => 'Custom'], g('dates', $def['default_dates'] ?? 'this_month'))
                . '</select></div>';
            if ($timed) {
                // a date and a time: shifts of a 24-hour business (12:01 AM - 8:00 AM, 8:01 AM - 2:00 PM, ...)
                foreach (['from' => 'From', 'to' => 'To'] as $k => $lab) {
                    $tv = (string)($f[$k . '_time'] ?? '');
                    echo '<div class="col-12 col-sm-auto"><label class="form-label">' . $lab . ' date and time</label><div class="input-group input-group-sm rpt-datetime">'
                        . '<input class="form-control" type="date" name="' . $k . '" value="' . h($f[$k]) . '" aria-label="' . $lab . ' date">'
                        . '<input class="form-control rpt-time" type="time" name="' . $k . '_time" value="' . h($tv) . '"' . (strlen($tv) === 8 ? ' step="1"' : '')
                        . ' aria-label="' . $lab . ' time" title="' . ($k === 'from' ? 'Leave empty for the start of the day' : 'Leave empty for the end of the day') . '"></div></div>';
                }
            } else {
                echo '<div class="col-auto"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($f['from']) . '"></div>';
                echo '<div class="col-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="' . h($f['to']) . '"></div>';
            }
        }
        foreach ($def['date_inputs'] ?? [] as $k => $spec) {
            echo '<div class="col-6 col-sm-auto"><label class="form-label">' . h($spec[0]) . '</label><input class="form-control form-control-sm" type="date" name="' . h($k) . '" value="' . h((string)($f[$k] ?? '')) . '"></div>';
        }
        $selects = [
            'customer' => ['customer_id', 'Customer', fn() => Lookup::customers()],
            'vendor' => ['vendor_id', 'Vendor', fn() => Lookup::vendors()],
            'item' => ['item_id', 'Item', fn() => Lookup::itemPairs("item_type IN ('inventory','assembly','non_inventory','service','other_charge')")],
            'site' => ['site_id', 'Site', fn() => Lookup::sites()],
            'rep' => ['rep_id', 'Sales rep', fn() => Lookup::reps()],
            'account' => ['account_id', 'Account', fn() => Accts::all(false)],
            'class' => ['class_id', 'Class', fn() => Classes::filterOptions()],
            'bank' => ['account_id', 'Account', fn() => Accts::banks()],
            'terminal' => ['terminal_id', 'Terminal', fn() => DB::pairs('SELECT id, name FROM pos_terminals ORDER BY code')],
            'user' => ['user_id', 'User', fn() => DB::pairs('SELECT id, username FROM users ORDER BY username')],
            'cashier' => ['user_id', 'Cashier', fn() => DB::pairs("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u
                                                                    WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.created_by = u.id AND t.terminal_id IS NOT NULL)
                                                                    ORDER BY 2")],
            'rec' => ['rec_id', 'Reconciliation', fn() => DB::pairs("SELECT r.id, CONCAT(a.code, ' - ', DATE_FORMAT(r.statement_date, '%m/%d/%Y'), ' - ', r.status) FROM bank_reconciliations r JOIN accounts a ON a.id = r.account_id ORDER BY r.statement_date DESC")],
            'asset' => ['asset_id', 'Asset', fn() => Lookup::assets()],
            'category' => ['category_id', 'Category', fn() => DB::pairs('SELECT id, name FROM item_categories ORDER BY name')],
            'item_type' => ['item_type', 'Item type', fn() => array_intersect_key(Importer::ITEM_TYPES, array_flip(DB::column('SELECT DISTINCT item_type FROM items')))],
            'pref_vendor' => ['pref_vendor_id', 'Preferred supplier', fn() => DB::pairs('SELECT v.id, v.name FROM vendors v WHERE EXISTS (SELECT 1 FROM items i WHERE i.preferred_vendor_id = v.id) ORDER BY v.name')],
            'customer_type' => ['customer_type_id', 'Customer type', fn() => DB::pairs('SELECT id, name FROM customer_types ORDER BY name')],
            'fa_category' => ['category_id', 'Asset category', fn() => DB::pairs('SELECT id, name FROM asset_categories ORDER BY name')],
        ];
        foreach ($selects as $name => [$field, $label, $opts]) {
            // a report can call a filter in its own words ('filter_labels' => ['site' => 'Inventory site'])
            $label = $def['filter_labels'][$name] ?? $label;
            if (in_array($name, $want, true)) {
                echo '<div class="col-auto"><label class="form-label">' . h($label) . '</label><select class="form-select form-select-sm" name="' . h($field) . '" style="max-width:240px">'
                    . options($opts(), $f[$field] ?? '', 'All') . '</select></div>';
            }
        }
        foreach ($def['extra_filters'] ?? [] as $k => $spec) {
            [$label, $opts] = $spec;
            $blank = array_key_exists(2, $spec) ? $spec[2] : 'All';   // third element: blank choice label, or null for none
            $choices = $opts();
            // a short list of its own (a number of days, a yes/no) stays a plain drop-down
            // fifth element: choosing a value submits the form at once (a POS shift fills in the dates and times)
            $submit = !empty($spec[4]) ? ' onchange="this.form.submit()"' : '';
            echo '<div class="col-auto"><label class="form-label">' . h($label) . '</label><select class="form-select form-select-sm" name="' . h($k) . '" style="max-width:' . (!empty($spec[4]) ? '300' : '240') . 'px"' . (count($choices) <= 8 ? ' data-no-combo' : '') . $submit . '>'
                . options($choices, $f[$k] ?? '', $blank) . '</select></div>';
        }
        foreach ($def['checks'] ?? [] as $k => $label) {
            echo '<div class="col-auto"><label class="form-check rpt-check mb-1"><input class="form-check-input" type="checkbox" name="' . h($k) . '" value="1" ' . checked(($f[$k] ?? '') === '1')
                . ' onchange="this.form.submit()"> <span class="form-check-label">' . h($label) . '</span></label></div>';
        }
        $sortBy = self::sortChoices($def, $cols);
        if ($sortBy) {
            $sv = (string)g('sort');
            echo '<div class="col-auto rpt-sort"><label class="form-label">Sort by</label><div class="input-group input-group-sm">'
                . '<select class="form-select form-select-sm" name="sort" data-no-combo onchange="this.form.submit()" aria-label="Sort by">' . options($sortBy, isset($sortBy[$sv]) ? $sv : '', 'Default order') . '</select>'
                . '<select class="form-select form-select-sm rpt-sort-dir" name="dir" data-no-combo onchange="if(this.form.sort.value)this.form.submit()" aria-label="Order">'
                . options(['asc' => 'Increasing', 'desc' => 'Decreasing'], g('dir') === 'desc' ? 'desc' : 'asc') . '</select></div></div>';
        } elseif (in_array((string)g('sort'), array_column($cols, 'key'), true)) {
            // a column heading clicked: keep it while the filters change
            echo '<input type="hidden" name="sort" value="' . h((string)g('sort')) . '"><input type="hidden" name="dir" value="' . (g('dir') === 'desc' ? 'desc' : 'asc') . '">';
        }
        if (in_array('module', $want, true)) {
            echo '<div class="col-auto"><label class="form-label">Module</label><select class="form-select form-select-sm" name="module">' . options(DB::pairs('SELECT code, name FROM modules ORDER BY sort_order') + ['security' => 'Security'], $f['module'], 'All') . '</select></div>';
        }
        if (in_array('txn_type', $want, true)) {
            echo '<div class="col-auto"><label class="form-label">Type</label><select class="form-select form-select-sm" name="txn_type">' . options(array_map(fn($x) => $x[0], Txn::TYPES), $f['txn_type'], 'All') . '</select></div>';
        }
        if (in_array('columns', $want, true)) {
            echo '<div class="col-auto"><label class="form-label">Columns</label><select class="form-select form-select-sm" name="columns">' . options(['total' => 'Total only', 'month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year'], $f['columns'] ?: 'total') . '</select></div>';
        }
        if (in_array('nonposting', $want, true)) {
            echo '<div class="col-auto"><label class="small"><input type="checkbox" name="include_nonposting" value="1" ' . checked($f['include_nonposting'] === '1') . '> Include estimates and sales orders</label></div>';
        }
        if (in_array('blind', $want, true)) {
            echo '<div class="col-auto"><label class="small"><input type="checkbox" name="blind" value="1" ' . checked($f['blind'] === '1') . '> Hide system quantities (blind count)</label></div>';
        }
        if (in_array('search', $want, true)) {
            echo '<div class="col-auto"><label class="form-label">Search</label><input class="form-control form-control-sm" name="q" value="' . h($f['q']) . '"></div>';
        }
        if (in_array('schedule_inputs', $want, true)) {
            foreach (['cost' => 'Cost', 'salvage' => 'Salvage', 'life' => 'Life (years)'] as $k => $l) {
                echo '<div class="col-auto"><label class="form-label">' . h($l) . '</label><input class="form-control form-control-sm" style="width:110px" name="' . $k . '" value="' . h($f[$k]) . '"></div>';
            }
        }
        if (!empty($def['column_picker'])) {
            // the report's own Columns box, grouped as the report groups them: open to everybody who may open the report
            $groups = [];
            $on = [];
            foreach ($cols as $c) {
                if (ReportFields::isRelated($c)) {
                    continue;
                }
                $groups[(string)($c['group'] ?? 'Columns')][$c['key']] = (string)($c['_orig_label'] ?? $c['label']);
                if (!in_array($c['key'], $hidden, true)) {
                    $on[] = $c['key'];
                }
            }
            echo multi_check_field('show', 'Columns', $groups, $on, ['all' => 'Standard columns', 'noun' => 'columns', 'submit' => true, 'class' => 'col-auto rpt-col-picker', 'id' => 'rptPick']);
        }
        echo '<div class="col-auto"><button class="btn btn-sm btn-primary">Refresh</button></div>';
        // choosing the columns is part of designing the report
        if ($canDesign && count($cols) > 2 && empty($def['column_picker'])) {
            echo '<div class="col-auto"><div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">Columns</button><div class="dropdown-menu p-2 small">'
                . '<input type="hidden" name="hide[]" value="">';
            foreach ($cols as $c) {
                echo '<label class="d-block text-nowrap"><input type="checkbox" name="hide[]" value="' . h($c['key']) . '" ' . checked(in_array($c['key'], $hidden, true)) . '> Hide ' . h($c['label']) . '</label>';
            }
            echo '</div></div></div>';
        }
        // keep the layout that is on screen while the filters change
        foreach (['layout', 'order'] as $k) {
            $v = $k === 'order' && self::$pickerOrder !== null ? self::$pickerOrder : ($_GET[$k] ?? null);
            if (is_string($v) && $v !== '') {
                echo '<input type="hidden" name="' . $k . '" value="' . h($v) . '">';
            }
        }
        foreach (['opt', 'head', 'cw', 'ca'] as $k) {
            if (isset($_GET[$k]) && is_array($_GET[$k])) {
                echo hidden_inputs([$k => $_GET[$k]]);
            }
        }
        if (!$canDesign && isset($_GET['hide']) && is_array($_GET['hide'])) {
            echo hidden_inputs(['hide' => $_GET['hide']]);
        }
        // a report run for other choices of its own (the Find Results) keeps them too
        if (isset($_GET['f']) && is_array($_GET['f'])) {
            echo hidden_inputs(['f' => $_GET['f']]);
        }
        echo '</div></form>';
    }

    private static function chart(array $chart): void
    {
        echo '<div class="row g-3 mb-3">';
        foreach (['bars' => 'By aging period', 'pie' => 'Share by customer'] as $kind => $label) {
            if (empty($chart[$kind])) {
                continue;
            }
            $vals = $chart[$kind];
            $max = max(array_map('abs', $vals)) ?: 1;
            $sum = array_sum(array_map('abs', $vals)) ?: 1;
            echo '<div class="col-md-6"><div class="small fw-semibold mb-1">' . h($chart[$kind . '_title'] ?? $label) . '</div>';
            if ($kind === 'bars') {
                foreach ($vals as $k => $v) {
                    $link = $chart['bar_links'][$k] ?? null;
                    echo '<div class="d-flex align-items-center gap-2 mb-1"><div style="width:90px" class="small">' . h($k) . '</div>'
                        . '<div class="flex-grow-1"><div class="bar" style="width:' . max(1, round(abs($v) / $max * 100)) . '%"></div></div>'
                        . '<div style="width:110px" class="num small">' . ($link ? '<a href="' . h($link) . '">' . money($v) . '</a>' : money($v)) . '</div></div>';
                }
            } else {
                $palette = ['#0d6efd', '#20c997', '#ffc107', '#fd7e14', '#6f42c1', '#adb5bd'];
                $stops = [];
                $acc = 0.0;
                $i = 0;
                $legend = '';
                foreach ($vals as $k => $v) {
                    $pct = abs($v) / $sum * 100;
                    $color = $palette[$i % count($palette)];
                    $stops[] = "$color {$acc}% " . ($acc + $pct) . '%';
                    $acc += $pct;
                    $legend .= '<div class="small"><span style="display:inline-block;width:10px;height:10px;background:' . $color . '"></span> ' . h($k) . ' — ' . money($v) . ' (' . number_format($pct, 1) . '%)</div>';
                    $i++;
                }
                echo '<div class="d-flex gap-3 align-items-center"><div style="width:140px;height:140px;border-radius:50%;background:conic-gradient(' . implode(',', $stops) . ')"></div><div>' . $legend . '</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------ helpers for definitions

    public static function link(string $pageKey, array $params): ?string
    {
        return Access::page($pageKey) ? url($pageKey, $params) : null;
    }

    public static function txnLink(array $r): ?string
    {
        $type = $r['txn_type'] ?? null;
        $id = $r['txn_id'] ?? ($r['id'] ?? null);
        if (!$type || !$id) {
            return null;
        }
        return Access::page(Txn::page($type)) ? url(Txn::page($type), ['a' => 'view', 'id' => $id]) : null;
    }

    public static function group(string $label, int $span = 0): array
    {
        return ['_class' => 'group-row', '_html' => [], 'first' => $label];
    }
}
