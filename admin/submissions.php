<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

qr_require_admin();

$errorBanner = '';
$rows = [];

try {
    $mysqli = qr_db();
    $result = $mysqli->query('SELECT submission_no, counselor_code, student_name, parent_contact, grade, interest_fields, background_level, submitted_at FROM lead_submissions ORDER BY submitted_at DESC LIMIT 300');
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    $mysqli->close();
} catch (Throwable $throwable) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    http_response_code(500);
    $errorBanner = '报名记录页面暂时不可用，请稍后刷新。';
}

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>最近报名记录</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
</head>
<body>
  <main class="page-shell">
    <header class="page-header">
      <div>
        <span class="eyebrow">报名数据</span>
        <h1>最近报名记录</h1>
        <p class="lede">这里集中查看最新报名表提交记录，可配合导出按钮一起使用。</p>
      </div>
      <div class="page-actions">
        <a class="button button-secondary" href="<?php echo qr_h(qr_admin_path('index.php')); ?>">返回后台</a>
        <a class="button button-secondary" href="<?php echo qr_h(qr_export_path() . '?scope=all'); ?>">导出全部报名表</a>
      </div>
    </header>

    <?php if ($errorBanner !== ''): ?><div class="error-banner"><?php echo qr_h($errorBanner); ?></div><?php endif; ?>

    <section class="surface table-shell">
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>报名编号</th><th>提交时间</th><th>顾问</th><th>学生姓名</th><th>家长联系方式</th><th>年级</th><th>感兴趣领域</th><th>基础情况</th></tr></thead>
          <tbody>
            <?php if ($rows === []): ?><tr><td colspan="8" class="empty-state">暂时还没有报名记录。</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <tr><td><span class="code-chip"><?php echo qr_h((string)($row['submission_no'] ?? '')); ?></span></td><td><?php echo qr_h((string)$row['submitted_at']); ?></td><td><span class="code-chip"><?php echo qr_h((string)$row['counselor_code']); ?></span></td><td><?php echo qr_h((string)$row['student_name']); ?></td><td><?php echo qr_h((string)$row['parent_contact']); ?></td><td><?php echo qr_h((string)$row['grade']); ?></td><td><?php echo qr_h((string)$row['interest_fields']); ?></td><td><?php echo qr_h((string)$row['background_level']); ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
