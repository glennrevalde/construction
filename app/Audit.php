<?php
declare(strict_types=1);

/**
 * Audit trail. Entries are insert-only; database triggers block UPDATE and DELETE.
 */
final class Audit
{
    /** Fields ignored when computing before/after differences */
    private const IGNORE = ['updated_at', 'updated_by', 'created_at', 'password_hash', 'last_login_at', 'last_login_ip', 'failed_attempts'];

    public static function log(
        string $action,
        string $module,
        ?string $recordType = null,
        $recordId = null,
        ?string $recordRef = null,
        array $opts = []
    ): void {
        $user = Auth::user();
        $changes = $opts['changes'] ?? null;
        if ($changes === null && (isset($opts['before']) || isset($opts['after']))) {
            $changes = self::diff($opts['before'] ?? [], $opts['after'] ?? []);
        }
        DB::insert('audit_trail', [
            'user_id'     => $opts['user_id'] ?? ($user['id'] ?? null),
            'username'    => $opts['username'] ?? ($user['username'] ?? null),
            'user_roles'  => $opts['roles'] ?? ($user ? implode(', ', Access::roleNames()) : null),
            'ip_address'  => client_ip(),
            'workstation' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'CLI' : '')), 0, 250),
            'site_id'     => $opts['site_id'] ?? null,
            'terminal_id' => $opts['terminal_id'] ?? ($_SESSION['pos_terminal_id'] ?? null),
            'module'      => $module,
            'page_key'    => $opts['page_key'] ?? ($GLOBALS['CURRENT_PAGE'] ?? null),
            'action'      => $action,
            'record_type' => $recordType,
            'record_id'   => $recordId,
            'record_ref'  => $recordRef !== null ? mb_substr($recordRef, 0, 160) : null,
            'amount'      => isset($opts['amount']) ? r2($opts['amount']) : null,
            'changes'     => $changes ? json_encode(array_values($changes), JSON_UNESCAPED_UNICODE) : null,
            'snapshot'    => isset($opts['snapshot']) ? json_encode($opts['snapshot'], JSON_UNESCAPED_UNICODE) : null,
            'reason'      => isset($opts['reason']) ? mb_substr((string)$opts['reason'], 0, 255) : null,
            'approved_by' => $opts['approved_by'] ?? null,
        ]);
    }

    /**
     * Puts the audit trail protection triggers (database/triggers.sql) in place on this company's database,
     * or on another one ($db). A restore drops and recreates the audit_trail table, and a dropped table
     * loses its triggers, so a restore calls this too. Returns '' when done, else why it could not be done
     * (some hosts only let a MySQL user with SUPER create triggers).
     */
    public static function ensureTriggers(?Sql $db = null): string
    {
        try {
            foreach (Migrator::split((string)file_get_contents(APP_ROOT . '/database/triggers.sql')) as $stmt) {
                $db ? $db->exec($stmt) : DB::pdo()->exec($stmt);
            }
            return '';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Are both protection triggers there? */
    public static function triggersInPlace(?Sql $db = null): bool
    {
        $sql = "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()
                   AND trigger_name IN ('trg_audit_trail_no_update', 'trg_audit_trail_no_delete')";
        return (int)($db ? $db->value($sql) : DB::value($sql)) === 2;
    }

    /** Field-level differences between two flat arrays (nested "lines" compared line by line). */
    public static function diff(array $before, array $after, string $prefix = ''): array
    {
        $out = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $k) {
            if (in_array($k, self::IGNORE, true)) {
                continue;
            }
            $old = $before[$k] ?? null;
            $new = $after[$k] ?? null;
            if (is_array($old) || is_array($new)) {
                $old = is_array($old) ? $old : [];
                $new = is_array($new) ? $new : [];
                if (array_is_list($old) && array_is_list($new)) {
                    $n = max(count($old), count($new));
                    for ($i = 0; $i < $n; $i++) {
                        $o = $old[$i] ?? null;
                        $nw = $new[$i] ?? null;
                        $label = $prefix . $k . '[' . ($i + 1) . ']';
                        if ($o === null) {
                            $out[] = ['field' => $label, 'old' => null, 'new' => self::lineText($nw)];
                        } elseif ($nw === null) {
                            $out[] = ['field' => $label, 'old' => self::lineText($o), 'new' => null];
                        } elseif (is_array($o) && is_array($nw)) {
                            $out = array_merge($out, self::diff($o, $nw, $label . '.'));
                        } elseif ((string)$o !== (string)$nw) {
                            $out[] = ['field' => $label, 'old' => $o, 'new' => $nw];
                        }
                    }
                } else {
                    $out = array_merge($out, self::diff($old, $new, $prefix . $k . '.'));
                }
                continue;
            }
            if (self::norm($old) !== self::norm($new)) {
                $out[] = ['field' => $prefix . $k, 'old' => $old, 'new' => $new];
            }
        }
        return $out;
    }

    private static function norm($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
        }
        return trim((string)$v);
    }

    private static function lineText($line): string
    {
        if (!is_array($line)) {
            return (string)$line;
        }
        $parts = [];
        foreach (['item', 'account', 'description', 'qty', 'rate', 'amount'] as $k) {
            if (isset($line[$k]) && $line[$k] !== '' && $line[$k] !== null) {
                $parts[] = "$k=" . $line[$k];
            }
        }
        return implode('; ', $parts);
    }

    /** A readable snapshot of a transaction (header, lines, applications). */
    public static function txnSnapshot(int $txnId): array
    {
        $t = DB::row(
            'SELECT t.*, c.name AS customer, v.name AS vendor, a.name AS account, cl.name AS class, o.name AS other_name
               FROM transactions t
               LEFT JOIN customers c ON c.id = t.customer_id
               LEFT JOIN vendors v ON v.id = t.vendor_id
               LEFT JOIN accounts a ON a.id = t.account_id
               LEFT JOIN classes cl ON cl.id = t.class_id
               LEFT JOIN other_names o ON o.id = t.other_name_id
              WHERE t.id = :id',
            ['id' => $txnId]
        );
        if (!$t) {
            return [];
        }
        $keep = ['txn_type', 'txn_no', 'txn_date', 'due_date', 'ref_no', 'customer', 'vendor', 'other_name', 'payee_name', 'account', 'class',
            'memo', 'subtotal', 'tax_total', 'discount_amount', 'withholding_amount', 'total', 'status', 'is_pending',
            'is_voided', 'terms_id', 'sales_rep_id', 'site_id', 'receipt_no', 'site2_id', 'process_type', 'alloc_method', 'process_formula_id', 'process_batches'];
        $snap = array_intersect_key($t, array_flip($keep));
        $snap['lines'] = DB::all(
            'SELECT l.line_kind, i.code AS item, a.code AS account, l.description, l.qty, l.rate, l.discount_amount, l.amount, l.tax_amount, l.site_id, l.is_billable,
                    l.output_role, l.alloc_basis, cl.name AS class
               FROM transaction_lines l
               LEFT JOIN items i ON i.id = l.item_id
               LEFT JOIN accounts a ON a.id = l.account_id
               LEFT JOIN classes cl ON cl.id = l.class_id
              WHERE l.txn_id = :id ORDER BY l.line_no, l.id',
            ['id' => $txnId]
        );
        $apps = DB::all(
            'SELECT t2.txn_type, t2.txn_no, pa.amount, pa.discount, pa.withholding
               FROM payment_applications pa JOIN transactions t2 ON t2.id = pa.target_txn_id
              WHERE pa.payment_txn_id = :id',
            ['id' => $txnId]
        );
        if ($apps) {
            $snap['applied_to'] = $apps;
        }
        return $snap;
    }
}
