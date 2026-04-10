<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

qr_require_login();

function qr_report_month(?string $value): DateTimeImmutable
{
    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
        $month = DateTimeImmutable::createFromFormat('!Y-m', $value);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($month instanceof DateTimeImmutable && !$hasErrors && $month->format('Y-m') === $value) {
            return $month;
        }
    }

    return new DateTimeImmutable('first day of this month 00:00:00');
}

function qr_report_window(DateTimeImmutable $month): array
{
    $start = $month->setTime(0, 0, 0);
    return [$start, $start->modify('+1 month')];
}

function qr_report_url(DateTimeImmutable $month): string
{
    return qr_report_path() . '?month=' . rawurlencode($month->format('Y-m'));
}

$selectedMonth = qr_report_month(isset($_GET['month']) ? (string)$_GET['month'] : null);
$previousMonth = $selectedMonth->modify('-1 month');
[$currentStart, $currentEnd] = qr_report_window($selectedMonth);
[$previousStart, $previousEnd] = qr_report_window($previousMonth);

$rows = [];
$summary = ['current_unique' => 0, 'current_raw' => 0, 'current_submissions' => 0, 'previous_unique' => 0, 'previous_raw' => 0, 'previous_submissions' => 0];
$errorBanner = '';

try {
    $mysqli = qr_db();
    $sql = "
    SELECT c.code, c.name, c.remarks,
      COALESCE(cu.unique_count, 0) AS current_unique,
      COALESCE(cr.raw_count, 0) AS current_raw,
      COALESCE(cs.submission_count, 0) AS current_submissions,
      COALESCE(pu.unique_count, 0) AS previous_unique,
      COALESCE(pr.raw_count, 0) AS previous_raw,
      COALESCE(ps.submission_count, 0) AS previous_submissions
    FROM counselors c
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS unique_count FROM unique_counts WHERE first_seen >= ? AND first_seen < ? GROUP BY counselor_code) cu ON cu.counselor_code = c.code
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS raw_count FROM scans_raw WHERE ts >= ? AND ts < ? GROUP BY counselor_code) cr ON cr.counselor_code = c.code
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS submission_count FROM lead_submissions WHERE submitted_at >= ? AND submitted_at < ? GROUP BY counselor_code) cs ON cs.counselor_code = c.code
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS unique_count FROM unique_counts WHERE first_seen >= ? AND first_seen < ? GROUP BY counselor_code) pu ON pu.counselor_code = c.code
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS raw_count FROM scans_raw WHERE ts >= ? AND ts < ? GROUP BY counselor_code) pr ON pr.counselor_code = c.code
    LEFT JOIN (SELECT counselor_code, COUNT(*) AS submission_count FROM lead_submissions WHERE submitted_at >= ? AND submitted_at < ? GROUP BY counselor_code) ps ON ps.counselor_code = c.code
    WHERE c.active = 1 AND (? = 'admin' OR c.code = ?)
    ORDER BY CASE WHEN c.name IS NULL OR c.name = '' THEN 1 ELSE 0 END, c.name, c.code
    ";
    $stmt = $mysqli->prepare($sql);
    $role = qr_current_role();
    $codeFilter = qr_current_counselor_code();
    $stmt->bind_param(
        'ssssssssssssss',
        $currentStart->format('Y-m-d H:i:s'),
        $currentEnd->format('Y-m-d H:i:s'),
        $currentStart->format('Y-m-d H:i:s'),
        $currentEnd->format('Y-m-d H:i:s'),
        $currentStart->format('Y-m-d H:i:s'),
        $currentEnd->format('Y-m-d H:i:s'),
        $previousStart->format('Y-m-d H:i:s'),
        $previousEnd->format('Y-m-d H:i:s'),
        $previousStart->format('Y-m-d H:i:s'),
        $previousEnd->format('Y-m-d H:i:s'),
        $previousStart->format('Y-m-d H:i:s'),
        $previousEnd->format('Y-m-d H:i:s'),
        $role,
        $codeFilter
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $summary['current_unique'] += (int)$row['current_unique'];
        $summary['current_raw'] += (int)$row['current_raw'];
        $summary['current_submissions'] += (int)$row['current_submissions'];
        $summary['previous_unique'] += (int)$row['previous_unique'];
        $summary['previous_raw'] += (int)$row['previous_raw'];
        $summary['previous_submissions'] += (int)$row['previous_submissions'];
    }
    $result->free();
    $stmt->close();
    $mysqli->close();
} catch (Throwable $throwable) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    http_response_code(500);
    $errorBanner = '月报暂时不可用，请稍后重试。';
}

$selectedLabel = $selectedMonth->format('Y-m');
$previousLabel = $previousMonth->format('Y-m');
$isAdmin = qr_is_admin();

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>月度统计报表</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
</head>
<body>
  <main class="page-shell">
    <header class="page-header">
      <div>
        <span class="eyebrow">月度统计报表</span>
        <h1><?php echo $isAdmin ? '全部顾问月报' : '我的月报'; ?></h1>
        <p class="lede">本页同时统计扫码数据和报名数据：唯一扫码来自 unique_counts.first_seen，总扫码来自 scans_raw.ts，报名数来自 lead_submissions.submitted_at。</p>
      </div>
      <div class="page-actions">
        <a class="button button-secondary" href="<?php echo qr_h($isAdmin ? qr_admin_path('index.php') : qr_dashboard_path()); ?>"><?php echo $isAdmin ? '返回后台' : '返回我的首页'; ?></a>
        <a class="button button-secondary" href="<?php echo qr_h(qr_export_path() . '?scope=' . ($isAdmin ? 'all' : 'mine') . '&month=' . rawurlencode($selectedLabel)); ?>">导出报名表</a>
        <a class="button button-quiet" href="<?php echo qr_h(qr_change_password_path()); ?>">修改密码</a>
        <a class="button button-quiet" href="<?php echo qr_h(qr_logout_path()); ?>">退出登录</a>
      </div>
    </header>

    <?php if ($errorBanner !== ''): ?><div class="error-banner"><?php echo qr_h($errorBanner); ?></div><?php endif; ?>

    <section class="surface month-toolbar">
      <div>
        <h2><?php echo qr_h($selectedLabel); ?></h2>
        <p class="helper-text">对比月份：<?php echo qr_h($selectedLabel); ?> vs <?php echo qr_h($previousLabel); ?></p>
      </div>
      <div class="month-switcher">
        <a class="button button-secondary" href="<?php echo qr_h(qr_report_url($selectedMonth->modify('-1 month'))); ?>">上个月</a>
        <form method="get" action="<?php echo qr_h(qr_report_path()); ?>">
          <label class="field" for="month"><span>切换月份</span><input id="month" name="month" type="month" value="<?php echo qr_h($selectedLabel); ?>"></label>
          <button class="button button-primary" type="submit">查看</button>
        </form>
        <a class="button button-quiet" href="<?php echo qr_h(qr_report_url($selectedMonth->modify('+1 month'))); ?>">下个月</a>
      </div>
    </section>

    <section class="stats-grid">
      <article class="stat-card"><div class="stat-label">本月唯一扫码</div><div class="stat-value"><?php echo $summary['current_unique']; ?></div><div class="helper-text">上月：<?php echo $summary['previous_unique']; ?></div></article>
      <article class="stat-card"><div class="stat-label">本月总扫码</div><div class="stat-value"><?php echo $summary['current_raw']; ?></div><div class="helper-text">上月：<?php echo $summary['previous_raw']; ?></div></article>
      <article class="stat-card"><div class="stat-label">本月报名数</div><div class="stat-value"><?php echo $summary['current_submissions']; ?></div><div class="helper-text">上月：<?php echo $summary['previous_submissions']; ?></div></article>
    </section>

    <section class="surface table-shell">
      <h2><?php echo $isAdmin ? '顾问月度对比' : '个人月度对比'; ?></h2>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>顾问</th><th><?php echo qr_h($selectedLabel); ?> 唯一扫码</th><th><?php echo qr_h($selectedLabel); ?> 总扫码</th><th><?php echo qr_h($selectedLabel); ?> 报名数</th><th><?php echo qr_h($previousLabel); ?> 唯一扫码</th><th><?php echo qr_h($previousLabel); ?> 总扫码</th><th><?php echo qr_h($previousLabel); ?> 报名数</th></tr></thead>
          <tbody>
            <?php if ($rows === []): ?><tr><td colspan="7" class="empty-state">当前月份暂无数据。</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><strong><?php echo qr_h(trim((string)($row['name'] ?? '')) !== '' ? (string)$row['name'] : (string)$row['code']); ?></strong><div class="helper-text"><span class="code-chip"><?php echo qr_h((string)$row['code']); ?></span></div></td>
                <td><span class="metric"><?php echo (int)$row['current_unique']; ?></span></td>
                <td><span class="metric"><?php echo (int)$row['current_raw']; ?></span></td>
                <td><span class="metric"><?php echo (int)$row['current_submissions']; ?></span></td>
                <td><span class="metric"><?php echo (int)$row['previous_unique']; ?></span></td>
                <td><span class="metric"><?php echo (int)$row['previous_raw']; ?></span></td>
                <td><span class="metric"><?php echo (int)$row['previous_submissions']; ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
