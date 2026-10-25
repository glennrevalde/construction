<?php
declare(strict_types=1);

/**
 * Project stock and material reconciliation: what was bought for the project, issued, returned, used, wasted and
 * transferred, what is still in its stockyard, and the warehouse items below their reorder point.
 */
$pageKey = 'proc.stock';
$pid = CsProject::current();
$p = $pid ? CsProject::requireAccess($pid) : null;
layout_start('Project Stock & Reconciliation', ['sub' => $p ? CsUi::projectLink((int)$pid) : 'Choose a project for its reconciliation; the warehouse levels are below.']);
echo cs_filter_bar($pageKey, $pid, '', false);
$costs = Access::viewCosts();
if ($p) {
    $cls = (int)$p['class_id'];
    $items = [];
    $add = function (int $item, string $k, float $q, float $v) use (&$items): void {
        $items[$item] ??= ['bought' => [0, 0], 'issued' => [0, 0], 'returned' => [0, 0], 'consumed' => [0, 0], 'wasted' => [0, 0], 'out' => [0, 0], 'in' => [0, 0]];
        $items[$item][$k][0] += $q;
        $items[$item][$k][1] += $v;
    };
    foreach (DB::all("SELECT l.item_id, SUM(CASE WHEN t.txn_type = 'vendor_credit' THEN -l.qty ELSE l.qty END) AS q, SUM(CASE WHEN t.txn_type = 'vendor_credit' THEN -l.amount ELSE l.amount END) AS v
                        FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN items i ON i.id = l.item_id
                       WHERE t.txn_type IN ('item_receipt','bill','check','vendor_credit') AND t.is_voided = 0 AND COALESCE(l.class_id, t.class_id) = :c
                         AND (l.source_line_id IS NULL OR t.txn_type <> 'bill' OR NOT EXISTS (SELECT 1 FROM transaction_lines s JOIN transactions st ON st.id = s.txn_id WHERE s.id = l.source_line_id AND st.txn_type = 'item_receipt'))
                       GROUP BY l.item_id", ['c' => $cls]) as $r) {
        $add((int)$r['item_id'], 'bought', (float)$r['q'], (float)$r['v']);
    }
    foreach (DB::all("SELECT d.doc_type, d.project_id, d.to_project_id, l.item_id, SUM(l.qty) AS q, SUM(l.amount) AS v, SUM(l.qty_wasted) AS w, SUM(l.qty_wasted * l.unit_cost) AS wv
                        FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id
                       WHERE d.status = 'posted' AND (d.project_id = :p OR d.to_project_id = :p2) GROUP BY d.doc_type, d.project_id, d.to_project_id, l.item_id", ['p' => $pid, 'p2' => $pid]) as $r) {
        $k = ['issue' => 'issued', 'return' => 'returned', 'consume' => 'consumed', 'transfer' => (int)$r['project_id'] === (int)$pid ? 'out' : 'in'][$r['doc_type']];
        $add((int)$r['item_id'], $k, (float)$r['q'], (float)$r['v']);
        if ($r['doc_type'] === 'consume') {
            $add((int)$r['item_id'], 'wasted', (float)$r['w'], (float)$r['wv']);
        }
    }
    $onHand = $p['site_id'] ? DB::pairs('SELECT item_id, qty_on_hand FROM item_site_stock WHERE site_id = :s AND qty_on_hand <> 0', ['s' => $p['site_id']]) : [];
    foreach ($onHand as $item => $q) {
        $items[(int)$item] ??= ['bought' => [0, 0], 'issued' => [0, 0], 'returned' => [0, 0], 'consumed' => [0, 0], 'wasted' => [0, 0], 'out' => [0, 0], 'in' => [0, 0]];
    }
    $names = $items ? DB::all('SELECT id, code, name, unit, avg_cost, cost FROM items WHERE id IN (' . implode(',', array_map('intval', array_keys($items))) . ') ORDER BY name') : [];
    $rows = [];
    $tv = ['bought' => 0.0, 'used' => 0.0, 'wasted' => 0.0, 'stock' => 0.0];
    $policy = CsMaterials::policy();
    foreach ($names as $n) {
        $x = $items[(int)$n['id']];
        $stock = (float)($onHand[$n['id']] ?? 0);
        $avg = (float)$n['avg_cost'] ?: (float)$n['cost'];
        $used = $policy === 'on_issue' ? $x['issued'][0] - $x['returned'][0] - $x['out'][0] + $x['in'][0] : $x['consumed'][0];
        $usedV = $policy === 'on_issue' ? $x['issued'][1] - $x['returned'][1] - $x['out'][1] + $x['in'][1] : $x['consumed'][1];
        $tv['bought'] += $x['bought'][1];
        $tv['used'] += $usedV;
        $tv['wasted'] += $x['wasted'][1];
        $tv['stock'] += $stock * $avg;
        $wastePct = $x['consumed'][0] > 0 ? $x['wasted'][0] / $x['consumed'][0] * 100 : 0;
        $rows[] = [h($n['code'] . ' ' . $n['name']) . '<span class="cs-sub">' . h((string)$n['unit']) . '</span>', qty($x['bought'][0]), qty($x['issued'][0]), qty($x['returned'][0]),
            qty($x['in'][0] - $x['out'][0]), qty($used), $x['wasted'][0] ? '<span class="' . ($wastePct > 5 ? 'cs-bad' : '') . '">' . qty($x['wasted'][0]) . ' (' . number_format($wastePct, 1) . '%)</span>' : '0',
            qty($stock), $costs ? money($usedV) : ''];
    }
    echo '<div class="cs-kpis">' . ($costs ? CsUi::kpi('Bought for the project', CsUi::amt($tv['bought']), 'Received and billed', 'key') . CsUi::kpi('Charged to the project', CsUi::amt($tv['used']), $policy === 'on_issue' ? 'Issued less returns' : 'Reported used on site')
        . CsUi::kpi('Waste', CsUi::amt($tv['wasted']), $tv['used'] > 0 ? CsUi::pct($tv['wasted'] / $tv['used'] * 100) . ' of the materials used' : '', $tv['used'] > 0 && $tv['wasted'] / $tv['used'] > 0.05 ? 'warn' : '')
        . CsUi::kpi('In the project stockyard', CsUi::amt($tv['stock']), 'At average cost') : '') . '</div>';
    echo cs_table_card('Material reconciliation', ['Material' => '', 'Bought' => 'num', 'Issued' => 'num', 'Returned' => 'num', 'Transfers in less out' => 'num', 'Used' => 'num', 'Wasted' => 'num', 'In stockyard' => 'num', 'Cost charged' => 'num'],
        $rows, 'No materials moved for this project yet.');
    echo '<div class="mb-3"></div>';
}
// warehouse levels
$low = DB::all("SELECT i.id, i.code, i.name, i.unit, i.reorder_point, i.preferred_vendor_id, COALESCE(SUM(s.qty_on_hand),0) AS on_hand
                  FROM items i LEFT JOIN item_site_stock s ON s.item_id = i.id LEFT JOIN sites st ON st.id = s.site_id AND st.site_type <> 'project'
                 WHERE i.is_active = 1 AND i.item_type = 'inventory' AND i.reorder_point > 0 AND (st.id IS NOT NULL OR s.item_id IS NULL)
                 GROUP BY i.id, i.code, i.name, i.unit, i.reorder_point, i.preferred_vendor_id HAVING on_hand <= i.reorder_point ORDER BY i.name LIMIT 100");
$rows = [];
foreach ($low as $l) {
    $rows[] = [h($l['code'] . ' ' . $l['name']), qty($l['on_hand']) . ' ' . h((string)$l['unit']), qty($l['reorder_point']), '<span class="cs-bad">Below reorder point</span>'];
}
echo cs_table_card('Warehouse items at or below their reorder point', ['Material' => '', 'On hand (warehouses)' => 'num', 'Reorder point' => 'num', '' => ''], $rows, 'Every stocked material is above its reorder point.',
    page_link('inv.center', [], 'Item list', 'btn-outline-secondary btn-sm ms-auto', 'box-seam'));
layout_end();
