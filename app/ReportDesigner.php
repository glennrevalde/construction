<?php
declare(strict_types=1);

/**
 * The Modify Report panel of every report, for the users allowed to design reports.
 *
 * Eight tabs: Columns, Rows, Heading, Fonts & look, Figures, Footer, Page and Layouts. Whatever only
 * changes how the report looks (fonts, sizes, colours, shading, lines, the words of the heading and the
 * foot, which lines show) is changed on the report while you watch. Whatever changes what is printed
 * (the columns, the rows, the figures, the page) is marked as waiting and applied with "Show it this way".
 *
 * Everything is a field of one GET form, so the designed report is in the address: it can be printed,
 * exported, e-mailed and memorized as it stands, and "Save this layout" stores the same choices.
 */
final class ReportDesigner
{
    /** The parts of the address that belong to the design, not to the report's own filters */
    private const DESIGN_KEYS = ['p', 'hide', 'opt', 'head', 'order', 'layout', 'print', 'export', 'cw', 'ca', 'sort', 'dir', 'design', 'show'];

    public static function panel(string $pageKey, array $cols, array $hidden, array $lay, string $title, array $rowInfo, array $saved): void
    {
        $o = $lay['options'];
        $order = array_column($cols, 'key');
        $current = $lay['layout'];
        $s = Settings::all();

        echo '<div class="collapse mb-3 no-print" id="rptCustomize"><div class="card rpt-designer">';
        echo '<form method="get" id="rptDesignForm" autocomplete="off">';
        echo '<input type="hidden" name="p" value="' . h($pageKey) . '">';
        // the report's own filters stay as they are
        $keep = array_diff_key($_GET, array_flip(self::DESIGN_KEYS));
        echo hidden_inputs($keep);
        echo '<input type="hidden" name="layout" value="' . ($current ? (int)$current['id'] : '0') . '">';
        echo '<input type="hidden" name="order" id="rptOrder" value="' . h(implode(',', $order)) . '">';
        echo '<input type="hidden" name="hide[]" value="">';
        echo '<div id="rptHidden"></div>';
        echo '<textarea name="opt[rows_hidden]" id="rptRowsHidden" hidden>' . h((string)$o['rows_hidden']) . '</textarea>';

        $tabs = ['columns' => ['Columns', 'layout-three-columns'], 'rows' => ['Rows', 'list-ul'], 'heading' => ['Heading', 'type-h1'],
            'look' => ['Fonts & look', 'fonts'], 'figures' => ['Figures', '123'], 'footer' => ['Footer', 'text-paragraph'],
            'page' => ['Page', 'file-earmark'], 'layouts' => ['Layouts', 'bookmark']];
        echo '<div class="card-header rpt-designer-head">'
            . '<ul class="nav nav-tabs card-header-tabs rpt-designer-tabs" role="tablist">';
        $first = true;
        foreach ($tabs as $k => [$label, $icon]) {
            echo '<li class="nav-item" role="presentation"><button class="nav-link' . ($first ? ' active' : '') . '" type="button" data-bs-toggle="tab" data-bs-target="#rd-' . $k . '" role="tab">'
                . '<i class="bi bi-' . $icon . '"></i> ' . h($label) . '</button></li>';
            $first = false;
        }
        echo '</ul></div><div class="card-body"><div class="tab-content">';

        // ================================================================ Columns
        $own = array_filter($cols, fn($c) => !ReportFields::isRelated($c));
        $related = array_filter($cols, fn($c) => ReportFields::isRelated($c));
        $groups = [];
        foreach ($related as $c) {
            $groups[$c['related']] = ($groups[$c['related']] ?? 0) + 1;
        }
        echo '<div class="tab-pane fade show active" id="rd-columns" role="tabpanel">'
            . '<p class="small text-muted mb-2">Tick a column to print it, untick it to leave it out. Move a column with the arrows or by dragging its handle. '
            . 'Type over a heading to change the words that print. Width and lining up can be set for each column on its own.'
            . ($related ? ' Under the report\'s own columns are the fields of the records its rows come from (' . h(implode(', ', array_map('strtolower', array_keys($groups)))) . '): tick any of them to add it to the report.' : '')
            . '</p>';
        echo '<div class="rd-cols-tools d-flex flex-wrap gap-2 align-items-center mb-2">'
            . '<input type="search" class="form-control form-control-sm rd-cols-find" id="rptColFind" placeholder="Find a column or field" aria-label="Find a column or field" autocomplete="off">'
            . '<span class="small text-muted rd-cols-count" id="rptColCount"></span>';
        foreach ($groups as $g => $n) {
            echo '<button type="button" class="btn btn-sm btn-link p-0 rd-cols-jump" data-group="' . h($g) . '">' . h($g) . ' fields (' . $n . ')</button>';
        }
        echo '</div>';
        echo '<div class="table-responsive rd-cols-box"><table class="table table-sm mb-0 rd-cols" id="rptCols">'
            . '<thead><tr><th></th><th>Print</th><th>Column</th><th>Heading printed</th><th>Width</th><th>Lines up</th><th>Move</th></tr></thead><tbody>';
        $lastGroup = null;
        foreach ($cols as $c) {
            $k = $c['key'];
            $orig = $c['_orig_label'] ?? $c['label'];
            $isRel = ReportFields::isRelated($c);
            $group = $isRel ? (string)$c['related'] : '';
            $what = $isRel ? $group . ' field' : (!empty($c['optional']) ? 'extra column' : 'own column');
            echo '<tr data-key="' . h($k) . '" data-group="' . h($group) . '" class="' . ($isRel ? 'rd-related' : 'rd-own') . (!empty($c['optional']) ? ' rd-optional' : '') . (!in_array($k, $hidden, true) ? ' is-on' : '') . '"'
                . ' data-find="' . h(strtolower($orig . ' ' . self::standardLabel($c) . ' ' . $what)) . '">'
                . '<td class="text-muted rd-grip-cell" title="Drag to move"><i class="bi bi-grip-vertical rpt-grip"></i></td>'
                . '<td><input class="form-check-input rpt-show" type="checkbox" aria-label="Print ' . h($orig) . '"' . checked(!in_array($k, $hidden, true)) . '></td>'
                . '<td class="small rd-col-name">' . h(self::standardLabel($c))
                . ($isRel ? ' <span class="text-muted rd-col-from">from the ' . h(strtolower($group)) . '</span>' : (!empty($c['optional']) ? ' <span class="text-muted">(extra)</span>' : '')) . '</td>'
                . '<td><input class="form-control form-control-sm rd-head" name="head[' . h($k) . ']" value="' . h($lay['labels'][$k] ?? '') . '" placeholder="' . h(self::standardLabel($c)) . '" maxlength="60" data-col="' . h($k) . '"></td>'
                . '<td><select class="form-select form-select-sm rd-w" name="cw[' . h($k) . ']" data-no-combo data-col="' . h($k) . '">' . options(ReportLayout::COL_WIDTHS, $c['_width'] ?? '') . '</select></td>'
                . '<td><select class="form-select form-select-sm rd-a" name="ca[' . h($k) . ']" data-no-combo data-col="' . h($k) . '">' . options(ReportLayout::COL_ALIGNS, $c['_align'] ?? '') . '</select></td>'
                . '<td class="text-nowrap rd-move">'
                . '<button type="button" class="btn btn-sm btn-link p-0 rpt-first" title="Move to the start"><i class="bi bi-chevron-double-up"></i></button>'
                . '<button type="button" class="btn btn-sm btn-link p-0 rpt-up" title="Move up"><i class="bi bi-arrow-up"></i></button>'
                . '<button type="button" class="btn btn-sm btn-link p-0 rpt-down" title="Move down"><i class="bi bi-arrow-down"></i></button>'
                . '<button type="button" class="btn btn-sm btn-link p-0 rpt-last" title="Move to the end"><i class="bi bi-chevron-double-down"></i></button></td></tr>';
        }
        echo '</tbody></table></div>'
            . '<div class="mt-2 d-flex flex-wrap gap-2 align-items-center"><button type="button" class="btn btn-sm btn-outline-secondary" id="rptAll">Print all its own columns</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" id="rptReset">Back to the standard columns</button>'
            . ($related ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="rptNoRelated">Take out the added fields</button>' : '')
            . '<span class="small text-muted" id="rptColsOn"></span></div></div>';

        // ================================================================ Rows
        $hiddenRows = ReportLayout::lines($o['rows_hidden']);
        echo '<div class="tab-pane fade" id="rd-rows" role="tabpanel"><div class="row g-3"><div class="col-lg-6"><div class="row g-2">'
            . self::select('rows_detail', $o, [], 'col-12', !$rowInfo['groups'] && !$rowInfo['subtotals']
                ? 'This report has no groups, so every row is a detail row.' : '')
            . self::select('rows_zero', $o, [], 'col-12')
            . self::field('rows_match', $o, 'col-md-8', 'text', 'Words to look for in a row')
            . self::field('rows_limit', $o, 'col-md-4', 'number', '0')
            . self::check('rows_subtotals', $o, [], 'col-md-6')
            . self::check('rows_total', $o, [], 'col-md-6')
            . '</div><p class="small text-muted mt-2 mb-0">Leaving rows out changes what prints, not the figures: every total is still the total of the whole report.</p></div>'
            . '<div class="col-lg-6"><div class="rd-sub">Rows taken out one by one</div>'
            . '<p class="small text-muted mb-2">While this panel is open, point at a row of the report and press <i class="bi bi-eye-slash"></i> to take it out. '
            . 'A row is known by the words in its first columns, so it stays out whenever the report is run.</p>'
            . '<ul class="rd-rowlist" id="rptRowList">';
        foreach ($hiddenRows as $rk) {
            echo '<li data-rowkey="' . h($rk) . '"><span>' . h($rk) . '</span><button type="button" class="btn btn-sm btn-link p-0 rpt-rowback">Put back</button></li>';
        }
        echo '</ul><p class="small text-muted" id="rptRowNone"' . ($hiddenRows ? ' hidden' : '') . '>None taken out.</p>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" id="rptRowsAllBack"' . ($hiddenRows ? '' : ' hidden') . '>Put them all back</button>'
            . '</div></div></div>';

        // ================================================================ Heading
        echo '<div class="tab-pane fade" id="rd-heading" role="tabpanel"><div class="row g-3"><div class="col-lg-6"><div class="row g-2">'
            . self::field('title', $o, 'col-12', 'text', $title, ['text:#rptTitle'])
            . self::field('subtitle', $o, 'col-12', 'text', 'For the board meeting', ['text:#rptSub'])
            . self::area('header_lines', $o, 'col-12', "Prepared for the audit\nUnaudited figures", ['lines:#rptHeadLines'])
            . '</div></div><div class="col-lg-6"><div class="row g-2">'
            . self::select('head_align', $o, ['class:rh-align-'], 'col-md-6')
            . self::check('title_upper', $o, ['toggle:rt-upper'], 'col-md-6 d-flex align-items-end')
            . '<div class="col-12"><div class="rd-sub">Show in the heading</div></div>'
            . self::check('show_company', $o, ['show:#rptCompany'], 'col-md-6')
            . self::check('show_address', $o, ['show:#rptAddress'], 'col-md-6', trim((string)$s['company_address']) === '' ? 'No address in the company settings yet' : '')
            . self::check('show_tin', $o, ['show:#rptTin'], 'col-md-6', trim((string)$s['company_tin']) === '' ? 'No TIN in the company settings yet' : '')
            . self::check('show_logo', $o, ['show:.report-logo'], 'col-md-6', Logo::enabled($pageKey) ? '' : (Logo::path() === null ? 'No logo uploaded yet' : 'Not ticked for this report under Company, Security & Closing Date'))
            . self::check('show_basis', $o, ['show:#rptBasis'], 'col-md-6')
            . self::check('show_prepared', $o, ['show:#rptPrepared'], 'col-md-6')
            . self::check('head_rule', $o, ['toggle-off:rh-norule'], 'col-md-6')
            . '</div></div></div></div>';

        // ================================================================ Fonts & look
        $fonts = array_map(fn($f) => $f[0], ReportLayout::FONTS);
        $fontCss = array_map(fn($f) => $f[1], ReportLayout::FONTS);
        echo '<div class="tab-pane fade" id="rd-look" role="tabpanel"><div class="row g-3">'
            . '<div class="col-lg-4"><div class="rd-sub">The heading</div><div class="row g-2">'
            . self::select('head_font', $o, ['var:--rh-font'], 'col-12', '', $fonts, $fontCss)
            . self::select('title_size', $o, ['var:--rt-size'], 'col-6', '', null, ReportLayout::SIZES['title_size'])
            . self::select('company_size', $o, ['var:--rco-size'], 'col-6', '', null, ReportLayout::SIZES['company_size'])
            . self::select('head_color', $o, ['var:--rh-color'], 'col-12', '', null, ReportLayout::SIZES['head_color'])
            . '</div></div>'
            . '<div class="col-lg-4"><div class="rd-sub">The column headings</div><div class="row g-2">'
            . self::select('col_font', $o, ['var:--rc-font'], 'col-12', '', $fonts, $fontCss)
            . self::select('col_size', $o, ['var:--rc-size'], 'col-6', '', null, ReportLayout::SIZES['col_size'])
            . self::select('col_shade', $o, ['class:rc-shade-'], 'col-6')
            . self::select('col_align', $o, ['class:rc-align-'], 'col-12')
            . self::check('col_bold', $o, ['toggle-off:rc-light'], 'col-6')
            . self::check('col_upper', $o, ['toggle:rc-upper'], 'col-6')
            . self::check('col_wrap', $o, ['toggle:rc-wrap'], 'col-12')
            . '</div></div>'
            . '<div class="col-lg-4"><div class="rd-sub">The rows</div><div class="row g-2">'
            . self::select('body_font', $o, ['var:--rb-font'], 'col-12', '', $fonts, $fontCss)
            . self::select('font', $o, ['tset:rt-font-:small,normal,large'], 'col-6')
            . self::select('density', $o, ['tset:rt-:normal,tight,roomy'], 'col-6')
            . self::select('total_style', $o, ['class:rt-total-'], 'col-12')
            . self::check('zebra', $o, ['ttoggle:table-striped'], 'col-6')
            . self::check('grid', $o, ['ttoggle:rt-grid'], 'col-6')
            . self::check('box', $o, ['toggle:rt-box'], 'col-6')
            . self::check('group_shade', $o, ['toggle-off:rt-plain-groups'], 'col-6')
            . '</div></div></div></div>';

        // ================================================================ Figures
        echo '<div class="tab-pane fade" id="rd-figures" role="tabpanel"><div class="row g-2">'
            . self::select('negatives', $o, [], 'col-md-4')
            . self::select('zeros', $o, [], 'col-md-4')
            . self::select('decimals', $o, [], 'col-md-4')
            . self::select('scale', $o, [], 'col-md-4', 'The heading then says "amounts in thousands".')
            . self::select('currency', $o, [], 'col-md-4', 'The sign is ' . h((string)($GLOBALS['CONFIG']['app']['currency'] ?? '')) . ', from the system settings.')
            . self::check('thousands', $o, [], 'col-md-4 d-flex align-items-center')
            . '</div></div>';

        // ================================================================ Footer
        echo '<div class="tab-pane fade" id="rd-footer" role="tabpanel"><div class="row g-3"><div class="col-lg-6"><div class="row g-2">'
            . self::area('footer_text', $o, 'col-12', "Confidential: for internal use only", ['lines:#rptFoot'])
            . self::select('footer_align', $o, ['class:rf-align-'], 'col-md-6')
            . self::check('footer_rule', $o, ['toggle:rf-rule'], 'col-md-6 d-flex align-items-end')
            . self::check('show_printed', $o, ['show:#rptPrinted'], 'col-md-6')
            . self::check('page_numbers', $o, [], 'col-12', 'Printed as "Page 1 of 3" at the foot of each page (Chrome and Edge).')
            . '</div></div><div class="col-lg-6"><div class="row g-2">'
            . self::check('prepared_by', $o, ['show:#rptSign'], 'col-12')
            . self::area('sign_labels', $o, 'col-md-6', "Prepared by\nChecked by\nApproved by")
            . self::area('sign_names', $o, 'col-md-6', "Juan Dela Cruz\nMaria Santos")
            . '<div class="col-12 small text-muted">One caption on each line, up to four; a name on the same line of the second box prints above it.</div>'
            . '</div></div></div></div>';

        // ================================================================ Page
        echo '<div class="tab-pane fade" id="rd-page" role="tabpanel"><div class="row g-2">'
            . self::select('paper', $o, [], 'col-md-4')
            . self::select('orientation', $o, [], 'col-md-4')
            . self::select('margins', $o, [], 'col-md-4')
            . self::check('repeat_title', $o, [], 'col-12', 'From the second printed page on, in the top margin (Chrome and Edge).')
            . '<div class="col-12 small text-muted">These are for the printed page. Press <strong>Print / PDF</strong> above the report to see them.</div>'
            . '</div></div>';

        // ================================================================ Layouts
        echo '<div class="tab-pane fade" id="rd-layouts" role="tabpanel"><div class="row g-3"><div class="col-lg-6">'
            . '<p class="small text-muted">A layout keeps everything set on these tabs. Save it for yourself, share it with the other users, '
            . 'and make it the one this report opens with. Users who cannot design reports can still open any layout shared with them.</p>';
        if ($saved) {
            echo '<label class="form-label">Open a saved layout</label><select class="form-select form-select-sm" data-no-combo onchange="location = this.value">'
                . '<option value="' . h(current_url(['layout' => '0'])) . '">Standard (no layout)</option>';
            foreach ($saved as $l) {
                echo '<option value="' . h(current_url(['layout' => (int)$l['id']])) . '"' . ($current && (int)$current['id'] === (int)$l['id'] ? ' selected' : '') . '>'
                    . h($l['name']) . ((int)$l['is_default'] ? ' (opens by default)' : '') . ((int)$l['is_shared'] ? ', shared' : '') . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p class="small">No layout saved for this report yet.</p>';
        }
        echo '</div><div class="col-lg-6 d-flex flex-column gap-2 align-items-start">'
            . '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#layoutSave"><i class="bi bi-save"></i> Save this layout</button>';
        if ($current && ((int)$current['created_by'] === Auth::id() || Access::isAdmin())) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#layoutDelete"><i class="bi bi-trash"></i> Delete "' . h($current['name']) . '"</button>';
        }
        echo page_link('home.report_layouts', [], 'Every saved layout', 'btn-link p-0', 'list-ul')
            . '</div></div></div>';

        echo '</div></div>';   // tab-content, card-body

        // ================================================================ the buttons
        echo '<div class="card-footer rpt-designer-foot d-flex flex-wrap gap-2 align-items-center">'
            . '<button class="btn btn-sm btn-primary" id="rptApply"><i class="bi bi-eye"></i> Show it this way</button>'
            . '<a class="btn btn-sm btn-outline-secondary" href="' . h(url($pageKey, ['layout' => '0'] + array_diff_key($_GET, array_flip(self::DESIGN_KEYS)))) . '">Back to the plain report</a>'
            . '<span class="small rd-pending" id="rptPending" hidden><i class="bi bi-dot"></i>Some changes show once you press "Show it this way".</span>'
            . '<span class="small text-muted ms-auto d-none d-md-inline">Fonts, sizes, colours and the words of the heading change as you pick them.</span>'
            . '</div></form></div></div>';

        self::modals($pageKey, $current, $title);
        self::script();
    }

    /** The heading a column has in the report itself, before any layout renamed it */
    private static function standardLabel(array $c): string
    {
        $label = trim((string)($c['_orig_label'] ?? $c['label']));
        // a column printed without a heading (the account names of a financial statement) still needs a name here
        return $label !== '' ? $label : ucfirst(str_replace('_', ' ', (string)$c['key']));
    }

    // ------------------------------------------------------------------ one field of the panel

    private static function liveAttr(array $live, ?array $css = null): string
    {
        if (!$live) {
            return ' data-pending="1"';
        }
        return ' data-live="' . h(implode(' ', $live)) . '"' . ($css !== null ? ' data-css="' . h(json_encode($css)) . '"' : '');
    }

    private static function select(string $k, array $o, array $live, string $cls, string $help = '', ?array $choices = null, ?array $css = null): string
    {
        $choices ??= ReportLayout::CHOICES[$k];
        return '<div class="' . h($cls) . '"><label class="form-label" for="rd_' . $k . '">' . h(ReportLayout::OPTIONS[$k][0]) . '</label>'
            . '<select class="form-select form-select-sm" id="rd_' . $k . '" name="opt[' . $k . ']" data-no-combo' . self::liveAttr($live, $css) . '>'
            . options($choices, (string)$o[$k]) . '</select>'
            . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
    }

    private static function check(string $k, array $o, array $live, string $cls, string $help = ''): string
    {
        return '<div class="' . h($cls) . '"><div class="form-check">'
            . '<input type="hidden" name="opt[' . $k . ']" value="0">'
            . '<input class="form-check-input" type="checkbox" id="rd_' . $k . '" name="opt[' . $k . ']" value="1"' . checked(!empty($o[$k])) . self::liveAttr($live) . '>'
            . '<label class="form-check-label" for="rd_' . $k . '">' . h(ReportLayout::OPTIONS[$k][0]) . '</label>'
            . ($help !== '' ? '<div class="form-text mt-0">' . h($help) . '</div>' : '') . '</div></div>';
    }

    private static function field(string $k, array $o, string $cls, string $type, string $placeholder, array $live = []): string
    {
        return '<div class="' . h($cls) . '"><label class="form-label" for="rd_' . $k . '">' . h(ReportLayout::OPTIONS[$k][0]) . '</label>'
            . '<input class="form-control form-control-sm" id="rd_' . $k . '" type="' . ($type === 'number' ? 'number' : 'text') . '"'
            . ($type === 'number' ? ' min="0" step="1"' : ' maxlength="160"')
            . ' name="opt[' . $k . ']" value="' . h((string)$o[$k]) . '" placeholder="' . h($placeholder) . '"' . self::liveAttr($live) . '></div>';
    }

    private static function area(string $k, array $o, string $cls, string $placeholder, array $live = []): string
    {
        $v = (string)$o[$k];
        return '<div class="' . h($cls) . '"><label class="form-label" for="rd_' . $k . '">' . h(ReportLayout::OPTIONS[$k][0]) . '</label>'
            . '<textarea class="form-control form-control-sm" id="rd_' . $k . '" rows="3" name="opt[' . $k . ']" placeholder="' . h($placeholder) . '"' . self::liveAttr($live) . '>'
            . h($v) . '</textarea></div>';
    }

    // ------------------------------------------------------------------ saving and deleting a layout

    private static function modals(string $pageKey, ?array $current, string $title): void
    {
        $post = url('home.report_layouts');
        $qs = $_GET;
        unset($qs['p'], $qs['print'], $qs['export']);
        echo '<form method="post" action="' . h($post) . '" class="no-print" id="layoutSaveForm">' . csrf_field()
            . '<input type="hidden" name="report_key" value="' . h($pageKey) . '">'
            . '<input type="hidden" name="query_string" id="layoutQuery" value="' . h(http_build_query($qs)) . '">'
            . '<div class="modal fade" id="layoutSave" tabindex="-1"><div class="modal-dialog"><div class="modal-content">'
            . '<div class="modal-header"><h5 class="modal-title">Save this layout</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body row g-2">'
            . '<div class="col-12"><label class="form-label">Name</label><input class="form-control" name="name" value="' . h($current['name'] ?? '') . '" placeholder="My ' . h($title) . '" required maxlength="120"></div>'
            . ($current && (int)$current['created_by'] === Auth::id()
                ? '<div class="col-12"><label><input type="checkbox" name="replace" value="' . (int)$current['id'] . '" checked> Replace "' . h($current['name']) . '" instead of adding another</label></div>' : '')
            . '<div class="col-12"><label><input type="checkbox" name="is_default" value="1" ' . checked($current && (int)$current['is_default']) . '> Open this report with this layout from now on</label></div>'
            . '<div class="col-12"><label><input type="checkbox" name="is_shared" value="1" ' . checked($current && (int)$current['is_shared']) . '> Let the other users use it too</label></div>'
            . '<div class="col-12 small text-muted">Everything on the Modify Report panel is saved, including changes not shown yet.</div>'
            . '</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
            . '<button class="btn btn-primary" name="do" value="save">Save the layout</button></div></div></div></div></form>';

        if ($current && ((int)$current['created_by'] === Auth::id() || Access::isAdmin())) {
            echo '<form method="post" action="' . h($post) . '" class="no-print">' . csrf_field()
                . '<input type="hidden" name="report_key" value="' . h($pageKey) . '"><input type="hidden" name="id" value="' . (int)$current['id'] . '">'
                . '<input type="hidden" name="name" value="' . h($current['name']) . '">'
                . '<div class="modal fade" id="layoutDelete" tabindex="-1"><div class="modal-dialog"><div class="modal-content">'
                . '<div class="modal-header"><h5 class="modal-title">Delete this layout</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                . '<div class="modal-body"><p>Delete the layout <strong>' . h($current['name']) . '</strong>? The report itself is not changed.</p></div>'
                . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
                . '<button class="btn btn-danger" name="do" value="delete">Delete it</button></div></div></div></div></form>';
        }
    }

    // ------------------------------------------------------------------ the panel at work

    private static function script(): void
    {
        echo <<<'JS'
<script>
// the panel sits above the report, so it starts once the whole page is there
document.addEventListener('DOMContentLoaded', function () {
  'use strict';
  var form = document.getElementById('rptDesignForm');
  if (!form) { return; }
  var doc = document.getElementById('rptDoc');
  var table = document.getElementById('rptTable');
  var panel = document.getElementById('rptCustomize');
  var pending = document.getElementById('rptPending');
  var cols = document.getElementById('rptCols');
  var body = cols.querySelector('tbody');
  var orderBox = document.getElementById('rptOrder');
  var hiddenBox = document.getElementById('rptHidden');
  var rowsBox = document.getElementById('rptRowsHidden');
  var rowList = document.getElementById('rptRowList');

  var waiting = function () { pending.hidden = false; };

  // while the panel is open, the report shows the buttons that take a row out
  panel.addEventListener('show.bs.collapse', function () { doc.classList.add('designing'); });
  panel.addEventListener('hide.bs.collapse', function () { doc.classList.remove('designing'); });

  // ---------------------------------------------------------------- the choices that show at once
  var cssOf = function (el) {
    var map = el.dataset.css ? JSON.parse(el.dataset.css) : null;
    return map ? (map[el.value] || '') : el.value;
  };
  var setClass = function (target, prefix, value) {
    Array.prototype.slice.call(target.classList).forEach(function (c) { if (c.indexOf(prefix) === 0) { target.classList.remove(c); } });
    if (value !== '') { target.classList.add(prefix + value); }
  };
  var linesInto = function (box, text) {
    box.innerHTML = '';
    text.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 8).forEach(function (line) {
      var d = document.createElement('div');
      d.textContent = line;
      box.appendChild(d);
    });
    box.hidden = !box.children.length;
  };
  var apply = function (el) {
    (el.dataset.live || '').split(' ').forEach(function (rule) {
      var i = rule.indexOf(':'), how = rule.slice(0, i), what = rule.slice(i + 1);
      var on = el.type === 'checkbox' ? el.checked : null;
      if (how === 'var') { doc.style.setProperty(what, cssOf(el)); }
      else if (how === 'class') { setClass(doc, what, el.value); }
      else if (how === 'toggle') { doc.classList.toggle(what, on); }
      else if (how === 'toggle-off') { doc.classList.toggle(what, !on); }
      else if (how === 'ttoggle') { table.classList.toggle(what, on); }
      else if (how === 'tset') {
        var p = what.split(':'), prefix = p[0];
        p[1].split(',').forEach(function (v) { table.classList.remove(prefix + v); });
        table.classList.add(prefix + el.value);
      }
      else if (how === 'show') { document.querySelectorAll(what).forEach(function (x) { if (x.id === 'rptAddress' || x.id === 'rptTin') { x.hidden = !on || !x.textContent.replace(/^TIN\s*/, '').trim(); } else { x.hidden = !on; } if (x.classList.contains('report-logo')) { x.parentNode.classList.toggle('has-logo', on); } }); }
      else if (how === 'text') {
        var t = document.querySelector(what);
        if (what === '#rptTitle') { t.textContent = el.value.trim() || t.dataset.standard; }
        else { t.textContent = el.value.trim(); t.hidden = !el.value.trim(); }
      }
      else if (how === 'lines') { linesInto(document.querySelector(what), el.value); }
    });
  };
  form.addEventListener('input', function (e) {
    var el = e.target;
    if (el.dataset.live) { apply(el); }
    else if (el.dataset.pending) { waiting(); }
  });
  form.addEventListener('change', function (e) {
    var el = e.target;
    if (el.dataset.live) { apply(el); }
    else if (el.dataset.pending) { waiting(); }
  });

  // ---------------------------------------------------------------- columns
  // the cells of a column; a heading, subtotal or total whose words run over several columns (colspan) is
  // kept apart in .spanned, and showing or hiding a column it covers makes it one column narrower or wider
  var cellsOf = function (key) {
    var th = table.querySelector('thead th[data-col="' + key + '"]');
    if (!th) { var none = []; none.spanned = []; return none; }
    var i = th.cellIndex, out = [th];
    out.spanned = [];
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      var pos = 0;
      for (var k = 0; k < tr.cells.length; k++) {
        var c = tr.cells[k], span = parseInt(c.dataset.span || c.colSpan || 1, 10);
        if (i < pos + span) {
          if (span > 1) { out.spanned.push(c); } else if (tr.cells.length > i || span === 1) { out.push(c); }
          return;
        }
        pos += span;
      }
    });
    return out;
  };
  var showCells = function (cells, on) {
    cells.forEach(function (c) { c.style.display = on ? '' : 'none'; });
    (cells.spanned || []).forEach(function (c) {
      var off = parseInt(c.dataset.off || '0', 10) + (on ? -1 : 1);
      off = Math.max(0, off);
      c.dataset.off = off;
      var w = parseInt(c.dataset.span, 10) - off;
      c.colSpan = Math.max(1, w);
      c.style.display = w > 0 ? '' : 'none';
    });
  };
  var sync = function () {
    var keys = [], hidden = [];
    body.querySelectorAll('tr[data-key]').forEach(function (tr) {
      keys.push(tr.dataset.key);
      var cb = tr.querySelector('.rpt-show');
      if (cb && !cb.checked) { hidden.push(tr.dataset.key); }
    });
    orderBox.value = keys.join(',');
    hiddenBox.innerHTML = hidden.map(function (k) {
      return '<input type="hidden" name="hide[]" value="' + k.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">';
    }).join('');
    var on = 0, added = 0;
    body.querySelectorAll('tr[data-key]').forEach(function (tr) {
      var cb = tr.querySelector('.rpt-show'), yes = !!(cb && cb.checked);
      tr.classList.toggle('is-on', yes);
      if (yes) { on++; if (tr.classList.contains('rd-related')) { added++; } }
    });
    var line = document.getElementById('rptColsOn');
    if (line) { line.textContent = on + (on === 1 ? ' column prints' : ' columns print') + (added ? ', ' + added + ' of them added from related records' : ''); }
  };
  body.addEventListener('change', function (e) {
    var tr = e.target.closest('tr[data-key]');
    if (!tr) { return; }
    var key = tr.dataset.key, cells = cellsOf(key);
    if (e.target.classList.contains('rpt-show')) {
      // a column already on the report is shown or hidden at once; one that is not waits for "Show it this way"
      if (cells.length) { showCells(cells, e.target.checked); } else { waiting(); }
    } else if (e.target.classList.contains('rd-w')) {
      if (cells.length) { cells.forEach(function (c) { setClass(c, 'rcw-', e.target.value); }); } else { waiting(); }
    } else if (e.target.classList.contains('rd-a')) {
      if (cells.length) { cells.forEach(function (c) { setClass(c, 'rca-', e.target.value); }); } else { waiting(); }
    }
    sync();
  });
  body.addEventListener('input', function (e) {
    if (!e.target.classList.contains('rd-head')) { return; }
    var th = table.querySelector('thead th[data-col="' + e.target.dataset.col + '"]');
    if (!th) { waiting(); return; }
    var words = e.target.value.trim() || e.target.placeholder;
    var a = th.querySelector('a');
    if (a) { a.firstChild.nodeValue = words; } else { th.textContent = words; }
  });
  body.addEventListener('click', function (e) {
    var b = e.target.closest('.rpt-up, .rpt-down, .rpt-first, .rpt-last');
    if (!b) { return; }
    var tr = b.closest('tr');
    if (b.classList.contains('rpt-up') && tr.previousElementSibling) { body.insertBefore(tr, tr.previousElementSibling); }
    if (b.classList.contains('rpt-down') && tr.nextElementSibling) { body.insertBefore(tr.nextElementSibling, tr); }
    if (b.classList.contains('rpt-first')) { body.insertBefore(tr, body.firstElementChild); }
    if (b.classList.contains('rpt-last')) { body.appendChild(tr); }
    sync();
    waiting();
  });
  var dragging = null;
  body.querySelectorAll('tr[data-key]').forEach(function (tr) {
    var grip = tr.querySelector('.rpt-grip');
    grip.addEventListener('mousedown', function () { tr.draggable = true; });
    tr.addEventListener('dragstart', function () { dragging = tr; tr.classList.add('is-dragging'); });
    tr.addEventListener('dragend', function () { tr.classList.remove('is-dragging'); tr.draggable = false; dragging = null; sync(); waiting(); });
    tr.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragging || dragging === tr) { return; }
      var box = tr.getBoundingClientRect();
      body.insertBefore(dragging, (e.clientY - box.top) < box.height / 2 ? tr : tr.nextElementSibling);
    });
  });
  var tick = function (tr, on) {
    var cb = tr.querySelector('.rpt-show');
    if (!cb || cb.checked === on) { return; }
    cb.checked = on;
    var cells = cellsOf(tr.dataset.key);
    if (cells.length) { showCells(cells, on); } else { waiting(); }
  };
  document.getElementById('rptAll').addEventListener('click', function () {
    body.querySelectorAll('tr.rd-own').forEach(function (tr) { tick(tr, true); });
    sync();
    waiting();
  });
  document.getElementById('rptReset').addEventListener('click', function () {
    body.querySelectorAll('.rd-head').forEach(function (i) { i.value = ''; });
    body.querySelectorAll('.rd-w, .rd-a').forEach(function (s) { s.value = ''; });
    body.querySelectorAll('tr[data-key]').forEach(function (tr) { tick(tr, !tr.classList.contains('rd-optional')); });
    sync();
    orderBox.value = '';
    hiddenBox.innerHTML = '';
    waiting();
  });
  var noRelated = document.getElementById('rptNoRelated');
  if (noRelated) {
    noRelated.addEventListener('click', function () {
      body.querySelectorAll('tr.rd-related').forEach(function (tr) { tick(tr, false); });
      sync();
    });
  }
  // finding a column or field among the many on offer, and jumping to a group of fields
  var find = document.getElementById('rptColFind'), count = document.getElementById('rptColCount');
  var filterCols = function () {
    var q = (find.value || '').trim().toLowerCase(), shown = 0, all = 0;
    body.querySelectorAll('tr[data-key]').forEach(function (tr) {
      var hit = q === '' || (tr.dataset.find || '').indexOf(q) >= 0;
      tr.classList.toggle('rd-miss', !hit);
      all++;
      if (hit) { shown++; }
    });
    count.textContent = q === '' ? '' : (shown ? shown + ' of ' + all + ' match' : 'Nothing matches');
  };
  find.addEventListener('input', filterCols);
  document.querySelectorAll('.rd-cols-jump').forEach(function (b) {
    b.addEventListener('click', function () {
      find.value = '';
      filterCols();
      var tr = body.querySelector('tr[data-group="' + b.dataset.group.replace(/"/g, '\\"') + '"]');
      if (tr) { tr.scrollIntoView({ block: 'center', behavior: 'smooth' }); tr.classList.add('rd-flash'); setTimeout(function () { tr.classList.remove('rd-flash'); }, 1500); }
    });
  });

  // ---------------------------------------------------------------- rows taken out one by one
  var rowKeys = function () { return rowsBox.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean); };
  var drawRows = function () {
    var keys = rowKeys();
    rowList.innerHTML = '';
    keys.forEach(function (k) {
      var li = document.createElement('li');
      li.dataset.rowkey = k;
      li.innerHTML = '<span></span><button type="button" class="btn btn-sm btn-link p-0 rpt-rowback">Put back</button>';
      li.firstChild.textContent = k;
      rowList.appendChild(li);
    });
    document.getElementById('rptRowNone').hidden = keys.length > 0;
    document.getElementById('rptRowsAllBack').hidden = keys.length === 0;
    table.querySelectorAll('tbody tr[data-rowkey]').forEach(function (tr) {
      tr.classList.toggle('rpt-row-out', keys.indexOf(tr.dataset.rowkey) >= 0);
    });
  };
  table.addEventListener('click', function (e) {
    var b = e.target.closest('.rpt-rowhide');
    if (!b) { return; }
    e.preventDefault();
    var key = b.closest('tr').dataset.rowkey, keys = rowKeys(), at = keys.indexOf(key);
    if (at >= 0) { keys.splice(at, 1); } else { keys.push(key); }
    rowsBox.value = keys.join('\n');
    drawRows();
    waiting();
  });
  rowList.addEventListener('click', function (e) {
    var b = e.target.closest('.rpt-rowback');
    if (!b) { return; }
    var key = b.closest('li').dataset.rowkey;
    rowsBox.value = rowKeys().filter(function (k) { return k !== key; }).join('\n');
    drawRows();
    waiting();
  });
  document.getElementById('rptRowsAllBack').addEventListener('click', function () {
    rowsBox.value = '';
    drawRows();
    waiting();
  });

  // ---------------------------------------------------------------- saving takes what is on the panel now
  var saveForm = document.getElementById('layoutSaveForm');
  if (saveForm) {
    saveForm.addEventListener('submit', function () {
      sync();
      var fd = new FormData(form), pairs = [];
      fd.forEach(function (v, k) { if (k !== 'p') { pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(v)); } });
      document.getElementById('layoutQuery').value = pairs.join('&');
    });
  }

  sync();
  drawRows();
  // opened from a link that asked for it (?design=1), or a layout was just saved: the panel starts open
  if (/[?&]design=1/.test(location.search) && window.bootstrap) { new bootstrap.Collapse(panel, { toggle: true }); }
});
</script>
JS;
    }
}
