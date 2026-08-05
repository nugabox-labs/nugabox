<?php
/**
 * 관리자 설정 — 값은 모두 .env 에서 읽고, 없으면 아래 기본값을 쓴다.
 * 아이디 · 비밀번호 같은 비밀 값은 이 파일이 아니라 .env 에 둔다. (.env.example 참고)
 */
declare(strict_types=1);

require_once __DIR__ . '/include/env.php';

$rootDir = __DIR__ . '/..';
$rootDir = realpath($rootDir) ?: dirname(__DIR__);

$uploadDir = env_str('UPLOAD_DIR', $rootDir . '/upload');

return [
    // ── 로그인 ────────────────────────────────────────────────
    'admin_user'          => env_str('ADMIN_ID', 'admin'),
    // 둘 중 하나만 있으면 된다. HASH 가 있으면 HASH 를 우선 사용한다.
    //   ADMIN_PASSWORD_HASH — password_hash() 결과 (권장)
    //   ADMIN_PASSWORD      — 평문
    'admin_password_hash' => env_str('ADMIN_PASSWORD_HASH', ''),
    'admin_password'      => env_str('ADMIN_PASSWORD', ''),
    'session_name'        => env_str('SESSION_NAME', 'nugabox_admin'),
    'session_idle_sec'    => env_int('SESSION_IDLE_HOURS', 4) * 3600,
    'login_max_attempts'  => env_int('LOGIN_MAX_ATTEMPTS', 10),
    'login_window_sec'    => env_int('LOGIN_WINDOW_MINUTES', 15) * 60,

    // 잠금 · 로그인 시도 기록을 둘 폴더. 비우면 시스템 임시 폴더를 쓴다.
    // (admin/ 에 쓰기 권한을 주지 않기 위해 기본적으로 저장소 밖에 둔다)
    'runtime_dir'         => env_str('RUNTIME_DIR', ''),

    // ── 업로드 ────────────────────────────────────────────────
    'root_dir'         => $rootDir,
    'upload_dir'       => $uploadDir,
    'public_base_url'  => rtrim(env_str('PUBLIC_BASE_URL', 'https://nugabox.com'), '/'),
    'upload_url_path'  => env_str('UPLOAD_URL_PATH', '/upload'),
    'max_upload_bytes' => env_int('MAX_UPLOAD_MB', 100) * 1024 * 1024,
    'allowed_ext'      => env_list('ALLOWED_EXT', [
        // 이미지
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico',
        // 문서
        'pdf', 'txt', 'md', 'csv', 'json', 'xml',
        'hwp', 'hwpx', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        // 미디어 · 압축
        'mp3', 'wav', 'mp4', 'webm', 'mov', 'zip',
        // 'svg' 는 같은 오리진에서 스크립트 실행이 가능해 기본 제외.
        //  필요하면 .env 의 ALLOWED_EXT 에 직접 나열하세요.
    ]),

    // ── git 동기화 ────────────────────────────────────────────
    'git' => [
        'enabled'        => env_bool('GIT_ENABLED', true),
        'bin'            => env_str('GIT_BIN', 'git'),
        'repo_dir'       => env_str('GIT_REPO_DIR', $rootDir),
        'remote'         => env_str('GIT_REMOTE', 'origin'),
        'branch'         => env_str('GIT_BRANCH', 'main'),
        'author_name'    => env_str('GIT_AUTHOR_NAME', 'nugabox admin'),
        'author_email'   => env_str('GIT_AUTHOR_EMAIL', 'admin@nugabox.com'),
        'commit_message' => env_str('GIT_COMMIT_MESSAGE', 'upload 파일 업로드'),
        // PHP-FPM 사용자의 HOME 이 비어 있으면 credential helper 를 못 찾는다.
        // 서버 저장소가 push 가능한 계정의 홈 경로를 지정한다. (빈 값 = 건드리지 않음)
        'home'           => env_str('GIT_HOME', '') ?: null,
        'timeout_sec'    => env_int('GIT_TIMEOUT_SEC', 180),
    ],
];
