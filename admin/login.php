<?php
declare(strict_types=1);

require_once __DIR__ . '/include/bootstrap.php';

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
$attemptFile = ADMIN_DIR . '/.login-attempts.json';

function load_attempts(string $file): array
{
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function record_attempt(string $file, string $ip, int $window): int
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
    file_put_contents($file, json_encode($data), LOCK_EX);
    return count($data[$ip]);
}

function count_attempts(string $file, string $ip, int $window): int
{
    $data = load_attempts($file);
    $now  = time();
    return count(array_filter((array) ($data[$ip] ?? []), static fn($t) => ($now - (int) $t) < $window));
}

function clear_attempts(string $file, string $ip): void
{
    $data = load_attempts($file);
    unset($data[$ip]);
    file_put_contents($file, json_encode($data), LOCK_EX);
}

$ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$window  = (int) cfg('login_window_sec', 900);
$maxTry  = (int) cfg('login_max_attempts', 10);
$error   = '';

/**
 * 비밀번호 확인. 해시가 설정되어 있으면 해시를 우선 쓰고,
 * 없으면 .env.php 의 평문과 비교한다.
 */
function password_ok(string $input): bool
{
    $hash = (string) cfg('admin_password_hash', '');
    if ($hash !== '') {
        return password_verify($input, $hash);
    }
    $plain = (string) cfg('admin_password', '');
    return $plain !== '' && hash_equals($plain, $input);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = (string) ($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');
    $configured = cfg('admin_password_hash', '') !== '' || cfg('admin_password', '') !== '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = '세션이 만료되었습니다. 다시 시도해 주세요.';
    } elseif (count_attempts($attemptFile, $ip, $window) >= $maxTry) {
        $error = '로그인 시도가 너무 많습니다. 잠시 후 다시 시도해 주세요.';
    } elseif (!$configured) {
        $error = '관리자 비밀번호가 설정되지 않았습니다. admin/.env.php 를 먼저 만들어 주세요.';
    } elseif (hash_equals((string) cfg('admin_user', 'admin'), $user) && password_ok($pass)) {
        clear_attempts($attemptFile, $ip);
        admin_login_ok($user);
        header('Location: ' . $next);
        exit;
    } else {
        record_attempt($attemptFile, $ip, $window);
        $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
        usleep(400_000);   // 타이밍 차이·연타 완화
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <title>로그인 · NUGABOX 관리자</title>
  <link rel="icon" type="image/png" href="/admin/assets/images/admin_favicon.png">

  <link rel="stylesheet" href="/admin/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/admin/assets/css/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/admin/assets/css/pretendard.css">
  <link rel="stylesheet" href="/admin/assets/css/fontawesome.min.css">
  <link rel="stylesheet" href="/admin/assets/css/admin.css">
  <link rel="stylesheet" href="/admin/assets/css/admin-custom.css">
</head>
<body class="login-page">

<button type="button" class="login-theme-toggle" id="themeToggle" aria-label="다크/라이트 전환" title="다크/라이트 전환">
  <i class="fa-solid fa-moon" id="themeIcon"></i>
</button>

<main class="login-stage">

  <section class="login-hero" aria-labelledby="loginHeroTitle">
    <div class="login-hero-copy">
      <div class="login-hero-eyebrow">NUGABOX · Admin</div>
      <h2 class="login-hero-title" id="loginHeroTitle">환영합니다.<br>사이트 파일을 한 자리에서.</h2>
      <p class="login-hero-lede">
        업로드한 파일은 곧바로 <b>nugabox.com</b> 에 공개되고,
        같은 내용이 git 저장소에도 커밋됩니다.
      </p>
      <ul class="login-hero-features">
        <li><span class="login-tick"><i class="fa-solid fa-check" aria-hidden="true"></i></span>파일 업로드</li>
        <li><span class="login-tick"><i class="fa-solid fa-check" aria-hidden="true"></i></span>이름 변경 · 삭제</li>
        <li><span class="login-tick"><i class="fa-solid fa-check" aria-hidden="true"></i></span>공개 URL 즉시 발급</li>
        <li><span class="login-tick"><i class="fa-solid fa-check" aria-hidden="true"></i></span>git 자동 커밋 · 푸시</li>
      </ul>
    </div>
  </section>

  <section class="login-auth" aria-labelledby="loginAuthTitle">
    <div class="login-auth-inner">
      <h1 id="loginAuthTitle">관리자 로그인.</h1>
      <p class="login-auth-lede">
        관리자 계정으로 로그인하세요.
      </p>

      <?php if ($error !== ''): ?>
        <div class="login-alert" role="alert">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
          <span><?= h($error) ?></span>
        </div>
      <?php endif; ?>

      <form id="loginForm" method="post" action="/admin/login.php?next=<?= h(rawurlencode($next)) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="login-form-group">
          <label class="login-label" for="loginId">아이디</label>
          <input id="loginId" class="login-field" type="text" name="username" autocomplete="username"
                 placeholder="아이디를 입력하세요" required autofocus>
        </div>
        <div class="login-form-group">
          <label class="login-label" for="loginPw">비밀번호</label>
          <input id="loginPw" class="login-field" type="password" name="password" autocomplete="current-password"
                 placeholder="비밀번호를 입력하세요" required>
        </div>
        <button class="login-btn-primary" type="submit">로그인</button>
      </form>
    </div>

    <div class="login-footer-links">
      <span>© NUGABOX. All rights reserved.</span>
    </div>
  </section>

</main>

<script src="/admin/assets/js/admin.js"></script>
</body>
</html>
