<?php
declare(strict_types=1);

/**
 * Find results as a report.
 *
 * The Report button on the Find page opens this with the same choices (f[...] in the address), so what
 * was found can be printed, exported, customized with a layout of its own and memorized like any other
 * report. Every number links back to the transaction it came from.
 */

Report::define('find', [
    'filters' => [],
    'sortable' => true,
    // the line under the title says what was searched for
    'basis' => function (array $f): string {
        $words = Find::describe(Find::parse((array)($_GET['f'] ?? [])));
        if (!$words) {
            return 'Every transaction you are allowed to open';
        }
        return implode('; ', array_map(fn($x) => $x[0] . ': ' . $x[1], $words));
    },
    'run' => function (array $f): array {
        $c = Find::parse((array)($_GET['f'] ?? []));
        $res = Find::run($c, Find::REPORT_LIMIT, 0, 'date', 'asc');
        $lines = ($c['detail']['v'] ?? 'summary') === 'lines';
        $rows = [];
        foreach ($res['rows'] as $r) {
            $rows[] = [
                'date' => $r['txn_date'],
                'type' => $r['type_label'] . ((int)$r['is_voided'] ? ' (void)' : ''),
                'num' => $r['num'],
                'name' => (string)$r['name'],
                'account' => (string)$r['account'],
                'memo' => (string)$r['memo'],
                'status' => ucfirst((string)$r['status']),
                'amount' => $r['amount'],
                '_links' => ['num' => $r['url']],
            ];
        }
        $notes = '';
        if ($res['count'] > count($rows)) {
            $notes = 'Only the first ' . number_format(count($rows)) . ' of ' . number_format($res['count'])
                . ' are listed here. Narrow the search on the Find page to see the rest.';
        }
        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'num', 'label' => 'Num'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'account', 'label' => $lines ? 'Account / Item' : 'Account'],
                ['key' => 'memo', 'label' => 'Memo'],
                ['key' => 'status', 'label' => 'Status', 'optional' => true],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $rows ? [
                'name' => number_format($res['count']) . ($lines ? ' line' : ' transaction') . ($res['count'] === 1 ? '' : 's'),
                'amount' => $res['total'],
            ] : [],
            'notes' => h($notes),
        ];
    },
]);
