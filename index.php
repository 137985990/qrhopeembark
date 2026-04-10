<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (qr_is_admin() || qr_is_counselor()) {
    qr_redirect_after_login();
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qr_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = '会话已过期，请刷新页面后重试。';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $loginCode = qr_counselor_code($username);

        if (strcasecmp($username, ADMIN_USER) === 0 && hash_equals(ADMIN_PASS, $password)) {
            qr_login_admin();
            qr_redirect_after_login();
        }

        try {
            $mysqli = qr_db();
            $stmt = $mysqli->prepare('SELECT code, name, active, password_hash, must_change_password FROM counselors WHERE code = ? LIMIT 1');
            $stmt->bind_param('s', $loginCode);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $result->free();
            $stmt->close();

            if (!$row) {
                $error = '账号或密码错误。';
            } elseif ((int)($row['active'] ?? 0) !== 1) {
                $error = '该账号已停用，请联系管理员。';
            } elseif (!is_string($row['password_hash'] ?? null) || $row['password_hash'] === '' || !password_verify($password, (string)$row['password_hash'])) {
                $error = '账号或密码错误。';
            } else {
                $update = $mysqli->prepare('UPDATE counselors SET last_login_at = NOW() WHERE code = ?');
                $update->bind_param('s', $loginCode);
                $update->execute();
                $update->close();
                $mysqli->close();

                qr_login_counselor($row);
                qr_redirect_after_login();
            }

            $mysqli->close();
        } catch (Throwable $throwable) {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $mysqli->close();
            }

            $error = '登录服务暂时不可用，请稍后重试。';
        }
    }
}

$notice = qr_get_flash('notice');
$csrf = qr_csrf_token();

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>恒点学术科研项目登录</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
  <style>
    .login-shell { width: min(calc(100% - var(--space-8)), 64rem); margin: 0 auto; min-height: 100vh; display: grid; align-items: center; padding: var(--space-8) 0; }
    .login-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(18rem, 0.9fr); gap: var(--space-5); }
    .login-panel, .login-card { background: rgba(255,255,255,.94); border: var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-soft); }
    .login-panel { padding: var(--space-8); }
    .login-panel h1 { margin: 0 0 var(--space-3); font-family: var(--font-display); font-size: clamp(2.2rem, 4vw, 3.4rem); line-height: 1.02; }
    .login-copy, .login-list, .login-footnote { color: var(--color-muted); }
    .login-list { margin: var(--space-5) 0 0; padding-left: 1.2rem; display: grid; gap: var(--space-2); }
    .login-card { padding: var(--space-6); display: grid; gap: var(--space-4); }
    .login-card h2 { margin: 0; font-family: var(--font-display); font-size: 2rem; }
    .login-card .button { width: 100%; }
    @media (max-width: 58rem) { .login-shell { width: min(calc(100% - var(--space-5)), 64rem); } .login-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <main class="login-shell">
    <section class="login-grid">
      <article class="login-panel">
        <span class="eyebrow">恒点学术科研项目</span>
        <h1>统一登录入口</h1>
        <p class="login-copy">顾问直接使用自己的 <strong>code</strong> 登录，例如 <strong>LVS</strong>、<strong>HBC</strong>、<strong>BLG</strong>。顾问默认密码为 <strong>123456</strong>，首次登录后必须修改密码。</p>
        <ul class="login-list">
          <li>管理员可查看全部顾问、全部扫码统计、全部报名表，并导出 Excel 兼容表格。</li>
          <li>顾问登录后仅可查看自己的二维码、自己的扫码数据和自己的报名数据。</li>
          <li>扫码学生将先进入中文报名表，提交成功后再进入官网说明页。</li>
        </ul>
      </article>

      <article class="login-card">
        <div>
          <span class="eyebrow">账号登录</span>
          <h2>欢迎回来</h2>
        </div>

        <?php if ($notice !== ''): ?>
          <div class="notice"><?php echo qr_h($notice); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="error-banner"><?php echo qr_h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>">
          <div class="field">
            <label for="username">账号 / 顾问 Code</label>
            <input id="username" name="username" value="<?php echo qr_h($username); ?>" autocomplete="username" required>
          </div>
          <div class="field">
            <label for="password">密码</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
          </div>
          <button class="button button-primary" type="submit">立即登录</button>
        </form>

        <p class="login-footnote">管理员和顾问账号都从这里进入系统。</p>
      </article>
    </section>
  </main>
</body>
</html>
