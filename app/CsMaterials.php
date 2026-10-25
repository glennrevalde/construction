<?php
declare(strict_types=1);

/**
 * Materials of a project, from the request to the cost:
 *
 *  Material request (site) > approval > canvass of suppliers > award (approval) > purchase order (accounting)
 *  > receipt at the warehouse or the project's stockyard (accounting) > quality check (rejects go back on a supplier
 *  debit memo) > issue to the project > use on site, returns, transfers between projects.
 *
 * Cost policy (setting cs_material_policy):
 *  - on_issue:   the material is the project's cost when it is issued:  Dr Materials Cost (project, cost code) / Cr Inventory.
 *                Returns reverse that; a transfer between projects moves the cost (journal) and the material.
 *  - on_consume: issuing only moves the stock into the project's stockyard (no cost yet); the cost is taken when the
 *                site reports what it used (consumption, with waste). Returns and transfers move stock between sites.
 * A purchase order never makes a cost by itself: it is a commitment until received or billed.
 */
final class CsMaterials
{
    public const MR_STATUSES = ['draft' => 'Draft', 'for_approval' => 'For approval', 'approved' => 'Approved', 'partially_ordered' => 'Partially ordered',
        'ordered' => 'Ordered', 'issued' => 'Issued', 'closed' => 'Closed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    public const DOC_TYPES = ['issue' => 'Issue to project', 'return' => 'Return from project', 'consume' => 'Use on site (consumption)', 'transfer' => 'Transfer to another project'];

    public static function policy(): string
    {
        return Settings::get('cs_material_policy', 'on_issue') === 'on_consume' ? 'on_consume' : 'on_issue';
    }

    /** The main warehouse (first active warehouse site) */
    public static function warehouse(): ?int
    {
        $id = DB::value("SELECT id FROM sites WHERE is_active = 1 AND site_type = 'warehouse' ORDER BY id LIMIT 1");
        return $id ? (int)$id : null;
    }

    /** Items for the material grids: id => label, with data for unit and cost */
    public static function itemOptions(): array
    {
        return DB::all("SELECT id, code, name, unit, cost, avg_cost FROM items WHERE is_active = 1 AND item_type IN ('inventory','non_inventory') ORDER BY name");
    }

    public static function itemSelect(string $name, $value, string $extra = ''): string
    {
        static $items = null;
        $items ??= self::itemOptions();
        $out = '<select class="form-select form-select-sm" name="' . h($name) . '" data-combo ' . $extra . '><option value="">Choose material</option>';
        foreach ($items as $i) {
            $cost = (float)$i['avg_cost'] > 0 ? (float)$i['avg_cost'] : (float)$i['cost'];
            $out .= '<option value="' . (int)$i['id'] . '" data-unit="' . h((string)$i['unit']) . '" data-cost="' . h(number_format($cost, 2, '.', '')) . '" data-desc="' . h($i['name']) . '"'
                . ((string)$value === (string)$i['id'] ? ' selected' : '') . '>' . h($i['code'] . ' ' . $i['name']) . '</option>';
        }
        return $out . '</select>';
    }

    // ------------------------------------------------------------------ material requests

    public static function getMr(int $id): ?array
    {
        return DB::row('SELECT m.*, p.code AS project_code, p.name AS project_name, u.full_name AS requester FROM cs_material_requests m
                          JOIN cs_projects p ON p.id = m.project_id LEFT JOIN users u ON u.id = m.requested_by WHERE m.id = :id', ['id' => $id]);
    }

    public static function saveMr(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::getMr($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft request can be changed.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            $p = CsProject::get($pid);
            if (!$p) {
                fail('Choose the project.');
            }
            if (!in_array($p['status'], CsProject::OPEN, true)) {
                fail('Materials are requested only for an open project.');
            }
            $clean = [];
            foreach ($lines as $l) {
                $item = (int)($l['item_id'] ?? 0) ?: null;
                $desc = trim((string)($l['description'] ?? ''));
                if (!$item && $desc === '') {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                if ($qty <= 0) {
                    fail('Enter the quantity of ' . ($desc ?: 'every line') . '.');
                }
                if ($item && $desc === '') {
                    $desc = (string)DB::value('SELECT name FROM items WHERE id = :i', ['i' => $item]);
                }
                $clean[] = ['item_id' => $item, 'description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null,
                    'est_unit_cost' => to_num($l['est_unit_cost'] ?? 0), 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null];
            }
            if (!$clean) {
                fail('Add at least one material.');
            }
            $row = ['request_date' => valid_date((string)($d['request_date'] ?? '')) ? $d['request_date'] : today(), 'needed_date' => valid_date((string)($d['needed_date'] ?? '')) ? $d['needed_date'] : null,
                'deliver_to' => ($d['deliver_to'] ?? '') === 'warehouse' ? 'warehouse' : 'site', 'purpose' => mb_substr(trim((string)($d['purpose'] ?? '')), 0, 255) ?: null,
                'activity_id' => (int)($d['activity_id'] ?? 0) ?: null];
            if ($old) {
                DB::update('cs_material_requests', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_mr_lines WHERE mr_id = :m', ['m' => $id]);
            } else {
                $id = DB::insert('cs_material_requests', $row + ['mr_no' => Seq::next('cs_mr'), 'project_id' => $pid, 'status' => 'draft', 'requested_by' => Auth::id()]);
            }
            foreach ($clean as $i => $l) {
                DB::insert('cs_mr_lines', $l + ['mr_id' => $id, 'line_no' => $i + 1]);
            }
            $m = self::getMr($id);
            Audit::log($old ? 'edit' : 'create', 'proc', 'material_request', $id, $m['mr_no'], ['page_key' => 'proc.requests', 'amount' => self::mrValue($id)]);
            return $id;
        });
    }

    public static function mrValue(int $id): float
    {
        return r2(DB::value('SELECT COALESCE(SUM(qty * est_unit_cost),0) FROM cs_mr_lines WHERE mr_id = :m', ['m' => $id]));
    }

    /** Budget check of a request, by cost code: returns the warnings (fails when the company blocks overruns) */
    public static function budgetWarnings(int $projectId, array $byCode, string $what): array
    {
        $out = [];
        foreach ($byCode as $cc => $amt) {
            if ($amt <= 0) {
                continue;
            }
            $w = CsCost::checkBudget($projectId, $cc ? (int)$cc : null, (float)$amt, $what);
            if ($w !== '') {
                $out[] = $w;
            }
        }
        return $out;
    }

    public static function submitMr(int $id): void
    {
        $m = self::getMr($id);
        if (!$m || !in_array($m['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft request is submitted.');
        }
        $byCode = [];
        foreach (DB::all('SELECT cost_code_id, SUM(qty * est_unit_cost) AS amt FROM cs_mr_lines WHERE mr_id = :m GROUP BY cost_code_id', ['m' => $id]) as $r) {
            $byCode[(int)$r['cost_code_id']] = (float)$r['amt'];
        }
        $warn = self::budgetWarnings((int)$m['project_id'], $byCode, 'This request');
        CsApproval::submit('material_request', $id, $m['mr_no'], (int)$m['project_id'], self::mrValue($id), $warn ? 'Budget warning: ' . implode(' ', $warn) : '', $warn ? 'high' : null);
        if ($warn) {
            flash('warning', implode(' ', $warn));
        }
    }

    /** Status of a request from what was ordered and issued against it */
    public static function refreshMr(int $id): void
    {
        $m = DB::row('SELECT * FROM cs_material_requests WHERE id = :id', ['id' => $id]);
        if (!$m || !in_array($m['status'], ['approved', 'partially_ordered', 'ordered', 'issued'], true)) {
            return;
        }
        $t = DB::row('SELECT SUM(qty_approved) AS a, SUM(LEAST(qty_ordered + qty_issued, qty_approved)) AS done, SUM(qty_ordered) AS o, SUM(qty_issued) AS i FROM cs_mr_lines WHERE mr_id = :m', ['m' => $id]);
        $status = 'approved';
        if ((float)$t['i'] > 0 && (float)$t['done'] + 0.0001 >= (float)$t['a']) {
            $status = 'issued';
        } elseif ((float)$t['done'] + 0.0001 >= (float)$t['a'] && (float)$t['a'] > 0) {
            $status = 'ordered';
        } elseif ((float)$t['o'] > 0 || (float)$t['i'] > 0) {
            $status = 'partially_ordered';
        }
        if ($status !== $m['status']) {
            DB::update('cs_material_requests', ['status' => $status], 'id = :id', ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ canvass and award

    public static function getCanvass(int $id): ?array
    {
        return DB::row('SELECT c.*, m.mr_no, p.code AS project_code, p.name AS project_name, v.name AS awarded_name FROM cs_canvass c
                          JOIN cs_material_requests m ON m.id = c.mr_id JOIN cs_projects p ON p.id = c.project_id LEFT JOIN vendors v ON v.id = c.awarded_vendor_id
                         WHERE c.id = :id', ['id' => $id]);
    }

    public static function startCanvass(int $mrId): int
    {
        return DB::tx(function () use ($mrId) {
            $m = self::getMr($mrId);
            if (!$m || !in_array($m['status'], ['approved', 'partially_ordered'], true)) {
                fail('Only an approved request is canvassed.');
            }
            if (DB::value("SELECT id FROM cs_canvass WHERE mr_id = :m AND status IN ('open','for_approval')", ['m' => $mrId])) {
                fail('This request already has an open canvass.');
            }
            $open = (int)DB::value('SELECT COUNT(*) FROM cs_mr_lines WHERE mr_id = :m AND qty_approved - qty_ordered - qty_issued > 0.0001', ['m' => $mrId]);
            if (!$open) {
                fail('Everything on this request is already ordered or issued.');
            }
            $id = DB::insert('cs_canvass', ['canvass_no' => Seq::next('cs_canvass'), 'mr_id' => $mrId, 'project_id' => $m['project_id'], 'canvass_date' => today(), 'status' => 'open', 'created_by' => Auth::id()]);
            Audit::log('create', 'proc', 'canvass', $id, $m['mr_no'], ['page_key' => 'proc.canvass']);
            return $id;
        });
    }

    /** Lines still to be bought on the request of a canvass */
    public static function openLines(int $mrId): array
    {
        return DB::all('SELECT l.*, (l.qty_approved - l.qty_ordered - l.qty_issued) AS to_buy FROM cs_mr_lines l WHERE l.mr_id = :m AND l.qty_approved - l.qty_ordered - l.qty_issued > 0.0001 ORDER BY l.line_no', ['m' => $mrId]);
    }

    /** Adds or updates one supplier's quotation with its prices per line */
    public static function saveQuote(int $canvassId, array $d, array $prices, ?int $quoteId = null): int
    {
        return DB::tx(function () use ($canvassId, $d, $prices, $quoteId) {
            $c = self::getCanvass($canvassId);
            if (!$c || $c['status'] !== 'open') {
                fail('Quotations are entered while the canvass is open.');
            }
            $vendor = (int)($d['vendor_id'] ?? 0);
            if (!$vendor) {
                fail('Choose the supplier.');
            }
            if (DB::value('SELECT id FROM cs_canvass_quotes WHERE canvass_id = :c AND vendor_id = :v' . ($quoteId ? ' AND id <> :q' : ''), ['c' => $canvassId, 'v' => $vendor] + ($quoteId ? ['q' => $quoteId] : []))) {
                fail('That supplier already has a quotation on this canvass.');
            }
            $row = ['vendor_id' => $vendor, 'quote_ref' => mb_substr(trim((string)($d['quote_ref'] ?? '')), 0, 60) ?: null, 'quote_date' => valid_date((string)($d['quote_date'] ?? '')) ? $d['quote_date'] : today(),
                'delivery_days' => ($d['delivery_days'] ?? '') !== '' ? (int)$d['delivery_days'] : null, 'payment_terms' => mb_substr(trim((string)($d['payment_terms'] ?? '')), 0, 60) ?: null,
                'valid_until' => valid_date((string)($d['valid_until'] ?? '')) ? $d['valid_until'] : null, 'remarks' => mb_substr(trim((string)($d['remarks'] ?? '')), 0, 255) ?: null];
            if ($quoteId) {
                DB::update('cs_canvass_quotes', $row, 'id = :id AND canvass_id = :c', ['id' => $quoteId, 'c' => $canvassId]);
                DB::run('DELETE FROM cs_canvass_prices WHERE quote_id = :q', ['q' => $quoteId]);
            } else {
                $quoteId = DB::insert('cs_canvass_quotes', $row + ['canvass_id' => $canvassId]);
            }
            $lineIds = array_map('intval', DB::column('SELECT id FROM cs_mr_lines WHERE mr_id = :m', ['m' => $c['mr_id']]));
            $any = false;
            foreach ($prices as $lineId => $price) {
                if (!in_array((int)$lineId, $lineIds, true) || trim((string)$price) === '') {
                    continue;
                }
                DB::insert('cs_canvass_prices', ['quote_id' => $quoteId, 'mr_line_id' => (int)$lineId, 'unit_price' => to_num($price), 'available' => 1]);
                $any = true;
            }
            if (!$any) {
                fail('Enter the supplier\'s price for at least one line.');
            }
            Audit::log('quote', 'proc', 'canvass', $canvassId, $c['canvass_no'], ['page_key' => 'proc.canvass', 'reason' => 'Quotation of ' . DB::value('SELECT name FROM vendors WHERE id = :v', ['v' => $vendor])]);
            return $quoteId;
        });
    }

    /** The comparison: per quote its total over the open lines, completeness, and which is lowest */
    public static function comparison(int $canvassId): array
    {
        $c = self::getCanvass($canvassId);
        $lines = self::openLines((int)$c['mr_id']);
        if ($c['status'] !== 'open') {
            $lines = DB::all('SELECT l.*, l.qty_approved AS to_buy FROM cs_mr_lines l WHERE l.mr_id = :m ORDER BY l.line_no', ['m' => $c['mr_id']]);
        }
        $quotes = DB::all('SELECT q.*, v.name AS vendor_name, v.rating, v.tax_code_id FROM cs_canvass_quotes q JOIN vendors v ON v.id = q.vendor_id WHERE q.canvass_id = :c ORDER BY q.id', ['c' => $canvassId]);
        $prices = [];
        foreach ($quotes ? DB::all('SELECT p.* FROM cs_canvass_prices p JOIN cs_canvass_quotes q ON q.id = p.quote_id WHERE q.canvass_id = :c', ['c' => $canvassId]) : [] as $p) {
            $prices[(int)$p['quote_id']][(int)$p['mr_line_id']] = (float)$p['unit_price'];
        }
        $best = [];
        foreach ($lines as $l) {
            $min = null;
            foreach ($quotes as $q) {
                $pr = $prices[(int)$q['id']][(int)$l['id']] ?? null;
                if ($pr !== null && ($min === null || $pr < $min)) {
                    $min = $pr;
                }
            }
            $best[(int)$l['id']] = $min;
        }
        $totals = [];
        $lowest = null;
        foreach ($quotes as $q) {
            $sum = 0.0;
            $complete = true;
            foreach ($lines as $l) {
                $pr = $prices[(int)$q['id']][(int)$l['id']] ?? null;
                if ($pr === null) {
                    $complete = false;
                    continue;
                }
                $sum += r2((float)$l['to_buy'] * $pr);
            }
            $totals[(int)$q['id']] = ['total' => r2($sum), 'complete' => $complete];
            if ($complete && ($lowest === null || $sum < $totals[$lowest]['total'])) {
                $lowest = (int)$q['id'];
            }
        }
        return ['canvass' => $c, 'lines' => $lines, 'quotes' => $quotes, 'prices' => $prices, 'best' => $best, 'totals' => $totals, 'lowest' => $lowest];
    }

    /** Chooses the supplier and sends the award for approval; not choosing the lowest complete quote needs the reason */
    public static function award(int $canvassId, int $quoteId, string $reason): void
    {
        DB::tx(function () use ($canvassId, $quoteId, $reason) {
            $cmp = self::comparison($canvassId);
            $c = $cmp['canvass'];
            if ($c['status'] !== 'open') {
                fail('This canvass is no longer open.');
            }
            $q = null;
            foreach ($cmp['quotes'] as $x) {
                if ((int)$x['id'] === $quoteId) {
                    $q = $x;
                }
            }
            if (!$q) {
                fail('Choose one of the quotations.');
            }
            if (count($cmp['quotes']) < 2 && trim($reason) === '') {
                fail('Only one quotation was received: give the reason for buying without comparing suppliers.');
            }
            if ($cmp['lowest'] !== null && $cmp['lowest'] !== $quoteId && trim($reason) === '') {
                fail('This is not the lowest complete quotation: give the reason for choosing it (quality, delivery, terms).');
            }
            DB::run('UPDATE cs_canvass_quotes SET is_awarded = (id = :q) WHERE canvass_id = :c', ['q' => $quoteId, 'c' => $canvassId]);
            DB::update('cs_canvass', ['awarded_vendor_id' => $q['vendor_id'], 'award_reason' => mb_substr(trim($reason), 0, 255) ?: null], 'id = :id', ['id' => $canvassId]);
            $total = $cmp['totals'][$quoteId]['total'];
            $rate = (float)DB::value('SELECT rate FROM tax_codes WHERE id = :t', ['t' => (int)$q['tax_code_id']]);
            CsApproval::submit('purchase', $canvassId, $c['canvass_no'] . ' ' . $q['vendor_name'], (int)$c['project_id'], r2($total * (1 + $rate / 100)), trim($reason) !== '' ? 'Award reason: ' . $reason : 'Lowest complete quotation');
        });
    }

    /** The purchase order of an approved award (charged to the project and its cost codes, delivered where requested) */
    public static function makePo(int $canvassId): int
    {
        $cmp = self::comparison($canvassId);
        $c = $cmp['canvass'];
        $q = null;
        foreach ($cmp['quotes'] as $x) {
            if ((int)$x['is_awarded']) {
                $q = $x;
            }
        }
        if (!$q) {
            fail('No supplier was awarded.');
        }
        $m = self::getMr((int)$c['mr_id']);
        $p = CsProject::get((int)$c['project_id']);
        $site = $m['deliver_to'] === 'site' && $p['site_id'] ? (int)$p['site_id'] : self::warehouse();
        $taxId = $q['tax_code_id'] ? (int)$q['tax_code_id'] : null;
        $rate = $taxId ? (float)DB::value('SELECT rate FROM tax_codes WHERE id = :t', ['t' => $taxId]) : 0.0;
        $lines = [];
        $ordered = [];
        foreach ($cmp['lines'] as $l) {
            $price = $cmp['prices'][(int)$q['id']][(int)$l['id']] ?? null;
            if ($price === null || (float)$l['to_buy'] <= 0) {
                continue;
            }
            $amt = r2((float)$l['to_buy'] * $price);
            $item = $l['item_id'] ? DB::row('SELECT * FROM items WHERE id = :i', ['i' => $l['item_id']]) : null;
            $lines[] = ['line_kind' => $item ? 'item' : 'expense', 'item_id' => $item ? (int)$item['id'] : null, 'account_id' => $item ? null : CsPost::costAccount('material', $l['cost_code_id'] ? (int)$l['cost_code_id'] : null),
                'description' => $l['description'], 'qty' => (float)$l['to_buy'], 'unit' => $l['unit'] ?: ($item['unit'] ?? null), 'uom_qty' => (float)$l['to_buy'], 'uom_rate' => $price, 'uom_factor' => 1,
                'rate' => $price, 'amount' => $amt, 'tax_code_id' => $taxId, 'tax_amount' => r2($amt * $rate / 100), 'site_id' => $site, 'class_id' => (int)$p['class_id'],
                'cost_code_id' => $l['cost_code_id'] ?: null];
            $ordered[(int)$l['id']] = (float)$l['to_buy'];
        }
        if (!$lines) {
            fail('The awarded quotation has no price for the open lines.');
        }
        $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $q['vendor_id']]);
        $po = Txn::save('purchase_order', ['txn_date' => today(), 'vendor_id' => (int)$q['vendor_id'], 'site_id' => $site, 'class_id' => (int)$p['class_id'], 'terms_id' => $terms ?: null,
            'due_date' => $m['needed_date'] ?: ($q['delivery_days'] ? date('Y-m-d', strtotime('+' . (int)$q['delivery_days'] . ' days')) : null),
            'ref_no' => $c['canvass_no'], 'memo' => 'For ' . $p['code'] . ' ' . $p['name'] . ': ' . $m['mr_no'] . ($m['purpose'] ? ' (' . $m['purpose'] . ')' : ''),
            'shipping_address' => $m['deliver_to'] === 'site' ? ($p['location'] ?: null) : null], $lines, null, ['reason' => 'Awarded canvass ' . $c['canvass_no']]);
        foreach ($ordered as $lineId => $qty) {
            DB::run('UPDATE cs_mr_lines SET qty_ordered = qty_ordered + :q WHERE id = :id', ['q' => $qty, 'id' => $lineId]);
        }
        DB::update('cs_canvass', ['status' => 'awarded', 'po_txn_id' => $po], 'id = :id', ['id' => $canvassId]);
        self::refreshMr((int)$c['mr_id']);
        return $po;
    }

    // ------------------------------------------------------------------ approval events

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type === 'material_request') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if (!$status) {
                return;
            }
            $row = ['status' => $status];
            if ($event === 'approved') {
                $row += ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')];
                DB::run('UPDATE cs_mr_lines SET qty_approved = qty WHERE mr_id = :m', ['m' => $id]);
            }
            DB::update('cs_material_requests', $row, 'id = :id', ['id' => $id]);
            return;
        }
        if ($type === 'purchase') {
            if ($event === 'submitted') {
                DB::update('cs_canvass', ['status' => 'for_approval'], 'id = :id', ['id' => $id]);
            } elseif ($event === 'approved') {
                DB::update('cs_canvass', ['status' => 'open'], 'id = :id', ['id' => $id]);
                self::makePo($id);
            } elseif (in_array($event, ['rejected', 'returned', 'cancelled'], true)) {
                DB::update('cs_canvass', ['status' => 'open', 'awarded_vendor_id' => null], 'id = :id', ['id' => $id]);
                DB::run('UPDATE cs_canvass_quotes SET is_awarded = 0 WHERE canvass_id = :c', ['c' => $id]);
            }
            return;
        }
        if ($type === 'material_transfer') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'cancelled', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_material_docs', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id()] : []), 'id = :id', ['id' => $id]);
            }
            if ($event === 'approved') {
                self::postDoc($id);
            }
        }
    }

    // ------------------------------------------------------------------ issues, returns, consumption, transfers

    public static function getDoc(int $id): ?array
    {
        return DB::row('SELECT d.*, p.code AS project_code, p.name AS project_name, p.class_id, p.site_id AS stockyard_id FROM cs_material_docs d JOIN cs_projects p ON p.id = d.project_id WHERE d.id = :id', ['id' => $id]);
    }

    public static function saveDoc(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::getDoc($id) : null;
            if ($old && $old['status'] !== 'draft') {
                fail('Only a draft can be changed.');
            }
            $type = $old['doc_type'] ?? (string)($d['doc_type'] ?? '');
            if (!isset(self::DOC_TYPES[$type])) {
                fail('Choose what kind of material document this is.');
            }
            if ($type === 'consume' && self::policy() !== 'on_consume') {
                fail('Consumption is recorded only when materials are costed when used (Construction settings). Materials are costed when issued.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            $p = CsProject::get($pid);
            if (!$p) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $toProject = $type === 'transfer' ? ((int)($d['to_project_id'] ?? 0) ?: null) : null;
            if ($type === 'transfer' && (!$toProject || $toProject === $pid)) {
                fail('Choose the project the materials go to.');
            }
            $site = (int)($d['site_id'] ?? 0) ?: null;
            if (in_array($type, ['issue', 'return'], true) && !$site) {
                fail($type === 'issue' ? 'Choose where the materials are taken from.' : 'Choose where the materials are returned to.');
            }
            $clean = [];
            foreach ($lines as $l) {
                $item = (int)($l['item_id'] ?? 0);
                if (!$item) {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                if ($qty <= 0) {
                    fail('Enter the quantity of every material.');
                }
                $waste = $type === 'consume' ? max(0, min($qty, to_num($l['qty_wasted'] ?? 0))) : 0.0;
                $it = DB::row('SELECT * FROM items WHERE id = :i', ['i' => $item]);
                $uc = to_num($l['unit_cost'] ?? 0) ?: ((float)$it['avg_cost'] ?: (float)$it['cost']);
                $clean[] = ['item_id' => $item, 'description' => mb_substr(trim((string)($l['description'] ?? '')) ?: $it['name'], 0, 255), 'qty' => $qty, 'qty_wasted' => $waste,
                    'unit' => $it['unit'], 'unit_cost' => $uc, 'amount' => r2($qty * $uc), 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null,
                    'activity_id' => (int)($l['activity_id'] ?? 0) ?: null, 'mr_line_id' => (int)($l['mr_line_id'] ?? 0) ?: null];
            }
            if (!$clean) {
                fail('Add at least one material.');
            }
            $row = ['doc_date' => valid_date((string)($d['doc_date'] ?? '')) ? $d['doc_date'] : today(), 'site_id' => $site, 'to_project_id' => $toProject,
                'mr_id' => (int)($d['mr_id'] ?? 0) ?: null, 'daily_report_id' => (int)($d['daily_report_id'] ?? 0) ?: null,
                'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null, 'total_cost' => r2(array_sum(array_column($clean, 'amount')))];
            if ($old) {
                DB::update('cs_material_docs', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_material_doc_lines WHERE doc_id = :d', ['d' => $id]);
            } else {
                $id = DB::insert('cs_material_docs', $row + ['doc_no' => Seq::next('cs_matdoc'), 'doc_type' => $type, 'project_id' => $pid, 'status' => 'draft', 'requested_by' => Auth::id()]);
            }
            foreach ($clean as $i => $l) {
                DB::insert('cs_material_doc_lines', $l + ['doc_id' => $id, 'line_no' => $i + 1]);
            }
            Audit::log($old ? 'edit' : 'create', 'proc', 'material_doc', $id, self::DOC_TYPES[$type], ['page_key' => 'proc.materials', 'amount' => $row['total_cost']]);
            return $id;
        });
    }

    /**
     * Posts a material document to the books and the stock. Issues and consumption check the stock on hand and the
     * budget; a transfer between projects posts only once approved.
     */
    public static function postDoc(int $id): void
    {
        DB::tx(function () use ($id) {
            $d = self::getDoc($id);
            if (!$d || !in_array($d['status'], ['draft', 'approved'], true)) {
                fail('This document is already posted or cancelled.');
            }
            if ($d['doc_type'] === 'transfer' && $d['status'] !== 'approved') {
                fail('A transfer between projects is posted once it is approved.');
            }
            $lines = DB::all('SELECT * FROM cs_material_doc_lines WHERE doc_id = :d ORDER BY line_no', ['d' => $id]);
            $policy = self::policy();
            $p = CsProject::get((int)$d['project_id']);
            $classId = (int)$p['class_id'];
            $stockyard = $p['site_id'] ? (int)$p['site_id'] : null;
            $costAcct = CsPost::account('cs_materials_cost');
            $memo = self::DOC_TYPES[$d['doc_type']] . ' ' . $d['doc_no'] . ', ' . $p['code'];
            $txn = null;
            $txn2 = null;
            $total = 0.0;
            // stock on hand where the material comes from
            $from = match ($d['doc_type']) {
                'issue' => (int)$d['site_id'],
                'consume' => $stockyard,
                'return' => $policy === 'on_consume' ? $stockyard : null,
                'transfer' => $policy === 'on_consume' ? $stockyard : null,
                default => null,
            };
            if ($from) {
                $need = [];
                foreach ($lines as $l) {
                    $need[(int)$l['item_id']] = ($need[(int)$l['item_id']] ?? 0) + (float)$l['qty'];
                }
                foreach ($need as $item => $q) {
                    $on = Stock::onHand($item, $from);
                    if ($on + 0.00001 < $q) {
                        fail('Not enough ' . DB::value('SELECT name FROM items WHERE id = :i', ['i' => $item]) . ' at ' . DB::value('SELECT name FROM sites WHERE id = :s', ['s' => $from])
                            . ': ' . qty($on) . ' on hand, ' . qty($q) . ' needed.');
                    }
                }
            }
            $costNow = in_array($d['doc_type'], ['issue'], true) && $policy === 'on_issue' || $d['doc_type'] === 'consume';
            if ($costNow) {
                $byCode = [];
                foreach ($lines as $l) {
                    $uc = self::unitCost((int)$l['item_id'], $from);
                    $byCode[(int)$l['cost_code_id']] = ($byCode[(int)$l['cost_code_id']] ?? 0) + (float)$l['qty'] * $uc;
                }
                $warn = self::budgetWarnings((int)$d['project_id'], $byCode, 'This issue');
                if ($warn) {
                    flash('warning', implode(' ', $warn));
                }
            }
            switch ($d['doc_type']) {
                case 'issue':
                    if ($policy === 'on_issue') {
                        $txn = self::adjust($d['doc_date'], (int)$d['site_id'], $classId, $costAcct, $lines, -1, $memo);
                    } elseif ((int)$d['site_id'] !== $stockyard) {
                        if (!$stockyard) {
                            fail('The project has no stockyard to move the materials into.');
                        }
                        $txn = self::transfer($d['doc_date'], (int)$d['site_id'], $stockyard, $lines, $memo, $classId);
                    }
                    break;
                case 'consume':
                    if (!$stockyard) {
                        fail('The project has no stockyard.');
                    }
                    $txn = self::adjust($d['doc_date'], $stockyard, $classId, $costAcct, $lines, -1, $memo);
                    break;
                case 'return':
                    if ($policy === 'on_issue') {
                        $txn = self::adjust($d['doc_date'], (int)$d['site_id'], $classId, $costAcct, $lines, 1, $memo);
                    } elseif ($stockyard && (int)$d['site_id'] !== $stockyard) {
                        $txn = self::transfer($d['doc_date'], $stockyard, (int)$d['site_id'], $lines, $memo, $classId);
                    }
                    break;
                case 'transfer':
                    $to = CsProject::get((int)$d['to_project_id']);
                    if ($policy === 'on_consume') {
                        if (!$stockyard || !$to['site_id']) {
                            fail('Both projects need a stockyard.');
                        }
                        $txn = self::transfer($d['doc_date'], $stockyard, (int)$to['site_id'], $lines, $memo . ' to ' . $to['code'], $classId);
                    } else {
                        $jl = [];
                        foreach ($lines as $l) {
                            $jl[] = ['account_id' => $costAcct, 'amount' => r2((float)$l['amount']), 'class_id' => (int)$to['class_id'], 'cost_code_id' => $l['cost_code_id'], 'description' => $l['description'] . ' from ' . $p['code']];
                            $jl[] = ['account_id' => $costAcct, 'amount' => -r2((float)$l['amount']), 'class_id' => $classId, 'cost_code_id' => $l['cost_code_id'], 'description' => $l['description'] . ' to ' . $to['code']];
                        }
                        $txn = CsPost::journal($d['doc_date'], $memo . ' to ' . $to['code'], $jl, null, $d['doc_no']);
                    }
                    break;
            }
            // the cost actually posted, line by line
            if ($txn) {
                foreach (DB::all('SELECT id, item_id, qty, cost_amount FROM transaction_lines WHERE txn_id = :t ORDER BY line_no', ['t' => $txn]) as $i => $tl) {
                    if (isset($lines[$i]) && abs((float)$tl['qty']) > 0 && in_array($d['doc_type'], ['issue', 'consume', 'return'], true)) {
                        $cost = abs((float)$tl['cost_amount']);
                        if ($cost > 0) {
                            DB::update('cs_material_doc_lines', ['unit_cost' => round($cost / (float)$lines[$i]['qty'], 4), 'amount' => r2($cost)], 'id = :id', ['id' => $lines[$i]['id']]);
                        }
                    }
                }
            }
            $total = (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM cs_material_doc_lines WHERE doc_id = :d', ['d' => $id]);
            DB::update('cs_material_docs', ['status' => 'posted', 'cost_policy' => $policy, 'txn_id' => $txn, 'txn2_id' => $txn2, 'total_cost' => r2($total), 'posted_by' => Auth::id(), 'posted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            // what was issued against the request
            if ($d['mr_id'] && $d['doc_type'] === 'issue') {
                foreach ($lines as $l) {
                    if ($l['mr_line_id']) {
                        DB::run('UPDATE cs_mr_lines SET qty_issued = qty_issued + :q WHERE id = :id', ['q' => $l['qty'], 'id' => $l['mr_line_id']]);
                    }
                }
                self::refreshMr((int)$d['mr_id']);
            }
            // site quantities used: the activity's progress is reported on the daily report, not here
            Audit::log('post', 'proc', 'material_doc', $id, $d['doc_no'], ['page_key' => 'proc.materials', 'amount' => $total,
                'reason' => self::DOC_TYPES[$d['doc_type']] . ' (' . ($policy === 'on_issue' ? 'costed when issued' : 'costed when used') . ')']);
        });
    }

    /** Average cost of an item now (at a site when given) */
    public static function unitCost(int $itemId, ?int $siteId = null): float
    {
        $it = DB::row('SELECT cost, avg_cost FROM items WHERE id = :i', ['i' => $itemId]);
        return (float)($it['avg_cost'] ?? 0) > 0 ? (float)$it['avg_cost'] : (float)($it['cost'] ?? 0);
    }

    /** Inventory adjustment out of (sign -1) or back into (+1) a site, with the other side on the project's materials cost */
    private static function adjust(string $date, int $site, int $classId, int $costAcct, array $lines, int $sign, string $memo): int
    {
        $tl = [];
        foreach ($lines as $l) {
            $tl[] = ['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'description' => $l['description'], 'qty' => $sign * (float)$l['qty'], 'unit' => $l['unit'],
                'rate' => $sign > 0 ? (float)$l['unit_cost'] : 0, 'amount' => 0, 'site_id' => $site, 'class_id' => $classId, 'cost_code_id' => $l['cost_code_id'] ?: null];
        }
        return Txn::save('inventory_adjustment', ['txn_date' => $date, 'site_id' => $site, 'account_id' => $costAcct, 'class_id' => $classId, 'memo' => $memo], $tl, null, ['reason' => $memo]);
    }

    private static function transfer(string $date, int $from, int $to, array $lines, string $memo, int $classId): int
    {
        $tl = [];
        foreach ($lines as $l) {
            $tl[] = ['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'description' => $l['description'], 'qty' => (float)$l['qty'], 'unit' => $l['unit'], 'amount' => 0, 'cost_code_id' => $l['cost_code_id'] ?: null];
        }
        return Txn::save('inventory_transfer', ['txn_date' => $date, 'site_id' => $from, 'site2_id' => $to, 'memo' => $memo, 'class_id' => $classId], $tl, null, ['reason' => $memo]);
    }

    public static function cancelDoc(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $d = self::getDoc($id);
            if (!$d) {
                fail('Not found.');
            }
            if (trim($reason) === '') {
                fail('Give the reason.');
            }
            if ($d['status'] === 'posted') {
                // a correction: the accounting entries are voided (kept with their reason), the quantities against the request are taken back
                CsPost::void($d['txn_id'] ? (int)$d['txn_id'] : null, 'Material document ' . $d['doc_no'] . ' cancelled: ' . $reason);
                CsPost::void($d['txn2_id'] ? (int)$d['txn2_id'] : null, 'Material document ' . $d['doc_no'] . ' cancelled: ' . $reason);
                if ($d['mr_id'] && $d['doc_type'] === 'issue') {
                    foreach (DB::all('SELECT * FROM cs_material_doc_lines WHERE doc_id = :d AND mr_line_id IS NOT NULL', ['d' => $id]) as $l) {
                        DB::run('UPDATE cs_mr_lines SET qty_issued = GREATEST(0, qty_issued - :q) WHERE id = :id', ['q' => $l['qty'], 'id' => $l['mr_line_id']]);
                    }
                    self::refreshMr((int)$d['mr_id']);
                }
            } elseif (CsApproval::pendingFor('material_transfer', $id)) {
                fail('Withdraw it from approval first.');
            }
            DB::update('cs_material_docs', ['status' => 'cancelled', 'notes' => mb_substr(trim((string)$d['notes'] . ' Cancelled: ' . $reason), 0, 255)], 'id = :id', ['id' => $id]);
            Audit::log('reverse', 'proc', 'material_doc', $id, $d['doc_no'], ['page_key' => 'proc.materials', 'reason' => $reason, 'amount' => $d['total_cost']]);
        });
    }

    // ------------------------------------------------------------------ receiving inspection

    /**
     * Records the quality check of a delivery line. Rejected quantities go back to the supplier on a supplier debit memo
     * at the price received, so neither the stock nor the project carries them.
     */
    public static function inspect(int $txnLineId, float $accepted, float $rejected, string $reason): int
    {
        return DB::tx(function () use ($txnLineId, $accepted, $rejected, $reason) {
            $l = DB::row("SELECT l.*, t.txn_type, t.vendor_id, t.txn_no, t.class_id AS h_class, t.site_id AS h_site, t.id AS tid FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                           WHERE l.id = :id AND t.txn_type IN ('item_receipt','bill') AND t.is_voided = 0", ['id' => $txnLineId]);
            if (!$l || !$l['item_id']) {
                fail('Delivery line not found.');
            }
            $done = (float)DB::value('SELECT COALESCE(SUM(qty_delivered),0) FROM cs_receiving_checks WHERE txn_line_id = :l', ['l' => $txnLineId]);
            if ($accepted < 0 || $rejected < 0 || $accepted + $rejected <= 0) {
                fail('Enter the quantities accepted and rejected.');
            }
            if ($done + $accepted + $rejected > (float)$l['qty'] + 0.0001) {
                fail('Only ' . qty((float)$l['qty'] - $done) . ' of this delivery is left to inspect.');
            }
            if ($rejected > 0 && trim($reason) === '') {
                fail('Give the reason for rejecting.');
            }
            $classId = (int)(($l['class_id'] ?: $l['h_class']) ?? 0);
            $project = $classId ? CsProject::byClass($classId) : null;
            $dm = null;
            if ($rejected > 0) {
                $amt = r2($rejected * (float)$l['rate']);
                $taxRate = $l['tax_code_id'] ? (float)DB::value('SELECT rate FROM tax_codes WHERE id = :t', ['t' => $l['tax_code_id']]) : 0.0;
                $dm = Txn::save('vendor_credit', ['txn_date' => today(), 'vendor_id' => (int)$l['vendor_id'], 'site_id' => $l['site_id'] ?: $l['h_site'], 'account_id' => Accts::get('ap'),
                    'class_id' => $classId ?: null, 'ref_no' => $l['txn_no'], 'memo' => 'Rejected on delivery ' . $l['txn_no'] . ': ' . $reason],
                    [['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'description' => $l['description'], 'qty' => $rejected, 'unit' => $l['unit'], 'rate' => (float)$l['rate'],
                        'amount' => $amt, 'tax_code_id' => $l['tax_code_id'] ?: null, 'tax_amount' => r2($amt * $taxRate / 100), 'site_id' => $l['site_id'] ?: $l['h_site'], 'class_id' => $classId ?: null,
                        'cost_code_id' => $l['cost_code_id'] ?: null]], null, ['reason' => 'Rejected materials returned to the supplier']);
            }
            $id = DB::insert('cs_receiving_checks', ['txn_id' => $l['tid'], 'txn_line_id' => $txnLineId, 'project_id' => $project['id'] ?? null, 'item_id' => $l['item_id'], 'check_date' => today(),
                'qty_delivered' => $accepted + $rejected, 'qty_accepted' => $accepted, 'qty_rejected' => $rejected, 'reject_reason' => $rejected > 0 ? mb_substr($reason, 0, 255) : null,
                'inspected_by' => Auth::id(), 'debit_memo_txn_id' => $dm]);
            Audit::log('inspect', 'proc', 'receiving', $id, $l['txn_no'] . ' ' . $l['description'], ['page_key' => 'proc.receiving', 'reason' => qty($accepted) . ' accepted, ' . qty($rejected) . ' rejected' . ($reason ? ': ' . $reason : '')]);
            return $id;
        });
    }
}
