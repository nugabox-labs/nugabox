<?php
/** 관리자 로그인 — 비밀번호 한 칸. 비밀번호가 설정돼 있지 않으면 여기까지 오지 못한다. */
declare(strict_types=1);

require_once __DIR__ . '/include/bootstrap.php';

admin_require_configured();
admin_session_start();

$next = $_GET['next'] ?? '/admin/index.php';
if (!is_string($next) || !preg_match('#^/admin/[A-Za-z0-9._/\-?=&]*$#', $next)) {
    $next = '/admin/index.php';   // 오픈 리다이렉트 방지 — /admin 하위만 허용
}

if (admin_is_logged_in()) {
    header('Location: ' . $next);
    exit;
}

/* ── 무차별 대입 완화: 파일 기반 시도 횟수 제한 ─────────────── */

$attemptFile = runtime_file('login-attempts.json');

function load_attempts(string $file): array
{
    $raw  = is_file($file) ? (string) @file_get_contents($file) : '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function record_attempt(string $file, string $ip, int $window): void
{
    $data = load_attempts($file);
    $now  = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array) $times, static fn($t) => ($now - (int) $t) < $window));
        if (!$data[$k]) {
            unset($data[$k]);
        }
    }
    $data[$ip][] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function count_attempts(string $file, string $ip, int $window): int
{
    $now = time();
    return count(array_filter(
        (array) (load_attempts($file)[$ip] ?? []),
        static fn($t) => ($now - (int) $t) < $window
    ));
}

function clear_attempts(string $file, string $ip): void
{
    $data = load_attempts($file);
    unset($data[$ip]);
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

$ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$window = (int) cfg('login_window_sec', 900);
$maxTry = (int) cfg('login_max_attempts', 10);
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pass = (string) ($_POST['password'] ?? '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = '세션이 만료되었습니다. 다시 시도해 주세요.';
    } elseif (count_attempts($attemptFile, $ip, $window) >= $maxTry) {
        $error = '로그인 시도가 너무 많습니다. 잠시 후 다시 시도해 주세요.';
    } elseif (admin_password_verify($pass)) {
        clear_attempts($attemptFile, $ip);
        admin_login_ok();
        header('Location: ' . $next);
        exit;
    } else {
        record_attempt($attemptFile, $ip, $window);
        $error = '비밀번호가 맞지 않습니다.';
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>로그인 · NUGABOX 관리자</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32x32.png">
  <link href="/admin/assets/admin.css" rel="stylesheet">
</head>
<body class="login-body">
  <form class="login-card" method="post" autocomplete="off">
    <h1>NUGABOX</h1>
    <p class="muted">관리자</p>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="password" name="password" placeholder="비밀번호" autofocus required>
    <?php if ($error !== ''): ?>
      <p class="error"><?= h($error) ?></p>
    <?php endif; ?>
    <button type="submit">들어가기</button>
  </form>
</body>
</html>
