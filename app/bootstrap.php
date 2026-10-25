<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['CONFIG'] = require APP_ROOT . '/config/config.php';
date_default_timezone_set($GLOBALS['CONFIG']['app']['timezone']);

error_reporting(E_ALL);
ini_set('display_errors', $GLOBALS['CONFIG']['app']['debug'] ? '1' : '0');

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/app/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('GSCSESSID');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

set_exception_handler(function (Throwable $e): void {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    error_log((string)$e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (string)$e . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $msg = $GLOBALS['CONFIG']['app']['debug'] ? (string)$e : $e->getMessage();
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<div style="font-family:sans-serif;max-width:760px;margin:60px auto">'
        . '<h2>Something went wrong</h2><pre style="white-space:pre-wrap;background:#f6f6f6;padding:12px">'
        . htmlspecialchars($msg) . '</pre><p><a href="javascript:history.back()">Go back</a></p></div>';
});

// Which company this request works for, and so which database every module uses (app/Tenant.php).
// Command-line tools choose theirs with Tenant::bootCli().
if (PHP_SAPI !== 'cli') {
    Tenant::boot();
}
