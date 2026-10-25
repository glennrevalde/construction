<?php
declare(strict_types=1);

/** Audit reports (System Administration). */

function voided_deleted(array $f): array
{
    $sql = "SELECT a.* FROM audit_trail a WHERE a.record_type = 'transaction' AND a.action IN ('void','delete') AND a.created_at BETWEEN :f AND :t";
    $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
    if ($f['user_id']) {
        $sql .= ' AND a.user_id = :u';
        $p['u'] = $f['user_id'];
    }
    if ($f['module'] !== '') {
        $sql .= ' AND a.module = :m';
        $p['m'] = $f['module'];
    }
    return DB::all($sql . ' ORDER BY a.id', $p);
}

Report::define('voided_deleted_summary', [
    'filters' => ['range', 'user', 'module'],
    'run' => function (array $f): array {
        $rows = [];
        foreach (voided_deleted($f) as $a) {
            $snap = json_decode((string)$a['snapshot'], true) ?: [];
            $party = $snap['customer'] ?? $snap['vendor'] ?? $snap['payee_name'] ?? '';
            $rows[] = ['when' => $a['created_at'], 'action' => $a['action'] === 'void' ? 'Voided' : 'Deleted', 'type' => Txn::label($snap['txn_type'] ?? ''),
                'num' => $snap['txn_no'] ?? $a['record_ref'], 'date' => $snap['txn_date'] ?? '', 'name' => $party, 'amount' => (float)($snap['total'] ?? $a['amount']),
                'user' => $a['username'], 'reason' => $a['reason'], 'approved' => $a['approved_by'],
                '_links' => ['num' => Report::link('admin.txn_history', ['id' => $a['record_id']])]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'Voided / deleted', 'type' => 'datetime'], ['key' => 'action', 'label' => 'Action'], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'num', 'label' => 'Num'], ['key' => 'date', 'label' => 'Txn date', 'type' => 'date'], ['key' => 'name', 'label' => 'Name'], ['key' => 'amount', 'label' => 'Original amount', 'type' => 'money'],
            ['key' => 'user', 'label' => 'User'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'approved', 'label' => 'Approved by']],
            'rows' => $rows, 'totals' => ['when' => 'TOTAL (' . count($rows) . ')', 'amount' => r2(array_sum(array_column($rows, 'amount')))],
            'notes' => 'Voids and deletions are a common way to hide errors or theft — review them regularly.'];
    },
]);

Report::define('voided_deleted_detail', [
    'filters' => ['range', 'user', 'module'],
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = [];
        foreach (voided_deleted($f) as $a) {
            $snap = json_decode((string)$a['snapshot'], true) ?: [];
            $rows[] = ['col1' => ($a['action'] === 'void' ? 'VOIDED ' : 'DELETED ') . Txn::label($snap['txn_type'] ?? '') . ' ' . ($snap['txn_no'] ?? $a['record_ref'])
                . ' — ' . fdatetime($a['created_at']) . ' by ' . $a['username'] . ($a['approved_by'] ? ', approved by ' . $a['approved_by'] : '') . ' — reason: ' . $a['reason'], '_class' => 'group-row'];
            $rows[] = ['col1' => 'Date ' . fdate($snap['txn_date'] ?? '') . ', ' . ($snap['customer'] ?? $snap['vendor'] ?? $snap['payee_name'] ?? '') . ', memo: ' . ($snap['memo'] ?? ''),
                'col5' => (float)($snap['total'] ?? 0), '_class' => 'indent-1'];
            foreach ($snap['lines'] ?? [] as $l) {
                $rows[] = ['col1' => trim(($l['item'] ?? '') . ' ' . ($l['account'] ?? '')), 'col2' => $l['description'] ?? '', 'col3' => (float)($l['qty'] ?? 0) ?: '', 'col4' => (float)($l['rate'] ?? 0) ?: '',
                    'col5' => (float)($l['amount'] ?? 0), '_class' => 'indent-2'];
            }
            foreach ($snap['applied_to'] ?? [] as $x) {
                $rows[] = ['col1' => 'Was applied to', 'col2' => ($x['txn_no'] ?? ''), 'col5' => (float)($x['amount'] ?? 0), '_class' => 'indent-2'];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'col1', 'label' => 'Transaction / item'], ['key' => 'col2', 'label' => 'Description'], ['key' => 'col3', 'label' => 'Qty', 'type' => 'qty'],
            ['key' => 'col4', 'label' => 'Rate', 'type' => 'money'], ['key' => 'col5', 'label' => 'Amount', 'type' => 'money']], 'rows' => $rows,
            'notes' => 'Full content of each transaction as it was before it was voided or deleted (from the audit trail).'];
    },
]);

Report::define('user_activity', [
    'filters' => ['range', 'user', 'module'],
    'default_dates' => 'this_week',
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = 'SELECT a.user_id, a.username, a.module, a.action, COUNT(*) AS cnt, MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at,
                       SUM(CASE WHEN HOUR(a.created_at) < 7 OR HOUR(a.created_at) >= 19 OR DAYOFWEEK(a.created_at) = 1 THEN 1 ELSE 0 END) AS off_hours
                  FROM audit_trail a WHERE a.created_at BETWEEN :f AND :t AND a.username IS NOT NULL';
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if ($f['user_id']) {
            $sql .= ' AND a.user_id = :u';
            $p['u'] = $f['user_id'];
        }
        if ($f['module'] !== '') {
            $sql .= ' AND a.module = :m';
            $p['m'] = $f['module'];
        }
        $mods = DB::pairs('SELECT code, name FROM modules') + ['security' => 'Security'];
        $rows = [];
        $cur = null;
        $sub = 0;
        foreach (DB::all($sql . ' GROUP BY a.user_id, a.username, a.module, a.action ORDER BY a.username, a.module, a.action', $p) as $r) {
            if ($r['username'] !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['module' => 'Total ' . $cur, 'count' => $sub, '_class' => 'subtotal-row'];
                }
                $logins = (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE username = :u AND action = 'login' AND created_at BETWEEN :f AND :t", ['u' => $r['username']] + $p);
                $rows[] = ['module' => $r['username'] . ' — ' . $logins . ' login(s)', '_class' => 'group-row'];
                $cur = $r['username'];
                $sub = 0;
            }
            $rows[] = ['module' => $mods[$r['module']] ?? $r['module'], 'action' => ucwords(str_replace('_', ' ', $r['action'])), 'count' => (int)$r['cnt'], 'first' => $r['first_at'], 'last' => $r['last_at'],
                'off' => (int)$r['off_hours'] ?: '', '_class' => (int)$r['off_hours'] ? 'table-warning indent-1' : 'indent-1',
                '_links' => ['count' => Report::link('admin.audit_trail', ['user_id' => $r['user_id'], 'action' => $r['action'], 'module' => $r['module'], 'from' => $f['from'], 'to' => $f['to']])]];
            $sub += (int)$r['cnt'];
        }
        if ($cur !== null) {
            $rows[] = ['module' => 'Total ' . $cur, 'count' => $sub, '_class' => 'subtotal-row'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'module', 'label' => 'User / module'], ['key' => 'action', 'label' => 'Action'], ['key' => 'count', 'label' => 'Count', 'type' => 'int'],
            ['key' => 'first', 'label' => 'First', 'type' => 'datetime'], ['key' => 'last', 'label' => 'Last', 'type' => 'datetime'], ['key' => 'off', 'label' => 'Outside business hours', 'type' => 'int']],
            'rows' => $rows, 'notes' => 'Business hours are taken as 7:00 AM – 7:00 PM, Monday to Saturday. Rows with activity outside these hours are highlighted.'];
    },
]);

Report::define('login_history', [
    'filters' => ['range', 'user'],
    'default_dates' => 'this_week',
    'run' => function (array $f): array {
        $sql = "SELECT a.* FROM audit_trail a WHERE a.module = 'security' AND a.created_at BETWEEN :f AND :t";
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if ($f['user_id']) {
            $sql .= ' AND (a.user_id = :u OR a.record_id = :u)';
            $p['u'] = $f['user_id'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY a.id DESC LIMIT 5000', $p) as $a) {
            $bad = in_array($a['action'], ['login_failed', 'account_locked', 'access_denied', 'approval_failed'], true);
            $rows[] = ['when' => $a['created_at'], 'user' => $a['username'] ?: $a['record_ref'], 'event' => ucwords(str_replace('_', ' ', $a['action'])),
                'detail' => $a['action'] === 'access_denied' ? $a['record_ref'] : $a['reason'], 'ip' => $a['ip_address'], 'workstation' => mb_strimwidth((string)$a['workstation'], 0, 60, '…'),
                '_class' => $bad ? 'table-warning' : ''];
        }
        $failed = count(array_filter($rows, fn($r) => $r['event'] === 'Login Failed'));
        $denied = count(array_filter($rows, fn($r) => $r['event'] === 'Access Denied'));
        return ['columns' => [['key' => 'when', 'label' => 'Date / time', 'type' => 'datetime'], ['key' => 'user', 'label' => 'User'], ['key' => 'event', 'label' => 'Event'],
            ['key' => 'detail', 'label' => 'Detail'], ['key' => 'ip', 'label' => 'IP address'], ['key' => 'workstation', 'label' => 'Workstation']],
            'rows' => $rows, 'notes' => "$failed failed login(s) and $denied denied page access attempt(s) in this period."];
    },
]);

Report::define('user_access', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = [['name' => 'Current access by user', '_class' => 'group-row']];
        $total = count(Access::allPageDefs());
        foreach (DB::all('SELECT * FROM users ORDER BY full_name') as $u) {
            $eff = Access::effective((int)$u['id']);
            $allowed = count(array_filter($eff, fn($e) => $e['allowed']));
            $ov = (int)DB::value('SELECT COUNT(*) FROM user_page_overrides WHERE user_id = :u', ['u' => $u['id']]);
            $rows[] = ['name' => $u['full_name'] . ' (' . $u['username'] . ')', 'status' => $u['status'], 'roles' => implode(', ', Access::roleNames((int)$u['id'])),
                'pages' => $allowed . ' / ' . $total, 'overrides' => $ov ?: '', 'last' => fdatetime($u['last_login_at']),
                '_class' => ($u['status'] === 'active' && $u['last_login_at'] && strtotime($u['last_login_at']) < strtotime('-90 days')) ? 'table-warning' : '',
                '_links' => ['pages' => Report::link('admin.effective_access', ['user_id' => $u['id']])]];
        }
        $rows[] = ['name' => 'Changes to users, roles and page access (' . fdate($f['from']) . ' – ' . fdate($f['to']) . ')', '_class' => 'group-row'];
        $actions = ['user_create', 'user_edit', 'user_deactivated', 'user_activated', 'role_create', 'role_edit', 'role_delete', 'page_access_change', 'page_access_override',
            'sod_override', 'password_reset', 'account_unlocked', 'account_locked', 'settings_change', 'closing_date_change'];
        [$in, $p] = DB::in('ac', $actions);
        foreach (DB::all("SELECT * FROM audit_trail WHERE action IN $in AND created_at BETWEEN :f AND :t ORDER BY id", $p + ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59']) as $a) {
            $changes = json_decode((string)$a['changes'], true) ?: [];
            $summary = implode('; ', array_map(fn($c) => $c['field'] . ': ' . (is_scalar($c['old']) ? $c['old'] : json_encode($c['old'])) . ' → ' . (is_scalar($c['new']) ? $c['new'] : json_encode($c['new'])), array_slice($changes, 0, 6)));
            if (count($changes) > 6) {
                $summary .= ' (+' . (count($changes) - 6) . ' more)';
            }
            $rows[] = ['name' => fdatetime($a['created_at']) . ', ' . $a['username'], 'status' => ucwords(str_replace('_', ' ', $a['action'])), 'roles' => $a['record_ref'],
                'pages' => mb_strimwidth($summary, 0, 300, '…'), 'overrides' => '', 'last' => $a['reason'], '_class' => 'indent-1'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'name', 'label' => 'User / when'], ['key' => 'status', 'label' => 'Status / change'], ['key' => 'roles', 'label' => 'Roles / record'],
            ['key' => 'pages', 'label' => 'Pages allowed / details'], ['key' => 'overrides', 'label' => 'Overrides', 'type' => 'int'], ['key' => 'last', 'label' => 'Last login / reason']],
            'rows' => $rows, 'notes' => 'Use this report for periodic access reviews. Active users who have not logged in for 90 days are highlighted — confirm that departing employees were deactivated.'];
    },
]);
