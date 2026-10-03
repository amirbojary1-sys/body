<?php
declare(strict_types=1);
require __DIR__ . '/inc/admin.php';
\FitBot\AdminAuth::logout();
\FitBot\AdminAuth::flash('success', 'از پنل مدیریت خارج شدی.');
admin_redirect('login.php');
