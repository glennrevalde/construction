<?php
declare(strict_types=1);

// Marks the user's notifications read (one, or all), then goes back to the alerts page or the notification's link.
verify_csrf();
if (is_post()) {
    $id = (int)p('id');
    if ($id) {
        $n = DB::row('SELECT * FROM cs_notifications WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => Auth::id()]);
        if ($n) {
            DB::run('UPDATE cs_notifications SET is_read = 1 WHERE id = :id', ['id' => $id]);
            if (p('go') === '1' && $n['link'] && str_starts_with((string)$n['link'], base_url() . '/')) {
                redirect((string)$n['link']);
            }
        }
    } else {
        DB::run('UPDATE cs_notifications SET is_read = 1 WHERE user_id = :u', ['u' => Auth::id()]);
    }
}
redirect(url('home.alerts'));
