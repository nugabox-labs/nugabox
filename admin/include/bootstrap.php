<?php
/**
 * 관리자 공통 부트스트랩 — 설정 로드, 세션, CSRF, 로그인 가드, 유틸리티.
 * 모든 관리자 페이지와 API 가 가장 먼저 require 합니다.
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

    // 무활동 타임아웃
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

function admin_login_ok(string $user): void
{
    session_regenerate_id(true);
    $_SESSION['admin_ok']   = true;
    $_SESSION['admin_user'] = $user;
    $_SESSION['last_seen']  = time();
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

/** 로그인하지 않았으면 로그인 페이지로 보낸다. (HTML 페이지용) */
function admin_require_login(): void
{
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

/* ── 응답 ──────────────────────────────────────────────────── */

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $message, int $status = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ── 런타임 상태 파일 ──────────────────────────────────────── */

/**
 * 잠금 · 로그인 시도 기록처럼 실행 중에만 쓰는 파일의 경로.
 *
 * admin/ 안에 두지 않는다. 그러면 웹서버 사용자에게 PHP 가 실행되는
 * 디렉터리의 쓰기 권한을 줘야 하기 때문이다. 기본값은 시스템 임시 폴더이고,
 * 저장소 경로로 이름을 나눠 한 서버에 여러 사이트가 있어도 겹치지 않는다.
 */
function runtime_file(string $name): string
{
    $dir = (string) cfg('runtime_dir', '');
    $dir = rtrim($dir !== '' ? $dir : sys_get_temp_dir(), '/');
    return $dir . '/nugabox-admin-' . substr(sha1(ROOT_DIR), 0, 8) . '-' . $name;
}

/* ── 파일 유틸 ─────────────────────────────────────────────── */

/** 항상 실행 가능한 확장자로 취급해 거부할 목록. 이름 어디에 나타나도 막는다. */
const FORBIDDEN_EXT = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
    'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
    'htaccess', 'htpasswd', 'user', 'ini', 'so', 'dll', 'jsp', 'asp', 'aspx',
];

/**
 * 업로드/이름변경에 쓸 안전한 파일명으로 정규화한다.
 * 통과하지 못하면 null 을 돌려준다.
 */
function safe_filename(string $name): ?string
{
    $name = str_replace(["\0", "\r", "\n", '/', '\\'], '', $name);
    $name = basename($name);
    $name = trim($name);

    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }
    if ($name[0] === '.') {            // 숨김 파일 금지
        return null;
    }
    if (strlen($name) > 200) {
        return null;
    }
    // 한글·영숫자로 시작하고, 이후 공백/. _ - ( ) [ ] 만 허용
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\-()\[\]]*$/u', $name)) {
        return null;
    }
    return $name;
}

/** 확장자 정책 검사. 통과하면 null, 아니면 오류 메시지를 돌려준다. */
function check_extension(string $name): ?string
{
    $parts = array_map('strtolower', explode('.', $name));
    array_shift($parts);               // 첫 조각은 파일명 본체

    if (!$parts) {
        return '확장자가 없는 파일은 업로드할 수 없습니다.';
    }
    foreach ($parts as $part) {
        if (in_array($part, FORBIDDEN_EXT, true)) {
            return "실행 가능한 확장자(.{$part})는 허용되지 않습니다.";
        }
    }
    $ext     = end($parts);
    $allowed = (array) cfg('allowed_ext', []);
    if (!in_array($ext, $allowed, true)) {
        return "허용되지 않은 확장자입니다: .{$ext}";
    }
    return null;
}

function upload_dir(): string
{
    return rtrim((string) cfg('upload_dir', ROOT_DIR . '/upload'), '/');
}

/** upload 폴더 안의 실제 경로를 돌려준다. 벗어나면 null. */
function upload_path(string $filename): ?string
{
    $safe = safe_filename($filename);
    if ($safe === null) {
        return null;
    }
    $dir  = upload_dir();
    $path = $dir . '/' . $safe;

    // 심볼릭 링크 등으로 upload 밖을 가리키지 않는지 최종 확인
    $realDir = realpath($dir);
    if ($realDir === false) {
        return null;
    }
    $realPath = realpath($path);
    if ($realPath !== false && strpos($realPath, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $path;
}

function public_url(string $filename): string
{
    $base = rtrim((string) cfg('public_base_url', ''), '/');
    $path = '/' . trim((string) cfg('upload_url_path', '/upload'), '/');
    return $base . $path . '/' . rawurlencode($filename);
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($n, $n >= 100 ? 0 : 1)) . ' ' . $units[$i];
}

/** php.ini 가 실제로 허용하는 업로드 상한 (설정값과 비교해 더 작은 쪽). */
function effective_max_upload(): int
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

    $candidates = [(int) cfg('max_upload_bytes', PHP_INT_MAX)];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = $toBytes((string) ini_get($key));
        if ($bytes > 0) {
            $candidates[] = $bytes;
        }
    }
    return min($candidates);
}

/** upload 폴더의 파일 목록 (하위 폴더·숨김 파일 제외). */
function list_upload_files(): array
{
    $dir = upload_dir();
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
        $out[] = [
            'name'       => $name,
            'size'       => $size,
            'size_human' => human_size($size),
            'mtime'      => (int) filemtime($path),
            'modified'   => date('Y-m-d H:i', (int) filemtime($path)),
            'ext'        => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'url'        => public_url($name),
        ];
    }
    usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    return $out;
}
