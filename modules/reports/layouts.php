<?php
declare(strict_types=1);

/**
 * Saved report layouts.
 *
 * The Modify Report panel on every report posts here to save or delete a layout; opening the page on its
 * own lists every layout saved so far, so old ones can be tidied up or made the default from one place.
 */

$pageKey = 'home.report_layouts';

handle_post(function () use ($pageKey) {
    $reportKey = (string)p('report_key');
    $back = $reportKey !== '' ? url($reportKey) : url($pageKey);
    $qs = trim((string)p('query_string'));

    if (p('do') === 'delete') {
        ReportLayout::delete(pint('id') ?: 0);
        Audit::log('delete', 'home', 'report_layout', pint('id'), (string)p('name'), ['page_key' => $pageKey]);
        flash('success', 'Layout deleted.');
        redirect($back);
    }

    if (p('do') === 'default') {
        if (!ReportLayout::canDesign()) {
            fail('Choosing the layout a report opens with needs the "Can design report layouts" permission.');
        }
        $id = pint('id') ?: 0;
        $l = ReportLayout::load($id);
        if (!$l) {
            fail('That layout is no longer there.');
        }
        DB::tx(function () use ($l, $id) {
            DB::run('UPDATE report_layouts SET is_default = 0 WHERE report_key = :k AND (created_by = :u OR is_shared = 1)',
                ['k' => $l['report_key'], 'u' => Auth::id()]);
            DB::run('UPDATE report_layouts SET is_default = 1 WHERE id = :id', ['id' => $id]);
        });
        flash('success', '"' . $l['name'] . '" now opens by default.');
        redirect(url($pageKey));
    }

    if (p('do') !== 'save') {
        return;
    }

    // the page key ('rpt.trial_balance') and the report's own key ('trial_balance') are not the same thing
    $page = Access::pageDef($reportKey);
    $def = $page ? Report::get((string)($page['params']['report'] ?? '')) : null;
    if (!$def) {
        fail('That report could not be found.');
    }

    // read the choices back out of the address the Modify Report panel was showing
    parse_str($qs, $get);
    $keep = $_GET;
    $_GET = $get + ['p' => $reportKey];
    try {
        // every column the report can print: its own and the related fields the Modify Report panel offers
        [, , $cols] = Report::prepare($def, Report::filters($def));
        $current = ReportLayout::current($reportKey);
    } finally {
        $_GET = $keep;
    }

    $id = ReportLayout::save($reportKey, (string)p('name'), $cols, $current,
        p('is_shared') === '1', p('is_default') === '1', pint('replace') ?: null);
    Audit::log(pint('replace') ? 'edit' : 'create', 'home', 'report_layout', $id, (string)p('name'),
        ['page_key' => $pageKey, 'snapshot' => ['report' => $reportKey]]);
    flash('success', 'Layout "' . trim((string)p('name')) . '" saved. It is on the Modify Report panel of that report.');
    redirect(url($reportKey, ['layout' => $id] + array_diff_key($get, array_flip(['p', 'hide', 'opt', 'head', 'order', 'layout', 'print', 'export', 'cw', 'ca', 'design']))));
});

$rows = DB::all('SELECT l.*, u.username FROM report_layouts l LEFT JOIN users u ON u.id = l.created_by
                  WHERE l.created_by = :u OR l.is_shared = 1
                  ORDER BY l.report_key, l.name', ['u' => Auth::id()]);

layout_start('Saved Report Layouts');
?>
<p class="text-muted">A layout holds everything set on the Modify Report panel of a report: the columns and the rows it prints,
  the heading and the foot, the fonts, the figures and the printed page. Layouts are made on the report itself: open any
  report and press <strong>Design</strong>. This page is for keeping them tidy.</p>

<div class="card">
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Report</th><th>Layout</th><th>Columns</th><th>Owner</th><th></th><th style="width:1%"></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">No layouts saved yet. Open a report and press Design.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $l):
        $page = Access::pageDef($l['report_key']);
        $shown = 0;
        $total = 0;
        foreach (explode("\n", (string)$l['columns']) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $total++;
            if (!str_starts_with(trim($line), '-')) {
                $shown++;
            }
        }
    ?>
      <tr>
        <td><?= $page ? page_link($l['report_key'], ['layout' => (int)$l['id']], $page['name'], 'btn-link p-0 align-baseline') : h($l['report_key']) ?></td>
        <td><?= h($l['name']) ?><?= $l['title'] ? '<div class="small text-muted">' . h($l['title']) . '</div>' : '' ?></td>
        <td class="small text-muted"><?= $total ? $shown . ' of ' . $total . ' shown' : 'standard' ?></td>
        <td class="small"><?= h((string)($l['username'] ?? '')) ?><?= (int)$l['is_shared'] ? ' ' . badge('shared', 'info') : '' ?></td>
        <td><?= (int)$l['is_default'] ? badge('opens by default', 'success') : '' ?></td>
        <td class="text-nowrap text-end">
          <?php if (!(int)$l['is_default'] && ReportLayout::canDesign()): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" name="do" value="default" title="Open this report with this layout">Make default</button>
            </form>
          <?php endif; ?>
          <?php if ((int)$l['created_by'] === Auth::id() || Access::isAdmin()): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <input type="hidden" name="name" value="<?= h($l['name']) ?>">
              <button class="btn btn-sm btn-outline-danger" name="do" value="delete"
                data-confirm="Delete the layout &quot;<?= h($l['name']) ?>&quot;?"><i class="bi bi-trash"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
layout_end();
