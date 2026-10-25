<?php
declare(strict_types=1);

/**
 * Report Center (Home > Report Center): every report the user may open, one card per module, and the
 * memorized reports (their own and the ones shared with them).
 *
 * v29: a module's card can carry a name of its own (registry 'report_center_names'): the reports of the
 * Financial Set-up & Reports menu are listed under "Financial Reports". A search box narrows the list as you type.
 */

$pageKey = 'home.reports';
$uid = (int)Auth::id();

// ---------------------------------------------------------------- memorized reports: own and shared, that may be opened
$memorized = [];
if (Access::page('home.memorized')) {
    foreach (DB::all('SELECT * FROM memorized_reports WHERE created_by = :u OR is_shared = 1 ORDER BY group_name IS NULL, group_name, name', ['u' => $uid]) as $m) {
        if (!Access::pageDef($m['report_key']) || !Access::page($m['report_key'])) {
            continue;
        }
        parse_str((string)$m['query_string'], $q);
        unset($q['p']);
        $m['href'] = url($m['report_key'], $q);
        $memorized[(string)($m['group_name'] ?? '')][] = $m;
    }
}

// ---------------------------------------------------------------- the reports of each module, in menu order
$cards = [];
$count = 0;
foreach (Access::allPageDefs() as $key => $d) {
    if ($d['type'] !== 'report' || (!$d['menu'] && $key !== 'rpt.find') || !Access::page($key)) {
        continue;
    }
    // the module's own "Reports" heading is the card itself; other groups get a heading of their own
    $group = $d['group'] === 'Reports' ? '' : $d['group'];
    $cards[$d['module']][$group][] = ['key' => $key, 'name' => $d['name']];
    $count++;
}
$order = array_keys(Access::modules());
uksort($cards, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

layout_start('Report Center', ['actions' => page_link('home.memorized', [], 'Memorized Reports', 'btn-outline-secondary', 'bookmark')]);
?>
<div class="rc-intro">
  <p class="text-muted mb-0">Every report can be filtered by date and other fields, customized (columns and sorting), drilled into, memorized, printed or saved as PDF, exported to a spreadsheet, and emailed. You only see the reports your role and page access allow.</p>
  <div class="rc-search">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input class="form-control form-control-sm" type="search" id="rcSearch" placeholder="Find a report by name" aria-label="Find a report by name" autocomplete="off">
    <div class="small text-muted rc-count" id="rcCount"><?= (int)$count ?> report<?= $count === 1 ? '' : 's' ?></div>
  </div>
</div>

<?php if ($memorized): ?>
<div class="card mb-3 rc-card rc-memorized" data-rc-card>
  <div class="card-header fw-semibold"><i class="bi bi-bookmark-star"></i> Memorized reports</div>
  <div class="card-body rc-mem-body">
    <?php foreach ($memorized as $grp => $list): ?>
    <div class="rc-group" data-rc-group>
      <?php if ($grp !== ''): ?><div class="rc-group-name"><?= h($grp) ?></div><?php endif; ?>
      <ul class="rc-list">
        <?php foreach ($list as $m): ?>
        <li data-rc-item data-rc-text="<?= h(mb_strtolower($grp . ' ' . $m['name'] . ' ' . (Access::pageDef($m['report_key'])['name'] ?? ''))) ?>">
          <a href="<?= h($m['href']) ?>"><?= h($m['name']) ?></a>
          <span class="small text-muted"><?= h(Access::pageDef($m['report_key'])['name'] ?? '') ?><?= (int)$m['created_by'] !== $uid ? ', shared' : '' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$cards): ?>
<div class="alert alert-info">Your role does not include any report. Ask your administrator for access.</div>
<?php endif; ?>

<div class="rc-grid" id="rcGrid">
  <?php foreach ($cards as $code => $groups): ?>
  <section class="card rc-card" data-rc-card aria-label="<?= h(Access::reportCenterName($code)) ?>">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-<?= h(Access::modules()[$code][1] ?? 'folder') ?>" aria-hidden="true"></i><?= h(Access::reportCenterName($code)) ?>
    </div>
    <div class="card-body">
      <?php foreach ($groups as $grp => $list): ?>
      <div class="rc-group" data-rc-group>
        <?php if ($grp !== ''): ?><div class="rc-group-name"><?= h($grp) ?></div><?php endif; ?>
        <ul class="rc-list">
          <?php foreach ($list as $r): ?>
          <li data-rc-item data-rc-text="<?= h(mb_strtolower($r['name'] . ' ' . $grp)) ?>"><a href="<?= h(url($r['key'])) ?>"><?= h($r['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<div class="alert alert-light border text-center small" id="rcNone" hidden>No report name has these words.</div>

<script>
(function () {
  const box = document.getElementById('rcSearch');
  const count = document.getElementById('rcCount');
  const none = document.getElementById('rcNone');
  if (!box) { return; }
  const total = <?= (int)$count ?>;
  box.addEventListener('input', function () {
    const words = box.value.toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    document.querySelectorAll('[data-rc-card]').forEach(function (card) {
      let inCard = 0;
      card.querySelectorAll('[data-rc-group]').forEach(function (g) {
        let inGroup = 0;
        g.querySelectorAll('[data-rc-item]').forEach(function (li) {
          const ok = words.every((w) => li.dataset.rcText.includes(w));
          li.hidden = !ok;
          if (ok) { inGroup++; if (!card.classList.contains('rc-memorized')) { shown++; } }
        });
        g.hidden = inGroup === 0;
        inCard += inGroup;
      });
      card.hidden = inCard === 0;
    });
    count.textContent = words.length ? shown + ' of ' + total + ' report' + (total === 1 ? '' : 's') : total + ' report' + (total === 1 ? '' : 's');
    none.hidden = document.querySelectorAll('[data-rc-card]:not([hidden])').length > 0;
  });
})();
</script>
<?php
layout_end();
