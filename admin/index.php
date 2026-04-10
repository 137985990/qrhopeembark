<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

qr_require_admin();

function qr_admin_index_url(array $params = []): string
{
    $base = qr_admin_path('index.php');
    return $params === [] ? $base : $base . '?' . http_build_query($params);
}

$errors = [];
$editing = false;
$form = ['original_code' => '', 'code' => '', 'name' => '', 'remarks' => '', 'active' => true];
$notice = qr_get_flash('notice');
$errorBanner = qr_get_flash('error');

try {
    $mysqli = qr_db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!qr_verify_csrf($_POST['csrf_token'] ?? null)) {
            $errorBanner = '会话已过期，请刷新页面后重试。';
        } else {
            $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

            if ($action === 'logout') {
                qr_clear_auth();
                session_destroy();
                qr_redirect(qr_login_path());
            }

            if ($action === 'clear_test_data') {
                $mysqli->query('TRUNCATE TABLE scans_raw');
                $mysqli->query('TRUNCATE TABLE unique_counts');
                qr_set_flash('notice', '扫码测试数据已清空。');
                qr_redirect(qr_admin_index_url());
            }

            if ($action === 'toggle_counselor') {
                $targetCode = qr_counselor_code((string)($_POST['code'] ?? ''));
                if ($targetCode !== '') {
                    $stmt = $mysqli->prepare('UPDATE counselors SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE code = ?');
                    $stmt->bind_param('s', $targetCode);
                    $stmt->execute();
                    $stmt->close();
                    qr_set_flash('notice', '顾问状态已更新。');
                }
                qr_redirect(qr_admin_index_url());
            }

            if ($action === 'reset_password') {
                $targetCode = qr_counselor_code((string)($_POST['code'] ?? ''));
                if ($targetCode !== '') {
                    $hash = qr_default_password_hash();
                    $stmt = $mysqli->prepare('UPDATE counselors SET password_hash = ?, must_change_password = 1 WHERE code = ?');
                    $stmt->bind_param('ss', $hash, $targetCode);
                    $stmt->execute();
                    $stmt->close();
                    qr_set_flash('notice', '已重置为默认密码 123456，下次登录必须修改。');
                }
                qr_redirect(qr_admin_index_url());
            }

            if ($action === 'save_counselor') {
                $editing = (string)($_POST['mode'] ?? '') === 'edit';
                $form = [
                    'original_code' => qr_counselor_code((string)($_POST['original_code'] ?? '')),
                    'code' => qr_counselor_code((string)($_POST['code'] ?? '')),
                    'name' => trim((string)($_POST['name'] ?? '')),
                    'remarks' => trim((string)($_POST['remarks'] ?? '')),
                    'active' => isset($_POST['active']),
                ];

                if ($form['code'] === '' || !preg_match('/^[A-Z0-9]{1,16}$/', $form['code'])) {
                    $errors[] = '顾问 Code 需为 1-16 位字母或数字。';
                }
                if ($form['name'] === '') {
                    $errors[] = '请填写顾问姓名。';
                }
                if ($editing && $form['original_code'] === '') {
                    $errors[] = '缺少要编辑的原始顾问 Code。';
                }

                if ($errors === []) {
                    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM counselors WHERE code = ? AND code <> ?');
                    $excludeCode = $editing ? $form['original_code'] : '';
                    $stmt->bind_param('ss', $form['code'], $excludeCode);
                    $stmt->execute();
                    $stmt->bind_result($duplicateCount);
                    $stmt->fetch();
                    $stmt->close();
                    if ((int)$duplicateCount > 0) {
                        $errors[] = '该顾问 Code 已存在。';
                    }
                }

                if ($errors === []) {
                    $active = $form['active'] ? 1 : 0;
                    if ($editing) {
                        $mysqli->begin_transaction();
                        $stmt = $mysqli->prepare('UPDATE counselors SET code = ?, name = ?, remarks = ?, active = ? WHERE code = ?');
                        $stmt->bind_param('sssis', $form['code'], $form['name'], $form['remarks'], $active, $form['original_code']);
                        $stmt->execute();
                        $stmt->close();

                        if ($form['code'] !== $form['original_code']) {
                            foreach (['scans_raw', 'unique_counts', 'lead_submissions'] as $table) {
                                $stmt = $mysqli->prepare('UPDATE ' . $table . ' SET counselor_code = ? WHERE counselor_code = ?');
                                $stmt->bind_param('ss', $form['code'], $form['original_code']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }

                        $mysqli->commit();
                        qr_set_flash('notice', '顾问信息已更新。');
                    } else {
                        $hash = qr_default_password_hash();
                        $mustChange = 1;
                        $stmt = $mysqli->prepare('INSERT INTO counselors (code, name, remarks, active, password_hash, must_change_password) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('sssisi', $form['code'], $form['name'], $form['remarks'], $active, $hash, $mustChange);
                        $stmt->execute();
                        $stmt->close();
                        qr_set_flash('notice', '顾问已新增，默认密码为 123456。');
                    }

                    qr_redirect(qr_admin_index_url());
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
        $editCode = qr_counselor_code((string)$_GET['edit']);
        if ($editCode !== '') {
            $counselor = qr_fetch_counselor($mysqli, $editCode);
            if ($counselor !== null) {
                $editing = true;
                $form = ['original_code' => (string)$counselor['code'], 'code' => (string)$counselor['code'], 'name' => (string)($counselor['name'] ?? ''), 'remarks' => (string)($counselor['remarks'] ?? ''), 'active' => (int)($counselor['active'] ?? 1) === 1];
            }
        }
    }

    $result = $mysqli->query("SELECT c.code, c.name, c.remarks, c.active, c.last_login_at, c.must_change_password,
      COALESCE(u.unique_count, 0) AS unique_count,
      COALESCE(r.raw_count, 0) AS raw_count,
      COALESCE(s.submission_count, 0) AS submission_count
      FROM counselors c
      LEFT JOIN (SELECT counselor_code, COUNT(*) AS unique_count FROM unique_counts GROUP BY counselor_code) u ON u.counselor_code = c.code
      LEFT JOIN (SELECT counselor_code, COUNT(*) AS raw_count FROM scans_raw GROUP BY counselor_code) r ON r.counselor_code = c.code
      LEFT JOIN (SELECT counselor_code, COUNT(*) AS submission_count FROM lead_submissions GROUP BY counselor_code) s ON s.counselor_code = c.code
      ORDER BY c.code");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    $submissionResult = $mysqli->query('SELECT submission_no, counselor_code, student_name, parent_contact, grade, interest_fields, background_level, submitted_at FROM lead_submissions ORDER BY submitted_at DESC LIMIT 150');
    $submissionRows = [];
    while ($row = $submissionResult->fetch_assoc()) {
        $submissionRows[] = $row;
    }
    $submissionResult->free();
    $mysqli->close();
} catch (Throwable $throwable) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            $mysqli->rollback();
        } catch (Throwable $rollbackError) {
        }
        $mysqli->close();
    }
    http_response_code(500);
    $errorBanner = '后台暂时不可用，请稍后刷新。';
    $rows = [];
    $submissionRows = [];
}

$summary = ['counselors' => count($rows), 'active' => 0, 'unique' => 0, 'raw' => 0, 'submissions' => 0];
foreach ($rows as $row) {
    if ((int)$row['active'] === 1) {
        $summary['active']++;
    }
    $summary['unique'] += (int)$row['unique_count'];
    $summary['raw'] += (int)$row['raw_count'];
    $summary['submissions'] += (int)$row['submission_count'];
}
$csrf = qr_csrf_token();

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>管理后台</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
</head>
<body>
  <main class="page-shell">
    <header class="page-header">
      <div>
        <span class="eyebrow">恒点学术科研项目后台</span>
        <h1>顾问管理与报名数据总览</h1>
        <p class="lede">管理员可以统一管理顾问账号、查看扫码统计、查看报名数据，并导出 Excel 兼容表格。</p>
      </div>
      <div class="page-actions">
        <a class="button button-secondary" href="/qr-redirect/report.php">月度报表</a>
        <a class="button button-secondary" href="<?php echo qr_h(qr_admin_submissions_path()); ?>">最近报名记录</a>
        <a class="button button-secondary" href="<?php echo qr_h(qr_export_path() . '?scope=all'); ?>">导出报名表</a>
        <a class="button button-quiet" href="/qr-redirect/change-password.php">修改管理员密码</a>
        <form method="post" data-confirm-reset>
          <input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>"><input type="hidden" name="action" value="clear_test_data">
          <button class="button button-danger" type="submit">清空扫码测试数据</button>
        </form>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>"><input type="hidden" name="action" value="logout"><button class="button button-quiet" type="submit">退出登录</button></form>
      </div>
    </header>

    <?php if ($notice !== ''): ?><div class="notice"><?php echo qr_h($notice); ?></div><?php endif; ?>
    <?php if ($errorBanner !== '' || $errors !== []): ?>
      <div class="error-banner"><?php echo qr_h($errorBanner); ?><?php if ($errors !== []): ?><ul class="error-list"><?php foreach ($errors as $message): ?><li><?php echo qr_h($message); ?></li><?php endforeach; ?></ul><?php endif; ?></div>
    <?php endif; ?>

    <section class="stats-grid">
      <article class="stat-card"><div class="stat-label">顾问总数</div><div class="stat-value"><?php echo $summary['counselors']; ?></div></article>
      <article class="stat-card"><div class="stat-label">启用顾问</div><div class="stat-value"><?php echo $summary['active']; ?></div></article>
      <article class="stat-card"><div class="stat-label">累计唯一扫码</div><div class="stat-value"><?php echo $summary['unique']; ?></div></article>
      <article class="stat-card"><div class="stat-label">累计总扫码</div><div class="stat-value"><?php echo $summary['raw']; ?></div></article>
      <article class="stat-card"><div class="stat-label">累计报名数</div><div class="stat-value"><?php echo $summary['submissions']; ?></div></article>
    </section>

    <section style="display:grid; gap: var(--space-6);">
      <article class="surface table-shell">
        <h2><?php echo $editing ? '编辑顾问信息' : '新增顾问'; ?></h2>
        <p class="helper-text">新增顾问后，系统会自动设置默认密码为 123456，首次登录必须修改密码。</p>
        <form class="form-grid" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>"><input type="hidden" name="action" value="save_counselor"><input type="hidden" name="mode" value="<?php echo $editing ? 'edit' : 'add'; ?>"><input type="hidden" name="original_code" value="<?php echo qr_h((string)$form['original_code']); ?>">
          <div class="field"><label for="code">顾问 Code</label><input id="code" name="code" maxlength="16" value="<?php echo qr_h((string)$form['code']); ?>" required></div>
          <div class="field"><label for="name">顾问姓名</label><input id="name" name="name" maxlength="128" value="<?php echo qr_h((string)$form['name']); ?>" required></div>
          <div class="field"><label for="remarks">备注</label><textarea id="remarks" name="remarks"><?php echo qr_h((string)$form['remarks']); ?></textarea></div>
          <label class="checkbox-row"><input type="checkbox" name="active" <?php echo $form['active'] ? 'checked' : ''; ?>>该顾问启用扫码和登录</label>
          <div class="button-row"><button class="button button-primary" type="submit"><?php echo $editing ? '保存修改' : '新增顾问'; ?></button><?php if ($editing): ?><a class="button button-secondary" href="<?php echo qr_h(qr_admin_index_url()); ?>">取消</a><?php endif; ?></div>
        </form>
      </article>

      <article class="surface table-shell">
        <h2>顾问账号与二维码</h2>
        <div class="table-scroll">
          <table class="data-table">
            <thead><tr><th>Code</th><th>姓名</th><th>二维码</th><th>报名链接</th><th>唯一扫码</th><th>总扫码</th><th>报名数</th><th>账号状态</th><th>操作</th></tr></thead>
            <tbody>
              <?php if ($rows === []): ?><tr><td colspan="9" class="empty-state">还没有顾问数据。</td></tr><?php endif; ?>
              <?php foreach ($rows as $row): ?>
                <?php $code = (string)$row['code']; $link = qr_public_link($code); $inputId = 'link-' . strtolower($code); $isActive = (int)$row['active'] === 1; $mustChange = (int)$row['must_change_password'] === 1; ?>
                <tr>
                  <td><span class="code-chip"><?php echo qr_h($code); ?></span></td>
                  <td><strong><?php echo qr_h((string)($row['name'] ?? '')); ?></strong><?php if ((string)($row['remarks'] ?? '') !== ''): ?><div class="helper-text"><?php echo qr_h((string)$row['remarks']); ?></div><?php endif; ?></td>
                  <td><div class="qr-card"><img src="<?php echo qr_h(qr_qr_image_url($link)); ?>" alt="<?php echo qr_h($code); ?> 二维码"></div></td>
                  <td><div class="link-stack"><input class="link-input" id="<?php echo qr_h($inputId); ?>" value="<?php echo qr_h($link); ?>" readonly><div class="mini-actions"><button class="button button-secondary" type="button" data-copy-target="<?php echo qr_h($inputId); ?>">复制</button><a class="button button-quiet" href="<?php echo qr_h($link); ?>" target="_blank" rel="noopener">打开</a></div></div></td>
                  <td><span class="metric"><?php echo (int)$row['unique_count']; ?></span></td>
                  <td><span class="metric"><?php echo (int)$row['raw_count']; ?></span></td>
                  <td><span class="metric"><?php echo (int)$row['submission_count']; ?></span></td>
                  <td><span class="badge <?php echo $isActive ? 'badge-active' : 'badge-inactive'; ?>"><?php echo $isActive ? '启用中' : '已停用'; ?></span><div class="helper-text"><?php echo $mustChange ? '待改默认密码' : '密码已更新'; ?></div><div class="helper-text"><?php echo (string)($row['last_login_at'] ?? '') !== '' ? '最近登录：' . qr_h((string)$row['last_login_at']) : '尚未登录'; ?></div></td>
                  <td><div class="stack-actions"><a class="button button-secondary" href="<?php echo qr_h(qr_admin_index_url(['edit' => $code])); ?>">编辑</a><form method="post"><input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>"><input type="hidden" name="action" value="toggle_counselor"><input type="hidden" name="code" value="<?php echo qr_h($code); ?>"><button class="button <?php echo $isActive ? 'button-danger' : 'button-primary'; ?>" type="submit"><?php echo $isActive ? '停用' : '启用'; ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="code" value="<?php echo qr_h($code); ?>"><button class="button button-secondary" type="submit">重置密码</button></form></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>
    </section>
  </main>

  <script src="/qr-redirect/admin/assets/admin.js"></script>
</body>
</html>
