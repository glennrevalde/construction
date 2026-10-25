<?php
declare(strict_types=1);

$def = Report::get($PARAMS['report']);
if (!$def) {
    layout_start('Report not found');
    echo '<p>This report is not available.</p>';
    layout_end();
    return;
}
Report::render($PARAMS['report'], $def, $PAGE);
