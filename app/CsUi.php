<?php
declare(strict_types=1);

/**
 * Small building blocks of the construction pages: status words, progress meters, figures, fact lists,
 * the project and cost code pickers, empty states and tabs. Plain HTML; the look is in assets/css/theme.css.
 */
final class CsUi
{
    /** status => tone (ok, warn, bad, info, muted) */
    private const TONES = [
        'active' => 'ok', 'approved' => 'ok', 'posted' => 'ok', 'completed' => 'ok', 'accepted' => 'ok', 'awarded' => 'ok', 'passed' => 'ok',
        'certified' => 'ok', 'invoiced' => 'ok', 'paid' => 'ok', 'closed' => 'muted', 'client_approved' => 'ok', 'internally_approved' => 'info',
        'won' => 'ok', 'granted' => 'ok', 'returned_ok' => 'ok', 'released' => 'ok', 'deducted' => 'muted', 'verified' => 'ok', 'rectified' => 'info',
        'available' => 'ok', 'deployed' => 'info', 'dispatched' => 'info', 'scheduled' => 'info', 'returned' => 'warn', 'answered' => 'info',
        'draft' => 'muted', 'pending' => 'warn', 'for_approval' => 'warn', 'submitted' => 'info', 'under_review' => 'warn', 'in_progress' => 'info',
        'planning' => 'info', 'mobilization' => 'info', 'on_hold' => 'warn', 'substantially_completed' => 'info', 'under_warranty' => 'info',
        'open' => 'warn', 'monitoring' => 'info', 'lead' => 'muted', 'qualifying' => 'info', 'bidding' => 'info', 'proposed' => 'warn',
        'partially_ordered' => 'info', 'ordered' => 'info', 'issued' => 'ok', 'partially_granted' => 'warn', 'conditional' => 'warn',
        'rejected' => 'bad', 'cancelled' => 'muted', 'void' => 'muted', 'reversed' => 'muted', 'lost' => 'muted', 'no_bid' => 'muted',
        'failed' => 'bad', 'disputed' => 'bad', 'terminated' => 'bad', 'suspended' => 'warn', 'withdrawn' => 'muted', 'under_repair' => 'bad',
        'idle' => 'warn', 'off_hired' => 'muted', 'disposed' => 'muted', 'not_started' => 'muted', 'superseded' => 'muted', 'expired' => 'bad',
        'claimed' => 'bad', 'revise_resubmit' => 'warn', 'approved_as_noted' => 'ok', 'waived' => 'muted', 'reported' => 'warn',
        'under_rectification' => 'info', 'inactive' => 'muted', 'overdue' => 'bad', 'late' => 'bad', 'on_track' => 'ok',
    ];

    /** Labels for status values whose words are not just the value with spaces */
    private const LABELS = [
        'for_approval' => 'For approval', 'client_approved' => 'Client-approved', 'internally_approved' => 'Internally approved',
        'substantially_completed' => 'Substantially completed', 'under_warranty' => 'Under warranty', 'no_bid' => 'No bid',
        'approved_as_noted' => 'Approved as noted', 'revise_resubmit' => 'Revise and resubmit', 'off_hired' => 'Off-hired',
        'tm' => 'Time and materials', 'lump_sum' => 'Lump sum', 'unit_price' => 'Unit price', 'cost_plus' => 'Cost-plus',
        'poc_cost' => 'Percentage of completion (cost-to-cost)', 'poc_physical' => 'Percentage of completion (physical progress)',
        'billing' => 'As certified billings', 'completed' => 'Completed', 'partially_granted' => 'Partially granted',
    ];

    public static function label(?string $value): string
    {
        $v = (string)$value;
        if ($v === '') {
            return '';
        }
        return self::LABELS[$v] ?? ucfirst(str_replace('_', ' ', $v));
    }

    /** A status as plain coloured text (never a capsule) */
    public static function state(?string $status, ?string $label = null, ?string $tone = null): string
    {
        $s = (string)$status;
        $tone ??= self::TONES[$s] ?? 'muted';
        return '<span class="cs-state is-' . h($tone) . '">' . h($label ?? self::label($s)) . '</span>';
    }

    /** A progress bar with its figure; $mark = where it should be by now (planned), drawn as a tick */
    public static function meter(float $pct, ?float $mark = null, ?string $tone = null, ?string $text = null): string
    {
        $p = max(0.0, min(100.0, $pct));
        if ($tone === null) {
            $tone = $mark !== null && $pct + 5 < $mark ? 'bad' : ($mark !== null && $pct + 0.01 < $mark ? 'warn' : '');
        }
        $markHtml = $mark !== null ? '<span class="cs-meter-mark" style="left:' . h((string)max(0, min(100, $mark))) . '%" title="Planned: ' . h(number_format($mark, 1)) . '%"></span>' : '';
        return '<div class="cs-meter" role="img" aria-label="' . h(number_format($pct, 1)) . ' percent' . ($mark !== null ? ', planned ' . h(number_format($mark, 1)) . ' percent' : '') . '">'
            . '<div class="cs-meter-track"><span class="cs-meter-fill' . ($tone ? ' is-' . h($tone) : '') . '" style="width:' . h((string)$p) . '%"></span>' . $markHtml . '</div>'
            . '<span class="cs-meter-text">' . h($text ?? number_format($pct, 1) . '%') . '</span></div>';
    }

    /** One figure: a label, the value, an optional note; $tone ok / warn / bad / key; $href makes it a link */
    public static function kpi(string $label, string $value, string $note = '', string $tone = '', ?string $href = null): string
    {
        $tag = $href ? 'a' : 'div';
        return '<' . $tag . ' class="cs-kpi' . ($tone ? ' is-' . h($tone) : '') . '"' . ($href ? ' href="' . h($href) . '"' : '') . '>'
            . '<div class="cs-kpi-label">' . h($label) . '</div><div class="cs-kpi-value" title="' . h(strip_tags($value)) . '">' . $value . '</div>'
            . ($note !== '' ? '<div class="cs-kpi-note">' . $note . '</div>' : '') . '</' . $tag . '>';
    }

    /** Peso amount for figures: 1,234,567.89 or, when large, 1.23M */
    public static function amt($v, bool $short = false): string
    {
        $v = (float)$v;
        if ($short && abs($v) >= 1000000) {
            return ($v < 0 ? '(' : '') . number_format(abs($v) / 1000000, 2) . 'M' . ($v < 0 ? ')' : '');
        }
        return money($v);
    }

    /** label => value (HTML, already escaped by the caller) */
    public static function facts(array $pairs): string
    {
        $out = '<dl class="cs-facts">';
        foreach ($pairs as $label => $value) {
            if ($value === null) {
                continue;
            }
            $out .= '<div><dt>' . h((string)$label) . '</dt><dd>' . ($value === '' ? '<span class="text-muted">None</span>' : $value) . '</dd></div>';
        }
        return $out . '</dl>';
    }

    public static function empty(string $text, string $action = '', string $icon = 'inbox'): string
    {
        return '<div class="cs-empty"><i class="bi bi-' . h($icon) . '"></i><p>' . h($text) . '</p>' . $action . '</div>';
    }

    /** Tabs that are links: [key => label], the current key, and the address of each tab */
    public static function tabs(array $tabs, string $current, callable $href): string
    {
        $out = '<ul class="nav nav-tabs mb-3" role="tablist">';
        foreach ($tabs as $k => $label) {
            $out .= '<li class="nav-item"><a class="nav-link' . ($k === $current ? ' active" aria-current="page' : '') . '" href="' . h($href($k)) . '">' . $label . '</a></li>';
        }
        return $out . '</ul>';
    }

    /** <select> of the projects this user may work on */
    public static function projectSelect(string $name, $value, array $attr = []): string
    {
        $opts = CsProject::pickList((bool)($attr['closed'] ?? false));
        $blank = array_key_exists('blank', $attr) ? $attr['blank'] : 'Choose a project';
        return '<select class="form-select ' . h($attr['class'] ?? 'form-select-sm') . '" name="' . h($name) . '" data-combo'
            . (!empty($attr['required']) ? ' required' : '') . (!empty($attr['submit']) ? ' onchange="this.form.submit()"' : '') . ' ' . ($attr['extra'] ?? '') . '>'
            . options($opts, $value, $blank) . '</select>';
    }

    /** <select> of cost codes (work items) */
    public static function costCodeSelect(string $name, $value, string $blank = 'No cost code', string $extra = ''): string
    {
        return '<select class="form-select form-select-sm" name="' . h($name) . '" data-combo ' . $extra . '>' . options(CsCost::codeOptions(), $value, $blank) . '</select>';
    }

    /** A labelled field wrapper for the construction forms */
    public static function field(string $label, string $control, string $cls = 'col-md-4', string $help = ''): string
    {
        return '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label>' . $control
            . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
    }

    public static function input(string $name, $value, array $attr = []): string
    {
        $type = $attr['type'] ?? 'text';
        $step = $type === 'number' ? ' step="' . h($attr['step'] ?? '0.01') . '"' : '';
        return '<input class="form-control form-control-sm' . (!empty($attr['num']) ? ' text-end' : '') . '" type="' . h($type) . '" name="' . h($name) . '" value="' . h((string)$value) . '"'
            . $step . (!empty($attr['required']) ? ' required' : '') . (isset($attr['placeholder']) ? ' placeholder="' . h($attr['placeholder']) . '"' : '')
            . (isset($attr['max']) ? ' maxlength="' . (int)$attr['max'] . '"' : '') . ' ' . ($attr['extra'] ?? '') . '>';
    }

    public static function textarea(string $name, $value, int $rows = 3, string $extra = ''): string
    {
        return '<textarea class="form-control form-control-sm" name="' . h($name) . '" rows="' . $rows . '" ' . $extra . '>' . h((string)$value) . '</textarea>';
    }

    public static function select(string $name, array $opts, $value, ?string $blank = null, string $extra = ''): string
    {
        return '<select class="form-select form-select-sm" name="' . h($name) . '" ' . $extra . '>' . options($opts, $value, $blank) . '</select>';
    }

    /** "PRJ-0003 Mall Expansion" linked to its workspace when the user may open it */
    public static function projectLink(?int $id, ?string $text = null): string
    {
        if (!$id) {
            return '';
        }
        $p = CsProject::get($id);
        $text ??= $p ? $p['code'] . ' ' . $p['name'] : '#' . $id;
        if (!Access::page('proj.view') || !CsProject::canSee($id)) {
            return h($text);
        }
        return '<a href="' . h(url('proj.view', ['id' => $id])) . '">' . h($text) . '</a>';
    }

    /** A link to an accounting transaction (invoice, bill, journal ...) */
    public static function txnLink(?int $txnId): string
    {
        if (!$txnId) {
            return '';
        }
        $t = Txn::load($txnId);
        if (!$t) {
            return '<span class="text-muted">Deleted entry</span>';
        }
        return Txn::link($t, Txn::label($t['txn_type']) . ' ' . ($t['txn_no'] ?: '#' . $t['id'])) . ((int)$t['is_voided'] === 1 ? ' <span class="cs-state is-muted">Void</span>' : '');
    }

    public static function pct(float $v, int $dec = 1): string
    {
        return number_format($v, $dec) . '%';
    }

    /** The plain sub-line under a page title: facts separated by commas */
    public static function sub(array $parts): string
    {
        return implode(', ', array_filter(array_map('strval', $parts), fn($p) => $p !== ''));
    }

    /** A confirm modal for consequential actions (approve, reject, post, reverse); returns the button and the dialog */
    public static function confirmButton(string $id, string $buttonLabel, string $title, string $message, string $formHtml, string $btnClass = 'btn-primary', string $icon = 'check2', bool $needsReason = false): string
    {
        return '<button type="button" class="btn btn-sm ' . h($btnClass) . '" data-bs-toggle="modal" data-bs-target="#' . h($id) . '"><i class="bi bi-' . h($icon) . '"></i> ' . h($buttonLabel) . '</button>'
            . '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post">'
            . csrf_field() . $formHtml
            . '<div class="modal-header"><h5 class="modal-title" id="' . h($id) . 'T">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body"><p class="mb-2">' . $message . '</p>'
            . ($needsReason ? '<label class="form-label">Reason (required, kept in the audit trail)</label><textarea class="form-control" name="reason" rows="2" required></textarea>'
                : '<label class="form-label">Comment (optional)</label><textarea class="form-control" name="reason" rows="2"></textarea>')
            . '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
            . '<button class="btn ' . h($btnClass) . '">' . h($buttonLabel) . '</button></div></form></div></div>';
    }
}
