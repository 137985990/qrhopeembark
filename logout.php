<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

qr_start_session();
qr_clear_auth();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
}

session_destroy();
qr_set_flash('notice', '你已安全退出登录。');
qr_redirect(qr_login_path());
