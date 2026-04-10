<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$code = isset($_GET['c']) ? qr_counselor_code((string)$_GET['c']) : '';
$sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$submissionNo = isset($_GET['no']) ? trim((string)$_GET['no']) : '';
$displayName = $code;

if ($code !== '') {
    try {
        $mysqli = qr_db();
        $row = qr_fetch_counselor($mysqli, $code);
        if ($row) {
            $displayName = trim((string)($row['name'] ?? $code));
        }
        $mysqli->close();
    } catch (Throwable $throwable) {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $mysqli->close();
        }
    }
}

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>报名提交成功</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
  <style>
    .success-grid {
      display: grid;
      gap: var(--space-4);
    }
    .success-links {
      display: grid;
      gap: var(--space-3);
      text-align: left;
    }
    .success-links a {
      word-break: break-all;
    }
    .group-qr-card {
      display: grid;
      gap: var(--space-3);
      justify-items: center;
      text-align: center;
      padding: var(--space-4);
    }
    .group-qr-card img {
      width: min(100%, 20rem);
      border-radius: var(--radius-md);
      border: var(--border);
      background: #fff;
    }
  </style>
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card">
      <span class="eyebrow">报名成功</span>
      <h1>信息已提交</h1>
      <p class="auth-copy">感谢提交恒点学术科研项目报名表。当前归属顾问：<strong><?php echo qr_h($displayName); ?></strong><?php echo $submissionNo !== '' ? '，报名编号：' . qr_h($submissionNo) : ($sid > 0 ? '，记录编号：' . $sid : ''); ?>。</p>
      <div class="success-grid">
        <div class="surface" style="text-align:left;">
          <h2 style="margin-top:0;">报名后推荐查看：</h2>
          <div class="success-links">
            <div><strong>课程体系：</strong><a href="https://mp.weixin.qq.com/s/kc1cF05QdFO8ZlhN44qA-Q" target="_blank" rel="noopener">https://mp.weixin.qq.com/s/kc1cF05QdFO8ZlhN44qA-Q</a></div>
            <div><strong>期刊地址：</strong><a href="https://hopeembark.org" target="_blank" rel="noopener">https://hopeembark.org</a></div>
            <div><strong>项目官网：</strong><a href="<?php echo qr_h(qr_main_site_url()); ?>" target="_blank" rel="noopener"><?php echo qr_h(qr_main_site_url()); ?></a></div>
          </div>
        </div>

        <div class="surface group-qr-card">
          <h2 style="margin:0;">扫码进入微信群</h2>
          <p class="helper-text" style="margin:0;">可在群内了解更多项目内容与后续安排。</p>
          <img src="/qr-redirect/assets/group-qr-mpa-ai.jpg" alt="MPA 恒点学术 AI 项目微信群二维码">
        </div>
      </div>
      <div class="button-row">
        <a class="button button-primary" href="<?php echo qr_h(qr_main_site_url()); ?>" target="_blank" rel="noopener">进入官网</a>
        <?php if ($code !== ''): ?><a class="button button-secondary" href="<?php echo qr_h(qr_public_link($code)); ?>">返回报名页</a><?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
