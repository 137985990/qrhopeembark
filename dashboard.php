<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

qr_require_counselor();

$code = qr_current_counselor_code();
$month = new DateTimeImmutable('first day of this month 00:00:00');
$monthStart = $month->format('Y-m-d H:i:s');
$monthEnd = $month->modify('+1 month')->format('Y-m-d H:i:s');
$selectedMonth = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month']) ? (string)$_GET['month'] : $month->format('Y-m');

$profile = null;
$totals = ['unique' => 0, 'raw' => 0, 'month_unique' => 0, 'month_raw' => 0, 'submissions' => 0, 'month_submissions' => 0];
$history = [];
$submissions = [];
$errorBanner = '';

try {
    $mysqli = qr_db();

    $stmt = $mysqli->prepare('SELECT code, name, remarks FROM counselors WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc() ?: null;
    $result->free();
    $stmt->close();

    foreach ([
        ['SELECT COUNT(*) FROM unique_counts WHERE counselor_code = ?', 'unique'],
        ['SELECT COUNT(*) FROM scans_raw WHERE counselor_code = ?', 'raw'],
        ['SELECT COUNT(*) FROM lead_submissions WHERE counselor_code = ?', 'submissions'],
    ] as [$sql, $key]) {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $stmt->bind_result($countValue);
        $stmt->fetch();
        $stmt->close();
        $totals[$key] = (int)$countValue;
    }

    foreach ([
        ['SELECT COUNT(*) FROM unique_counts WHERE counselor_code = ? AND first_seen >= ? AND first_seen < ?', 'month_unique'],
        ['SELECT COUNT(*) FROM scans_raw WHERE counselor_code = ? AND ts >= ? AND ts < ?', 'month_raw'],
        ['SELECT COUNT(*) FROM lead_submissions WHERE counselor_code = ? AND submitted_at >= ? AND submitted_at < ?', 'month_submissions'],
    ] as [$sql, $key]) {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('sss', $code, $monthStart, $monthEnd);
        $stmt->execute();
        $stmt->bind_result($countValue);
        $stmt->fetch();
        $stmt->close();
        $totals[$key] = (int)$countValue;
    }

    $historySql = "
    SELECT months.month_key,
           COALESCE(unique_rows.unique_count, 0) AS unique_count,
           COALESCE(raw_rows.raw_count, 0) AS raw_count,
           COALESCE(submission_rows.submission_count, 0) AS submission_count
    FROM (
      SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL seq.num MONTH), '%Y-%m') AS month_key
      FROM (SELECT 0 AS num UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) AS seq
    ) AS months
    LEFT JOIN (
      SELECT DATE_FORMAT(first_seen, '%Y-%m') AS month_key, COUNT(*) AS unique_count
      FROM unique_counts
      WHERE counselor_code = ?
      GROUP BY DATE_FORMAT(first_seen, '%Y-%m')
    ) AS unique_rows ON unique_rows.month_key = months.month_key
    LEFT JOIN (
      SELECT DATE_FORMAT(ts, '%Y-%m') AS month_key, COUNT(*) AS raw_count
      FROM scans_raw
      WHERE counselor_code = ?
      GROUP BY DATE_FORMAT(ts, '%Y-%m')
    ) AS raw_rows ON raw_rows.month_key = months.month_key
    LEFT JOIN (
      SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month_key, COUNT(*) AS submission_count
      FROM lead_submissions
      WHERE counselor_code = ?
      GROUP BY DATE_FORMAT(submitted_at, '%Y-%m')
    ) AS submission_rows ON submission_rows.month_key = months.month_key
    ORDER BY months.month_key DESC
    ";
    $stmt = $mysqli->prepare($historySql);
    $stmt->bind_param('sss', $code, $code, $code);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $result->free();
    $stmt->close();

    $submissionSql = 'SELECT submission_no, student_name, parent_contact, grade, interest_fields, background_level, submitted_at FROM lead_submissions WHERE counselor_code = ? ORDER BY submitted_at DESC LIMIT 100';
    $stmt = $mysqli->prepare($submissionSql);
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $submissions[] = $row;
    }
    $result->free();
    $stmt->close();

    $mysqli->close();
} catch (Throwable $throwable) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    $errorBanner = '个人工作台暂时不可用，请稍后刷新。';
}

$notice = qr_get_flash('notice');
$displayName = trim((string)($profile['name'] ?? $code));
$link = qr_public_link($code);

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>我的数据看板</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
  <style>
    .dashboard-stack {
      display: grid;
      gap: var(--space-6);
    }
  </style>
</head>
<body>
  <main class="page-shell">
    <header class="page-header">
      <div>
        <span class="eyebrow">顾问个人工作台</span>
        <h1><?php echo qr_h($displayName); ?></h1>
        <p class="lede">这里可以查看你的二维码、你的扫码统计、你的月报，以及通过你二维码提交的报名信息。</p>
      </div>
      <div class="page-actions">
        <a class="button button-secondary" href="<?php echo qr_h(qr_report_path()); ?>">查看月报</a>
        <a class="button button-secondary" href="<?php echo qr_h(qr_export_path() . '?scope=mine&month=' . rawurlencode($selectedMonth)); ?>">导出报名表</a>
        <a class="button button-quiet" href="<?php echo qr_h(qr_change_password_path()); ?>">修改密码</a>
        <a class="button button-quiet" href="<?php echo qr_h(qr_logout_path()); ?>">退出登录</a>
      </div>
    </header>

    <?php if ($notice !== ''): ?><div class="notice"><?php echo qr_h($notice); ?></div><?php endif; ?>
    <?php if ($errorBanner !== ''): ?><div class="error-banner"><?php echo qr_h($errorBanner); ?></div><?php endif; ?>

    <section class="stats-grid">
      <article class="stat-card"><div class="stat-label">累计唯一扫码</div><div class="stat-value"><?php echo $totals['unique']; ?></div></article>
      <article class="stat-card"><div class="stat-label">累计总扫码</div><div class="stat-value"><?php echo $totals['raw']; ?></div></article>
      <article class="stat-card"><div class="stat-label">本月唯一扫码</div><div class="stat-value"><?php echo $totals['month_unique']; ?></div></article>
      <article class="stat-card"><div class="stat-label">本月总扫码</div><div class="stat-value"><?php echo $totals['month_raw']; ?></div></article>
      <article class="stat-card"><div class="stat-label">累计报名数</div><div class="stat-value"><?php echo $totals['submissions']; ?></div></article>
      <article class="stat-card"><div class="stat-label">本月报名数</div><div class="stat-value"><?php echo $totals['month_submissions']; ?></div></article>
    </section>

    <section class="dashboard-stack">
      <article class="surface table-shell">
        <h2>我的二维码</h2>
        <p class="helper-text">学生扫码或打开此链接后，将先进入中文报名表页面。</p>
        <div class="qr-card" style="max-width: 10rem; margin-bottom: var(--space-4);"><img src="<?php echo qr_h(qr_qr_image_url($link)); ?>" alt="<?php echo qr_h($code); ?> 二维码"></div>
        <div class="link-stack">
          <input class="link-input" id="my-link" value="<?php echo qr_h($link); ?>" readonly>
          <div class="mini-actions">
            <button class="button button-secondary" type="button" data-copy-target="my-link">复制链接</button>
            <a class="button button-primary" href="<?php echo qr_h($link); ?>" target="_blank" rel="noopener">打开报名页</a>
          </div>
        </div>
      </article>

      <article class="surface table-shell">
        <h2>我的报名数据</h2>
        <p class="helper-text">这里显示通过你二维码提交的最近 100 条报名记录。</p>
        <div class="table-scroll">
          <table class="data-table">
          <thead><tr><th>报名编号</th><th>提交时间</th><th>学生姓名</th><th>家长联系方式</th><th>年级</th><th>感兴趣领域</th><th>基础情况</th></tr></thead>
          <tbody>
            <?php if ($submissions === []): ?><tr><td colspan="7" class="empty-state">暂时还没有报名记录。</td></tr><?php endif; ?>
            <?php foreach ($submissions as $row): ?>
              <tr>
                <td><span class="code-chip"><?php echo qr_h((string)($row['submission_no'] ?? '')); ?></span></td>
                <td><?php echo qr_h((string)$row['submitted_at']); ?></td>
                <td><?php echo qr_h((string)$row['student_name']); ?></td>
                  <td><?php echo qr_h((string)$row['parent_contact']); ?></td>
                  <td><?php echo qr_h((string)$row['grade']); ?></td>
                  <td><?php echo qr_h((string)$row['interest_fields']); ?></td>
                  <td><?php echo qr_h((string)$row['background_level']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </article>

      <article class="surface table-shell">
        <h2>近 6 个月趋势</h2>
        <div class="table-scroll">
          <table class="data-table">
            <thead><tr><th>月份</th><th>唯一扫码</th><th>总扫码</th><th>报名数</th></tr></thead>
            <tbody>
              <?php foreach ($history as $row): ?>
                <tr><td><?php echo qr_h((string)$row['month_key']); ?></td><td><span class="metric"><?php echo (int)$row['unique_count']; ?></span></td><td><span class="metric"><?php echo (int)$row['raw_count']; ?></span></td><td><span class="metric"><?php echo (int)$row['submission_count']; ?></span></td></tr>
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
