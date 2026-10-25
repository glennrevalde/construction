<?php
declare(strict_types=1);

// Small JSON lookups for forms (prices by price level, stock, customer info).
$what = g('what');
switch ($what) {
    case 'price':
        json_out(['price' => Lookup::priceFor((int)g('item'), gint('customer'))]);
    case 'customer':
        $c = DB::row('SELECT id, billing_address, shipping_address, terms_id, sales_rep_id, tax_code_id, credit_limit FROM customers WHERE id = :id', ['id' => (int)g('id')]);
        if ($c) {
            $c['balance'] = Balances::customerBalance((int)$c['id']);
        }
        json_out($c ?: []);
    case 'vendor':
        $v = DB::row('SELECT id, address, terms_id, default_expense_account_id, tax_code_id FROM vendors WHERE id = :id', ['id' => (int)g('id')]);
        json_out($v ?: []);
    case 'stock':
        $it = DB::row('SELECT id, item_type, unit FROM items WHERE id = :id', ['id' => (int)g('item')]);
        json_out([
            'on_hand' => Stock::onHand((int)g('item'), gint('site')),
            'tracked' => $it ? Stock::isTracked($it['item_type']) : false,
            'unit' => $it['unit'] ?? '',
        ]);
    default:
        json_out(['error' => 'unknown lookup'], 400);
}
