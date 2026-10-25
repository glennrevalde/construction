<?php
declare(strict_types=1);

/**
 * The receipt written out as plain printing instructions for a roll printer.
 *
 * The browser turns this list into the codes a thermal printer understands (assets/js/btprint.js) and sends
 * it over Bluetooth. The wording, the figures and the order are exactly what the receipt on the screen shows,
 * so a receipt printed on the small printer and one printed from the browser say the same thing.
 *
 * Each instruction is one of:
 *   ['op' => 'text',  'text' => '...', 'align' => 'left|center|right', 'bold' => 0|1, 'wide' => 0|1]
 *   ['op' => 'cols',  'left' => '...', 'right' => '...', 'bold' => 0|1]   left and right of the same line
 *   ['op' => 'rule']                                                      a line of dashes
 *   ['op' => 'feed',  'n' => 3]                                           blank lines
 *   ['op' => 'cut']                                                       cut the paper
 */
final class Receipt
{
    /** Paper widths offered, in characters per line */
    public const WIDTHS = [
        '32' => '58 mm roll (32 characters per line)',
        '42' => '80 mm roll, small type (42 characters per line)',
        '48' => '80 mm roll (48 characters per line)',
    ];

    /** Characters that fit on one line of the roll */
    public static function width(): int
    {
        $w = Settings::get('bt_printer_width', '32');
        return isset(self::WIDTHS[$w]) ? (int)$w : 32;
    }

    /** Is Bluetooth printing switched on for this company? */
    public static function btEnabled(): bool
    {
        return Settings::get('bt_printer_enabled', '0') === '1';
    }

    /** The choices the browser needs: paper width, which printer to look for, what to do at the end */
    public static function btOptions(): array
    {
        return [
            'width'     => self::width(),
            'name'      => trim(Settings::get('bt_printer_name')),
            'feed'      => max(0, min(10, (int)Settings::get('bt_printer_feed', '4'))),
            'cut'       => Settings::get('bt_printer_cut', '0') === '1',
            'autoprint' => Settings::get('bt_printer_autoprint', '0') === '1',
        ];
    }

    // ------------------------------------------------------------------ building a receipt

    /**
     * @param array $t       the transaction, as Txn::load gives it
     * @param array $lines   its lines, as Txn::lines gives them
     * @param array $tenders the amounts paid, with the name of each payment method
     * @param array $opt     title, customer, cashier, terminal name and the footer lines, as the screen works them out
     */
    public static function ops(array $t, array $lines, array $tenders, array $opt = []): array
    {
        $s = Settings::all();
        $w = self::width();
        $o = [];

        $o[] = ['op' => 'text', 'text' => (string)$s['company_name'], 'align' => 'center', 'bold' => 1];
        foreach (self::lines((string)$s['company_address'], $w) as $l) {
            $o[] = ['op' => 'text', 'text' => $l, 'align' => 'center'];
        }
        if (trim((string)$s['company_tin']) !== '') {
            $o[] = ['op' => 'text', 'text' => 'VAT REG TIN ' . $s['company_tin'], 'align' => 'center'];
        }
        if (($opt['terminal'] ?? '') !== '') {
            $o[] = ['op' => 'text', 'text' => (string)$opt['terminal'], 'align' => 'center'];
        }
        $o[] = ['op' => 'rule'];
        $o[] = ['op' => 'text', 'text' => (string)($opt['title'] ?? 'OFFICIAL RECEIPT'), 'align' => 'center', 'bold' => 1];
        $o[] = ['op' => 'text', 'text' => 'No: ' . ($t['receipt_no'] ?: $t['txn_no'])];
        $o[] = ['op' => 'text', 'text' => 'Date: ' . fdatetime($t['created_at'])];
        if (($opt['customer'] ?? '') !== '') {
            $o[] = ['op' => 'text', 'text' => 'Customer: ' . $opt['customer']];
        }
        if (($opt['cashier'] ?? '') !== '') {
            $o[] = ['op' => 'text', 'text' => 'Cashier: ' . $opt['cashier']];
        }
        if ((int)($t['is_voided'] ?? 0) === 1) {
            $o[] = ['op' => 'text', 'text' => '*** VOID ***', 'align' => 'center', 'bold' => 1];
        }
        $o[] = ['op' => 'rule'];

        foreach ($lines as $l) {
            foreach (self::wrap((string)$l['description'], $w) as $part) {
                $o[] = ['op' => 'text', 'text' => $part];
            }
            $qty = trim(strip_tags(line_qty($l)));
            $rate = line_rate($l, false);
            $disc = trim(strip_tags(line_discount($l)));
            $left = '  ' . $qty . ' x ' . $rate . ($disc !== '' ? ' less ' . $disc : '');
            $o[] = ['op' => 'cols', 'left' => $left, 'right' => money($l['amount'])];
        }
        $o[] = ['op' => 'rule'];

        if (($t['txn_type'] ?? '') !== 'payment') {
            $o[] = ['op' => 'cols', 'left' => 'VATable sales', 'right' => money($t['subtotal'])];
            $o[] = ['op' => 'cols', 'left' => 'VAT', 'right' => money($t['tax_total'])];
        }
        $o[] = ['op' => 'cols', 'left' => 'TOTAL', 'right' => money($t['total']), 'bold' => 1];
        foreach ($tenders as $tn) {
            $o[] = ['op' => 'cols', 'left' => trim($tn['name'] . ' ' . (string)($tn['reference'] ?? '')), 'right' => money($tn['amount'])];
        }
        if ((float)($t['amount_tendered'] ?? 0) > 0) {
            $o[] = ['op' => 'cols', 'left' => 'Amount tendered', 'right' => money($t['amount_tendered'])];
            $o[] = ['op' => 'cols', 'left' => 'Change', 'right' => money($t['change_given'])];
        }
        $o[] = ['op' => 'rule'];
        foreach ((array)($opt['footer'] ?? []) as $f) {
            foreach (self::wrap((string)$f, $w) as $part) {
                $o[] = ['op' => 'text', 'text' => $part, 'align' => 'center'];
            }
        }
        return self::finish($o);
    }

    /**
     * A short report on the roll printer (Ending Inventory - POS Cashier): the heading lines, a two-column list
     * (name on the left, figure on the right), the total, the signature lines and the foot text.
     *
     * @param string[] $head  centred lines under the company name (the first in bold)
     * @param string[] $info  left-aligned lines (cut-off, site, cashier)
     * @param array    $cols  [left heading, right heading]
     * @param array    $rows  [[left, right], ...]
     * @param array    $total [left, right] or []
     * @param array    $signs [[caption, name], ...]
     * @param string[] $foot  lines at the foot
     */
    public static function reportOps(array $head, array $info, array $cols, array $rows, array $total, array $signs, array $foot): array
    {
        $s = Settings::all();
        $w = self::width();
        $o = [['op' => 'text', 'text' => (string)$s['company_name'], 'align' => 'center', 'bold' => 1]];
        foreach (array_values($head) as $i => $l) {
            foreach (self::wrap((string)$l, $w) as $part) {
                $o[] = ['op' => 'text', 'text' => $part, 'align' => 'center', 'bold' => $i === 0 ? 1 : 0];
            }
        }
        $o[] = ['op' => 'rule'];
        foreach ($info as $l) {
            foreach (self::wrap((string)$l, $w) as $part) {
                $o[] = ['op' => 'text', 'text' => $part];
            }
        }
        $o[] = ['op' => 'rule'];
        $o[] = ['op' => 'cols', 'left' => (string)$cols[0], 'right' => (string)$cols[1], 'bold' => 1];
        foreach ($rows as [$left, $right]) {
            $right = (string)$right;
            $room = max(8, $w - mb_strlen($right) - 1);
            $parts = self::wrap((string)$left, $room) ?: [''];
            $last = array_pop($parts);
            foreach ($parts as $part) {
                $o[] = ['op' => 'text', 'text' => $part];
            }
            $o[] = ['op' => 'cols', 'left' => $last, 'right' => $right];
        }
        if ($total) {
            $o[] = ['op' => 'rule'];
            $o[] = ['op' => 'cols', 'left' => (string)$total[0], 'right' => (string)$total[1], 'bold' => 1];
        }
        foreach ($signs as [$caption, $name]) {
            $o[] = ['op' => 'feed', 'n' => 1];
            $o[] = ['op' => 'text', 'text' => (string)$caption];
            $o[] = ['op' => 'feed', 'n' => 1];
            $o[] = ['op' => 'text', 'text' => str_repeat('_', max(10, $w - 4)), 'align' => 'center'];
            if ((string)$name !== '') {
                $o[] = ['op' => 'text', 'text' => (string)$name, 'align' => 'center'];
            }
        }
        if ($foot) {
            $o[] = ['op' => 'rule'];
            foreach ($foot as $l) {
                foreach (self::wrap((string)$l, $w) as $part) {
                    $o[] = ['op' => 'text', 'text' => $part, 'align' => 'center'];
                }
            }
        }
        return self::finish($o);
    }

    /** The short receipt the "Test print" button on the settings page sends */
    public static function testOps(): array
    {
        $s = Settings::all();
        $w = self::width();
        $o = [];
        $o[] = ['op' => 'text', 'text' => (string)$s['company_name'], 'align' => 'center', 'bold' => 1];
        foreach (self::lines((string)$s['company_address'], $w) as $l) {
            $o[] = ['op' => 'text', 'text' => $l, 'align' => 'center'];
        }
        $o[] = ['op' => 'rule'];
        $o[] = ['op' => 'text', 'text' => 'TEST PRINT', 'align' => 'center', 'bold' => 1];
        $o[] = ['op' => 'text', 'text' => 'Date: ' . fdatetime(date('Y-m-d H:i:s'))];
        $o[] = ['op' => 'rule'];
        $o[] = ['op' => 'text', 'text' => 'Sample item, long name to show the wrap'];
        $o[] = ['op' => 'cols', 'left' => '  2 x 125.00', 'right' => '250.00'];
        $o[] = ['op' => 'text', 'text' => 'Another sample item'];
        $o[] = ['op' => 'cols', 'left' => '  1 x 99.00 less 10.00%', 'right' => '89.10'];
        $o[] = ['op' => 'rule'];
        $o[] = ['op' => 'cols', 'left' => 'TOTAL', 'right' => '339.10', 'bold' => 1];
        $o[] = ['op' => 'rule'];
        // exactly as many characters as the paper is meant to hold: if this row wraps, the width is set too wide
        $o[] = ['op' => 'text', 'text' => substr(str_repeat('1234567890', (int)ceil($w / 10)), 0, $w), 'align' => 'left'];
        foreach (self::wrap('The row of figures above should fill one line exactly. If it spills onto a second line, choose a narrower paper width here.', $w) as $part) {
            $o[] = ['op' => 'text', 'text' => $part, 'align' => 'center'];
        }
        return self::finish($o);
    }

    /**
     * Last pass over a finished receipt: nothing wider than the paper (a long cashier or customer
     * name would otherwise wrap itself in the middle of a word), then the blank lines and the cut.
     */
    private static function finish(array $o): array
    {
        $w = self::width();
        $out = [];
        foreach ($o as $op) {
            if (($op['op'] ?? 'text') === 'text' && mb_strlen((string)($op['text'] ?? '')) > $w) {
                foreach (self::wrap((string)$op['text'], $w) as $part) {
                    $out[] = ['op' => 'text', 'text' => $part] + $op;
                }
                continue;
            }
            $out[] = $op;
        }
        $opt = self::btOptions();
        if ($opt['feed'] > 0) {
            $out[] = ['op' => 'feed', 'n' => $opt['feed']];
        }
        if ($opt['cut']) {
            $out[] = ['op' => 'cut'];
        }
        return $out;
    }

    /** Splits text the user typed on its own line breaks, then to the width of the paper */
    private static function lines(string $text, int $w): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            foreach (self::wrap($line, $w) as $part) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /** Breaks one line at the last space that still fits, so no word is cut in half */
    private static function wrap(string $text, int $w): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", wordwrap($text, $w, "\n", true)) as $part) {
            $out[] = $part;
        }
        return $out;
    }
}
