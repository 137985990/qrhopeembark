<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

qr_require_login();

$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month']) ? (string)$_GET['month'] : '';
$scope = isset($_GET['scope']) ? (string)$_GET['scope'] : '';
$isAdmin = qr_is_admin();
$code = qr_current_counselor_code();

$sql = 'SELECT submission_no, counselor_code, student_name, parent_contact, grade, interest_fields, background_level, submitted_at FROM lead_submissions WHERE 1=1';
$types = '';
$params = [];

if (!$isAdmin || $scope === 'mine') {
    $sql .= ' AND counselor_code = ?';
    $types .= 's';
    $params[] = $code;
}

if ($month !== '') {
    $start = $month . '-01 00:00:00';
    $end = (new DateTimeImmutable($month . '-01 00:00:00'))->modify('+1 month')->format('Y-m-d H:i:s');
    $sql .= ' AND submitted_at >= ? AND submitted_at < ?';
    $types .= 'ss';
    $params[] = $start;
    $params[] = $end;
}

$sql .= ' ORDER BY submitted_at DESC';

try {
    $mysqli = qr_db();
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $filename = 'qr-submissions-' . ($isAdmin && $scope !== 'mine' ? 'all' : strtolower($code)) . ($month !== '' ? '-' . $month : '') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['报名编号', '顾问Code', '学生姓名', '家长联系方式', '年级', '感兴趣领域', '基础情况', '提交时间']);
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            (string)($row['submission_no'] ?? ''),
            (string)$row['counselor_code'],
            (string)$row['student_name'],
            (string)$row['parent_contact'],
            (string)$row['grade'],
            (string)$row['interest_fields'],
            (string)$row['background_level'],
            (string)$row['submitted_at'],
        ]);
    }
    fclose($out);
    $result->free();
    $stmt->close();
    $mysqli->close();
    exit;
} catch (Throwable $throwable) {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
    http_response_code(500);
    echo '导出失败，请稍后重试。';
}
