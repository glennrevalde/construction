<?php
declare(strict_types=1);

/**
 * Chart of Accounts > Set Up Budgets, and Customers > Set Up Forecast (the same page, $PARAMS['kind']).
 * Twelve monthly amounts per account for one fiscal year; a forecast holds them per customer as well.
 * See app/Budgets.php. The figures are compared with the ledger by the "vs Budget" and
 * "Forecast vs Actual" reports.
 */
$pageKey = $PAGE['key'];
$isForecast = ($PARAMS['kind'] ?? 'budget') === 'forecast';
$a = g('a', 'list');
$title = $isForecast ? 'Set Up Forecast' : 'Set Up Budgets';
$kinds = $isForecast ? ['forecast' => Budgets::KINDS['forecast']] : ['pl' => Budgets::KINDS['pl'], 'bs' => Budgets::KINDS['bs']];

// ------------------------------------------------------------------ delete
if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $b = Budgets::load((int)g('id'));
    if ($b && ($b['kind'] === 'forecast') === $isForecast) {
        DB::run('DELETE FROM budgets WHERE id = :id', ['id' => $b['id']]);
        Audit::log('delete', 'coa', 'budget', (int)$b['id'], (string)$b['name'], ['before' => $b, 'page_key' => $pageKey]);
        flash('success', '"' . $b['name'] . '" was deleted.');
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ new budget / forecast
if ($a === 'new') {
    Access::require($pageKey, 'create');
    handle_post(function () use ($pageKey, $isForecast, $kinds) {
        $kind = isset($kinds[p('kind')]) ? (string)p('kind') : array_key_first($kinds);
        $name = mb_substr(trim((string)p('name')), 0, 120);
        if ($name === '') {
            fail('Enter a name, for example "Budget 2027" or "Forecast 2027".');
        }
        $year = (int)p('fiscal_year', (string)(int)date('Y'));
        if ($year < 2000 || $year > 2100) {
            fail('Enter the fiscal year.');
        }
        if (DB::value('SELECT id FROM budgets WHERE kind = :k AND fiscal_year = :y AND name = :n', ['k' => $kind, 'y' => $year, 'n' => $name])) {
            fail('There is already a ' . ($isForecast ? 'forecast' : 'budget') . ' called "' . $name . '" for ' . $year . '.');
        }
        $id = DB::insert('budgets', [
            'name' => $name, 'kind' => $kind, 'fiscal_year' => $year,
            'start_month' => max(1, min(12, (int)(Settings::get('fiscal_year_start_month', '1') ?: 1))),
            'class_id' => Classes::ready() ? Classes::posted() : null,
            'customer_id' => $isForecast ? pint('customer_id') : null,
            'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null, 'created_by' => Auth::id(),
        ]);
        Audit::log('create', 'coa', 'budget', $id, $name, ['page_key' => $pageKey]);
        flash('success', $name . ' created. Enter the monthly amounts below.');
        redirect(url($pageKey, ['a' => 'edit', 'id' => $id] + (p('from_actual') === '1' ? ['fill' => 1] : [])));
    });
    layout_start('New ' . ($isForecast ? 'forecast' : 'budget'), ['actions' => btn_link(url($pageKey), 'Cancel')]);
    ?>
    <form method="post" class="card" style="max-width:820px" data-no-class-check><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('name', 'Name', fv('name', null, ($isForecast ? 'Forecast ' : 'Budget ') . (int)date('Y')), ['class' => 'col-md-5', 'required' => true, 'extra' => 'maxlength="120"']) ?>
      <?= select_field('kind', 'What it budgets', $kinds, fv('kind', null, array_key_first($kinds)), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
      <?= input_field('fiscal_year', 'Fiscal year', fv('fiscal_year', null, (string)(int)date('Y')), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3', 'extra' => 'min="2000" max="2100"']) ?>
      <?php if ($isForecast): ?>
        <?= select_field('customer_id', 'Customer (blank = one sheet for all customers)', Lookup::customers(), fv('customer_id'), ['blank' => 'All customers', 'class' => 'col-md-5']) ?>
      <?php endif; ?>
      <?= Classes::field(fv('class_id'), 'col-md-3', 'class_id', 'Class (optional)') ?>
      <?= input_field('notes', 'Notes', fv('notes'), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
      <?= check_field('from_actual', 'Start from last year\'s actual figures', fv('from_actual', null, 0), 'col-12') ?>
      <div class="col-12 small text-muted">The fiscal year starts in <?= h(date('F', mktime(0, 0, 0, max(1, (int)Settings::get('fiscal_year_start_month', '1')), 1))) ?>,
        as set in System Administration &gt; Company, Security &amp; Closing Date.</div>
      <div class="col-12"><?= save_buttons('Create', url($pageKey), false) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ enter the amounts
if ($a === 'edit') {
    $b = Budgets::load((int)gint('id'));
    if (!$b || ($b['kind'] === 'forecast') !== $isForecast) {
        redirect(url($pageKey));
    }
    Access::require($pageKey, 'edit');
    $periods = Budgets::periods($b);
    $accounts = Budgets::accounts($b['kind']);
    $customers = $isForecast ? ($b['customer_id'] ? [(int)$b['customer_id'] => (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $b['customer_id']])] : Lookup::customers()) : [0 => ''];

    handle_post(function () use ($b, $pageKey) {
        $n = Budgets::saveLines((int)$b['id'], (array)($_POST['amt'] ?? []));
        DB::update('budgets', ['notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null], 'id = :id', ['id' => $b['id']]);
        Audit::log('edit', 'coa', 'budget', (int)$b['id'], (string)$b['name'], ['reason' => $n . ' monthly amount(s) saved', 'page_key' => $pageKey]);
        flash('success', $n . ' monthly amount(s) saved.');
        redirect(url($pageKey, ['a' => 'edit', 'id' => $b['id']]));
    });

    $lines = Budgets::lines((int)$b['id']);
    if (g('fill') && !$lines) {
        $lines = Budgets::fillFromActual($b);
        flash('info', "Last year's actual figures were copied in. Change what you need and press Save.");
    }
    $report = $b['kind'] === 'bs' ? 'rpt.budget_balance' : ($b['kind'] === 'forecast' ? 'rpt.forecast_vs_actual' : 'rpt.budget_income');
    layout_start($b['name'] . ' - ' . Budgets::yearLabel($b), ['actions' =>
        page_link($report, ['budget_id' => $b['id']], $isForecast ? 'Forecast vs actual' : 'Compare with actual', 'btn-outline-primary', 'bar-chart')
        . ' ' . btn_link(url($pageKey), 'Back to the list')]);
    ?>
    <form method="post" id="budgetForm" data-no-class-check><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-end">
      <div><div class="small text-muted"><?= h(Budgets::KINDS[$b['kind']]) ?></div><div class="fw-semibold"><?= h(Budgets::yearLabel($b)) ?></div></div>
      <?php if ($b['class_id']): ?><div><div class="small text-muted">Class</div><div><?= h(Classes::name($b['class_id'])) ?></div></div><?php endif; ?>
      <?php if ($isForecast): ?><div><div class="small text-muted">Customer</div><div><?= h($b['customer_id'] ? (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $b['customer_id']]) : 'All customers') ?></div></div><?php endif; ?>
      <div class="flex-grow-1" style="min-width:220px"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="notes" value="<?= h($b['notes']) ?>" maxlength="255"></div>
      <div class="d-flex gap-1">
        <div><label class="form-label">Fill a row</label>
          <div class="input-group input-group-sm"><input class="form-control text-end" id="bFillAmt" placeholder="amount" inputmode="decimal" style="width:110px">
            <button class="btn btn-outline-secondary" type="button" id="bFillRow" title="Put this amount in every month of the row the cursor is in">Across</button></div></div>
        <div><label class="form-label">Yearly total</label>
          <div class="input-group input-group-sm"><input class="form-control text-end" id="bSpreadAmt" placeholder="amount" inputmode="decimal" style="width:120px">
            <button class="btn btn-outline-secondary" type="button" id="bSpread" title="Divide this yearly amount evenly over the twelve months of the row the cursor is in">Spread</button></div></div>
      </div>
    </div></div>

    <?php foreach ($customers as $custId => $custName): $custId = (int)$custId; ?>
      <?php if ($isForecast): ?><div class="fw-semibold mt-3 mb-1"><?= h($custName) ?></div><?php endif; ?>
      <div class="card mb-2"><div class="table-responsive"><table class="table table-sm table-tight budget-grid mb-0">
        <thead><tr><th style="min-width:230px">Account</th>
          <?php foreach ($periods as $p): ?><th class="num"><?= h($p['short']) ?><div class="small text-muted fw-normal"><?= h(substr($p['label'], -4)) ?></div></th><?php endforeach; ?>
          <th class="num">Year</th></tr></thead>
        <tbody>
        <?php $lastType = null; foreach ($accounts as $ac): ?>
          <?php if ($ac['account_type'] !== $lastType): $lastType = $ac['account_type']; ?>
            <tr class="table-light"><td colspan="14" class="fw-semibold small"><?= h($ac['account_type']) ?></td></tr>
          <?php endif; ?>
          <tr data-row><td class="small"><?= h($ac['code'] . ' - ' . $ac['name']) ?></td>
            <?php $rowTotal = 0.0; foreach ($periods as $p): $v = $lines[(int)$ac['id']][$custId][$p['key']] ?? null; $rowTotal += (float)$v; ?>
              <td><input class="form-control form-control-sm text-end budget-cell" inputmode="decimal"
                  name="amt[<?= (int)$ac['id'] ?>][<?= $custId ?>][<?= (int)$p['key'] ?>]"
                  value="<?= $v !== null && (float)$v != 0.0 ? h(number_format((float)$v, 2, '.', '')) : '' ?>"></td>
            <?php endforeach; ?>
            <td class="num fw-semibold" data-row-total><?= $rowTotal != 0.0 ? money($rowTotal) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold"><td class="text-end">Total</td>
          <?php foreach ($periods as $p): ?><td class="num" data-col-total="<?= (int)$p['key'] ?>">0.00</td><?php endforeach; ?>
          <td class="num" data-grand-total>0.00</td></tr></tfoot>
      </table></div></div>
    <?php endforeach; ?>
    <div class="sticky-actions"><?= save_buttons('Save the amounts', url($pageKey), false) ?></div>
    </form>
    <script>
    (function () {
      const { num, fmt, r2 } = window.AppFmt;
      const form = document.getElementById('budgetForm');
      function paint() {
        form.querySelectorAll('table.budget-grid').forEach((tbl) => {
          const cols = {}; let grand = 0;
          tbl.querySelectorAll('tbody tr[data-row]').forEach((tr) => {
            let rt = 0;
            tr.querySelectorAll('.budget-cell').forEach((i) => {
              const v = num(i.value); rt += v;
              const m = i.name.match(/\\[(\\d+)\\]$/); if (m) cols[m[1]] = (cols[m[1]] || 0) + v;
            });
            rt = r2(rt); grand += rt;
            tr.querySelector('[data-row-total]').textContent = rt ? fmt(rt) : '';
          });
          tbl.querySelectorAll('[data-col-total]').forEach((td) => { td.textContent = fmt(r2(cols[td.dataset.colTotal] || 0)); });
          tbl.querySelector('[data-grand-total]').textContent = fmt(r2(grand));
        });
      }
      let lastCell = null;
      form.addEventListener('focusin', (e) => { if (e.target.classList.contains('budget-cell')) lastCell = e.target; });
      form.addEventListener('input', paint);
      document.getElementById('bFillRow').addEventListener('click', () => {
        if (!lastCell) return;
        const v = num(document.getElementById('bFillAmt').value);
        lastCell.closest('tr').querySelectorAll('.budget-cell').forEach((i) => { i.value = v ? v.toFixed(2) : ''; });
        paint();
      });
      document.getElementById('bSpread').addEventListener('click', () => {
        if (!lastCell) return;
        const total = num(document.getElementById('bSpreadAmt').value);
        const cells = lastCell.closest('tr').querySelectorAll('.budget-cell');
        const each = r2(total / cells.length);
        cells.forEach((i, n) => { i.value = total ? (n === cells.length - 1 ? r2(total - each * (cells.length - 1)) : each).toFixed(2) : ''; });
        paint();
      });
      paint();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$rows = [];
foreach (array_keys($kinds) as $kind) {
    $rows = array_merge($rows, Budgets::all($kind));
}
layout_start($title, ['actions' => page_link($pageKey, ['a' => 'new'], $isForecast ? 'New forecast' : 'New budget', 'btn-primary', 'plus-lg', 'create')
    . ' ' . ($isForecast ? page_link('rpt.forecast_vs_actual', [], 'Forecast vs actual', 'btn-outline-secondary', 'bar-chart')
        : page_link('rpt.budget_income', [], 'Income statement vs budget', 'btn-outline-secondary', 'bar-chart'))]);
?>
<p class="text-muted small"><?= $isForecast
    ? 'A forecast holds the sales you expect from a customer, month by month, for one fiscal year. Put it beside what actually happened with the Forecast vs Actual report.'
    : 'A budget holds the amounts you plan for each account, month by month, for one fiscal year. The "vs Budget" reports put the actual figures beside them and show the difference.' ?></p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Name</th><th>What it budgets</th><th>Fiscal year</th><?= $isForecast ? '<th>Customer</th>' : '' ?><th>Class</th><th class="num">Yearly total</th><th>Notes</th><th></th></tr></thead>
  <?php foreach ($rows as $r): $total = (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM budget_lines WHERE budget_id = :b', ['b' => $r['id']]); ?>
    <tr><td class="fw-semibold"><a href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><?= h($r['name']) ?></a></td>
      <td class="small"><?= h(Budgets::KINDS[$r['kind']] ?? '') ?></td><td><?= h(Budgets::yearLabel($r)) ?></td>
      <?= $isForecast ? '<td class="small">' . h($r['customer_name'] ?: 'All customers') . '</td>' : '' ?>
      <td class="small"><?= h($r['class_name']) ?></td><td class="num"><?= money($total, true) ?></td><td class="small"><?= h($r['notes']) ?></td>
      <td class="text-nowrap text-end">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><i class="bi bi-pencil"></i> Amounts</a>
        <?php if (Access::can($pageKey, 'delete')): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-danger" data-confirm="Delete &quot;<?= h($r['name']) ?>&quot; and its amounts?"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted py-3">Nothing set up yet.</td></tr><?php endif; ?>
</table></div></div>
<?php
layout_end();
