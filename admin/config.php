<?php
/**
 * 관리자 설정 — 값은 모두 .env.php 에서 읽고, 없으면 아래 기본값을 쓴다.
 * 비밀번호 · 토큰 같은 비밀 값은 이 파일이 아니라 .env.php 에 둔다. (.env.php.example 참고)
 */
declare(strict_types=1);

require_once __DIR__ . '/include/env.php';

$rootDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

return [
    // ── 로그인 ────────────────────────────────────────────────
    // 비밀번호만 입력한다. 둘 중 하나만 있으면 되고, HASH 가 있으면 HASH 우선.
    //   ADMIN_PASSWORD_HASH — password_hash() 결과 (권장)
    //   ADMIN_PASSWORD      — 평문
    // 둘 다 비어 있으면 관리자 전체가 503 으로 막힌다. (admin_require_login)
    // 로그인 화면에 고정으로 채워져 나가는 아이디. 실제로 바꿀 일은 거의 없다.
    'admin_id'            => env_str('ADMIN_ID', 'root'),
    'admin_password_hash' => env_str('ADMIN_PASSWORD_HASH', ''),
    'admin_password'      => env_str('ADMIN_PASSWORD', ''),
    'session_name'        => env_str('SESSION_NAME', 'nugabox_admin'),
    'session_idle_sec'    => env_int('SESSION_IDLE_HOURS', 4) * 3600,
    'login_max_attempts'  => env_int('LOGIN_MAX_ATTEMPTS', 10),
    'login_window_sec'    => env_int('LOGIN_WINDOW_MINUTES', 15) * 60,

    // 잠금 · 로그인 시도 기록을 둘 폴더. 비우면 시스템 임시 폴더를 쓴다.
    'runtime_dir'         => env_str('RUNTIME_DIR', ''),

    // ── 경로 ──────────────────────────────────────────────────
    'root_dir'   => $rootDir,
    'icons_dir'  => env_str('ICONS_DIR', $rootDir . '/assets/images/icons'),
    'icons_url'  => '/assets/images/icons',

    'max_upload_bytes' => env_int('MAX_UPLOAD_MB', 8) * 1024 * 1024,
    // 아이콘은 이미지만. svg 는 같은 오리진에서 스크립트가 돌 수 있어 기본 제외.
    'allowed_ext'      => env_list('ALLOWED_EXT', ['png', 'jpg', 'jpeg', 'webp', 'gif']),

    // ── git 동기화 ────────────────────────────────────────────
    'git' => [
        'enabled'        => env_bool('GIT_ENABLED', true),
        'bin'            => env_str('GIT_BIN', 'git'),
        'repo_dir'       => env_str('GIT_REPO_DIR', $rootDir),
        'remote'         => env_str('GIT_REMOTE', 'origin'),
        'branch'         => env_str('GIT_BRANCH', 'main'),
        'author_name'    => env_str('GIT_AUTHOR_NAME', 'nugabox admin'),
        'author_email'   => env_str('GIT_AUTHOR_EMAIL', 'admin@nugabox.com'),
        // PHP-FPM 사용자의 HOME 이 비어 있으면 credential helper 를 못 찾는다.
        'home'           => env_str('GIT_HOME', '') ?: null,
        'timeout_sec'    => env_int('GIT_TIMEOUT_SEC', 180),

        // push 자격증명. 웹서버 사용자는 보통 자기 홈에 자격증명이 없다.
        'token'            => env_str('GIT_TOKEN', ''),
        'username'         => env_str('GIT_USERNAME', ''),
        'credentials_file' => env_str('GIT_CREDENTIALS_FILE', ''),
    ],
];
