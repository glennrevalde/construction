<?php
declare(strict_types=1);

/**
 * Multilevel approval of construction documents.
 *
 *  - The route is chosen when a document is submitted: the active route of its type whose amount range holds the
 *    document's amount (and, when a route is limited to a project or a risk level, that one first).
 *    With no route set up, the document needs one approval by someone with Approve on its page.
 *  - Each step is taken by a user holding the step's role, the step's named user, the project's own manager or
 *    engineer (project role), or the user the step was delegated to. The Company Owner and administrators may act
 *    on any step; it is recorded as theirs.
 *  - Maker-checker: nobody approves a document they submitted (unless the company switched that rule off), and
 *    nobody approves two steps of the same document.
 *  - Reject and return need a reason; a returned document goes back to its maker to correct and submit again.
 *  - Every action is stored with the time, the user, the amount and the comment (cs_approval_actions) and in the
 *    audit trail. A document that is not approved never posts to the books.
 */
final class CsApproval
{
    public static function routeFor(string $type, float $amount, ?int $projectId = null, ?string $risk = null): ?array
    {
        $routes = DB::all("SELECT * FROM cs_approval_routes WHERE doc_type = :t AND is_active = 1 AND min_amount <= :a AND (max_amount IS NULL OR max_amount > :a2)
                           ORDER BY (project_id IS NULL), (risk_level IS NULL), sort_order, id", ['t' => $type, 'a' => $amount, 'a2' => $amount]);
        foreach ($routes as $r) {
            if ($r['project_id'] && (int)$r['project_id'] !== (int)$projectId) {
                continue;
            }
            if ($r['risk_level'] && $r['risk_level'] !== $risk) {
                continue;
            }
            return $r;
        }
        return null;
    }

    public static function steps(?int $routeId): array
    {
        if (!$routeId) {
            return [];
        }
        return DB::all('SELECT s.*, r.name AS role_name, u.full_name AS user_name FROM cs_approval_steps s
                          LEFT JOIN roles r ON r.id = s.role_id LEFT JOIN users u ON u.id = s.user_id
                         WHERE s.route_id = :r ORDER BY s.step_no', ['r' => $routeId]);
    }

    /** The latest request of a document */
    public static function requestFor(string $type, int $docId): ?array
    {
        return DB::row('SELECT * FROM cs_approval_requests WHERE doc_type = :t AND doc_id = :d ORDER BY id DESC LIMIT 1', ['t' => $type, 'd' => $docId]);
    }

    public static function pendingFor(string $type, int $docId): ?array
    {
        $r = self::requestFor($type, $docId);
        return $r && $r['status'] === 'pending' ? $r : null;
    }

    // ------------------------------------------------------------------ submit

    public static function submit(string $type, int $docId, string $ref, ?int $projectId, float $amount, string $comment = '', ?string $risk = null): int
    {
        return DB::tx(function () use ($type, $docId, $ref, $projectId, $amount, $comment, $risk) {
            if (self::pendingFor($type, $docId)) {
                fail('This document is already waiting for approval.');
            }
            $route = self::routeFor($type, abs($amount), $projectId, $risk);
            $steps = $route ? self::steps((int)$route['id']) : [];
            $total = max(1, count($steps));
            $due = (int)($steps[0]['due_hours'] ?? Settings::get('cs_approval_overdue_hours', '48'));
            $id = DB::insert('cs_approval_requests', [
                'doc_type' => $type, 'doc_id' => $docId, 'doc_ref' => mb_substr($ref, 0, 120), 'project_id' => $projectId, 'amount' => r2($amount),
                'route_id' => $route['id'] ?? null, 'current_step' => 1, 'total_steps' => $total, 'status' => 'pending', 'requested_by' => Auth::id(),
                'step_since' => date('Y-m-d H:i:s'), 'due_at' => date('Y-m-d H:i:s', time() + 3600 * max(1, $due)),
            ]);
            self::act($id, 0, 'submit', $comment, $amount, 'Submitted');
            DB::afterCommit(fn() => null);
            CsDocs::notify($type, $docId, 'submitted', ['id' => $id]);
            self::notifyStep($id);
            Audit::log('submit', 'appr', $type, $docId, CsDocs::label($type) . ' ' . $ref, ['page_key' => CsDocs::page($type), 'amount' => $amount,
                'reason' => 'Submitted for approval' . ($route ? ': ' . $route['name'] : ' (no route set up: one approval by a user with Approve on the page)')]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ who may act

    /** The current step of a request (array with label, role_id ...), or a stand-in for "anyone with Approve" */
    public static function currentStep(array $req): array
    {
        $steps = self::steps($req['route_id'] ? (int)$req['route_id'] : null);
        foreach ($steps as $s) {
            if ((int)$s['step_no'] === (int)$req['current_step']) {
                return $s;
            }
        }
        return ['step_no' => (int)$req['current_step'], 'label' => 'Approver (Approve permission on ' . CsDocs::label($req['doc_type']) . ')',
            'role_id' => null, 'user_id' => null, 'project_role' => null, 'implicit' => true];
    }

    /** Why the user may not act on the request now, or '' when they may */
    public static function whyNot(array $req, ?int $userId = null): string
    {
        $userId ??= (int)Auth::id();
        if ($req['status'] !== 'pending') {
            return 'This request is no longer waiting for approval.';
        }
        $authority = Access::isAdmin($userId);
        if ((int)$req['requested_by'] === $userId && Settings::get('cs_self_approval', '0') !== '1') {
            return 'You submitted this document, so someone else has to approve it (maker-checker).';
        }
        $already = DB::value("SELECT step_no FROM cs_approval_actions WHERE request_id = :r AND user_id = :u AND action = 'approve' LIMIT 1", ['r' => $req['id'], 'u' => $userId]);
        if ($already !== null) {
            return 'You already approved step ' . (int)$already . ' of this document; another person approves the next step.';
        }
        if ($authority) {
            return '';
        }
        if ((int)($req['delegated_to'] ?? 0) === $userId) {
            return '';
        }
        $s = self::currentStep($req);
        if (!empty($s['implicit'])) {
            return Access::can(CsDocs::page($req['doc_type']), 'approve', $userId) ? '' : 'This step needs the Approve permission on ' . CsDocs::label($req['doc_type']) . '.';
        }
        if ($s['user_id'] && (int)$s['user_id'] === $userId) {
            return '';
        }
        if ($s['role_id'] && in_array((int)$s['role_id'], array_map('intval', array_column(Access::roles($userId), 'id')), true)) {
            return '';
        }
        if ($s['project_role'] && $req['project_id']) {
            $p = CsProject::get((int)$req['project_id']);
            $col = ['pm' => 'pm_user_id', 'engineer' => 'engineer_user_id', 'qs' => 'qs_user_id'][$s['project_role']] ?? null;
            if ($p && $col && (int)$p[$col] === $userId) {
                return '';
            }
        }
        return 'This step (' . $s['label'] . ') is for ' . self::stepWho($s, $req) . '.';
    }

    public static function canAct(array $req, ?int $userId = null): bool
    {
        return self::whyNot($req, $userId) === '';
    }

    /** Who a step is for, in words */
    public static function stepWho(array $s, array $req = []): string
    {
        if (!empty($s['implicit'])) {
            return 'a user with Approve permission';
        }
        if (!empty($s['user_name'])) {
            return $s['user_name'];
        }
        if (!empty($s['role_name'])) {
            return 'the ' . $s['role_name'];
        }
        if (!empty($s['project_role'])) {
            $p = !empty($req['project_id']) ? CsProject::get((int)$req['project_id']) : null;
            $col = ['pm' => 'pm_user_id', 'engineer' => 'engineer_user_id', 'qs' => 'qs_user_id'][$s['project_role']] ?? null;
            $name = $p && $col && $p[$col] ? (string)DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $p[$col]]) : '';
            return 'the project\'s ' . ['pm' => 'manager', 'engineer' => 'engineer', 'qs' => 'quantity surveyor'][$s['project_role']] . ($name ? ' (' . $name . ')' : '');
        }
        return 'an approver';
    }

    /** Users who may act on the current step (for notifications) */
    public static function stepUsers(array $req): array
    {
        $s = self::currentStep($req);
        $ids = [];
        if (!empty($req['delegated_to'])) {
            $ids[] = (int)$req['delegated_to'];
        }
        if (!empty($s['user_id'])) {
            $ids[] = (int)$s['user_id'];
        }
        if (!empty($s['role_id'])) {
            $ids = array_merge($ids, CsNotify::usersWithRole((int)$s['role_id']));
        }
        if (!empty($s['project_role']) && $req['project_id']) {
            $p = CsProject::get((int)$req['project_id']);
            $col = ['pm' => 'pm_user_id', 'engineer' => 'engineer_user_id', 'qs' => 'qs_user_id'][$s['project_role']] ?? null;
            if ($p && $col && $p[$col]) {
                $ids[] = (int)$p[$col];
            }
        }
        if (!empty($s['implicit'])) {
            $pageId = (int)DB::value('SELECT id FROM pages WHERE page_key = :k', ['k' => CsDocs::page($req['doc_type'])]);
            $ids = array_merge($ids, array_map('intval', DB::column('SELECT DISTINCT ur.user_id FROM role_page_access a JOIN user_roles ur ON ur.role_id = a.role_id WHERE a.page_id = :p AND a.can_approve = 1', ['p' => $pageId])));
        }
        return array_values(array_diff(array_unique($ids), [(int)$req['requested_by']]));
    }

    private static function notifyStep(int $reqId): void
    {
        $req = DB::row('SELECT * FROM cs_approval_requests WHERE id = :id', ['id' => $reqId]);
        if (!$req || $req['status'] !== 'pending') {
            return;
        }
        $s = self::currentStep($req);
        CsNotify::send(self::stepUsers($req), 'approval', CsDocs::label($req['doc_type']) . ' ' . $req['doc_ref'] . ' waits for your approval',
            'Step ' . $req['current_step'] . ' of ' . $req['total_steps'] . ': ' . $s['label'] . '. Amount ' . money($req['amount']) . '.',
            CsDocs::url($req['doc_type'], (int)$req['doc_id']), $req['project_id'] ? (int)$req['project_id'] : null);
    }

    // ------------------------------------------------------------------ decisions

    public static function decide(int $reqId, string $action, string $comment = '', ?int $delegateTo = null): void
    {
        DB::tx(function () use ($reqId, $action, $comment, $delegateTo) {
            $req = DB::row('SELECT * FROM cs_approval_requests WHERE id = :id FOR UPDATE', ['id' => $reqId]);
            if (!$req) {
                fail('Approval request not found.');
            }
            $me = (int)Auth::id();
            if ($action === 'cancel') {
                if ((int)$req['requested_by'] !== $me && !Access::isAdmin()) {
                    fail('Only the person who submitted it can withdraw it.');
                }
                if ($req['status'] !== 'pending') {
                    fail('This request is no longer waiting for approval.');
                }
            } elseif ($why = self::whyNot($req, $me)) {
                fail($why);
            }
            $comment = trim($comment);
            if (in_array($action, ['reject', 'return'], true) && $comment === '') {
                fail('Enter the reason: it is sent to the person who submitted the document and kept with it.');
            }
            $step = self::currentStep($req);
            $type = $req['doc_type'];
            $docId = (int)$req['doc_id'];
            switch ($action) {
                case 'approve':
                    self::act($reqId, (int)$req['current_step'], 'approve', $comment, (float)$req['amount'], $step['label']);
                    if ((int)$req['current_step'] < (int)$req['total_steps']) {
                        $next = (int)$req['current_step'] + 1;
                        $nextStep = self::currentStep(['current_step' => $next] + $req);
                        DB::update('cs_approval_requests', ['current_step' => $next, 'step_since' => date('Y-m-d H:i:s'), 'delegated_to' => null, 'escalated' => 0,
                            'due_at' => date('Y-m-d H:i:s', time() + 3600 * max(1, (int)($nextStep['due_hours'] ?? 48)))], 'id = :id', ['id' => $reqId]);
                        self::notifyStep($reqId);
                    } else {
                        DB::update('cs_approval_requests', ['status' => 'approved', 'decided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reqId]);
                        CsDocs::notify($type, $docId, 'approved', $req);
                        CsNotify::send([(int)$req['requested_by']], 'approved', CsDocs::label($type) . ' ' . $req['doc_ref'] . ' was approved', '', CsDocs::url($type, $docId), $req['project_id'] ? (int)$req['project_id'] : null);
                    }
                    break;
                case 'reject':
                case 'return':
                    self::act($reqId, (int)$req['current_step'], $action, $comment, (float)$req['amount'], $step['label']);
                    DB::update('cs_approval_requests', ['status' => $action === 'reject' ? 'rejected' : 'returned', 'decided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reqId]);
                    CsDocs::notify($type, $docId, $action === 'reject' ? 'rejected' : 'returned', $req);
                    CsNotify::send([(int)$req['requested_by']], $action, CsDocs::label($type) . ' ' . $req['doc_ref'] . ($action === 'reject' ? ' was rejected' : ' was returned for correction'),
                        $comment, CsDocs::url($type, $docId), $req['project_id'] ? (int)$req['project_id'] : null);
                    break;
                case 'delegate':
                    if (!$delegateTo || $delegateTo === $me || $delegateTo === (int)$req['requested_by']) {
                        fail('Choose another user to delegate this step to (not yourself and not the person who submitted it).');
                    }
                    self::act($reqId, (int)$req['current_step'], 'delegate', $comment, null, $step['label'], $delegateTo);
                    DB::update('cs_approval_requests', ['delegated_to' => $delegateTo], 'id = :id', ['id' => $reqId]);
                    CsNotify::send([$delegateTo], 'approval', CsDocs::label($type) . ' ' . $req['doc_ref'] . ' was delegated to you', $comment, CsDocs::url($type, $docId));
                    break;
                case 'escalate':
                    self::act($reqId, (int)$req['current_step'], 'escalate', $comment, null, $step['label']);
                    DB::update('cs_approval_requests', ['escalated' => 1], 'id = :id', ['id' => $reqId]);
                    $admins = array_map('intval', DB::column("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                                                               WHERE r.name IN ('General Manager', 'Business Owner / President') AND u.status = 'active'"));
                    CsNotify::send($admins, 'escalation', 'Escalated: ' . CsDocs::label($type) . ' ' . $req['doc_ref'], $comment, CsDocs::url($type, $docId));
                    break;
                case 'cancel':
                    self::act($reqId, (int)$req['current_step'], 'cancel', $comment, null, 'Withdrawn by the submitter');
                    DB::update('cs_approval_requests', ['status' => 'cancelled', 'decided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reqId]);
                    CsDocs::notify($type, $docId, 'cancelled', $req);
                    break;
                default:
                    fail('Unknown action.');
            }
            Audit::log('approval_' . $action, 'appr', $type, $docId, CsDocs::label($type) . ' ' . $req['doc_ref'], [
                'page_key' => CsDocs::page($type), 'amount' => $req['amount'], 'reason' => trim('Step ' . $req['current_step'] . ' (' . $step['label'] . '): ' . $comment, ': '),
                'approved_by' => $action === 'approve' ? (Auth::user()['username'] ?? null) : null,
            ]);
        });
    }

    private static function act(int $reqId, int $step, string $action, string $comment, ?float $amount, string $label, ?int $delegated = null): void
    {
        DB::insert('cs_approval_actions', ['request_id' => $reqId, 'step_no' => $step, 'action' => $action, 'user_id' => Auth::id(),
            'user_name' => Auth::user()['full_name'] ?? null, 'step_label' => mb_substr($label, 0, 80), 'amount' => $amount,
            'comment' => $comment !== '' ? $comment : null, 'delegated_to' => $delegated]);
    }

    // ------------------------------------------------------------------ lists

    /** Pending requests the user may act on now */
    public static function inbox(int $userId): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM cs_approval_requests WHERE status = 'pending' ORDER BY due_at, id") as $r) {
            if (self::canAct($r, $userId)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function countFor(int $userId): int
    {
        static $memo = [];
        return $memo[$userId] ??= count(self::inbox($userId));
    }

    public static function actions(int $reqId): array
    {
        return DB::all('SELECT * FROM cs_approval_actions WHERE request_id = :r ORDER BY id', ['r' => $reqId]);
    }

    // ------------------------------------------------------------------ the panel shown on every document

    /**
     * The approval panel of a document: its route with the steps done and the one waiting, the history, and the
     * buttons the user may press. Pages call handlePost() first, then echo panel().
     */
    public static function panel(string $type, int $docId, bool $canSubmit = false, string $submitLabel = 'Submit for approval'): string
    {
        $req = self::requestFor($type, $docId);
        $out = '<div class="card"><div class="card-header">Approval</div><div class="card-body">';
        if (!$req) {
            $out .= '<p class="cs-note mb-2">Not submitted yet.</p>';
            if ($canSubmit) {
                $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="appr_act" value="submit"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> ' . h($submitLabel) . '</button></form>';
            }
            return $out . '</div></div>';
        }
        // the route
        $steps = self::steps($req['route_id'] ? (int)$req['route_id'] : null);
        if (!$steps) {
            $steps = [['step_no' => 1, 'label' => 'Approver with Approve permission']];
        }
        $out .= '<div class="cs-route mb-3">';
        foreach ($steps as $i => $s) {
            $cls = $req['status'] === 'approved' || (int)$s['step_no'] < (int)$req['current_step'] ? 'is-done' : ((int)$s['step_no'] === (int)$req['current_step'] && $req['status'] === 'pending' ? 'is-now' : '');
            $out .= ($i ? '<i class="bi bi-chevron-right"></i>' : '') . '<span class="' . $cls . '">' . h($s['label']) . '</span>';
        }
        $out .= '</div>';
        $out .= '<div class="mb-2">' . CsUi::state($req['status'] === 'pending' ? 'for_approval' : $req['status'],
            $req['status'] === 'pending' ? 'Waiting: step ' . $req['current_step'] . ' of ' . $req['total_steps'] : null) . '</div>';
        // the history
        $out .= '<ul class="cs-trail">';
        foreach (self::actions((int)$req['id']) as $a) {
            $cls = ['approve' => 'is-ok', 'reject' => 'is-bad', 'return' => 'is-bad', 'submit' => ''][$a['action']] ?? '';
            $verb = ['submit' => 'Submitted', 'approve' => 'Approved', 'reject' => 'Rejected', 'return' => 'Returned for correction', 'delegate' => 'Delegated',
                'escalate' => 'Escalated', 'cancel' => 'Withdrawn', 'resubmit' => 'Resubmitted'][$a['action']] ?? $a['action'];
            $out .= '<li class="' . $cls . '"><div class="who">' . h($verb) . ' by ' . h((string)$a['user_name']) . '</div>'
                . '<div class="when">' . h(fdatetime($a['created_at'])) . ($a['step_label'] && $a['action'] !== 'submit' ? ', ' . h($a['step_label']) : '') . '</div>'
                . ($a['comment'] ? '<div class="what">' . nl2br(h($a['comment'])) . '</div>' : '') . '</li>';
        }
        if ($req['status'] === 'pending') {
            $s = self::currentStep($req);
            $out .= '<li class="is-now"><div class="who">Waiting for ' . h(self::stepWho($s, $req)) . '</div><div class="when">Since ' . h(fdatetime($req['step_since']))
                . ($req['due_at'] && $req['due_at'] < date('Y-m-d H:i:s') ? ', <span class="cs-bad">overdue</span>' : '') . '</div></li>';
        }
        $out .= '</ul>';
        // the buttons
        if ($req['status'] === 'pending') {
            $why = self::whyNot($req);
            if ($why === '') {
                $f = '<input type="hidden" name="appr_req" value="' . (int)$req['id'] . '">';
                $out .= '<div class="d-flex flex-wrap gap-1 mt-2">'
                    . CsUi::confirmButton('apprOk' . $req['id'], 'Approve', 'Approve ' . CsDocs::label($type) . ' ' . $req['doc_ref'],
                        'Step ' . (int)$req['current_step'] . ' of ' . (int)$req['total_steps'] . '. ' . ((int)$req['current_step'] === (int)$req['total_steps'] ? 'This is the last step: the document takes effect (and posts to the books where it does).' : 'It goes on to the next approver.'),
                        $f . '<input type="hidden" name="appr_act" value="approve">', 'btn-success', 'check2')
                    . CsUi::confirmButton('apprRet' . $req['id'], 'Return', 'Return for correction', 'The document goes back to the person who submitted it, with your reason.', $f . '<input type="hidden" name="appr_act" value="return">', 'btn-outline-secondary', 'arrow-return-left', true)
                    . CsUi::confirmButton('apprRej' . $req['id'], 'Reject', 'Reject ' . CsDocs::label($type), 'A rejected document cannot be approved again; it has to be prepared anew.', $f . '<input type="hidden" name="appr_act" value="reject">', 'btn-outline-danger', 'x-lg', true)
                    . '</div>'
                    . '<details class="mt-2 small"><summary>Delegate or escalate</summary><form method="post" class="row g-2 mt-1">' . csrf_field() . $f
                    . '<div class="col-12"><select class="form-select form-select-sm" name="delegate_to" data-combo>' . options(Lookup::users(), '', 'Delegate to') . '</select></div>'
                    . '<div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Note"></div>'
                    . '<div class="col-12 d-flex gap-1"><button class="btn btn-outline-secondary btn-sm" name="appr_act" value="delegate">Delegate</button>'
                    . '<button class="btn btn-outline-secondary btn-sm" name="appr_act" value="escalate">Escalate</button></div></form></details>';
            } else {
                $out .= '<p class="cs-note mb-1">' . h($why) . '</p>';
                if ((int)$req['requested_by'] === (int)Auth::id()) {
                    $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="appr_req" value="' . (int)$req['id'] . '"><button class="btn btn-link btn-sm p-0" name="appr_act" value="cancel" data-confirm="Withdraw this document from approval?">Withdraw from approval</button></form>';
                }
            }
        } elseif (in_array($req['status'], ['returned', 'cancelled'], true) && $canSubmit) {
            $out .= '<form method="post" class="mt-2">' . csrf_field() . '<input type="hidden" name="appr_act" value="submit"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Submit again</button></form>';
        }
        return $out . '</div></div>';
    }

    /**
     * Handles the approval buttons of panel(). $submit is called to submit the document (it knows the document's
     * reference, project and amount). Returns true when it handled the post (the page then redirects).
     */
    public static function handlePost(string $type, int $docId, callable $submit): bool
    {
        $act = (string)p('appr_act');
        if (!is_post() || $act === '') {
            return false;
        }
        if ($act === 'submit') {
            $submit();
            flash('success', CsDocs::label($type) . ' submitted for approval.');
            return true;
        }
        $req = DB::row('SELECT * FROM cs_approval_requests WHERE id = :id AND doc_type = :t AND doc_id = :d', ['id' => (int)p('appr_req'), 't' => $type, 'd' => $docId]);
        if (!$req) {
            fail('Approval request not found.');
        }
        self::decide((int)$req['id'], $act, (string)p('reason'), pint('delegate_to'));
        $after = DB::value('SELECT status FROM cs_approval_requests WHERE id = :id', ['id' => $req['id']]);
        flash('success', ['approve' => $after === 'approved' ? 'Approved. The document now takes effect.' : 'Approved. It went on to the next approver.',
            'reject' => 'Rejected.', 'return' => 'Returned for correction.', 'delegate' => 'Delegated.', 'escalate' => 'Escalated to management.', 'cancel' => 'Withdrawn from approval.'][$act] ?? 'Done.');
        return true;
    }
}
