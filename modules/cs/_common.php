<?php
declare(strict_types=1);

/** Helpers shared by the construction pages (modules/projects, planning, site, ...). */

/** The rows of a posted line grid (lines[n][field]), without the empty template rows */
function cs_lines(string $name = 'lines'): array
{
    $out = [];
    foreach ((array)($_POST[$name] ?? []) as $row) {
        if (is_array($row)) {
            $out[] = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
        }
    }
    return $out;
}

function cs_go(string $page, array $params = []): never
{
    redirect(url($page, $params));
}

/** The filter bar of a list: project picker, then the page's own fields, then Show */
function cs_filter_bar(string $pageKey, ?int $projectId, string $extra = '', bool $allProjects = true): string
{
    return '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">'
        . '<div class="cs-grow" style="max-width:360px"><label class="form-label">Project</label>'
        . CsUi::projectSelect('project', $projectId ?: '', ['blank' => $allProjects ? 'All my projects' : 'Choose a project', 'submit' => true, 'closed' => true]) . '</div>'
        . $extra . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
}

/** A search box for a filter bar */
function cs_search_field(string $placeholder = 'Number, title, name'): string
{
    return '<div><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="' . h((string)g('q')) . '" placeholder="' . h($placeholder) . '"></div>';
}

/** A status drop-down for a filter bar */
function cs_status_field(array $statuses, string $name = 'status', string $label = 'Status'): string
{
    return '<div><label class="form-label">' . h($label) . '</label><select class="form-select form-select-sm" name="' . h($name) . '" data-no-combo onchange="this.form.submit()">'
        . options($statuses, (string)g($name), 'Any') . '</select></div>';
}

/** WHERE fragment and params for a free-text search over some columns */
function cs_like(string $q, array $cols, string $tag = 'q'): array
{
    $q = trim($q);
    if ($q === '') {
        return ['', []];
    }
    $parts = [];
    $p = [];
    foreach ($cols as $i => $c) {
        $parts[] = "$c LIKE :{$tag}{$i}";
        $p[$tag . $i] = '%' . $q . '%';
    }
    return [' AND (' . implode(' OR ', $parts) . ')', $p];
}

/** The page keys a project's workspace links to, when the user may open them */
function cs_link(string $pageKey, array $params, string $label, string $cls = 'btn-outline-secondary', string $icon = '', string $action = 'view'): string
{
    return page_link($pageKey, $params, $label, $cls, $icon, $action);
}

/** Options of the users, for the people fields of a project */
function cs_user_options(): array
{
    return DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 AND approval_status = 'approved' ORDER BY full_name");
}

/** A card with a title and a table: $head = [label => 'num'|''], $rows = list of cell arrays (HTML) */
function cs_table_card(string $title, array $head, array $rows, string $empty = 'Nothing yet.', string $headerExtra = '', string $foot = ''): string
{
    $out = '<div class="card"><div class="card-header">' . h($title) . $headerExtra . '</div>';
    if (!$rows) {
        return $out . CsUi::empty($empty) . '</div>';
    }
    $out .= '<div class="table-responsive"><table class="table table-sm table-hover cs-table"><thead><tr>';
    foreach ($head as $label => $kind) {
        $out .= '<th' . ($kind === 'num' ? ' class="num"' : '') . '>' . h((string)$label) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    $kinds = array_values($head);
    foreach ($rows as $r) {
        $cls = '';
        if (isset($r['_class'])) {
            $cls = $r['_class'];
            unset($r['_class']);
        }
        $out .= '<tr' . ($cls ? ' class="' . h($cls) . '"' : '') . '>';
        foreach (array_values($r) as $i => $cell) {
            $out .= '<td' . (($kinds[$i] ?? '') === 'num' ? ' class="num"' : '') . '>' . $cell . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody>' . $foot . '</table></div></div>';
}

/** The audit history of one record as a trail (who did what, when, why) */
function cs_history(string $recordType, int $recordId, string $title = 'History', int $limit = 40): string
{
    $rows = DB::all('SELECT created_at, username, action, reason, amount, record_ref FROM audit_trail WHERE record_type = :t AND record_id = :i ORDER BY id DESC LIMIT ' . (int)$limit,
        ['t' => $recordType, 'i' => $recordId]);
    if (!$rows) {
        return '';
    }
    $names = DB::pairs('SELECT username, full_name FROM users');
    $out = '<div class="card"><div class="card-header">' . h($title) . '</div><div class="card-body"><ul class="cs-trail">';
    foreach ($rows as $r) {
        $bad = in_array($r['action'], ['reject', 'reverse', 'void', 'delete', 'return'], true);
        $out .= '<li class="' . ($bad ? 'is-bad' : (in_array($r['action'], ['accept', 'approve', 'certify', 'post', 'complete'], true) ? 'is-ok' : '')) . '"><div class="who">'
            . h(CsUi::label(str_replace('approval_', '', $r['action']))) . ' by ' . h($names[$r['username']] ?? (string)$r['username']) . '</div>'
            . '<div class="when">' . h(fdatetime($r['created_at'])) . ($r['amount'] !== null ? ', ' . money($r['amount']) : '') . '</div>'
            . ($r['reason'] ? '<div class="what">' . h($r['reason']) . '</div>' : '') . '</li>';
    }
    return $out . '</ul></div></div>';
}

/** Handles the "attach" post of CsFiles::panel() */
function cs_handle_attach(string $entityType, int $entityId, ?int $projectId): bool
{
    if (is_post() && p('act') === 'attach') {
        $page = (string)($GLOBALS['CURRENT_PAGE'] ?? '');
        if ($page !== '' && !Access::can($page, 'edit') && !Access::can($page, 'create')) {
            Access::require($page, 'edit');
        }
        $ids = CsFiles::attach('files', $entityType, $entityId, $projectId, (string)p('caption'));
        Audit::log('attach', 'proj', $entityType, $entityId, count($ids) . ' file(s)', ['reason' => (string)p('caption')]);
        flash('success', count($ids) . ' file(s) attached.');
        return true;
    }
    return false;
}
