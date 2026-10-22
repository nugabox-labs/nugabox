<?php
/**
 * /pages/<코드>/ 로 들어오면 그 프로젝트의 최신 버전으로 보낸다.
 * 각 프로젝트 폴더의 index.php(자동 생성 스텁)가 이 파일을 require 한다.
 *
 * 팀에는 /pages/<코드>/ 하나만 알려 주면 된다. 새 버전을 올리거나 관리자에서
 * 최신 버전을 바꾸면 같은 주소가 그쪽을 가리킨다. 특정 버전은 /pages/<코드>/<버전>/.
 *
 * 관리자 코드에 의존하지 않는다. (공개 쪽이 세션 · 설정을 읽지 않게)
 */
declare(strict_types=1);

function pages_redirect_latest(string $dir): void
{
    $raw  = @file_get_contents($dir . '/project.json');
    $json = $raw !== false ? json_decode($raw, true) : null;

    $latest = is_array($json) ? (string) ($json['latest'] ?? '') : '';
    $entry  = '';
    foreach (is_array($json) ? (array) ($json['versions'] ?? []) : [] as $v) {
        if (is_array($v) && (string) ($v['version'] ?? '') === $latest) {
            $entry = (string) ($v['entry'] ?? '');
        }
    }

    // 버전 이름 · 파일 경로가 폴더 밖을 가리키지 않는지 다시 본다. project.json 은 손으로도 고칠 수 있다.
    $bad = static fn(string $p): bool => $p === '' || strpos($p, '..') !== false || $p[0] === '.' || $p[0] === '/';
    if ($bad($latest) || strpos($latest, '/') !== false || !is_dir($dir . '/' . $latest)
        || ($entry !== '' && ($bad($entry) || !is_file($dir . '/' . $latest . '/' . $entry)))) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><meta charset="utf-8"><title>404</title>'
            . '<body style="font-family:system-ui;padding:60px;text-align:center;color:#666">'
            . '아직 올린 버전이 없습니다.</body>';
        exit;
    }

    // /pages/abc 와 /pages/abc/ 둘 다 들어올 수 있어 상대 주소를 쓰지 않는다.
    $base = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = rtrim((string) preg_replace('~/index\.php$~', '', $base), '/') . '/';

    $url = $base . rawurlencode($latest) . '/';
    if ($entry !== '' && $entry !== 'index.html') {
        $url .= implode('/', array_map('rawurlencode', explode('/', $entry)));
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');

    // 최신 버전은 언제든 바뀐다. 브라우저 · 프록시가 이 이동을 기억하면 안 된다.
    header('Cache-Control: no-store');
    header('Location: ' . $url . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}
