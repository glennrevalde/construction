<?php
declare(strict_types=1);

// ---------------------------------------------------------------- the product

/** The name of the system, shown in the top bar, on the log-in page and in every browser tab */
const APP_PRODUCT_NAME = 'GSMaRT Construction System';

/**
 * The name the system goes by. config/config.php still carries the name it was installed with
 * ('Accounting System'); that one is taken as "not changed" and the product name is used. A name that
 * was deliberately put in config.php is kept, so an installation can still call itself something else.
 */
function app_name(): string
{
    $set = trim((string)($GLOBALS['CONFIG']['app']['name'] ?? ''));
    return ($set === '' || strcasecmp($set, 'Accounting System') === 0 || strcasecmp($set, 'GSMaRT Construction System') === 0) ? APP_PRODUCT_NAME : $set;
}

// ---------------------------------------------------------------- output

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($v, bool $blankZero = false): string
{
    $v = round((float)$v, 2);
    if ($blankZero && abs($v) < 0.005) {
        return '';
    }
    $s = number_format(abs($v), 2);
    return $v < 0 ? '(' . $s . ')' : $s;
}

function qty($v): string
{
    $v = (float)$v;
    $s = rtrim(rtrim(number_format($v, 4, '.', ','), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

function pct($v): string
{
    return number_format((float)$v, 2) . '%';
}

function fdate($d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime((string)$d);
    return $t ? date('m/d/Y', $t) : '';
}

function fdatetime($d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime((string)$d);
    return $t ? date('m/d/Y h:i A', $t) : '';
}

function today(): string
{
    return date('Y-m-d');
}

function r2($v): float
{
    return round((float)$v, 2);
}

// ---------------------------------------------------------------- urls

function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    }
    return $base;
}

function url(string $page, array $params = []): string
{
    $q = array_merge(['p' => $page], $params);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return base_url() . '/index.php?' . http_build_query($q);
}

/** A Super Admin (platform) page */
function purl(string $page = 'companies', array $params = []): string
{
    $q = array_merge(['p' => $page], $params);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return base_url() . '/platform.php?' . http_build_query($q);
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    // the file's modification time is added so browsers pick up an updated stylesheet or script right away
    $file = APP_ROOT . '/assets/' . $path;
    $ver = is_file($file) ? '?v=' . filemtime($file) : '';
    return base_url() . '/assets/' . $path . $ver;
}

/** Bootstrap / icon files: served from assets/vendor when present (offline installs), otherwise from the CDN */
function vendor_asset(string $which): string
{
    $map = [
        'bootstrap.css' => ['vendor/bootstrap/css/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'],
        'bootstrap.js'  => ['vendor/bootstrap/js/bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'],
        'icons.css'     => ['vendor/bootstrap-icons/font/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'],
    ];
    [$local, $cdn] = $map[$which];
    return is_file(APP_ROOT . '/assets/' . $local) ? asset($local) : $cdn;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function current_url(array $override = []): string
{
    $q = array_merge($_GET, $override);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return base_url() . '/index.php?' . http_build_query($q);
}

// ---------------------------------------------------------------- flash / csrf

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/**
 * Put in a form that saves stock (sales invoice, sales receipt, POS sale, inventory adjustment, transfer): a save that
 * would take an item below zero at a site is not done straight away but asked about first ("Saving the transaction
 * will lead the inventory to a negative balance. Do you want to continue?"). The button pressed is remembered, so Yes
 * presses the same one again (Save & Close, Save & New...).
 */
function negative_stock_fields(): string
{
    $btn = is_post() ? (string)($_POST['confirm_submitter'] ?? '') : '';
    return '<input type="hidden" name="confirm_negative" value="0">'
        . '<input type="hidden" name="confirm_submitter" value="' . h(mb_substr($btn, 0, 80)) . '">';
}

/** The form asked to be asked before stock goes below zero, and has not said yes yet */
function negative_stock_ask(): bool
{
    return PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['confirm_negative'] ?? null) === '0';
}

function verify_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $packed = unpack_posted_form();
        $t = $_POST['_csrf'] ?? '';
        if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
            http_response_code(419);
            exit('Your session expired or the form was already submitted. Go back, refresh the page and try again.');
        }
        // PHP keeps only the first max_input_vars fields of a form (1000 as installed) and drops the rest without a
        // word, which would save a long invoice or bill without its last lines. The browser sends long forms packed
        // in one field (see app.js); a long form that still arrives unpacked is refused rather than half saved.
        $limit = (int)ini_get('max_input_vars');
        if (!$packed && $limit > 0 && count_input_vars($_POST) >= $limit) {
            http_response_code(413);
            exit('This form has more fields than the server accepts at once (' . $limit . '), so nothing was saved: the last lines would have been lost. '
                . 'Refresh the page (Ctrl+F5) so the browser loads the current program files, then enter it again. '
                . 'Or ask the administrator to raise max_input_vars in php.ini.');
        }
    }
}

/** How many fields a posted array holds (each leaf counts once, as PHP counts them) */
function count_input_vars(array $a): int
{
    $n = 0;
    foreach ($a as $v) {
        $n += is_array($v) ? max(1, count_input_vars($v)) : 1;
    }
    return $n;
}

/**
 * A form with many fields (an invoice or bill with a hundred lines, the grid of every item) is sent by the
 * browser as one field, _packed: a JSON list of [name, value] pairs. It is unfolded here into $_POST exactly as
 * PHP would have built it, so no page has to know. Returns true when the form came packed.
 */
function unpack_posted_form(): bool
{
    if (!isset($_POST['_packed']) || !is_string($_POST['_packed'])) {
        return false;
    }
    $pairs = json_decode($_POST['_packed'], true);
    unset($_POST['_packed']);
    if (!is_array($pairs)) {
        return false;
    }
    $out = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0])) {
            continue;
        }
        set_input_var($out, $pair[0], is_scalar($pair[1]) ? (string)$pair[1] : '');
    }
    $_POST = $out + $_POST;
    $_REQUEST = $_POST + $_REQUEST;
    return true;
}

/** Puts one field into an array the way PHP reads names such as lines[3][item_id] and hide[] */
function set_input_var(array &$into, string $name, string $value): void
{
    $open = strpos($name, '[');
    $base = $open === false ? $name : substr($name, 0, $open);
    $base = strtr($base, ['.' => '_', ' ' => '_']);
    if ($base === '') {
        return;
    }
    $keys = [];
    if ($open !== false && preg_match_all('/\[([^\]]*)\]/', substr($name, $open), $m)) {
        $keys = $m[1];
    }
    if (!$keys) {
        $into[$base] = $value;
        return;
    }
    if (!isset($into[$base]) || !is_array($into[$base])) {
        $into[$base] = [];
    }
    $ref = &$into[$base];
    $last = count($keys) - 1;
    foreach ($keys as $i => $k) {
        if ($i === $last) {
            if ($k === '') {
                $ref[] = $value;
            } else {
                $ref[$k] = $value;
            }
            break;
        }
        if ($k === '') {
            $ref[] = [];
            end($ref);
            $k = (string)key($ref);
        }
        if (!isset($ref[$k]) || !is_array($ref[$k])) {
            $ref[$k] = [];
        }
        $ref = &$ref[$k];
    }
    unset($ref);
}

// ---------------------------------------------------------------- input

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function g(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function p(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function pint(string $k): ?int
{
    $v = $_POST[$k] ?? '';
    return ($v === '' || $v === null) ? null : (int)$v;
}

function gint(string $k): ?int
{
    $v = $_GET[$k] ?? '';
    return ($v === '' || $v === null) ? null : (int)$v;
}

function pnum(string $k, float $default = 0.0): float
{
    return to_num($_POST[$k] ?? $default);
}

function to_num($v): float
{
    if (is_string($v)) {
        $v = str_replace([',', ' '], '', $v);
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function valid_date($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}

function pdate(string $k, ?string $default = null): ?string
{
    $v = $_POST[$k] ?? '';
    return valid_date($v) ? $v : $default;
}

function gdate(string $k, ?string $default = null): ?string
{
    $v = $_GET[$k] ?? '';
    return valid_date($v) ? $v : $default;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

// ---------------------------------------------------------------- forms

/** <option> list from [id => label] */
function options(array $pairs, $selected = null, ?string $blank = null): string
{
    $out = $blank !== null ? '<option value="">' . h($blank) . '</option>' : '';
    foreach ($pairs as $k => $v) {
        $sel = ((string)$k === (string)$selected) ? ' selected' : '';
        $out .= '<option value="' . h($k) . '"' . $sel . '>' . h($v) . '</option>';
    }
    return $out;
}

/**
 * Hidden form fields for a set of values, nested arrays included (f[type][v][]=...), so a form can
 * carry along what the address already holds.
 */
function hidden_inputs(array $values, string $prefix = ''): string
{
    $out = '';
    foreach ($values as $k => $v) {
        $name = $prefix === '' ? (string)$k : $prefix . '[' . (is_int($k) ? '' : $k) . ']';
        if (is_array($v)) {
            $out .= hidden_inputs($v, $name);
        } elseif ($v !== null) {
            $out .= '<input type="hidden" name="' . h($name) . '" value="' . h((string)$v) . '">';
        }
    }
    return $out;
}

function checked($v): string
{
    return $v ? ' checked' : '';
}

function badge(string $text, string $color = 'secondary'): string
{
    return '<span class="badge text-bg-' . h($color) . '">' . h($text) . '</span>';
}

function status_badge(?string $status): string
{
    $map = [
        'open' => 'primary', 'paid' => 'success', 'closed' => 'secondary', 'partial' => 'warning',
        'pending' => 'warning', 'accepted' => 'success', 'void' => 'dark', 'suspended' => 'info',
        'completed' => 'success', 'active' => 'success', 'inactive' => 'secondary', 'locked' => 'danger',
        'for_review' => 'warning', 'matched' => 'success', 'created' => 'success', 'rejected' => 'secondary',
        'disposed' => 'dark', 'fully_depreciated' => 'secondary', 'in_progress' => 'warning',
        'received' => 'success', 'backordered' => 'warning', 'unapplied' => 'info', 'applied' => 'success',
    ];
    $s = (string)$status;
    return badge(ucwords(str_replace('_', ' ', $s)), $map[$s] ?? 'secondary');
}

/** 1,250.50 -> "One thousand two hundred fifty pesos and 50/100 only" (vouchers and checks) */
function amount_in_words(float $amount, string $unit = 'pesos'): string
{
    $amount = round(abs($amount), 2);
    $whole = (int)floor($amount + 0.000001);
    $cents = (int)round(($amount - $whole) * 100);
    if ($cents === 100) {
        $whole++;
        $cents = 0;
    }
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $below1000 = function (int $n) use ($ones, $tens): string {
        $out = [];
        if ($n >= 100) {
            $out[] = $ones[intdiv($n, 100)] . ' hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        } elseif ($n > 0) {
            $out[] = $ones[$n];
        }
        return implode(' ', $out);
    };
    $parts = [];
    foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $size => $label) {
        $chunk = intdiv($whole, $size);
        $whole %= $size;
        if ($chunk > 0) {
            $parts[] = trim($below1000($chunk) . ' ' . $label);
        }
    }
    $words = $parts ? implode(' ', $parts) : 'zero';
    return ucfirst($words) . ' ' . $unit . ' and ' . str_pad((string)$cents, 2, '0', STR_PAD_LEFT) . '/100 only';
}

/**
 * How an item is shown in the drop-downs of the transaction forms.
 * System Administration > Company, Security & Closing Date > "Searching for items" decides the order:
 *   code_name  PORK CHOP - Pork chop, cut          (item name/code first, then the display name)
 *   name_code  Pork chop, cut - PORK CHOP          (display name first, then the item name/code)
 * Typing any part of either one finds the item, whichever order is chosen.
 *
 * Each transaction can be set on its own; with nothing chosen for it, it follows the overall choice.
 * The transaction is the page being looked at, unless one is named.
 */
function item_label(?string $code, ?string $name, ?string $pageKey = null): string
{
    $code = trim((string)$code);
    $name = trim((string)$name);
    if ($code === '' || $name === '' || strcasecmp($code, $name) === 0) {
        return $code !== '' ? $code : $name;
    }
    return Settings::itemFormat($pageKey) === 'name_code' ? $name . ' - ' . $code : $code . ' - ' . $name;
}

/** The two item columns of a list, in the order this transaction asks for: [heading1, heading2] */
function item_cols(?string $pageKey = null): array
{
    return Settings::itemFormat($pageKey) === 'name_code'
        ? ['Display name', 'Item name/code'] : ['Item name/code', 'Display name'];
}

/** The matching values of one item row, in the same order as item_cols() */
function item_vals(?string $code, ?string $name, ?string $pageKey = null): array
{
    return Settings::itemFormat($pageKey) === 'name_code'
        ? [(string)$name, (string)$code] : [(string)$code, (string)$name];
}

function method_label(string $m): string
{
    return [
        'straight_line' => 'Straight-line',
        'declining_balance' => 'Declining balance',
        'units_of_production' => 'Units of production',
        'syd' => "Sum-of-the-years'-digits",
    ][$m] ?? $m;
}

/** Throw a user-facing validation error */
class UserError extends RuntimeException
{
}

/** Not an error: the form did what was asked without saving (for example it was memorized), and is shown again */
final class FormNotice extends UserError
{
}

/**
 * Not an error but a question: the save would take stock below zero (allowed in the settings), and the form asked
 * to be told first (confirm_negative = 0). Nothing is saved; the form comes back with a Yes / No box, and Yes sends
 * it again with confirm_negative = 1.
 */
final class StockQuestion extends UserError
{
    /** @param array<int, array{0:string,1:string,2:float,3:float}> $short item code, site, quantity before, quantity after */
    public function __construct(string $message, public readonly array $short)
    {
        parent::__construct($message);
    }
}

function fail(string $msg): never
{
    throw new UserError($msg);
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function csv_download(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, $r, ',', '"', '');
    }
    fclose($out);
    exit;
}
