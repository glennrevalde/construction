<?php
declare(strict_types=1);

$pageKey = 'admin.settings';
$groups = [
    'Company' => [
        'company_name' => ['Company name', 'text'], 'company_address' => ['Address', 'text'], 'company_tin' => ['TIN', 'text'],
        'company_phone' => ['Phone', 'text'], 'company_email' => ['Email', 'text'], 'fiscal_year_start_month' => ['Fiscal year starts (month 1-12)', 'int'],
    ],
    'Closing date' => [
        'closing_date' => ['Books closed through (blank = open)', 'date'],
    ],
    'Security policy' => [
        'pw_min_length' => ['Minimum password length', 'int'], 'pw_require_upper' => ['Require upper- and lower-case letters', 'bool'],
        'pw_require_number' => ['Require a number', 'bool'], 'pw_require_symbol' => ['Require a symbol', 'bool'],
        'pw_expiry_days' => ['Passwords expire after (days, 0 = never)', 'int'], 'max_failed_logins' => ['Failed logins before lockout', 'int'],
        'session_timeout_minutes' => ['Log out after inactivity (minutes)', 'int'],
    ],
    'Audit trail' => [
        'audit_retention_years' => ['Keep audit trail entries for at least (years)', 'int'],
    ],
    'Finance charges' => [
        'finance_charge_rate' => ['Annual interest rate %', 'num'], 'finance_charge_min' => ['Minimum finance charge', 'num'],
        'finance_charge_grace_days' => ['Grace period (days)', 'int'], 'finance_charge_on_charges' => ['Charge interest on earlier finance charges', 'bool'],
    ],
    'Inventory and POS' => [
        'track_group_sales' => ['Track Item Group Sales transactions', 'bool'],
        'adopt_group_price' => ['Adopt the Group Price in the Item Group', 'bool'],
        'allow_negative_stock' => ['Allow selling or moving stock below zero (with warning)', 'bool'],
        'block_zero_cost_sales' => ['Block sales of items with no unit cost (inventory parts and the items of item groups)', 'bool'],
        'block_zero_cost_processing' => ['Block posting processing orders with no unit cost (inputs, co-products and by-products)', 'bool'],
    ],
    'POS receipt wording' => [
        'pos_receipt_title'         => ['Receipt title for sales', 'text'],
        'pos_receipt_title_return'  => ['Receipt title for returns and refunds', 'text'],
        'pos_receipt_title_charge'  => ['Receipt title for sales charged to an account', 'text'],
        'pos_receipt_title_payment' => ['Receipt title for payments on account', 'text'],
        'pos_receipt_footer'        => ['Text at the bottom of the receipt (one line of text per line)', 'textarea'],
        'pos_receipt_show_customer' => ['Print "Walk-in customer" when the sale has no customer name', 'bool'],
    ],
    'Bluetooth receipt printer' => [
        'bt_printer_enabled'   => ['Offer a Bluetooth printer on every receipt', 'bool'],
        'bt_printer_width'     => ['Paper width', 'select'],
        'bt_printer_name'      => ['Only offer printers whose name starts with (blank = offer every device found)', 'text'],
        'bt_printer_feed'      => ['Blank lines fed after each receipt', 'int'],
        'bt_printer_cut'       => ['Cut the paper at the end (printers with a cutter only)', 'bool'],
        'bt_printer_autoprint' => ['Print as soon as a receipt opens, when the printer is already connected', 'bool'],
    ],
];

// choices of the 'select' settings
$choices = ['bt_printer_width' => Receipt::WIDTHS];

handle_post(function () use ($groups, $pageKey, $choices) {
    Access::require($pageKey, 'edit');
    $old = Settings::all();
    $changes = [];
    // with several companies, the platform lists every company by name: two companies cannot share one
    $newName = mb_substr(trim((string)p('company_name')), 0, 255);
    if (Tenant::isMulti() && $newName !== '' && $newName !== (string)$old['company_name']
        && Master::sql()->value('SELECT id FROM companies WHERE name = :n AND id <> :id', ['n' => $newName, 'id' => (int)Tenant::id()])) {
        fail('Another company on this platform already has the name ' . $newName . '.');
    }
    foreach ($groups as $fields) {
        foreach ($fields as $k => [$label, $type]) {
            switch ($type) {
                case 'bool':
                    $v = p($k, '0') === '1' ? '1' : '0';
                    break;
                case 'select':
                    $v = isset($choices[$k][p($k)]) ? (string)p($k) : (string)array_key_first($choices[$k]);
                    break;
                case 'int':
                    $v = (string)max(0, (int)p($k, '0'));
                    break;
                case 'num':
                    $v = (string)pnum($k);
                    break;
                case 'date':
                    $v = (string)p($k);
                    if ($v !== '' && !valid_date($v)) {
                        fail("$label: enter a valid date.");
                    }
                    break;
                case 'textarea':
                    $v = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST[$k] ?? ''))), 0, 600);
                    break;
                default:
                    $v = mb_substr(trim((string)p($k)), 0, 255);
            }
            if ($k === 'pw_min_length' && (int)$v < 6) {
                fail('Passwords must be at least 6 characters.');
            }
            if ($k === 'max_failed_logins' && (int)$v < 1) {
                fail('Allow at least one failed login.');
            }
            if ($k === 'session_timeout_minutes' && (int)$v < 1) {
                fail('The session timeout must be at least one minute.');
            }
            if ((string)($old[$k] ?? '') !== $v) {
                Settings::set($k, $v);
                $changes[] = ['field' => $label, 'old' => $old[$k] ?? '', 'new' => $v];
                if ($k === 'closing_date') {
                    Audit::log('closing_date_change', 'admin', 'settings', null, 'Closing date', [
                        'changes' => [['field' => 'closing_date', 'old' => $old[$k] ?? '', 'new' => $v]], 'reason' => p('reason') ?: null, 'page_key' => $pageKey,
                    ]);
                }
            }
        }
    }
    // searching for items: the overall choice, and the transactions that were set differently
    if (p('item_search_posted') === '1') {
        $oldOverall = (string)($old['item_search_format'] ?? 'code_name');
        $oldMap = Settings::itemFormatMap();
        $overall = isset(Settings::ITEM_FORMATS[p('item_search_format')]) ? (string)p('item_search_format') : 'code_name';
        $byPage = [];
        foreach (Settings::ITEM_SEARCH_PAGES as $key => $name) {
            $v = (string)($_POST['item_search'][$key] ?? '');
            $byPage[$key] = isset(Settings::ITEM_FORMATS[$v]) ? $v : $overall;
        }
        Settings::saveItemFormats($overall, $byPage);
        if ($oldOverall !== $overall) {
            $changes[] = ['field' => 'Searching for items (all transactions)',
                'old' => Settings::ITEM_FORMATS[$oldOverall] ?? $oldOverall, 'new' => Settings::ITEM_FORMATS[$overall]];
        }
        foreach (Settings::ITEM_SEARCH_PAGES as $key => $name) {
            $was = $oldMap[$key] ?? $oldOverall;
            if ($was !== $byPage[$key]) {
                $changes[] = ['field' => 'Searching for items: ' . $name,
                    'old' => Settings::ITEM_FORMATS[$was] ?? $was, 'new' => Settings::ITEM_FORMATS[$byPage[$key]]];
            }
        }
    }

    // default site and class for POS transactions, one row per user
    if (p('pos_defaults_posted') === '1') {
        $changes = array_merge($changes, Pos::saveDefaults((array)($_POST['pos_site'] ?? []), (array)($_POST['pos_class'] ?? [])));
    }

    // company logo: upload / remove, and the reports and vouchers it is printed on
    if (p('logo_remove') === '1' && ($old['company_logo'] ?? '') !== '') {
        Logo::remove();
        Settings::set('company_logo', '');
        $changes[] = ['field' => 'Company logo', 'old' => $old['company_logo'], 'new' => '(removed)'];
    } elseif (isset($_FILES['company_logo_file']) && (int)$_FILES['company_logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $name = Logo::store($_FILES['company_logo_file']);
        Settings::set('company_logo', $name);
        $changes[] = ['field' => 'Company logo', 'old' => $old['company_logo'] ?? '', 'new' => $name];
    }
    if (p('logo_targets_posted') === '1') {
        $valid = array_flip(Logo::allKeys());
        $picked = array_values(array_filter(array_map('strval', (array)($_POST['logo_targets'] ?? [])), fn($k) => isset($valid[$k])));
        $v = implode("\n", $picked);
        if ((string)($old['logo_targets'] ?? '') !== $v) {
            Settings::set('logo_targets', $v);
            $changes[] = ['field' => 'Logo printed on', 'old' => substr_count((string)($old['logo_targets'] ?? ''), "\n") + ((string)($old['logo_targets'] ?? '') !== '' ? 1 : 0) . ' report(s) / voucher(s)', 'new' => count($picked) . ' report(s) / voucher(s)'];
        }
    }
    if ($changes) {
        Audit::log('settings_change', 'admin', 'settings', null, 'Company and security settings', ['changes' => $changes, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
    }
    // the platform's record of the company follows its name, address, phone, e-mail and TIN
    if (Tenant::isMulti() && Tenant::id()) {
        $now = Settings::all();
        Master::sql()->update('companies', [
            'name' => mb_substr((string)$now['company_name'], 0, 150) ?: Tenant::name(), 'address' => $now['company_address'] ?: null,
            'phone' => $now['company_phone'] ?: null, 'email' => $now['company_email'] ?: null, 'tin' => $now['company_tin'] ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int)Tenant::id()]);
        Master::forget((int)Tenant::id());
    }
    flash('success', count($changes) . ' setting(s) changed.');
    redirect(url($pageKey));
});

$s = Settings::all();
$canEdit = Access::can($pageKey, 'edit');
layout_start('Company, Security & Closing Date', ['js' => ['js/btprint.js']]);
?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<fieldset <?= $canEdit ? '' : 'disabled' ?>>
<div class="row g-3">
<?php foreach ($groups as $title => $fields): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold"><?= h($title) ?></div><div class="card-body row g-2">
    <?php foreach ($fields as $k => [$label, $type]): ?>
      <?php if ($type === 'bool'): ?><?= check_field($k, $label, $s[$k] === '1', 'col-12') ?>
      <?php elseif ($type === 'select'): ?><?= select_field($k, $label, $choices[$k], fv($k, $s), ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
      <?php elseif ($type === 'textarea'): ?><div class="col-12"><label class="form-label"><?= h($label) ?></label><textarea class="form-control form-control-sm" name="<?= h($k) ?>" rows="4" maxlength="600"><?= h(fv($k, $s)) ?></textarea></div>
      <?php else: ?><?= input_field($k, $label, fv($k, $s), ['type' => $type === 'date' ? 'date' : 'text', 'class' => $type === 'text' ? 'col-12' : 'col-md-6']) ?><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($title === 'Closing date'): ?>
      <div class="col-12 small text-muted">Users without the "closed-period changes" permission cannot record, change, void or delete transactions dated on or before this date. Every change of the closing date is recorded in the audit trail.</div>
    <?php elseif ($title === 'POS receipt wording'): ?>
      <div class="col-12 small text-muted">This is the wording printed on POS receipts. A title left blank goes back to the standard wording. The customer's name is printed under the date whenever the sale has one.</div>
    <?php elseif ($title === 'Bluetooth receipt printer'): ?>
      <div class="col-12">
        <div class="small text-muted">A receipt can be sent straight from the browser to a small Bluetooth roll printer, such as the
          Officom OC-PT210, with nothing installed on the phone or the till. The printer is paired once from the receipt itself:
          tap <strong>Bluetooth printer</strong>, pick it from the list the browser shows, and it is remembered for the next sale.</div>
        <div class="small text-muted mt-2">Two things are needed before the button can work, and they are the browser's rules, not the system's:
          the browser must be <strong>Chrome, Edge, Opera or Samsung Internet on Android</strong>, or Chrome or Edge on a computer
          (Firefox, the iPhone and iPad, and the browsers built into Facebook and Messenger cannot talk to Bluetooth at all); and the page
          must be opened on <strong>https://</strong> or on <strong>http://localhost/</strong>. Opened on a plain address such as
          http://192.168.1.10/ the browser refuses Bluetooth, and the receipt says so instead of failing quietly.</div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-bt-print data-bt-ops="#btTestReceipt"><i class="bi bi-bluetooth"></i> Test print</button>
          <span class="small text-muted">Prints a short sample so the paper width can be checked.</span>
        </div>
        <div class="mt-2" data-bt-status hidden></div>
        <script type="application/json" id="btPrinterOptions"><?= json_encode(Receipt::btOptions(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script type="application/json" id="btTestReceipt"><?= json_encode(Receipt::testOps(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      </div>
    <?php elseif ($title === 'Audit trail'): ?>
      <div class="col-12 small text-muted">Audit trail entries are read-only and cannot be switched off, edited or deleted — database triggers block changes even outside the application. Keep them at least as long as tax and accounting regulations require.</div>
    <?php endif; ?>
  </div></div></div>
<?php endforeach; ?>

  <?php
    // ---------------------------------------------------------------- searching for items
    $itemMap = Settings::itemFormatMap();
    $itemAll = Settings::itemFormat('');
    $itemFor = function (string $key) use ($itemMap, $itemAll) { return $itemMap[$key] ?? $itemAll; };
  ?>
  <div class="col-12"><div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span class="fw-semibold">Searching for items</span>
    <span class="small text-muted">Set each transaction on its own, or set them all at once</span>
  </div><div class="card-body">
    <input type="hidden" name="item_search_posted" value="1">
    <div class="row g-2 align-items-end mb-3">
      <div class="col-lg-6">
        <label class="form-label">All transactions, unless one is set differently below</label>
        <select class="form-select form-select-sm" name="item_search_format" id="itemSearchAll" data-no-combo>
          <?php foreach (Settings::ITEM_FORMATS as $k => $label): ?>
            <option value="<?= h($k) ?>"<?= $itemAll === $k ? ' selected' : '' ?>><?= h($label) ?><?= $k === 'code_name' ? '  (PORK CHOP - Pork chop, cut)' : '  (Pork chop, cut - PORK CHOP)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-6 d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-item-search-all="code_name">Set every transaction to Item name/code + Display name</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-item-search-all="name_code">Set every transaction to Display name + Item name/code</button>
      </div>
    </div>

    <div class="table-responsive item-search-grid">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr>
          <th style="width:42%">Transaction</th>
          <?php foreach (Settings::ITEM_FORMATS as $k => $label): ?>
            <th class="text-center"><?= h($label) ?>
              <div class="fw-normal small text-muted"><?= $k === 'code_name' ? 'PORK CHOP - Pork chop, cut' : 'Pork chop, cut - PORK CHOP' ?></div></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach (Settings::ITEM_SEARCH_PAGES as $key => $name): $cur = $itemFor($key); ?>
          <tr>
            <td><?= h($name) ?><?php if ($key === 'vend.receipts'): ?>
              <div class="small text-muted">Lists supplier documents, not items; the bill it opens follows Enter Bills.</div>
            <?php endif; ?></td>
            <?php foreach (Settings::ITEM_FORMATS as $k => $label): ?>
              <td class="text-center" data-label="<?= h($label) ?>">
                <input class="form-check-input item-search-pick" type="radio" name="item_search[<?= h($key) ?>]"
                       value="<?= h($k) ?>" <?= $cur === $k ? 'checked' : '' ?>
                       aria-label="<?= h($name . ': ' . $label) ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-muted mt-2">Typing any part of either the item name/code or the display name finds the item, whichever order is chosen.
      A transaction on the same choice as the box above follows it, so changing that box changes it too; one set the other way keeps its own choice and is marked in the list.</div>
  </div></div></div>

  <?php
    // ---------------------------------------------------------------- default site and class for POS
    $posUsers = Pos::defaultsReady() ? Pos::defaultRows() : [];
    $siteOpts = DB::pairs('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY name');
    $classOpts = Classes::ready() ? Classes::options() : [];
  ?>
  <?php if ($posUsers): ?>
  <div class="col-12"><div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span class="fw-semibold">Default site &amp; class for POS transactions</span>
    <input class="form-control form-control-sm" id="posDefFind" placeholder="Find a user" style="min-width:150px;max-width:220px">
  </div><div class="card-body">
    <input type="hidden" name="pos_defaults_posted" value="1">
    <div class="small text-muted mb-2">Optional, and set for one user at a time. Give a user a site and their POS Register
      opens on that site's counter. Give them a class and every new sale of theirs starts with that class already chosen.
      Anyone left blank works exactly as before: the counter is the first one they are allowed to use, and the class comes
      from the counter, or from the sale before it. The cashier can still change either one on the sale in front of them.</div>
    <div class="table-responsive pos-default-grid" data-no-combo>
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th style="width:40%">User name</th><th>Default site</th><th>Default class</th></tr></thead>
        <tbody>
        <?php foreach ($posUsers as $pu): ?>
          <tr>
            <td><div class="fw-semibold"><?= h($pu['full_name']) ?></div>
              <div class="small text-muted"><?= h($pu['username']) ?><?= $pu['role_names'] ? ', ' . h($pu['role_names']) : '' ?></div></td>
            <td data-label="Default site"><select class="form-select form-select-sm" name="pos_site[<?= (int)$pu['id'] ?>]"><?= options($siteOpts, $pu['pos_default_site_id'], 'No default') ?></select></td>
            <td data-label="Default class"><select class="form-select form-select-sm" name="pos_class[<?= (int)$pu['id'] ?>]"><?= options($classOpts, $pu['pos_default_class_id'], $classOpts ? 'No default' : 'No classes set up yet') ?></select></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div></div>
  <?php endif; ?>

  <?php
    // ---------------------------------------------------------------- company logo
    $logoUrl = Logo::url();
    $logoOn = array_flip(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($s['logo_targets'] ?? '')))));
    $logoGroups = Logo::targets();
  ?>
  <div class="col-12"><div class="card"><div class="card-header fw-semibold">Company logo</div><div class="card-body">
    <div class="row g-3 align-items-start">
      <div class="col-lg-4">
        <div class="logo-preview mb-2"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>" alt="Company logo" id="logoImg"><?php else: ?><span class="text-muted small" id="logoNone">No logo uploaded yet</span><img alt="" id="logoImg" style="display:none"><?php endif; ?></div>
        <label class="form-label"><?= $logoUrl ? 'Replace the logo' : 'Upload the logo' ?></label>
        <input class="form-control form-control-sm" type="file" name="company_logo_file" id="logoFile" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,image/*">
        <div class="form-text">JPG, JPEG, PNG, GIF, WEBP or BMP, up to 2 MB. A wide logo on a white or transparent background prints best.</div>
        <?php if ($logoUrl): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="logo_remove" value="1" id="logoRemove"><label class="form-check-label" for="logoRemove">Remove the logo</label></div><?php endif; ?>
      </div>
      <div class="col-lg-8">
        <input type="hidden" name="logo_targets_posted" value="1">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div><strong>Show the logo on</strong> <span class="text-muted small" id="logoCount"></span></div>
          <div class="d-flex flex-wrap gap-1"><input class="form-control form-control-sm flex-grow-1" id="logoFind" placeholder="Find a report or voucher" style="min-width:160px;max-width:220px">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-logo-all="1">Tick all</button><button type="button" class="btn btn-sm btn-outline-secondary" data-logo-all="0">Clear all</button></div>
        </div>
        <div class="logo-targets">
          <?php foreach ($logoGroups as $gName => $items): ?>
            <div class="logo-group"><div class="logo-group-head"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" data-logo-group> <span><?= h($gName) ?></span></label><span class="text-muted small"><?= count($items) ?></span></div>
              <div class="logo-group-items">
              <?php foreach ($items as $key => $label): ?>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="logo_targets[]" value="<?= h($key) ?>" <?= isset($logoOn[$key]) ? 'checked' : '' ?>> <span><?= h($label) ?></span></label>
              <?php endforeach; ?>
              </div></div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">The logo is printed beside the company name on the reports and vouchers ticked here, on screen, on Print / PDF and on the printed page.</div>
      </div>
    </div>
  </div></div></div>
</div>
<script>
(function () {
  const boxes = () => Array.from(document.querySelectorAll('input[name="logo_targets[]"]'));
  const count = () => { document.getElementById('logoCount').textContent = '(' + boxes().filter((b) => b.checked).length + ' of ' + boxes().length + ' ticked)';
    document.querySelectorAll('.logo-group').forEach((g) => { const bs = Array.from(g.querySelectorAll('input[name="logo_targets[]"]')); const on = bs.filter((b) => b.checked).length; const m = g.querySelector('[data-logo-group]'); m.checked = on === bs.length && on > 0; m.indeterminate = on > 0 && on < bs.length; }); };
  document.querySelectorAll('[data-logo-all]').forEach((b) => b.addEventListener('click', () => { boxes().forEach((x) => { if (x.closest('label').style.display !== 'none') x.checked = b.dataset.logoAll === '1'; }); count(); }));
  document.querySelectorAll('[data-logo-group]').forEach((m) => m.addEventListener('change', () => { m.closest('.logo-group').querySelectorAll('input[name="logo_targets[]"]').forEach((x) => { x.checked = m.checked; }); count(); }));
  document.querySelector('.logo-targets').addEventListener('change', count);
  document.getElementById('logoFind').addEventListener('input', function () { const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.logo-group').forEach((g) => { let any = false; g.querySelectorAll('.logo-group-items label').forEach((l) => { const hit = q === '' || l.textContent.toLowerCase().indexOf(q) >= 0; l.style.display = hit ? '' : 'none'; any = any || hit; }); g.style.display = any ? '' : 'none'; }); });
  document.getElementById('logoFile').addEventListener('change', function () { const f = this.files[0]; if (!f) return; const img = document.getElementById('logoImg'); img.src = URL.createObjectURL(f); img.style.display = ''; const n = document.getElementById('logoNone'); if (n) n.style.display = 'none'; });
  count();
})();

// ---------------------------------------------------------------- default site and class for POS
(function () {
  const find = document.getElementById('posDefFind');
  if (!find) { return; }
  find.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.pos-default-grid tbody tr').forEach((tr) => {
      tr.style.display = q === '' || tr.cells[0].textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
  });
  // the find box is inside the settings form: Enter must not save everything
  find.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); } });
})();

// ---------------------------------------------------------------- searching for items
(function () {
  const all = document.getElementById('itemSearchAll');
  if (!all) { return; }
  const rows = () => Array.from(document.querySelectorAll('.item-search-grid tbody tr'));
  const pick = (tr) => tr.querySelector('.item-search-pick:checked');

  function setEvery(value) {
    rows().forEach((tr) => {
      const box = tr.querySelector('.item-search-pick[value="' + value + '"]');
      if (box) { box.checked = true; }
    });
    mark();
  }

  // a transaction set differently from the overall choice is called out, so it is easy to see at a glance
  function mark() {
    rows().forEach((tr) => {
      const p = pick(tr);
      tr.classList.toggle('is-different', !!p && p.value !== all.value);
    });
  }

  document.querySelectorAll('[data-item-search-all]').forEach((b) => {
    b.addEventListener('click', () => {
      all.value = b.dataset.itemSearchAll;
      setEvery(all.value);
    });
  });
  // changing the overall choice moves every transaction that was following it
  let previous = all.value;
  all.addEventListener('change', () => {
    rows().forEach((tr) => {
      const p = pick(tr);
      if (p && p.value === previous) {
        const box = tr.querySelector('.item-search-pick[value="' + all.value + '"]');
        if (box) { box.checked = true; }
      }
    });
    previous = all.value;
    mark();
  });
  document.querySelector('.item-search-grid').addEventListener('change', mark);
  mark();
})();
</script>
<?php if ($canEdit): ?>
<div class="sticky-actions d-flex gap-2 mt-3"><input class="form-control form-control-sm" style="max-width:400px" name="reason" placeholder="Reason for the change (recorded in the audit trail)"><button class="btn btn-primary">Save settings</button></div>
<?php endif; ?>
</fieldset>
</form>
<?php
layout_end();
