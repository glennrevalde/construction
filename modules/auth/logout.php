<?php
declare(strict_types=1);

Auth::logout();
session_start();
flash('success', 'You have been logged out.');
redirect(base_url() . '/login.php');
