<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function qr_db(): mysqli
{
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
    return $db;
}

function qr_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function qr_is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function qr_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('qr_redirect_app');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => qr_is_https(),
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

function qr_base_path(string $path = ''): string
{
    $base = '/qr-redirect';
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function qr_login_path(): string
{
    return qr_base_path();
}

function qr_dashboard_path(): string
{
    return qr_base_path('dashboard.php');
}

function qr_change_password_path(): string
{
    return qr_base_path('change-password.php');
}

function qr_report_path(): string
{
    return qr_base_path('report.php');
}

function qr_export_path(): string
{
    return qr_base_path('export-submissions.php');
}

function qr_logout_path(): string
{
    return qr_base_path('logout.php');
}

function qr_success_path(): string
{
    return qr_base_path('success.php');
}

function qr_admin_path(string $path = ''): string
{
    $base = '/qr-redirect/admin';
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function qr_admin_submissions_path(): string
{
    return qr_admin_path('submissions.php');
}

function qr_public_link(string $code): string
{
    return 'https://hopeembark.org/qr-redirect/r.php?c=' . rawurlencode($code);
}

function qr_qr_image_url(string $link): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=112x112&margin=0&data=' . rawurlencode($link);
}

function qr_main_site_url(): string
{
    return 'https://mpa.hopeembark.org/';
}

function qr_current_role(): string
{
    qr_start_session();
    return isset($_SESSION['qr_role']) && is_string($_SESSION['qr_role']) ? $_SESSION['qr_role'] : '';
}

function qr_is_admin(): bool
{
    return qr_current_role() === 'admin';
}

function qr_is_counselor(): bool
{
    return qr_current_role() === 'counselor';
}

function qr_current_display_name(): string
{
    qr_start_session();
    return isset($_SESSION['qr_display_name']) && is_string($_SESSION['qr_display_name']) ? $_SESSION['qr_display_name'] : '';
}

function qr_current_counselor_code(): string
{
    qr_start_session();
    return isset($_SESSION['qr_counselor_code']) && is_string($_SESSION['qr_counselor_code']) ? $_SESSION['qr_counselor_code'] : '';
}

function qr_user_must_change_password(): bool
{
    qr_start_session();
    return !empty($_SESSION['qr_must_change_password']);
}

function qr_clear_auth(): void
{
    qr_start_session();
    unset($_SESSION['qr_role'], $_SESSION['qr_display_name'], $_SESSION['qr_counselor_code'], $_SESSION['qr_must_change_password']);
}

function qr_login_admin(): void
{
    qr_start_session();
    session_regenerate_id(true);
    qr_clear_auth();
    $_SESSION['qr_role'] = 'admin';
    $_SESSION['qr_display_name'] = ADMIN_USER;
    $_SESSION['qr_must_change_password'] = false;
}

function qr_login_counselor(array $row): void
{
    qr_start_session();
    session_regenerate_id(true);
    qr_clear_auth();
    $_SESSION['qr_role'] = 'counselor';
    $_SESSION['qr_display_name'] = (string)($row['name'] ?? $row['code'] ?? '');
    $_SESSION['qr_counselor_code'] = (string)($row['code'] ?? '');
    $_SESSION['qr_must_change_password'] = (int)($row['must_change_password'] ?? 0) === 1;
}

function qr_redirect_after_login(): void
{
    if (qr_user_must_change_password()) {
        qr_redirect(qr_change_password_path());
    }

    if (qr_is_admin()) {
        qr_redirect(qr_admin_path('index.php'));
    }

    qr_redirect(qr_dashboard_path());
}

function qr_require_login(): void
{
    if (!qr_is_admin() && !qr_is_counselor()) {
        qr_redirect(qr_login_path());
    }

    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (qr_user_must_change_password() && $path !== qr_change_password_path()) {
        qr_redirect(qr_change_password_path());
    }
}

function qr_require_admin(): void
{
    qr_require_login();
    if (!qr_is_admin()) {
        qr_redirect(qr_dashboard_path());
    }
}

function qr_require_counselor(): void
{
    qr_require_login();
    if (!qr_is_counselor()) {
        qr_redirect(qr_admin_path('index.php'));
    }
}

function qr_csrf_token(): string
{
    qr_start_session();
    if (empty($_SESSION['qr_csrf']) || !is_string($_SESSION['qr_csrf'])) {
        $_SESSION['qr_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['qr_csrf'];
}

function qr_verify_csrf(?string $token): bool
{
    qr_start_session();
    return is_string($token)
        && isset($_SESSION['qr_csrf'])
        && is_string($_SESSION['qr_csrf'])
        && hash_equals($_SESSION['qr_csrf'], $token);
}

function qr_set_flash(string $type, string $message): void
{
    qr_start_session();
    $_SESSION['qr_flash'][$type] = $message;
}

function qr_get_flash(string $type): string
{
    qr_start_session();
    if (!isset($_SESSION['qr_flash'][$type]) || !is_string($_SESSION['qr_flash'][$type])) {
        return '';
    }

    $message = $_SESSION['qr_flash'][$type];
    unset($_SESSION['qr_flash'][$type]);
    return $message;
}

function qr_redirect(string $location): void
{
    header('Location: ' . $location, true, 302);
    exit;
}

function qr_counselor_code(string $value): string
{
    return strtoupper(trim($value));
}

function qr_default_password_hash(): string
{
    return password_hash('123456', PASSWORD_DEFAULT);
}

function qr_update_admin_password(string $newPassword): bool
{
    $configPath = __DIR__ . '/config.php';
    $contents = @file_get_contents($configPath);
    if (!is_string($contents)) {
        return false;
    }

    $pattern = "/define\\('ADMIN_PASS',\\s*'((?:\\\\'|[^'])*)'\\);/";
    if (!preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return false;
    }

    $replacement = "define('ADMIN_PASS', " . var_export($newPassword, true) . ");";
    $matchValue = $matches[0][0];
    $offset = (int)$matches[0][1];
    $updated = substr($contents, 0, $offset) . $replacement . substr($contents, $offset + strlen($matchValue));

    return @file_put_contents($configPath, $updated, LOCK_EX) !== false;
}

function qr_device_fingerprint(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $cookieName = 'qr_fid';

    if (isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$cookieName])) {
        return $_COOKIE[$cookieName];
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $token = '';
    }

    if ($token !== '') {
        $ok = setcookie($cookieName, $token, [
            'expires' => time() + (10 * 365 * 24 * 60 * 60),
            'path' => '/',
            'secure' => qr_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($ok) {
            $_COOKIE[$cookieName] = $token;
            return $token;
        }
    }

    return hash('sha256', $ip . '|' . $ua);
}

function qr_capture_scan(string $code): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $fid = qr_device_fingerprint();
    $mysqli = qr_db();

    $stmt = $mysqli->prepare('INSERT INTO scans_raw (counselor_code, device_fingerprint, ip, user_agent) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $code, $fid, $ip, $ua);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare('INSERT IGNORE INTO unique_counts (counselor_code, device_fingerprint) VALUES (?, ?)');
    $stmt->bind_param('ss', $code, $fid);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();
}

function qr_fetch_counselor(mysqli $db, string $code): ?array
{
    $stmt = $db->prepare('SELECT code, name, remarks, active FROM counselors WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    return $row ?: null;
}

function qr_submission_grades(): array
{
    return ['G9', 'G10', 'G11', 'G12', '其他'];
}

function qr_submission_interest_options(): array
{
    return ['AI/计算机', '商科/金融', '生物/医学', '社科/其他', 'STEM'];
}

function qr_submission_background_options(): array
{
    return ['无基础', '有基础', '有项目/竞赛经验'];
}

function qr_next_submission_no(mysqli $db, string $code): string
{
    $like = $code . '-%';
    $stmt = $db->prepare('SELECT submission_no FROM lead_submissions WHERE counselor_code = ? AND submission_no LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('ss', $code, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    $next = 1;
    if ($row && isset($row['submission_no']) && is_string($row['submission_no']) && preg_match('/^[A-Z0-9]+-(\d+)$/', $row['submission_no'], $matches) === 1) {
        $next = ((int)$matches[1]) + 1;
    }

    return sprintf('%s-%04d', $code, $next);
}
