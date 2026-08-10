<?php
/**
 * 관리자 쓰기 계층 — data/*.json 저장과 리다이렉트 스텁 폴더 동기화.
 * 읽기 · 검증 · 렌더링은 include/site.php 에 있고 여기서는 쓰기만 다룬다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once ROOT_DIR . '/include/site.php';

/**
 * JSON 파일을 원자적으로 쓴다.
 * 같은 폴더에 임시 파일을 만들고 rename 하기 때문에, 쓰는 도중 요청이 들어와도
 * 반쯤 쓰인 파일이 읽히는 일이 없다.
 */
function data_write(string $name, array $payload): bool
{
    $dir = SITE_DATA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        return false;
    }
    $path = $dir . '/' . $name;
    $tmp  = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function icons_save(array $icons): bool
{
    return data_write('icons.json', [
        'columns' => ICON_COLUMNS,
        'icons'   => array_values(array_map('icon_normalize', $icons)),
    ]);
}

function redirects_save(array $list): bool
{
    $out = [];
    foreach ($list as $r) {
        $out[] = [
            'slug' => $r['slug'],
            'url'  => $r['url'],
            'note' => (string) ($r['note'] ?? ''),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
    return data_write('redirects.json', ['redirects' => $out]);
}

/** slug 하나의 스텁 폴더 경로. */
function redirect_stub_dir(string $slug): string
{
    return ROOT_DIR . '/' . $slug;
}

/**
 * 스텁 본문. 이 문자열과 정확히 같은 파일만 "우리가 만든 것"으로 보고 지운다.
 * 사람이 손댄 파일이나 진짜 하위 사이트를 실수로 날리지 않기 위해서다.
 */
const REDIRECT_STUB_BODY = "<?php\n"
    . "// 자동 생성 — 연결 주소는 data/redirects.json 에서 관리합니다. 직접 수정하지 마세요.\n"
    . "require dirname(__DIR__) . '/_redirect.php';\n";

/**
 * redirects.json 과 실제 스텁 폴더를 맞춘다.
 * 없는 폴더는 만들고, 목록에서 빠진 폴더는 스텁만 들어 있을 때에 한해 지운다.
 *
 * @return array{changed:string[], removed:string[], errors:string[]}
 */
function redirects_sync_stubs(array $list): array
{
    $result = ['changed' => [], 'removed' => [], 'errors' => []];
    $wanted = [];
    foreach ($list as $r) {
        $wanted[$r['slug']] = true;
    }

    foreach (array_keys($wanted) as $slug) {
        $dir  = redirect_stub_dir($slug);
        $file = $dir . '/index.php';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $result['errors'][] = "{$slug}/ 폴더를 만들지 못했습니다.";
            continue;
        }
        if (is_file($file) && @file_get_contents($file) === REDIRECT_STUB_BODY) {
            continue;   // 이미 최신
        }
        if (@file_put_contents($file, REDIRECT_STUB_BODY) === false) {
            $result['errors'][] = "{$slug}/index.php 를 쓰지 못했습니다.";
            continue;
        }
        @chmod($file, 0664);
        $result['changed'][] = $slug;

        // 예전 메타 리프레시 방식으로 만들어 둔 index.html 이 남아 있으면
        // 웹서버가 그쪽을 먼저 띄워 리다이렉트가 갱신되지 않는다.
        if (is_file($dir . '/index.html')) {
            @unlink($dir . '/index.html');
        }
    }

    foreach (scandir(ROOT_DIR) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.' || isset($wanted[$name])) {
            continue;
        }
        $dir = ROOT_DIR . '/' . $name;
        if (!is_dir($dir) || !is_file($dir . '/index.php')) {
            continue;
        }
        if (@file_get_contents($dir . '/index.php') !== REDIRECT_STUB_BODY) {
            continue;   // 우리가 만든 스텁이 아니면 건드리지 않는다
        }
        if (array_diff(scandir($dir) ?: [], ['.', '..', 'index.php'])) {
            continue;   // 다른 파일이 들어 있으면 남겨 둔다
        }
        if (@unlink($dir . '/index.php') && @rmdir($dir)) {
            $result['removed'][] = $name;
        } else {
            $result['errors'][] = "{$name}/ 폴더를 지우지 못했습니다.";
        }
    }

    return $result;
}
