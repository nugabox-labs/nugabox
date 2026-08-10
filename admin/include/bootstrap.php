<?php
/**
 * 관리자 공통 부트스트랩 — 설정 로드, 세션, CSRF, 로그인 가드, 파일 유틸리티.
 * 모든 관리자 페이지가 가장 먼저 require 한다.
 */
declare(strict_types=1);

define('ADMIN_DIR', dirname(__DIR__));
define('ROOT_DIR', dirname(ADMIN_DIR));

/** @var array $CONFIG */
$CONFIG = require ADMIN_DIR . '/config.php';

function cfg(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/* ── 로그인 가능 여부 ──────────────────────────────────────── */

/**
 * 비밀번호가 설정되어 있는지. 설정이 없으면 관리자는 아예 열리지 않는다.
 * "비밀번호 없음 = 누구나 통과" 가 되지 않게 하는 최소 안전장치다.
 */
function admin_password_configured(): bool
{
    return cfg('admin_password_hash', '') !== '' || cfg('admin_password', '') !== '';
}

/** 설정이 없으면 503 으로 끊는다. 로그인 화면조차 보여주지 않는다. */
function admin_require_configured(): void
{
    if (admin_password_configured()) {
        return;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $why = env_problem() ?? 'admin/.env.php 에 ADMIN_PASSWORD 또는 ADMIN_PASSWORD_HASH 를 설정해 주세요.';
    echo '<!doctype html><meta charset="utf-8"><title>503</title>'
        . '<body style="font-family:system-ui;padding:60px;max-width:640px;margin:auto">'
        . '<h1 style="font-size:20px">관리자가 설정되지 않았습니다</h1>'
        . '<p style="color:#666;line-height:1.7">' . h($why) . '</p>'
        . '</body>';
    exit;
}

/* ── 세션 ──────────────────────────────────────────────────── */

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name((string) cfg('session_name', 'nugabox_admin'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_start();

    $idle = (int) cfg('session_idle_sec', 14400);
    if (!empty($_SESSION['last_seen']) && (time() - (int) $_SESSION['last_seen']) > $idle) {
        admin_logout();
    }
    $_SESSION['last_seen'] = time();
}

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_ok']);
}

function admin_login_ok(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_ok']  = true;
    $_SESSION['last_seen'] = time();
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** 입력된 비밀번호가 맞는지. 해시가 있으면 해시를 우선한다. */
function admin_password_verify(string $input): bool
{
    $hash = (string) cfg('admin_password_hash', '');
    if ($hash !== '') {
        return password_verify($input, $hash);
    }
    $plain = (string) cfg('admin_password', '');
    return $plain !== '' && hash_equals($plain, $input);
}

/** 로그인하지 않았으면 로그인 페이지로 보낸다. */
function admin_require_login(): void
{
    admin_require_configured();
    admin_session_start();
    if (!admin_is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
        header('Location: /admin/login.php?next=' . rawurlencode($next));
        exit;
    }
}

/* ── CSRF ──────────────────────────────────────────────────── */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/** POST 요청을 받는 페이지의 첫 줄. 실패하면 즉시 끊는다. */
function csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!csrf_check($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF 검증 실패. 페이지를 새로고침한 뒤 다시 시도해 주세요.');
    }
}

/* ── 응답 · 플래시 ─────────────────────────────────────────── */

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 처리 결과를 세션에 담고 리다이렉트한다. (새로고침 재전송 방지) */
function flash_redirect(string $to, string $kind, string $message, string $log = ''): void
{
    $_SESSION['flash'] = ['kind' => $kind, 'message' => $message, 'log' => $log];
    header('Location: ' . $to);
    exit;
}

function flash_take(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ── 런타임 상태 파일 ──────────────────────────────────────── */

/**
 * 잠금 · 로그인 시도 기록처럼 실행 중에만 쓰는 파일의 경로.
 * admin/ 안에 두지 않는다 — PHP 가 실행되는 디렉터리에 쓰기 권한을 주지 않기 위해서다.
 */
function runtime_file(string $name): string
{
    $dir = (string) cfg('runtime_dir', '');
    $dir = rtrim($dir !== '' ? $dir : sys_get_temp_dir(), '/');
    return $dir . '/nugabox-admin-' . substr(sha1(ROOT_DIR), 0, 8) . '-' . $name;
}

/* ── 주소 ──────────────────────────────────────────────────── */

/**
 * 지금 접속한 주소를 기준으로 절대 URL 을 만든다.
 * 실서버에서 열었으면 https://nugabox.io/..., 로컬이면 http://localhost:1000/... 이
 * 그대로 나온다. 복사 버튼이 주는 링크가 곧 지금 보고 있는 사이트의 링크여야 한다.
 */
function absolute_url(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') ?: ($https ? 'https' : 'http');
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

/* ── 파일 유틸 ─────────────────────────────────────────────── */

/**
 * 언제나 거부할 확장자. 이름 어디에 나타나도 막는다.
 * (a.php.png 처럼 중간에 숨겨 두는 수법을 차단하려고 마지막 조각만 보지 않는다)
 *
 * 화이트리스트가 이미 있으니 이 목록은 두 번째 방어선이다. 화이트리스트를
 * 누가 잘못 넓히더라도 실행 · 스크립트 · 소스 파일은 여기서 걸린다.
 */
const FORBIDDEN_EXT = [
    // 서버에서 실행되는 것
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
    'cgi', 'fcgi', 'pl', 'py', 'pyc', 'pyo', 'rb', 'sh', 'bash', 'zsh', 'ksh', 'csh', 'fish',
    'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx', 'cfm', 'erb', 'ejs', 'twig',
    // 브라우저에서 같은 오리진으로 실행되는 것 (저장형 XSS)
    'html', 'htm', 'xhtml', 'shtml', 'xht', 'svg', 'xml', 'xsl', 'xslt',
    'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'css', 'vue', 'svelte', 'wasm', 'swf',
    // 내려받아 실행되는 것
    'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'msix', 'appx', 'msp', 'msc', 'cpl',
    'vbs', 'vbe', 'jse', 'wsf', 'wsh', 'hta', 'ps1', 'psm1', 'psd1', 'reg',
    'jar', 'war', 'ear', 'class', 'jnlp', 'apk', 'app', 'deb', 'rpm', 'pkg', 'dmg',
    'so', 'dll', 'dylib', 'bin', 'run', 'elf', 'lnk', 'url', 'desktop', 'action', 'workflow',
    // 매크로가 들어가는 오피스 형식
    'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'sldm',
    // 설정 · 소스 · 자격증명
    'htaccess', 'htpasswd', 'user', 'ini', 'env', 'conf', 'cnf', 'config',
    'sql', 'yml', 'yaml', 'toml', 'sqlite', 'sqlite3', 'db',
    'c', 'h', 'cpp', 'hpp', 'cc', 'cs', 'java', 'go', 'rs', 'swift', 'kt', 'lua',
    'scala', 'groovy', 'tcl', 'awk', 'pem', 'key', 'crt', 'cer', 'p12', 'pfx',
];

/**
 * 업로드 파일 관리(/upload)에서 받는 확장자. 분류별로 묶어 화면에 그대로 보여준다.
 * 여기에 없는 확장자는 올릴 수 없다.
 */
const UPLOAD_EXT_GROUPS = [
    '문서'        => ['pdf', 'hwp', 'hwpx', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                      'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md'],
    '이미지'      => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'ico'],
    '오디오 · 영상' => ['mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'mp4', 'm4v', 'mov',
                      'webm', 'mkv', 'avi'],
    '압축'        => ['zip', '7z', 'rar', 'tar', 'gz', 'tgz', 'bz2'],
    '글꼴'        => ['ttf', 'otf', 'woff', 'woff2'],
];

/** UPLOAD_EXT_GROUPS 를 한 줄로 편 기본 화이트리스트. */
function upload_default_ext(): array
{
    return array_values(array_unique(array_merge(...array_values(UPLOAD_EXT_GROUPS))));
}

/**
 * 업로드에 실제로 적용할 화이트리스트.
 * 설정이 비어 있어도 "제한 없음" 으로 열리지 않는다 — 기본 목록으로 되돌린다.
 * 설정에서 넓히더라도 FORBIDDEN_EXT 는 check_extension 이 따로 막는다.
 */
function upload_allowed_ext(): array
{
    $list = array_values(array_filter(array_map(
        static fn($e): string => strtolower(trim((string) $e)),
        (array) cfg('upload_allowed_ext', [])
    )));
    return $list ?: upload_default_ext();
}

/**
 * 실제로 허용된 확장자를 분류별로 묶어 돌려준다.
 * 설정에서 목록을 좁히거나 넓혔을 때도 화면이 진짜 정책과 어긋나지 않게,
 * 분류표가 아니라 허용 목록을 기준으로 만든다.
 */
function upload_ext_groups(array $allowed): array
{
    $out  = [];
    $seen = [];
    foreach (UPLOAD_EXT_GROUPS as $label => $exts) {
        $hit = array_values(array_intersect($exts, $allowed));
        if ($hit) {
            $out[$label] = $hit;
            $seen = array_merge($seen, $hit);
        }
    }
    $rest = array_values(array_diff($allowed, $seen));
    if ($rest) {
        $out['기타'] = $rest;
    }
    return $out;
}

/** 파일명으로 쓸 수 있게 정규화한다. 통과하지 못하면 null. */
function safe_filename(string $name): ?string
{
    $name = trim(basename(str_replace(["\0", "\r", "\n", '/', '\\'], '', $name)));

    if ($name === '' || $name === '.' || $name === '..' || $name[0] === '.') {
        return null;
    }
    if (strlen($name) > 120) {
        return null;
    }
    // 영숫자 · _ · - · . 만 허용한다. CSS url() 과 파일 시스템, 주소 양쪽에서 안전하다.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
        return null;
    }
    return $name;
}

/**
 * 확장자 정책 검사. 통과하면 null, 아니면 오류 메시지.
 *
 * $allowed 가 빈 배열이면 화이트리스트를 적용하지 않는다. 실행 가능한 확장자
 * (FORBIDDEN_EXT) 는 그 경우에도 언제나 막는다.
 */
function check_extension(string $name, ?array $allowed = null): ?string
{
    $allowed = $allowed ?? (array) cfg('allowed_ext', []);
    $parts = array_map('strtolower', explode('.', $name));
    array_shift($parts);

    if (!$parts) {
        return '확장자가 없는 파일은 올릴 수 없습니다.';
    }
    foreach ($parts as $part) {
        if (in_array($part, FORBIDDEN_EXT, true)) {
            return "실행 가능한 확장자(.{$part})는 허용되지 않습니다.";
        }
    }
    $ext = end($parts);
    if ($allowed && !in_array($ext, $allowed, true)) {
        return "허용되지 않은 확장자입니다: .{$ext}";
    }
    return null;
}

/** 정해진 폴더 안의 실제 경로. 폴더를 벗어나면 null. */
function managed_path(string $dir, string $filename): ?string
{
    $safe = safe_filename($filename);
    if ($safe === null) {
        return null;
    }
    $dir     = rtrim($dir, '/');
    $realDir = realpath($dir);
    if ($realDir === false) {
        return null;
    }
    $path     = $dir . '/' . $safe;
    $realPath = realpath($path);
    // 심볼릭 링크 등으로 관리 폴더 밖을 가리키지 않는지 최종 확인
    if ($realPath !== false && strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $path;
}

function icons_dir(): string
{
    return rtrim((string) cfg('icons_dir', ROOT_DIR . '/assets/images/icons'), '/');
}

function icon_path(string $filename): ?string
{
    return managed_path(icons_dir(), $filename);
}

function icon_url(string $filename): string
{
    return rtrim((string) cfg('icons_url', '/assets/images/icons'), '/') . '/' . rawurlencode($filename);
}

function uploads_dir(): string
{
    return rtrim((string) cfg('uploads_dir', ROOT_DIR . '/upload'), '/');
}

function upload_path(string $filename): ?string
{
    return managed_path(uploads_dir(), $filename);
}

function upload_url(string $filename): string
{
    return rtrim((string) cfg('uploads_url', '/upload'), '/') . '/' . rawurlencode($filename);
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($n, $n >= 100 ? 0 : 1)) . ' ' . $units[$i];
}

/** php.ini 가 실제로 허용하는 업로드 상한 (설정값과 비교해 더 작은 쪽). */
function effective_max_upload(string $cfgKey = 'max_upload_bytes'): int
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $unit = strtolower($v[strlen($v) - 1]);
        $num  = (int) $v;
        switch ($unit) {
            case 'g': $num *= 1024;
            // no break
            case 'm': $num *= 1024;
            // no break
            case 'k': $num *= 1024;
        }
        return $num;
    };

    $candidates = [(int) cfg($cfgKey, PHP_INT_MAX)];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = $toBytes((string) ini_get($key));
        if ($bytes > 0) {
            $candidates[] = $bytes;
        }
    }
    return min($candidates);
}

/**
 * 폴더 안의 파일 목록 (하위 폴더 · 숨김 파일 제외).
 * $urlOf 는 파일명을 공개 주소로 바꾸는 함수다.
 */
function list_managed_files(string $dir, callable $urlOf): array
{
    $dir = rtrim($dir, '/');
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $size = (int) filesize($path);
        $dim  = @getimagesize($path);
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $out[] = [
            'name'       => $name,
            'ext'        => $ext,
            'size'       => $size,
            'size_human' => human_size($size),
            'mtime'      => (int) filemtime($path),
            'modified'   => date('Y-m-d H:i', (int) filemtime($path)),
            'dimensions' => $dim ? $dim[0] . '×' . $dim[1] : '-',
            'url'        => $urlOf($name),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** 아이콘 폴더의 파일 목록. */
function list_icon_files(): array
{
    return list_managed_files(icons_dir(), 'icon_url');
}

/** 업로드 폴더의 파일 목록. */
function list_upload_files(): array
{
    return list_managed_files(uploads_dir(), 'upload_url');
}
