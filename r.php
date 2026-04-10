<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$code = isset($_REQUEST['c']) ? qr_counselor_code((string)$_REQUEST['c']) : '';
if ($code === '' || !preg_match('/^[A-Z0-9]{1,16}$/', $code)) {
    http_response_code(400);
    echo '无效的顾问编号。';
    exit;
}

$error = '';
$successId = 0;
$counselor = null;
$form = [
    'student_name' => '',
    'parent_contact' => '',
    'grade' => '',
    'grade_other' => '',
    'interest_fields' => [],
    'background_level' => '',
];

try {
    $mysqli = qr_db();
    $counselor = qr_fetch_counselor($mysqli, $code);
    if (!$counselor || (int)($counselor['active'] ?? 0) !== 1) {
        $mysqli->close();
        http_response_code(404);
        echo '该顾问链接暂不可用。';
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        qr_capture_scan($code);
    }
    $mysqli->close();
} catch (Throwable $throwable) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    http_response_code(500);
    echo '页面暂时不可用，请稍后再试。';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'student_name' => trim((string)($_POST['student_name'] ?? '')),
        'parent_contact' => trim((string)($_POST['parent_contact'] ?? '')),
        'grade' => trim((string)($_POST['grade'] ?? '')),
        'grade_other' => trim((string)($_POST['grade_other'] ?? '')),
        'interest_fields' => isset($_POST['interest_fields']) && is_array($_POST['interest_fields']) ? array_values(array_map('trim', $_POST['interest_fields'])) : [],
        'background_level' => trim((string)($_POST['background_level'] ?? '')),
    ];

    if (!qr_verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = '会话已过期，请刷新页面后重新提交。';
    } elseif ($form['student_name'] === '') {
        $error = '请填写学生姓名。';
    } elseif ($form['parent_contact'] === '') {
        $error = '请填写家长联系方式。';
    } elseif (!in_array($form['grade'], qr_submission_grades(), true)) {
        $error = '请选择学生年级。';
    } elseif ($form['interest_fields'] === []) {
        $error = '请至少选择一个感兴趣领域。';
    } else {
        foreach ($form['interest_fields'] as $value) {
            if (!in_array($value, qr_submission_interest_options(), true)) {
                $error = '感兴趣领域包含无效选项。';
                break;
            }
        }
    }

    if ($error === '' && !in_array($form['background_level'], qr_submission_background_options(), true)) {
        $error = '请选择科研/编程基础情况。';
    }

    if ($error === '' && $form['grade'] === '其他') {
        if ($form['grade_other'] === '') {
            $error = '请选择“其他”时请补充具体年级。';
        } else {
            $form['grade'] = $form['grade_other'];
        }
    }

    if ($error === '') {
        try {
            $mysqli = qr_db();
            $submissionNo = qr_next_submission_no($mysqli, $code);
            $stmt = $mysqli->prepare('INSERT INTO lead_submissions (submission_no, counselor_code, student_name, parent_contact, grade, interest_fields, background_level, device_fingerprint, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $interests = implode('、', $form['interest_fields']);
            $fid = qr_device_fingerprint();
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            $stmt->bind_param('ssssssssss', $submissionNo, $code, $form['student_name'], $form['parent_contact'], $form['grade'], $interests, $form['background_level'], $fid, $ip, $ua);
            $stmt->execute();
            $successId = (int)$stmt->insert_id;
            $stmt->close();
            $mysqli->close();

            qr_redirect(qr_success_path() . '?c=' . rawurlencode($code) . '&sid=' . $successId . '&no=' . rawurlencode($submissionNo));
        } catch (Throwable $throwable) {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $mysqli->close();
            }

            $error = '报名提交失败，请稍后重试。';
        }
    }
}

$csrf = qr_csrf_token();
$displayName = trim((string)($counselor['name'] ?? $code));
$grades = qr_submission_grades();
$interestOptions = qr_submission_interest_options();
$backgroundOptions = qr_submission_background_options();

?><!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>恒点学术科研项目报名表</title>
  <link rel="stylesheet" href="/qr-redirect/admin/assets/admin.css">
  <style>
    .intake-shell { width: min(calc(100% - var(--space-8)), 66rem); margin: 0 auto; padding: var(--space-8) 0 var(--space-10); }
    .intake-grid { display: grid; gap: var(--space-5); }
    .intake-hero, .intake-card { background: rgba(255,255,255,.94); border: var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-soft); }
    .intake-hero { padding: var(--space-6); display: grid; gap: var(--space-4); }
    .intake-card { padding: var(--space-6); }
    .intake-hero h1 { margin: 0; max-width: 12ch; font-family: var(--font-display); font-size: clamp(1.65rem, 2.8vw, 2.45rem); line-height: 1.18; letter-spacing: -0.01em; }
    .intake-meta { color: var(--color-muted); line-height: 1.7; }
    .intake-meta strong { color: var(--color-ink); }
    .gift-list { margin: 0; padding: 0; list-style: none; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); }
    .gift-list li { min-height: 100%; padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); background: var(--color-surface-soft); border: var(--border); color: var(--color-muted); }
    .check-grid { display: grid; gap: var(--space-3); }
    .check-grid-columns { display: grid; grid-template-columns: repeat(auto-fit,minmax(12rem,1fr)); gap: var(--space-3); }
    .check-option { display: inline-flex; gap: .65rem; align-items: center; min-height: 2.25rem; }
    .check-option input[type="radio"], .check-option input[type="checkbox"] { width: auto; min-width: 0; padding: 0; border: 0; border-radius: 0; box-shadow: none; background: transparent; accent-color: var(--color-accent); }
    .choice-row { display: flex; flex-wrap: wrap; gap: .85rem 1.25rem; align-items: center; }
    .choice-row .check-option { white-space: nowrap; }
    .inline-other-input { display: none; min-width: 12rem; flex: 1 1 14rem; }
    .inline-other-input.is-visible { display: block; }
    @media (max-width: 60rem) {
      .intake-shell { width: min(calc(100% - var(--space-5)), 66rem); }
      .intake-hero, .intake-card { padding: var(--space-5); }
      .gift-list { grid-template-columns: 1fr; }
      .choice-row { gap: .75rem 1rem; }
    }
    @media (max-width: 40rem) {
      .intake-hero h1 { max-width: none; font-size: 1.95rem; }
      .intake-meta { font-size: .98rem; }
      .choice-row { flex-direction: column; align-items: stretch; }
      .choice-row .check-option { display: flex; flex-direction: row; justify-content: flex-start; align-items: center; gap: .85rem; white-space: normal; width: 100%; min-height: 2.75rem; }
      .choice-row .check-option input { margin: 0; flex: 0 0 auto; width: auto; }
      .choice-row .check-option span { flex: 1 1 auto; text-align: left; line-height: 1.45; }
      .inline-other-input { min-width: 100%; width: 100%; }
    }
  </style>
</head>
<body>
  <main class="intake-shell">
    <section class="intake-grid">
      <article class="intake-hero">
        <span class="eyebrow">🎓 恒点学术科研项目报名表</span>
        <h1>填写表单后，可领取科研选题与一对一提升建议。</h1>
        <p class="intake-meta">当前顾问：<strong><?php echo qr_h($displayName); ?></strong>（<?php echo qr_h($code); ?>）。提交成功后，你会看到资料领取提示，并可继续进入官网了解更多信息。</p>
        <ul class="gift-list">
          <li>✔ AI 科研选题清单</li>
          <li>✔ 一对一科研提升规划</li>
          <li>✔ 可进微信群了解更多信息</li>
        </ul>
      </article>

      <article class="intake-card">
        <h2>立即报名</h2>
        <p class="helper-text">带 <strong>*</strong> 为必填项，提交后信息会同步给当前顾问。</p>

        <?php if ($error !== ''): ?><div class="error-banner"><?php echo qr_h($error); ?></div><?php endif; ?>

        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo qr_h($csrf); ?>">
          <input type="hidden" name="c" value="<?php echo qr_h($code); ?>">

          <div class="field">
            <label for="student_name">1️⃣ 学生姓名 *</label>
            <input id="student_name" name="student_name" value="<?php echo qr_h($form['student_name']); ?>" required>
          </div>

          <div class="field">
            <label for="parent_contact">2️⃣ 家长联系方式（微信 / 电话） *</label>
            <input id="parent_contact" name="parent_contact" value="<?php echo qr_h($form['parent_contact']); ?>" required>
          </div>

          <div class="field">
            <label>3️⃣ 年级 *</label>
            <div class="choice-row">
              <?php foreach ($grades as $grade): ?>
                <label class="check-option"><input type="radio" name="grade" value="<?php echo qr_h($grade); ?>" <?php echo $form['grade'] === $grade || ($grade === '其他' && $form['grade_other'] !== '') ? 'checked' : ''; ?> required><span><?php echo qr_h($grade); ?></span></label>
              <?php endforeach; ?>
              <input class="inline-other-input <?php echo ($form['grade'] === '其他' || $form['grade_other'] !== '') ? 'is-visible' : ''; ?>" id="grade_other" name="grade_other" value="<?php echo qr_h($form['grade_other']); ?>" placeholder="请输入具体年级，例如 AP / 大一 / 预科">
            </div>
          </div>

          <div class="field">
            <label>5️⃣ 感兴趣领域（可多选） *</label>
            <div class="choice-row">
              <?php foreach ($interestOptions as $option): ?>
                <label class="check-option"><input type="checkbox" name="interest_fields[]" value="<?php echo qr_h($option); ?>" <?php echo in_array($option, $form['interest_fields'], true) ? 'checked' : ''; ?>><span><?php echo qr_h($option); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="field">
            <label>6️⃣ 是否有科研 / 编程基础 *</label>
            <div class="choice-row">
              <?php foreach ($backgroundOptions as $option): ?>
                <label class="check-option"><input type="radio" name="background_level" value="<?php echo qr_h($option); ?>" <?php echo $form['background_level'] === $option ? 'checked' : ''; ?> required><span><?php echo qr_h($option); ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>

          <button class="button button-primary" type="submit">提交报名表</button>
        </form>
      </article>
    </section>
  </main>

  <script>
    (function () {
      var gradeRadios = Array.prototype.slice.call(document.querySelectorAll('input[name="grade"]'));
      var gradeOther = document.getElementById('grade_other');
      if (!gradeOther || gradeRadios.length === 0) {
        return;
      }

      function syncGradeOther() {
        var selected = gradeRadios.find(function (radio) { return radio.checked; });
        var shouldShow = !!selected && selected.value === '其他';
        gradeOther.classList.toggle('is-visible', shouldShow);
        gradeOther.required = shouldShow;
        if (!shouldShow) {
          gradeOther.value = '';
        }
      }

      gradeRadios.forEach(function (radio) {
        radio.addEventListener('change', syncGradeOther);
      });

      syncGradeOther();
    }());
  </script>
</body>
</html>
