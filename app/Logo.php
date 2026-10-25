<?php
declare(strict_types=1);

/**
 * Company logo (System Administration > Company, Security & Closing Date).
 * The file is kept in storage/company/ (not reachable from the web) and sent through index.php?p=file.logo.
 * The administrator ticks where it prints: every report, and every kind of voucher / document.
 */
final class Logo
{
    public const DIR = '/storage/company';
    public const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'];
    public const MAX_BYTES = 2097152;   // 2 MB

    /** Documents that are not plain transaction types */
    private const EXTRA_DOCS = [
        'doc.pos_receipt'  => 'POS Receipt (register)',
        'doc.statement'    => 'Customer Statement',
        'doc.maintenance'  => 'Asset Maintenance Record',
    ];

    private static ?array $on = null;

    /** The company's own folder for its logo (storage/company for the first company) */
    public static function dir(): string
    {
        return Tenant::storage('company');
    }

    public static function flush(): void
    {
        self::$on = null;
    }

    /** Full path of the saved logo, or null */
    public static function path(): ?string
    {
        $name = basename((string)Settings::get('company_logo', ''));
        if ($name === '') {
            return null;
        }
        $file = self::dir() . '/' . $name;
        return is_file($file) ? $file : null;
    }

    public static function url(): string
    {
        $file = self::path();
        return $file ? url('file.logo', ['v' => (string)filemtime($file)]) : '';
    }

    /** Is the logo switched on for this report / document? Keys: page keys of reports (rpt.xxx) and doc.<type> */
    public static function enabled(string $key): bool
    {
        if (self::$on === null) {
            self::$on = array_flip(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)Settings::get('logo_targets', '')))));
        }
        return isset(self::$on[$key]) && self::path() !== null;
    }

    /** <img> for a print-out, or '' */
    public static function img(string $key, string $class = 'doc-logo'): string
    {
        return self::enabled($key) ? '<img class="' . h($class) . '" src="' . h(self::url()) . '" alt="">' : '';
    }

    public static function forTxn(string $txnType): string
    {
        return self::img('doc.' . $txnType);
    }

    /**
     * Everything the logo can be printed on, grouped for the settings page:
     * ['Vouchers and documents' => [key => label], '<Module> reports' => [...], ...]
     */
    public static function targets(): array
    {
        $docs = [];
        $voucherNames = ['check' => 'Check Voucher', 'cash_voucher' => 'Cash Voucher', 'journal' => 'Journal Voucher (journal entry)', 'bill_payment' => 'Check / Payment Voucher (Pay Bills)',
            'deposit' => 'Deposit Voucher', 'transfer' => 'Fund Transfer Voucher', 'vendor_credit' => 'Supplier Debit Memo', 'pos_sale' => 'POS Sale (full page print)', 'pos_return' => 'POS Return (full page print)'];
        foreach (Txn::TYPES as $type => $def) {
            $docs['doc.' . $type] = $voucherNames[$type] ?? $def[0];
        }
        $docs += self::EXTRA_DOCS;
        asort($docs);
        $out = ['Vouchers and documents' => $docs];
        $reg = require APP_ROOT . '/app/registry.php';
        $modules = $reg['modules'] ?? [];
        foreach ($reg['pages'] as $key => $def) {
            if (($def[2] ?? '') !== 'report') {
                continue;
            }
            $m = $modules[$def[0]] ?? null;
            $mName = is_array($m) ? (string)($m['name'] ?? $m[0] ?? $def[0]) : (string)($m ?? $def[0]);
            $out[$mName . ' reports'][$key] = $def[1];
        }
        return $out;
    }

    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::targets() as $group) {
            $keys = array_merge($keys, array_keys($group));
        }
        return $keys;
    }

    /** Stores an uploaded logo ($_FILES entry). Returns the saved file name. */
    public static function store(array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE ? 'The logo file is too large.' : 'The logo could not be uploaded. Try again.');
        }
        if ((int)$upload['size'] > self::MAX_BYTES) {
            fail('The logo file is larger than 2 MB. Save a smaller copy and upload that.');
        }
        $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            fail('Upload a picture file: ' . implode(', ', array_map(fn($e) => '.' . $e, array_keys(self::TYPES))) . '.');
        }
        $info = @getimagesize($upload['tmp_name']);
        if (!$info || !in_array($info['mime'], self::TYPES, true)) {
            fail('That file is not a picture the system can show. Use a JPG or PNG file.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fail('The folder storage/company could not be created. Make the storage folder writable by the web server.');
        }
        $name = 'logo_' . date('YmdHis') . '.' . $ext;
        if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) {
            fail('The logo could not be saved. Make the storage folder writable by the web server.');
        }
        foreach (glob($dir . '/logo_*') ?: [] as $old) {
            if (basename($old) !== $name) {
                @unlink($old);
            }
        }
        return $name;
    }

    public static function remove(): void
    {
        foreach (glob(self::dir() . '/logo_*') ?: [] as $old) {
            @unlink($old);
        }
    }

    /** Sends the picture (index.php?p=file.logo) */
    public static function send(): never
    {
        $file = self::path();
        if (!$file) {
            http_response_code(404);
            exit;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . (self::TYPES[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;
    }
}
