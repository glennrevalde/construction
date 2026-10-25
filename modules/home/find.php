<?php
declare(strict_types=1);

/**
 * Find: every transaction of every module, by any combination of what is known about it.
 *
 * Simple  a transaction type, then the name, the dates, the number and the amount.
 * Advanced every filter there is, alphabetically. Each one set is added to the Current Choices and
 *          all of them have to hold. A list filter (accounts, names, items, types ...) matches any of
 *          the ones ticked.
 * The results list the Date, Type, Num, Name, Account, Memo and Amount. A click on a line opens the
 * transaction itself. Report opens the same results as a report that can be printed and customized;
 * Export saves them as a spreadsheet file.
 */

$pageKey = 'home.find';
$c = Find::parse((array)($_GET['f'] ?? []));
$words = Find::describe($c);
$cat = Find::catalogue();
$simpleKeys = ['type', 'name', 'date', 'number', 'amount'];
$searched = isset($_GET['f']) || g('go') === '1';
$tab = in_array(g('tab'), ['simple', 'advanced'], true)
    ? g('tab')
    : (array_diff(array_keys($c), $simpleKeys) || count($c['type']['v'] ?? []) > 1 ? 'advanced' : 'simple');
$sort = in_array(g('sort'), ['date', 'type', 'num', 'name', 'account', 'memo', 'amount'], true) ? g('sort') : 'date';
$dir = g('dir') === 'asc' ? 'asc' : 'desc';
$pg = max(1, (int)g('pg', '1'));
$canReport = Access::page('rpt.find');
$canExport = Access::can('rpt.find', 'print');
$lines = ($c['detail']['v'] ?? 'summary') === 'lines';

// ------------------------------------------------------------------ export to a spreadsheet file
if (g('export') === 'csv') {
    Access::require('rpt.find', 'print');
    $res = Find::run($c, Find::REPORT_LIMIT, 0, $sort, $dir);
    Audit::log('export', 'home', 'report', null, 'Find', ['page_key' => $pageKey,
        'reason' => $words ? implode('; ', array_map(fn($x) => $x[0] . ': ' . $x[1], $words)) : 'Every transaction']);
    $out = [];
    foreach ($res['rows'] as $r) {
        $out[] = [$r['txn_date'], $r['type_label'] . ((int)$r['is_voided'] ? ' (void)' : ''), $r['num'], $r['name'],
            $r['account'], $r['memo'], ucfirst((string)$r['status']), round($r['amount'], 2)];
    }
    csv_download('find_' . date('Ymd_His') . '.csv',
        ['Date', 'Type', 'Num', 'Name', $lines ? 'Account / Item' : 'Account', 'Memo', 'Status', 'Amount'], $out);
}

$res = $searched ? Find::run($c, Find::LIMIT, ($pg - 1) * Find::LIMIT, $sort, $dir) : null;
$query = Find::toQuery($c);

// the addresses the result buttons go to
$findUrl = fn(array $extra = []) => url($pageKey, ['f' => $query, 'tab' => $tab, 'sort' => $sort, 'dir' => $dir] + $extra);
$reportUrl = $canReport ? url('rpt.find', ['f' => $query]) : null;

// ------------------------------------------------------------------ the Simple tab: what fits each type
$types = Find::typeOptions();
$simpleType = $c['type']['v'][0] ?? (isset($types['invoice']) ? 'invoice' : (string)array_key_first($types));
$salesTypes = ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'finance_charge', 'payment', 'credit_memo', 'refund', 'pos_sale', 'pos_return'];
$buyTypes = ['purchase_order', 'bill', 'vendor_credit', 'item_receipt', 'bill_payment'];
$typeInfo = [];
foreach ($types as $t => $label) {
    [$nameLabel, $numLabel] = Find::simpleLabels($t);
    $typeInfo[$t] = ['name' => $nameLabel, 'num' => $numLabel,
        'kinds' => in_array($t, $salesTypes, true) ? 'c' : (in_array($t, $buyTypes, true) ? 'v' : 'cvo')];
}
$nameOpts = Find::options('name');
$chosenName = $c['name']['v'][0] ?? '';

// ------------------------------------------------------------------ the page
layout_start('Find', ['actions' =>
    page_link('home.search', [], 'Search names and items', 'btn-outline-secondary', 'search')]);

/** One editor of the Advanced tab */
$editor = function (string $key, array $spec) use ($c): string {
    [$label, $kind, $help] = $spec;
    $v = $c[$key] ?? [];
    $name = 'f[' . $key . ']';
    $h = '<div class="find-editor" data-key="' . h($key) . '" data-kind="' . h($kind) . '" data-label="' . h($label) . '" hidden>'
        . '<div class="find-editor-title">' . h($label) . '</div><p class="small text-muted mb-2">' . h($help) . '</p>';
    switch ($kind) {
        case 'pick':
            $opts = Find::options($key);
            $on = array_flip($v['v'] ?? []);
            $h .= '<input class="form-control form-control-sm mb-2 find-pick-search" placeholder="Type to narrow the list" autocomplete="off">'
                . '<div class="find-pick">';
            foreach ($opts as $val => $text) {
                $id = 'fp_' . $key . '_' . md5((string)$val);
                $h .= '<label class="find-pick-row" for="' . h($id) . '"><input class="form-check-input" type="checkbox" id="' . h($id) . '" name="' . h($name) . '[v][]" value="' . h($val) . '"'
                    . (isset($on[(string)$val]) ? ' checked' : '') . '> <span>' . h($text) . '</span></label>';
            }
            if (!$opts) {
                $h .= '<div class="small text-muted p-2">Nothing to choose from yet.</div>';
            }
            $h .= '</div><div class="d-flex justify-content-between align-items-center mt-1">'
                . '<span class="small text-muted find-pick-count"></span>'
                . '<button type="button" class="btn btn-sm btn-link p-0 find-clear">Clear</button></div>';
            break;
        case 'choice':
            $cur = $v['v'] ?? '';
            $choices = Find::CHOICES[$key];
            if ($key !== 'detail') {
                $choices = ['' => 'Either (no filter)'] + $choices;
            } elseif ($cur === '') {
                $cur = 'summary';
            }
            foreach ($choices as $val => $text) {
                $id = 'fc_' . $key . '_' . ($val === '' ? 'any' : $val);
                $h .= '<div class="form-check"><input class="form-check-input" type="radio" id="' . h($id) . '" name="' . h($name) . '[v]" value="' . h($val) . '"'
                    . ((string)$cur === (string)$val ? ' checked' : '') . '><label class="form-check-label" for="' . h($id) . '">' . h($text) . '</label></div>';
            }
            break;
        case 'range':
            $h .= '<div class="row g-2">'
                . '<div class="col-12"><label class="form-label">Dates</label><select class="form-select form-select-sm find-preset" name="' . h($name) . '[preset]" data-no-combo>'
                . options(Find::RANGE_PRESETS, $v['preset'] ?? '', 'Any date') . '</select></div>'
                . '<div class="col-6"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="' . h($name) . '[from]" value="' . h($v['from'] ?? '') . '"></div>'
                . '<div class="col-6"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="' . h($name) . '[to]" value="' . h($v['to'] ?? '') . '"></div>';
            if ($key === 'entered') {
                $which = $v['which'] ?? 'entered';
                $h .= '<div class="col-12 d-flex gap-3">'
                    . '<div class="form-check"><input class="form-check-input" type="radio" id="fe_entered" name="' . h($name) . '[which]" value="entered"' . checked($which === 'entered') . '><label class="form-check-label" for="fe_entered">First entered</label></div>'
                    . '<div class="form-check"><input class="form-check-input" type="radio" id="fe_modified" name="' . h($name) . '[which]" value="modified"' . checked($which === 'modified') . '><label class="form-check-label" for="fe_modified">Last modified</label></div></div>';
            }
            $h .= '</div>';
            break;
        case 'number':
            $op = $v['op'] ?? '';
            $h .= '<div class="d-flex flex-wrap gap-3 mb-2">';
            foreach (['' => 'Any'] + Find::NUMBER_OPS as $val => $text) {
                $id = 'fn_' . $key . '_' . ($val === '' ? 'any' : $val);
                $h .= '<div class="form-check"><input class="form-check-input find-op" type="radio" id="' . h($id) . '" name="' . h($name) . '[op]" value="' . h($val) . '"'
                    . ((string)$op === (string)$val ? ' checked' : '') . '><label class="form-check-label" for="' . h($id) . '">' . h($text) . '</label></div>';
            }
            $unit = $key === 'aging' ? 'days' : ($key === 'qty' ? 'units' : '');
            $h .= '</div><div class="row g-2 align-items-center">'
                . '<div class="col"><input class="form-control form-control-sm text-end" inputmode="decimal" name="' . h($name) . '[a]" value="' . h(isset($v['a']) ? (string)$v['a'] : '') . '" placeholder="0"></div>'
                . '<div class="col-auto find-and small text-muted"' . ($op === 'between' ? '' : ' hidden') . '>and</div>'
                . '<div class="col find-and"' . ($op === 'between' ? '' : ' hidden') . '><input class="form-control form-control-sm text-end" inputmode="decimal" name="' . h($name) . '[b]" value="' . h(isset($v['b']) ? (string)$v['b'] : '') . '" placeholder="0"></div>'
                . ($unit ? '<div class="col-auto small text-muted">' . h($unit) . '</div>' : '')
                . '</div>';
            break;
        case 'text':
            $modes = Find::TEXT_MODES + ($key === 'number' ? ['range' => 'from ... to'] : []);
            $mode = $v['mode'] ?? 'contains';
            $h .= '<div class="row g-2">'
                . '<div class="col-sm-5"><select class="form-select form-select-sm find-mode" name="' . h($name) . '[mode]" data-no-combo>' . options($modes, $mode) . '</select></div>'
                . '<div class="col"><input class="form-control form-control-sm" name="' . h($name) . '[q]" value="' . h($v['q'] ?? '') . '" maxlength="120"></div>'
                . ($key === 'number' ? '<div class="col find-to"' . ($mode === 'range' ? '' : ' hidden') . '><input class="form-control form-control-sm" name="' . h($name) . '[q2]" value="' . h($v['q2'] ?? '') . '" placeholder="to" maxlength="120"></div>' : '')
                . '</div>';
            break;
    }
    return $h . '</div>';
};
?>
<ul class="nav nav-tabs find-tabs" role="tablist">
  <li class="nav-item"><a class="nav-link<?= $tab === 'simple' ? ' active' : '' ?>" href="<?= h(url($pageKey, ['tab' => 'simple', 'f' => $query])) ?>" data-find-tab="simple">Simple</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'advanced' ? ' active' : '' ?>" href="<?= h(url($pageKey, ['tab' => 'advanced', 'f' => $query])) ?>" data-find-tab="advanced">Advanced</a></li>
</ul>

<div class="card find-card mb-3">
<?php if ($tab === 'simple'): ?>
  <!-- ======================================================== Simple -->
  <form method="get" class="card-body" id="findSimple">
    <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="tab" value="simple"><input type="hidden" name="go" value="1">
    <div class="row g-3">
      <div class="col-lg-3 col-md-6"><label class="form-label" for="fsType">Transaction type</label>
        <select class="form-select form-select-sm" id="fsType" name="f[type][v][]" data-no-combo>
          <?php foreach ($types as $t => $label): ?>
            <option value="<?= h($t) ?>" data-name="<?= h($typeInfo[$t]['name']) ?>" data-num="<?= h($typeInfo[$t]['num']) ?>" data-kinds="<?= h($typeInfo[$t]['kinds']) ?>"<?= $t === $simpleType ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-lg-4 col-md-6"><label class="form-label" for="fsName" id="fsNameLabel"><?= h($typeInfo[$simpleType]['name'] ?? 'Name') ?></label>
        <select class="form-select form-select-sm" id="fsName" name="f[name][v][]" data-combo data-combo-placeholder="Any name: type to search">
          <option value="">Any name</option>
          <?php foreach ($nameOpts as $val => $label): if (str_starts_with($val, 'all:')) { continue; } ?>
            <option value="<?= h($val) ?>" data-kind="<?= h($val[0]) ?>"<?= $val === $chosenName ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-lg-2 col-6"><label class="form-label" for="fsFrom">Date from</label>
        <input class="form-control form-control-sm" type="date" id="fsFrom" name="f[date][from]" value="<?= h($c['date']['from'] ?? '') ?>"></div>
      <div class="col-lg-2 col-6"><label class="form-label" for="fsTo">To</label>
        <input class="form-control form-control-sm" type="date" id="fsTo" name="f[date][to]" value="<?= h($c['date']['to'] ?? '') ?>"></div>
      <div class="col-lg-3 col-md-6"><label class="form-label" for="fsNum" id="fsNumLabel"><?= h($typeInfo[$simpleType]['num'] ?? 'Number') ?></label>
        <input type="hidden" name="f[number][mode]" value="contains">
        <input class="form-control form-control-sm" id="fsNum" name="f[number][q]" value="<?= h($c['number']['q'] ?? '') ?>" maxlength="60"></div>
      <div class="col-lg-3 col-md-6"><label class="form-label" for="fsAmt">Amount</label>
        <input type="hidden" name="f[amount][op]" value="eq">
        <input class="form-control form-control-sm text-end" id="fsAmt" inputmode="decimal" name="f[amount][a]" value="<?= h(isset($c['amount']['a']) && ($c['amount']['op'] ?? '') === 'eq' ? (string)$c['amount']['a'] : '') ?>" placeholder="Any amount"></div>
      <div class="col-lg-6 d-flex align-items-end gap-2 flex-wrap">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Find</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, ['tab' => 'simple'])) ?>">Reset</a>
        <span class="small text-muted">Need more ways to search? The <a href="<?= h(url($pageKey, ['tab' => 'advanced', 'f' => $query])) ?>">Advanced</a> tab has every filter.</span>
      </div>
    </div>
  </form>
<?php else: ?>
  <!-- ======================================================== Advanced -->
  <form method="get" class="card-body" id="findAdvanced">
    <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="tab" value="advanced"><input type="hidden" name="go" value="1">
    <div class="find-adv">
      <div class="find-col find-col-list">
        <div class="find-col-head">Choose filter</div>
        <input class="form-control form-control-sm mb-2" id="findFilterSearch" placeholder="Type to find a filter" autocomplete="off">
        <div class="find-filters" id="findFilters" role="listbox" aria-label="Filters">
          <?php foreach ($cat as $key => [$label]): ?>
            <button type="button" class="find-filter<?= isset($c[$key]) ? ' is-set' : '' ?>" data-key="<?= h($key) ?>" role="option"><?= h($label) ?><i class="bi bi-check2 find-filter-mark" aria-hidden="true"></i></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="find-col find-col-edit" id="findEditors">
        <?php foreach ($cat as $key => $spec): ?><?= $editor($key, $spec) ?><?php endforeach; ?>
      </div>
      <div class="find-col find-col-choices">
        <div class="find-col-head">Current choices</div>
        <table class="table table-sm find-choices mb-2" id="findChoices">
          <thead><tr><th>Filter</th><th>Set to</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($words as $key => [$label, $what]): ?>
            <tr data-key="<?= h($key) ?>"><td><?= h($label) ?></td><td><?= h($what) ?></td><td class="text-end"><button type="button" class="btn btn-sm btn-link p-0 find-remove" title="Remove this filter"><i class="bi bi-x-lg"></i></button></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="small text-muted mb-0" id="findNoChoices"<?= $words ? ' hidden' : '' ?>>No filter chosen yet: Find lists every transaction you are allowed to open, newest first.</p>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
      <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Find</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, ['tab' => 'advanced'])) ?>">Reset</a>
    </div>
  </form>
<?php endif; ?>
</div>

<?php if ($res !== null): ?>
<!-- ======================================================== results -->
<div class="card find-results">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div><strong><?= number_format($res['count']) ?></strong> <?= $lines ? 'line' : 'transaction' ?><?= $res['count'] === 1 ? '' : 's' ?> found<?php if ($res['count']): ?><span class="text-muted fw-normal">, amounts totalling <?= money($res['total']) ?></span><?php endif; ?></div>
    <div class="d-flex flex-wrap gap-1">
      <?php // on a phone the headings are folded away, so the order is picked here instead ?>
      <select class="form-select form-select-sm find-sort" aria-label="Sort by" data-no-combo onchange="location = this.value">
        <?php foreach (['date|desc' => 'Newest first', 'date|asc' => 'Oldest first', 'amount|desc' => 'Largest amount first', 'amount|asc' => 'Smallest amount first',
                        'name|asc' => 'Name, A to Z', 'type|asc' => 'Type', 'num|asc' => 'Number'] as $k => $label):
            [$sk, $sd] = explode('|', $k); ?>
          <option value="<?= h(url($pageKey, ['f' => $query, 'tab' => $tab, 'go' => '1', 'sort' => $sk, 'dir' => $sd])) ?>"<?= $sort === $sk && $dir === $sd ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" id="findGoTo"<?= $res['rows'] ? '' : ' disabled' ?> title="Open the transaction picked in the list"><i class="bi bi-box-arrow-up-right"></i> Go To</button>
      <?php if ($reportUrl): ?><a class="btn btn-sm btn-outline-secondary<?= $res['rows'] ? '' : ' disabled' ?>" href="<?= h($reportUrl) ?>"><i class="bi bi-file-earmark-text"></i> Report</a><?php endif; ?>
      <?php if ($canExport): ?><a class="btn btn-sm btn-outline-secondary<?= $res['rows'] ? '' : ' disabled' ?>" href="<?= h($findUrl(['export' => 'csv'])) ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Export</a><?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 find-grid" id="findGrid">
      <thead><tr>
        <?php foreach (['date' => 'Date', 'type' => 'Type', 'num' => 'Num', 'name' => 'Name', 'account' => $lines ? 'Account / Item' : 'Account', 'memo' => 'Memo', 'amount' => 'Amount'] as $k => $label):
            $next = ($sort === $k && $dir === 'asc') ? 'desc' : 'asc'; ?>
          <th class="<?= $k === 'amount' ? 'num' : '' ?>"><a class="text-reset" href="<?= h(url($pageKey, ['f' => $query, 'tab' => $tab, 'go' => '1', 'sort' => $k, 'dir' => $next])) ?>"><?= h($label) ?><?= $sort === $k ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '' ?></a></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php if (!$res['rows']): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Nothing matches every one of these choices. Remove a filter, or widen the dates.</td></tr>
      <?php endif; ?>
      <?php foreach ($res['rows'] as $i => $r): ?>
        <tr class="find-row<?= (int)$r['is_voided'] ? ' is-void' : '' ?><?= $i === 0 ? ' is-picked' : '' ?>" data-href="<?= h($r['url']) ?>" tabindex="0">
          <td class="text-nowrap"><?= h(fdate($r['txn_date'])) ?></td>
          <td class="text-nowrap"><?= h($r['type_label']) ?><?= (int)$r['is_voided'] ? ' <span class="find-void">Void</span>' : '' ?></td>
          <td class="text-nowrap"><a href="<?= h($r['url']) ?>"><?= h($r['num']) ?></a></td>
          <td class="find-wrap"><?= h((string)$r['name']) ?></td>
          <td class="find-wrap"><?= h((string)$r['account']) ?></td>
          <td class="find-wrap find-memo"><?= h((string)$r['memo']) ?></td>
          <td class="num"><?= money($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php $pages = (int)ceil($res['count'] / Find::LIMIT); if ($pages > 1): ?>
  <div class="card-body py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center small">
    <span class="text-muted">Showing <?= number_format(($pg - 1) * Find::LIMIT + 1) ?> to <?= number_format(min($res['count'], $pg * Find::LIMIT)) ?> of <?= number_format($res['count']) ?></span>
    <div class="d-flex gap-1">
      <?php if ($pg > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($findUrl(['go' => '1', 'pg' => $pg - 1])) ?>"><i class="bi bi-chevron-left"></i> Newer</a><?php endif; ?>
      <?php if ($pg < $pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($findUrl(['go' => '1', 'pg' => $pg + 1])) ?>">Older <i class="bi bi-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  // ---------------------------------------------------------------- Simple: the words follow the type chosen
  var type = document.getElementById('fsType');
  if (type) {
    var name = document.getElementById('fsName');
    var applyType = function () {
      var o = type.options[type.selectedIndex];
      document.getElementById('fsNameLabel').textContent = o.dataset.name;
      document.getElementById('fsNumLabel').textContent = o.dataset.num;
      // an invoice is for a customer, a bill from a supplier: the name list offers only what fits
      var kinds = o.dataset.kinds || 'cvo';
      Array.prototype.forEach.call(name.options, function (n) {
        if (!n.value) { return; }
        n.disabled = kinds.indexOf(n.dataset.kind) < 0;
      });
      if (name.selectedOptions[0] && name.selectedOptions[0].disabled) { name.value = ''; }
      if (name.comboSync) { name.comboSync(); }
    };
    type.addEventListener('change', applyType);
    applyType();
    // an empty box is left out of the search altogether
    document.getElementById('findSimple').addEventListener('submit', function () {
      if (!document.getElementById('fsAmt').value.trim()) {
        this.querySelector('[name="f[amount][op]"]').disabled = true;
        document.getElementById('fsAmt').disabled = true;
      }
      if (!document.getElementById('fsNum').value.trim()) {
        this.querySelector('[name="f[number][mode]"]').disabled = true;
        document.getElementById('fsNum').disabled = true;
      }
    });
  }

  // ---------------------------------------------------------------- Advanced
  var form = document.getElementById('findAdvanced');
  if (form) {
    var list = document.getElementById('findFilters');
    var choices = document.querySelector('#findChoices tbody');
    var noChoices = document.getElementById('findNoChoices');
    var editors = {};
    form.querySelectorAll('.find-editor').forEach(function (ed) { editors[ed.dataset.key] = ed; });
    var money = function (v) {
      var n = Number(String(v).replace(/[,\s]/g, ''));
      return isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v;
    };
    var fdate = function (d) { if (!d) { return ''; } var p = d.split('-'); return p[1] + '/' + p[2] + '/' + p[0]; };

    // what an editor is set to, in words ('' = not set)
    var summary = function (ed) {
      var key = ed.dataset.key, kind = ed.dataset.kind;
      if (kind === 'pick') {
        var on = Array.prototype.filter.call(ed.querySelectorAll('input[type=checkbox]'), function (b) { return b.checked; })
          .map(function (b) { return b.nextElementSibling.textContent; });
        var cnt = ed.querySelector('.find-pick-count');
        if (cnt) { cnt.textContent = on.length ? on.length + ' ticked' : 'None ticked: all of them'; }
        if (!on.length) { return ''; }
        return on.length <= 3 ? on.join(', ') : on.slice(0, 2).join(', ') + ' and ' + (on.length - 2) + ' more';
      }
      if (kind === 'choice') {
        var r = ed.querySelector('input[type=radio]:checked');
        if (!r || r.value === '' || (key === 'detail' && r.value === 'summary')) { return ''; }
        return r.nextElementSibling.textContent;
      }
      if (kind === 'range') {
        var pre = ed.querySelector('.find-preset'), from = ed.querySelector('[name$="[from]"]').value, to = ed.querySelector('[name$="[to]"]').value;
        var which = ed.querySelector('[name$="[which]"]:checked');
        var lead = which ? (which.value === 'modified' ? 'Modified ' : 'Entered ') : '';
        if (pre.value && pre.value !== 'custom') { return lead + pre.options[pre.selectedIndex].text; }
        if (from && to) { return lead + fdate(from) + ' to ' + fdate(to); }
        if (from) { return lead + 'From ' + fdate(from); }
        if (to) { return lead + 'Up to ' + fdate(to); }
        return '';
      }
      if (kind === 'number') {
        var op = ed.querySelector('.find-op:checked'), a = ed.querySelector('[name$="[a]"]').value.trim(), b = ed.querySelector('[name$="[b]"]').value.trim();
        ed.querySelectorAll('.find-and').forEach(function (x) { x.hidden = !(op && op.value === 'between'); });
        if (!op || !op.value || a === '') { return ''; }
        var f = key === 'amount' ? money : function (x) { return x; };
        var u = key === 'aging' ? ' days' : '';
        if (op.value === 'between') { return b === '' ? '' : f(a) + ' to ' + f(b) + u; }
        return { eq: '= ', le: 'At most ', ge: 'At least ' }[op.value] + f(a) + u;
      }
      if (kind === 'text') {
        var mode = ed.querySelector('.find-mode'), q = ed.querySelector('[name$="[q]"]').value.trim();
        var q2el = ed.querySelector('[name$="[q2]"]'), q2 = q2el ? q2el.value.trim() : '';
        var toBox = ed.querySelector('.find-to');
        if (toBox) { toBox.hidden = mode.value !== 'range'; }
        if (mode.value === 'range') { return q || q2 ? (q ? 'From ' + q : '') + (q && q2 ? ' to ' + q2 : (q2 ? 'Up to ' + q2 : '')) : ''; }
        if (!q) { return ''; }
        return mode.options[mode.selectedIndex].text.replace(/^./, function (x) { return x.toUpperCase(); }) + ' "' + q + '"';
      }
      return '';
    };

    // the Current Choices list, kept in step with every editor as it is changed
    var refresh = function () {
      var rows = [];
      Object.keys(editors).forEach(function (key) {
        var ed = editors[key], s = summary(ed);
        var btn = list.querySelector('.find-filter[data-key="' + key + '"]');
        if (btn) { btn.classList.toggle('is-set', s !== ''); }
        if (s !== '') { rows.push([key, ed.dataset.label, s]); }
      });
      rows.sort(function (x, y) { return x[1].localeCompare(y[1]); });
      choices.innerHTML = '';
      rows.forEach(function (r) {
        var tr = document.createElement('tr');
        tr.dataset.key = r[0];
        tr.innerHTML = '<td></td><td></td><td class="text-end"><button type="button" class="btn btn-sm btn-link p-0 find-remove" title="Remove this filter"><i class="bi bi-x-lg"></i></button></td>';
        tr.cells[0].textContent = r[1];
        tr.cells[1].textContent = r[2];
        choices.appendChild(tr);
      });
      noChoices.hidden = rows.length > 0;
    };

    var show = function (key) {
      Object.keys(editors).forEach(function (k) { editors[k].hidden = k !== key; });
      list.querySelectorAll('.find-filter').forEach(function (b) {
        var on = b.dataset.key === key;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    };

    var clear = function (ed) {
      ed.querySelectorAll('input[type=checkbox]').forEach(function (b) { b.checked = false; });
      ed.querySelectorAll('input[type=radio]').forEach(function (r) { r.checked = r.value === '' || (ed.dataset.key === 'detail' && r.value === 'summary') || r.value === 'entered'; });
      ed.querySelectorAll('input[type=text], input:not([type]), input[type=date]').forEach(function (i) { if (!i.classList.contains('find-pick-search')) { i.value = ''; } });
      ed.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
      refresh();
    };

    list.addEventListener('click', function (e) {
      var b = e.target.closest('.find-filter');
      if (b) { show(b.dataset.key); }
    });
    document.getElementById('findFilterSearch').addEventListener('input', function () {
      var q = this.value.trim().toLowerCase(), first = null;
      list.querySelectorAll('.find-filter').forEach(function (b) {
        var hit = !q || b.textContent.toLowerCase().indexOf(q) >= 0;
        b.hidden = !hit;
        if (hit && !first) { first = b; }
      });
      if (first) { show(first.dataset.key); }
    });
    form.addEventListener('input', refresh);
    form.addEventListener('change', function (e) {
      // a date typed in by hand makes the range a custom one
      if (e.target.type === 'date') {
        var pre = e.target.closest('.find-editor').querySelector('.find-preset');
        if (pre) { pre.value = 'custom'; }
      }
      refresh();
    });
    form.addEventListener('click', function (e) {
      if (e.target.closest('.find-clear')) { clear(e.target.closest('.find-editor')); }
    });
    choices.addEventListener('click', function (e) {
      var rm = e.target.closest('.find-remove');
      if (!rm) { return; }
      var key = rm.closest('tr').dataset.key;
      if (editors[key]) { clear(editors[key]); }
    });
    choices.addEventListener('click', function (e) {
      var tr = e.target.closest('tr');
      if (tr && !e.target.closest('.find-remove') && editors[tr.dataset.key]) { show(tr.dataset.key); }
    });
    // narrowing a long list of accounts, names or items
    form.querySelectorAll('.find-pick-search').forEach(function (box) {
      box.addEventListener('input', function () {
        var q = box.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        box.closest('.find-editor').querySelectorAll('.find-pick-row').forEach(function (row) {
          var t = row.textContent.toLowerCase();
          row.hidden = !q.every(function (w) { return t.indexOf(w) >= 0; });
        });
      });
      box.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    });
    // only the filters that are set go into the address, so it stays short enough to bookmark
    form.addEventListener('submit', function () {
      Object.keys(editors).forEach(function (key) {
        var ed = editors[key];
        if (summary(ed) === '') { ed.querySelectorAll('input, select').forEach(function (i) { i.disabled = true; }); }
        ed.querySelectorAll('.find-pick-search').forEach(function (i) { i.disabled = true; });
      });
      document.getElementById('findFilterSearch').disabled = true;
    });

    // open on the first filter that is set, or on Transaction type
    var firstSet = list.querySelector('.find-filter.is-set');
    show(firstSet ? firstSet.dataset.key : (editors.type ? 'type' : list.querySelector('.find-filter').dataset.key));
    refresh();
  }

  // ---------------------------------------------------------------- results: a click opens the transaction
  var grid = document.getElementById('findGrid');
  if (grid) {
    var rows = function () { return Array.prototype.slice.call(grid.querySelectorAll('tr.find-row')); };
    var pick = function (tr) {
      rows().forEach(function (r) { r.classList.toggle('is-picked', r === tr); });
      if (tr) { tr.focus({ preventScroll: false }); }
    };
    grid.addEventListener('click', function (e) {
      var tr = e.target.closest('tr.find-row');
      if (!tr || e.target.closest('a')) { return; }
      if (e.ctrlKey || e.metaKey) { window.open(tr.dataset.href, '_blank'); return; }
      location.href = tr.dataset.href;
    });
    grid.addEventListener('keydown', function (e) {
      var tr = e.target.closest('tr.find-row');
      if (!tr) { return; }
      if (e.key === 'Enter') { location.href = tr.dataset.href; }
      if (e.key === 'ArrowDown' && tr.nextElementSibling) { e.preventDefault(); pick(tr.nextElementSibling); }
      if (e.key === 'ArrowUp' && tr.previousElementSibling) { e.preventDefault(); pick(tr.previousElementSibling); }
    });
    grid.addEventListener('focusin', function (e) {
      var tr = e.target.closest('tr.find-row');
      if (tr) { rows().forEach(function (r) { r.classList.toggle('is-picked', r === tr); }); }
    });
    var go = document.getElementById('findGoTo');
    if (go) {
      go.addEventListener('click', function () {
        var tr = grid.querySelector('tr.find-row.is-picked') || rows()[0];
        if (tr) { location.href = tr.dataset.href; }
      });
    }
  }
})();
</script>
<?php
layout_end();
