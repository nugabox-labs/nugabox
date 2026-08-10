<?php
/**
 * 리다이렉트 처리기 — /blog, /wiki 처럼 바깥 주소로 보내는 경로를 전부 여기서 담당한다.
 *
 * 각 폴더에는 이 파일을 require 하는 한 줄짜리 index.php 만 들어 있다.
 * nginx 설정을 건드릴 수 없는 환경(시놀로지 DSM)이라 주소를 열려면 실제 폴더가
 * 필요하지만, 주소 목록 자체는 data/redirects.json 한 곳에서만 관리한다.
 *
 * 302 를 쓴다. 301 은 브라우저가 영구히 캐시해 버려서, 나중에 대상 주소를 바꿔도
 * 이미 방문했던 사람은 예전 주소로 계속 가게 된다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/site.php';

/** 자신을 require 한 스텁이 어느 폴더에 있는지로 slug 를 정한다. */
$callers = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
$stub    = $callers ? ($callers[count($callers) - 1]['file'] ?? '') : '';
$slug    = $stub !== '' ? basename(dirname($stub)) : '';

$target = redirects_load()[$slug] ?? null;

if ($target === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title>'
        . '<body style="font-family:system-ui;padding:60px;text-align:center">'
        . '<p>연결된 주소가 없습니다.</p>'
        . '<p><a href="/">NUGABOX 로 돌아가기</a></p></body>';
    exit;
}

header('Cache-Control: no-store');
header('Location: ' . $target['url'], true, 302);
echo '<!doctype html><meta charset="utf-8">'
    . '<meta http-equiv="refresh" content="0; url=' . e($target['url']) . '">'
    . '<a href="' . e($target['url']) . '">' . e($target['url']) . '</a>';
