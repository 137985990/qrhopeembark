<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

qr_require_login();

$error = '';
$notice = qr_get_flash('notice');
$mustChange = qr_user_must_change_password();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qr_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = '会话已过期，请刷新页面后重试。';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (strlen($newPassword) < 6) {
            $error = '新密码至少需要 6 位。';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '两次输入的新密码不一致。';
        } elseif ($currentPassword === $newPassword) {
            $error = '新密码不能与当前密码相同。';
        } elseif (qr_is_admin()) {
            if (!hash_equals(ADMIN_PASS, $currentPassword)) {
                $error = '当前管理员密码不正确。';
            } elseif (!qr_update_admin_password($newPassword)) {
                $error = '管理员密码更新失败，请稍后重试。';
            } else {
                qr_set_flash('notice', '管理员密码已更新，下次请使用新密码登录。');
                qr_redirect(qr_admin_path('index.php'));
            }
        } else {
            try {
                $mysqli = qr_db();
                $code = qr_current_counselor_code();
                $stmt = $mysqli->prepare('SELECT password_hash FROM counselors WHERE code = ? LIMIT 1');
                $stmt->bind_param('s', $code);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $result->free();
                $stmt->close();

                if (!$row || !password_verify($currentPassword, (string)($row['password_hash'] ?? ''))) {
                    $error = '当前密码不正确。';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $update = $mysqli->prepare('UPDATE counselors SET password_hash = ?, must_change_password = 0 WHERE code = ?');
                    $update->bind_param('ss', $hash, $code);
                    $update->execute();
                    $update->close();
                    $mysqli->close();

                    $_SESSION['qr_must_change_password'] = false;
                    qr_set_flash('notice', '密码修改成功。');
                    qr_redirect(qr_dashboard_path());
                }

                $mysqli->close();
            } catch (Throwable $throwable) {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $mysqli->close();
                }

                $error = '密码暂时无法修改，请稍后再试。';
            }
        }
    }
}

$csrf = qr_csrf_token();
$backLink = qr_is_admin() ? qr_admin_path('index.php') : qr_dashboard_path();

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>修改密码</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <span class="eyebrow"><?php echo qr_is_admin() ? '管理员密码' : '顾问密码'; ?></span>
      <h1><?php echo $mustChange ? '请先修改密码' : '修改当前密码'; ?></h1>
      <p class="auth-copy"><?php echo $mustChange ? '首次登录必须先把默认密码改掉，完成后才能继续使用系统。' : '为了账号安全，你可以随时在这里更新密码。'; ?></p>

      <?php if ($notice !== ''): ?><div class="notice"><?php echo qr_h($notice); ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="error-banner"><?php echo qr_h($error); ?></div><?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>">
        <div class="field">
          <label for="current_password">当前密码</label>
          <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label for="new_password">新密码</label>
          <input id="new_password" type="password" name="new_password" autocomplete="new-password" required>
        </div>
        <div class="field">
          <label for="confirm_password">确认新密码</label>
          <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required>
        </div>
        <div class="button-row">
          <button class="button button-primary" type="submit">保存密码</button>
          <?php if (!$mustChange): ?><a class="button button-secondary" href="<?php echo qr_h($backLink); ?>">返回</a><?php endif; ?>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
