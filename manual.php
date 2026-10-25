<?php
declare(strict_types=1);

/**
 * The User's Manual.
 *
 *   manual.php               the contents
 *   manual.php?c=customers   a chapter (with #section to go straight to a section)
 *   manual.php?a=search      the search list (JSON) of the chapters the reader may open
 *
 * The chapters are part of the program (views/manual); no database
 * table is involved. See app/Manual.php for who may read what.
 */
require __DIR__ . '/app/bootstrap.php';

$reader = Manual::reader();

if (($_GET['a'] ?? '') === 'search') {
    header('Cache-Control: private, max-age=300');
    json_out(Manual::searchData($reader));
}

$chapters = Manual::chapters();
$slug = (string)($_GET['c'] ?? '');
$chapter = $slug !== '' ? Manual::chapter($slug) : null;
if ($slug !== '' && !$chapter) {
    redirect(Manual::url());
}
$canRead = $chapter ? Manual::canRead($chapter, $reader) : true;
$html = $chapter && $canRead ? Manual::chapterHtml($chapter) : '';

// the neighbours of this chapter, for the buttons at the end
$prev = $next = null;
if ($chapter) {
    foreach ($chapters as $i => $c) {
        if ($c['slug'] === $chapter['slug']) {
            $prev = $chapters[$i - 1] ?? null;
            $next = $chapters[$i + 1] ?? null;
        }
    }
}

$app = app_name();
$back = match ($reader) {
    'user' => [url('home.dashboard'), 'Back to the program', 'arrow-left'],
    'platform' => [purl(), 'Back to the platform', 'arrow-left'],
    default => [base_url() . '/login.php', 'Log in', 'box-arrow-in-right'],
};
$title = $chapter ? $chapter['num'] . '. ' . $chapter['title'] : 'User\'s Manual';
$searchUrl = base_url() . '/manual.php?a=search';

/** One search box (the top bar has one, the menu of a phone another) */
function manual_search(string $id, string $class = ''): string
{
    return '<div class="man-search ' . h($class) . '" role="search"><i class="bi bi-search"></i>'
        . '<input class="form-control form-control-sm" type="search" id="' . h($id) . '" placeholder="Search the manual" autocomplete="off" aria-label="Search the manual">'
        . '<div class="man-results" hidden></div></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - User's Manual - <?= h($app) ?></title>
<meta name="theme-color" content="#14263a">
<link rel="stylesheet" href="<?= h(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/theme.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/manual.css')) ?>">
</head>
<body class="manual" data-search="<?= h($searchUrl) ?>">
<nav class="navbar navbar-dark bg-dark app-bar sticky-top px-3 no-print">
  <div class="d-flex align-items-center gap-2 min-w-0">
    <button class="btn btn-sm btn-user d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Contents"><i class="bi bi-list"></i></button>
    <a class="navbar-brand man-brand" href="<?= h(Manual::url()) ?>"><span class="gs-mark is-small" aria-hidden="true"><span>GS</span></span><span class="d-none d-sm-inline"><?= h($app) ?></span></a>
    <span class="company">User's Manual</span>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?= manual_search('manSearchTop', 'd-none d-md-block man-search-bar') ?>
    <a class="btn btn-sm btn-user" href="<?= h($back[0]) ?>"><i class="bi bi-<?= h($back[2]) ?>"></i><span class="d-none d-sm-inline ms-1"><?= h($back[1]) ?></span></a>
  </div>
</nav>
<div class="d-flex app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar bg-body-tertiary border-end no-print man-side" id="sidebar" tabindex="-1">
    <div class="offcanvas-header d-lg-none"><h5 class="offcanvas-title">Contents</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button></div>
    <div class="offcanvas-body d-block p-0 pt-2">
      <div class="px-3 pb-2 d-md-none"><?= manual_search('manSearchSide') ?></div>
      <a class="menu-link <?= $chapter ? '' : 'active' ?>" href="<?= h(Manual::url()) ?>"><i class="bi bi-book me-2"></i>Contents</a>
      <div class="man-group">Chapters</div>
      <?php foreach ($chapters as $c): $here = $chapter && $c['slug'] === $chapter['slug']; ?>
        <a class="menu-link man-ch <?= $here ? 'active' : '' ?>" href="<?= h(Manual::url($c['slug'])) ?>">
          <span class="man-ch-no"><?= (int)$c['num'] ?></span><span class="man-ch-t"><?= h($c['title']) ?></span>
          <?php if (!Manual::canRead($c, $reader)): ?><i class="bi bi-lock man-lock" title="Log in to read this chapter"></i><?php endif; ?>
        </a>
        <?php if ($here && $canRead): ?>
          <div class="man-secs">
            <?php foreach ($c['sections'] as $s): if ((int)$s['l'] !== 2) { continue; } ?>
              <a class="man-sec" href="#<?= h($s['id']) ?>" data-sec="<?= h($s['id']) ?>"><?= h($s['t']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </aside>

  <main class="flex-grow-1 p-3 p-lg-4 main-area man-main">
    <div class="page-bar">
      <h1><?= h($title) ?></h1>
      <div class="page-actions no-print">
        <?php if ($chapter && $canRead): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print this chapter</button>
        <?php endif; ?>
        <?php if ($chapter): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(Manual::url()) ?>"><i class="bi bi-book"></i> Contents</a>
        <?php endif; ?>
      </div>
    </div>

<?php if (!Manual::available()): ?>
    <div class="alert alert-warning">The manual's files are missing from this installation (views/manual).</div>

<?php elseif (!$chapter): ?>
    <div class="man-home">
      <p class="man-lead">This manual explains every page of <?= h($app) ?>: what it is for, every box on its forms, how to do each task step by step,
        and what the program records in the books. Every page of the program has a <strong>Help</strong> button that opens the manual at the section for that page.</p>
      <?= manual_search('manSearchHome', 'man-search-big') ?>
      <?php if (!$reader): ?>
        <div class="man-callout man-note mt-3"><div class="man-callout-t"><i class="bi bi-info-circle"></i>Note</div>
          <p>Chapters <?= h(implode(' and ', array_map(fn($c) => (string)$c['num'], array_values(array_filter($chapters, fn($c) => !empty($c['public'])))))) ?> can be read before you log in. <a href="<?= h(base_url() . '/login.php') ?>">Log in</a> to read the rest of the manual.</p></div>
      <?php endif; ?>
      <div class="row g-3 mt-1">
        <?php foreach ($chapters as $c): $open = Manual::canRead($c, $reader); ?>
          <div class="col-md-6 col-xxl-4">
            <a class="card man-card h-100 <?= $open ? '' : 'is-locked' ?>" href="<?= h(Manual::url($c['slug'])) ?>">
              <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                  <span class="man-card-no"><?= (int)$c['num'] ?></span>
                  <div class="min-w-0">
                    <div class="man-card-t"><?= h($c['title']) ?></div>
                    <p class="man-card-x"><?= h($c['blurb']) ?></p>
                    <div class="man-card-f"><?php if ($open): ?><?= count(array_filter($c['sections'], fn($s) => (int)$s['l'] === 2)) ?> sections<?php else: ?><i class="bi bi-lock"></i> Log in to read<?php endif; ?></div>
                  </div>
                </div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

<?php elseif (!$canRead): ?>
    <div class="card man-locked"><div class="card-body text-center py-5">
      <i class="bi bi-lock man-locked-i"></i>
      <p class="mb-3">This chapter is for the users of the program. Log in, then open the manual again from any page's <strong>Help</strong> button or from your user menu.</p>
      <a class="btn btn-primary" href="<?= h(base_url() . '/login.php') ?>"><i class="bi bi-box-arrow-in-right"></i> Log in</a>
      <a class="btn btn-outline-secondary" href="<?= h(Manual::url()) ?>">Contents</a>
    </div></div>

<?php else: ?>
    <div class="man-wrap">
      <article class="man-doc" id="manDoc">
        <div class="man-print-head"><?= h($app) ?> User's Manual</div>
        <?= $html /* made by the manual's build from the manual's own text */ ?>
        <nav class="man-pager no-print" aria-label="Chapters">
          <?php if ($prev): ?><a class="man-pg man-pg-prev" href="<?= h(Manual::url($prev['slug'])) ?>"><span>Previous chapter</span><strong><?= (int)$prev['num'] ?>. <?= h($prev['title']) ?></strong></a><?php else: ?><span></span><?php endif; ?>
          <?php if ($next): ?><a class="man-pg man-pg-next" href="<?= h(Manual::url($next['slug'])) ?>"><span>Next chapter</span><strong><?= (int)$next['num'] ?>. <?= h($next['title']) ?></strong></a><?php endif; ?>
        </nav>
      </article>
      <nav class="man-onpage no-print" aria-label="On this page">
        <div class="man-onpage-t">On this page</div>
        <?php foreach ($chapter['sections'] as $s): if ((int)$s['l'] !== 2) { continue; } ?>
          <a href="#<?= h($s['id']) ?>" data-sec="<?= h($s['id']) ?>"><?= h($s['t']) ?></a>
        <?php endforeach; ?>
        <a class="man-top" href="#"><i class="bi bi-arrow-up"></i> Back to top</a>
      </nav>
    </div>
<?php endif; ?>
  </main>
</div>

<div class="modal fade man-zoom-modal" id="manZoom" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
    <div class="modal-header py-2"><div class="modal-title small text-muted" id="manZoomT"></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-2 text-center"><img id="manZoomImg" alt=""></div>
  </div></div>
</div>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/manual.js')) ?>"></script>
</body>
</html>
