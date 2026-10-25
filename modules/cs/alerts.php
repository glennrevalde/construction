<?php
declare(strict_types=1);

/** Alerts & Notifications: messages sent to the user, and the alerts worked out from the records. */
$me = (int)Auth::id();
$notes = CsNotify::forUser($me, 60);
$alerts = CsAlerts::all();
$kind = (string)g('kind');
$kinds = [];
foreach ($alerts as $a) {
    $kinds[$a['kind']] = ($kinds[$a['kind']] ?? 0) + 1;
}
if ($kind !== '') {
    $alerts = array_values(array_filter($alerts, fn($a) => $a['kind'] === $kind));
}
$labels = ['approval' => 'Approvals', 'loss' => 'Projected losses', 'budget' => 'Budget overruns', 'spending' => 'Spending past budget', 'schedule' => 'Behind schedule', 'billing' => 'Billing',
    'collection' => 'Collections', 'payable' => 'Supplier invoices', 'stock' => 'Low stock', 'labor' => 'Labor hours', 'equipment' => 'Equipment', 'milestone' => 'Late milestones',
    'deliverable' => 'Missing deliverables', 'bond' => 'Bonds', 'contract' => 'Contracts', 'change_order' => 'Change orders', 'quality' => 'Quality', 'safety' => 'Safety', 'warranty' => 'Warranty'];
layout_start('Alerts & Notifications', ['module' => 'home']);
echo '<div class="cs-split"><div class="cs-stack">';
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="home.alerts"><div><label class="form-label">Show</label><select class="form-select form-select-sm" name="kind" data-no-combo onchange="this.form.submit()">'
    . '<option value="">Every alert (' . array_sum($kinds) . ')</option>';
foreach ($kinds as $k => $n) {
    echo '<option value="' . h($k) . '"' . ($k === $kind ? ' selected' : '') . '>' . h(($labels[$k] ?? ucfirst($k)) . ' (' . $n . ')') . '</option>';
}
echo '</select></div></form>';
echo '<div class="card"><div class="card-header">Alerts <span class="cs-note">' . count($alerts) . '</span></div>' . ($alerts ? CsAlerts::render($alerts) : CsUi::empty('Nothing needs attention right now.', '', 'check2-circle')) . '</div>';
echo '</div><div class="card"><div class="card-header">Notifications' . ($notes ? '<form method="post" action="' . h(url('cs.notify')) . '" class="ms-auto">' . csrf_field() . '<button class="btn btn-link btn-sm p-0">Mark all read</button></form>' : '') . '</div>';
if (!$notes) {
    echo CsUi::empty('No notifications.', '', 'bell');
} else {
    echo '<ul class="cs-alerts">';
    foreach ($notes as $n) {
        echo '<li class="' . ((int)$n['is_read'] ? '' : 'is-warn') . '"><i class="bi bi-' . ($n['kind'] === 'approval' ? 'check2-square' : ($n['kind'] === 'reject' ? 'x-circle' : 'bell')) . '"></i><div>'
            . '<div class="t">' . ($n['link'] ? '<form method="post" action="' . h(url('cs.notify')) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$n['id'] . '"><input type="hidden" name="go" value="1"><button class="btn btn-link p-0 text-start fw-semibold">' . h($n['title']) . '</button></form>' : h($n['title'])) . '</div>'
            . '<div class="d">' . h((string)$n['body']) . '</div><div class="d text-muted">' . h(fdatetime($n['created_at'])) . '</div></div><div></div></li>';
    }
    echo '</ul>';
}
echo '</div></div>';
layout_end();
