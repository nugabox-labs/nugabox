<?php
/**
 * 페이지 관리 — 목업 · 정적 HTML 을 프로젝트 단위로 올려 /pages/<코드>/ 로 공개한다.
 *
 * 구조는 cag-mockup 의 버전 구조를 따른다.
 *   pages/<코드>/project.json   제목 · 최신 버전 · 버전별 메모
 *   pages/<코드>/index.php      최신 버전으로 보내는 스텁 (자동 생성)
 *   pages/<코드>/<버전>/...      올린 파일 그대로
 *
 * 디스크가 진실이다. 버전 목록은 실제 하위 폴더에서 만들고, project.json 은 제목 · 메모처럼
 * 폴더만 보고는 알 수 없는 값을 보탠다. 폴더를 손으로 지우거나 넣어도 목록이 깨지지 않는다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * 페이지로 올릴 수 있는 확장자. 분류별로 묶어 화면에 그대로 보여준다.
 * /upload 와 달리 html · js · css · svg 를 받는다 — 페이지는 그게 본체다.
 */
const PAGE_EXT_GROUPS = [
    'HTML'            => ['html', 'htm'],
    '스타일 · 스크립트' => ['css', 'js', 'mjs', 'json', 'map'],
    '이미지'          => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp'],
    '글꼴'            => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
    '오디오 · 영상'    => ['mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm'],
    '기타'            => ['txt', 'md', 'pdf', 'csv'],
];

/**
 * 이름 중간에 있어도 거부하는 확장자 — 서버가 실행하는 것들.
 * a.php.html 을 PHP 로 돌리는 웹서버 설정이 있어서 마지막 조각만 봐서는 안 된다.
 * (FORBIDDEN_EXT 를 그대로 쓰지 않는 이유: app.config.js 의 config 같은 흔한 조각까지 막힌다)
 */
const PAGE_SERVER_EXT = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
    'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx',
    'cfm', 'shtml', 'htaccess', 'htpasswd',
];

/** 버전 이름으로 쓸 수 없는 값 — 프로젝트 폴더 안의 관리 파일과 겹친다. */
const PAGE_RESERVED_NAMES = ['index.php', 'project.json'];

/** 경로 깊이 상한. 정상적인 목업이 넘을 일은 없다. */
const PAGE_MAX_DEPTH = 16;

/** pages/<코드>/index.php 본문. 내용이 이것과 다르면 다시 쓴다. */
const PAGE_STUB_BODY = "<?php\n"
    . "// 자동 생성 — 최신 버전으로 보냅니다. 관리자 > 페이지 관리에서 바꾸세요. 직접 수정하지 마세요.\n"
    . "require dirname(__DIR__) . '/_latest.php';\n"
    . "pages_redirect_latest(__DIR__);\n";

/* ── 경로 · 주소 ───────────────────────────────────────────── */

function pages_dir(): string
{
    return rtrim((string) cfg('pages_dir', ROOT_DIR . '/pages'), '/');
}

/** 저장소 루트 기준 상대 경로. 커밋 대상으로 넘긴다. */
function pages_rel(): string
{
    $dir  = pages_dir();
    $root = rtrim(ROOT_DIR, '/') . '/';
    return strpos($dir, $root) === 0 ? substr($dir, strlen($root)) : 'pages';
}

/** 경로 조각을 하나씩 인코딩해 공개 주소를 만든다. */
function pages_url(string $code, ?string $version = null, string $entry = ''): string
{
    $url = rtrim((string) cfg('pages_url', '/pages'), '/') . '/' . rawurlencode($code) . '/';
    if ($version === null) {
        return $url;
    }
    $url .= rawurlencode($version) . '/';
    // index.html 은 폴더 주소로 충분하다. 공유하는 링크가 짧아진다.
    if ($entry !== '' && $entry !== 'index.html') {
        $url .= implode('/', array_map('rawurlencode', explode('/', $entry)));
    }
    return $url;
}

/* ── 이름 규칙 ─────────────────────────────────────────────── */

/** 프로젝트 코드. 공유 주소가 되므로 영문 소문자 · 숫자 · _ · - 만 쓴다. */
function page_code_clean(string $code): ?string
{
    $code = strtolower(trim($code));
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $code) ? $code : null;
}

/** 버전 이름. v1 · v1.2 · 2026-10-22 · 2차검토 모두 된다. */
function page_version_clean(string $version): ?string
{
    $version = page_nfc(trim($version));
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,39}$/u', $version)) {
        return null;
    }
    return in_array(strtolower($version), PAGE_RESERVED_NAMES, true) ? null : $version;
}

/**
 * 올린 파일 경로의 한 조각. 한글 파일명을 그대로 살린다 — 목업 안의 링크가
 * 그 이름을 가리키고 있으므로 바꾸면 화면 사이 이동이 끊긴다.
 * 주소에서 의미가 바뀌는 문자(# ? % & 등)와 숨김 이름만 막는다.
 */
function page_segment_clean(string $seg): ?string
{
    $seg = page_nfc($seg);
    if ($seg === '' || $seg[0] === '.' || mb_strlen($seg) > 120) {
        return null;
    }
    return preg_match('/^[\p{L}\p{M}\p{N}_()\[\]][\p{L}\p{M}\p{N} _.()\[\]+@~,-]*$/u', $seg) ? $seg : null;
}

/**
 * 맥에서 만든 zip · 폴더는 한글 파일명이 자모로 풀린 NFD 로 들어온다.
 * HTML 안의 링크는 NFC 라서 그대로 두면 리눅스 서버에서 404 가 난다.
 */
function page_nfc(string $s): string
{
    if ($s !== '' && class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) {
            return $n;
        }
    }
    return $s;
}

/** zip 엔트리 이름을 UTF-8 로. 윈도우 탐색기로 만든 zip 은 이름이 CP949 다. */
function page_zip_name(string $raw): string
{
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }
    $conv = @iconv('CP949', 'UTF-8//IGNORE', $raw);
    return is_string($conv) && $conv !== '' ? $conv : mb_convert_encoding($raw, 'UTF-8', 'CP949');
}

function page_allowed_ext(): array
{
    return array_values(array_unique(array_merge(...array_values(PAGE_EXT_GROUPS))));
}

/** 확장자 정책 검사. 통과하면 null, 아니면 거부 사유. */
function page_ext_check(string $name): ?string
{
    $parts = array_map('strtolower', explode('.', $name));
    array_shift($parts);
    if (!$parts) {
        return '확장자 없음';
    }
    foreach ($parts as $part) {
        if (in_array($part, PAGE_SERVER_EXT, true)) {
            return "실행 파일(.{$part})";
        }
    }
    $ext = end($parts);
    return in_array($ext, page_allowed_ext(), true) ? null : ".{$ext} 제외";
}

/**
 * 올린 파일의 상대 경로를 검사 · 정규화한다.
 * 통과하면 ['path' => 'a/b.html'], 조용히 버릴 것(숨김 · __MACOSX)이면 ['skip' => ''],
 * 사용자에게 알려야 할 거부면 ['skip' => '사유'].
 */
function page_path_clean(string $raw): array
{
    $segs = [];
    foreach (explode('/', str_replace('\\', '/', $raw)) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            return ['skip' => '상위 폴더 경로'];
        }
        // 맥 압축 찌꺼기 · .DS_Store · .git 같은 숨김 항목은 알리지 않고 버린다.
        if ($seg[0] === '.' || $seg === '__MACOSX' || $seg === 'Thumbs.db' || $seg === 'desktop.ini') {
            return ['skip' => ''];
        }
        $clean = page_segment_clean($seg);
        if ($clean === null) {
            return ['skip' => '쓸 수 없는 이름'];
        }
        $segs[] = $clean;
    }
    if (!$segs) {
        return ['skip' => ''];
    }
    if (count($segs) > PAGE_MAX_DEPTH) {
        return ['skip' => '폴더가 너무 깊음'];
    }
    $path = implode('/', $segs);
    if (($why = page_ext_check(end($segs))) !== null) {
        return ['skip' => $why];
    }
    return ['path' => $path];
}

/* ── 업로드 수집 ───────────────────────────────────────────── */

/**
 * 올린 것들을 "상대 경로 → 원본" 목록으로 모은다. 아직 아무것도 쓰지 않는다.
 *
 * zip 한 개면 그 안을 펼치고, 아니면 각 파일을 그대로 쓴다. 폴더를 골랐으면
 * 브라우저가 보내 준 상대 경로(paths[])로 폴더 구조를 되살린다.
 *
 * @return array{items: array<string, array>, skipped: string[], source: string, error: ?string}
 */
function page_collect(array $files, array $paths, int $maxFile, int $maxTotal, int $maxCount): array
{
    $out = ['items' => [], 'skipped' => [], 'source' => 'files', 'error' => null];

    $list = [];
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = (string) $files['name'][$i];
        if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
            $out['error'] = "{$name}: 업로드에 실패했습니다 (오류 코드 {$files['error'][$i]}).";
            return $out;
        }
        // PHP 8.1 부터는 full_path 가 오지만, 그 아래 버전을 위해 JS 가 paths[] 도 함께 보낸다.
        $rel = (string) ($files['full_path'][$i] ?? '') ?: (string) ($paths[$i] ?? '') ?: $name;
        $list[] = ['name' => $name, 'rel' => $rel, 'tmp' => (string) $files['tmp_name'][$i], 'size' => (int) $files['size'][$i]];
    }

    if (!$list) {
        $out['error'] = '올린 파일이 없습니다.';
        return $out;
    }

    // 폴더를 골랐는데 받은 파일 수가 모자라면 php.ini 의 max_file_uploads 에 잘린 것이다.
    // 일부만 올라간 페이지는 깨진 페이지라서 통째로 거절한다.
    $sent = count(array_filter($paths, static fn($p): bool => (string) $p !== ''));
    if ($sent > count($list)) {
        $out['error'] = "파일 {$sent}개 중 " . count($list) . '개만 서버에 도착했습니다. '
            . '한 번에 받을 수 있는 파일 수(max_file_uploads)를 넘었습니다. 폴더를 zip 으로 묶어 올려 주세요.';
        return $out;
    }

    $zips = array_filter($list, static fn(array $f): bool => strtolower((string) pathinfo($f['name'], PATHINFO_EXTENSION)) === 'zip');
    if ($zips) {
        if (count($list) !== 1) {
            $out['error'] = 'zip 은 한 번에 하나만, 다른 파일 없이 올려 주세요.';
            return $out;
        }
        return page_collect_zip($list[0], $maxFile, $maxTotal, $maxCount);
    }

    $out['source'] = count(array_filter($list, static fn(array $f): bool => strpos(str_replace('\\', '/', $f['rel']), '/') !== false))
        ? 'folder' : 'files';

    $total = 0;
    foreach ($list as $f) {
        if ($f['size'] > $maxFile) {
            $out['error'] = "{$f['name']}: 너무 큽니다 (최대 " . human_size($maxFile) . ').';
            return $out;
        }
        $r = page_path_clean($f['rel']);
        if (isset($r['skip'])) {
            if ($r['skip'] !== '') {
                $out['skipped'][] = "{$f['rel']} ({$r['skip']})";
            }
            continue;
        }
        $total += $f['size'];
        $out['items'][$r['path']] = ['tmp' => $f['tmp'], 'size' => $f['size']];
    }

    return page_collect_finish($out, $total, $maxTotal, $maxCount);
}

/** zip 하나를 펼칠 목록으로 바꾼다. 실제 압축 해제는 page_stage 가 한다. */
function page_collect_zip(array $f, int $maxFile, int $maxTotal, int $maxCount): array
{
    $out = ['items' => [], 'skipped' => [], 'source' => 'zip', 'error' => null];

    if (!class_exists('ZipArchive')) {
        $out['error'] = '서버 PHP 에 zip 확장이 없어 zip 을 풀 수 없습니다. 폴더 선택으로 올려 주세요.';
        return $out;
    }
    if ($f['size'] > $maxFile) {
        $out['error'] = "{$f['name']}: 너무 큽니다 (최대 " . human_size($maxFile) . ').';
        return $out;
    }

    $zip = new ZipArchive();
    if ($zip->open($f['tmp']) !== true) {
        $out['error'] = "{$f['name']}: zip 을 열 수 없습니다. 파일이 깨졌는지 확인해 주세요.";
        return $out;
    }

    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
        if ($stat === false) {
            continue;
        }
        $name = page_zip_name((string) $stat['name']);
        if (substr($name, -1) === '/') {
            continue;   // 폴더 항목 — 파일을 쓸 때 만들어진다
        }
        $r = page_path_clean($name);
        if (isset($r['skip'])) {
            if ($r['skip'] !== '') {
                $out['skipped'][] = "{$name} ({$r['skip']})";
            }
            continue;
        }
        // 압축 폭탄 방지 — 풀린 크기 기준으로 센다.
        $total += (int) $stat['size'];
        $out['items'][$r['path']] = ['zip' => $f['tmp'], 'index' => $i, 'size' => (int) $stat['size']];
    }
    $zip->close();

    return page_collect_finish($out, $total, $maxTotal, $maxCount);
}

/** 개수 · 크기 상한을 확인하고, 최상위 폴더가 하나뿐이면 그 안쪽을 기준으로 삼는다. */
function page_collect_finish(array $out, int $total, int $maxTotal, int $maxCount): array
{
    if (!$out['items']) {
        $out['error'] = '올릴 수 있는 파일이 없습니다.'
            . ($out['skipped'] ? ' (제외: ' . page_summarize($out['skipped']) . ')' : '');
        return $out;
    }
    if (count($out['items']) > $maxCount) {
        $out['error'] = '파일이 너무 많습니다 (' . count($out['items']) . "개, 최대 {$maxCount}개).";
        return $out;
    }
    if ($total > $maxTotal) {
        $out['error'] = '풀린 크기가 너무 큽니다 (' . human_size($total) . ', 최대 ' . human_size($maxTotal) . ').';
        return $out;
    }

    // "목업/index.html" 처럼 폴더째 묶인 zip · 폴더 선택은 그 폴더를 벗긴다.
    $firsts = [];
    foreach (array_keys($out['items']) as $path) {
        $cut = strpos($path, '/');
        $firsts[$cut === false ? "\0" : substr($path, 0, $cut)] = true;
    }
    if (count($firsts) === 1 && !isset($firsts["\0"])) {
        $prefix = strlen((string) array_key_first($firsts)) + 1;
        $items  = [];
        foreach ($out['items'] as $path => $item) {
            $items[substr($path, $prefix)] = $item;
        }
        $out['items'] = $items;
    }

    if (page_entry_pick(array_keys($out['items'])) === null) {
        $out['error'] = 'HTML 파일이 없습니다. 페이지로 열 .html 파일이 하나는 있어야 합니다.';
    }
    return $out;
}

/** 거부 목록을 배너 한 줄에 들어갈 만큼 줄인다. */
function page_summarize(array $names, int $max = 6): string
{
    $head = array_slice($names, 0, $max);
    $rest = count($names) - count($head);
    return implode(', ', $head) . ($rest > 0 ? " 외 {$rest}개" : '');
}

/**
 * 처음 열 파일. 최상위의 index.html → index.htm → 최상위 첫 html → 가장 얕은 html.
 * @param string[] $paths
 */
function page_entry_pick(array $paths): ?string
{
    $html = array_values(array_filter($paths, static fn(string $p): bool => (bool) preg_match('/\.html?$/i', $p)));
    if (!$html) {
        return null;
    }
    foreach (['index.html', 'index.htm'] as $want) {
        if (in_array($want, $html, true)) {
            return $want;
        }
    }
    usort($html, static function (string $a, string $b): int {
        return [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b];
    });
    return $html[0];
}

/* ── 쓰기 ──────────────────────────────────────────────────── */

/** 작업용 임시 폴더. pages/ 안의 숨김 폴더라 커밋되지 않고 같은 디스크라 rename 이 된다. */
function page_temp_dir(string $tag): ?string
{
    $dir = pages_dir() . '/.work/' . $tag . '-' . bin2hex(random_bytes(6));
    return @mkdir($dir, 0775, true) ? $dir : null;
}

/** 모은 목록을 임시 폴더에 실제 파일로 쓴다. 실패하면 오류 메시지. */
function page_stage(array $items, string $dir): ?string
{
    $zips = [];
    try {
        foreach ($items as $path => $item) {
            $dest = $dir . '/' . $path;
            $sub  = dirname($dest);
            if (!is_dir($sub) && !@mkdir($sub, 0775, true) && !is_dir($sub)) {
                return "{$path}: 폴더를 만들지 못했습니다.";
            }
            if (isset($item['tmp'])) {
                if (!@move_uploaded_file($item['tmp'], $dest)) {
                    return "{$path}: 저장하지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.";
                }
            } else {
                $zip = $zips[$item['zip']] ?? null;
                if ($zip === null) {
                    $zip = new ZipArchive();
                    if ($zip->open($item['zip']) !== true) {
                        return 'zip 을 다시 열지 못했습니다.';
                    }
                    $zips[$item['zip']] = $zip;
                }
                // 헤더에 적힌 크기보다 많이 풀리면 압축 폭탄으로 본다. 한 바이트만 더 읽어 확인한다.
                $limit = $item['size'] + 1;
                if (method_exists($zip, 'getStreamIndex')) {
                    $in = $zip->getStreamIndex($item['index']);
                    $fp = $in !== false ? @fopen($dest, 'wb') : false;
                    $ok = $fp !== false && stream_copy_to_stream($in, $fp, $limit) !== false;
                    if ($fp !== false) {
                        fclose($fp);
                    }
                    if ($in !== false) {
                        fclose($in);
                    }
                } else {
                    // PHP 8.2 미만. 이름으로 여는 getStream 은 CP949 이름을 못 찾아 내용째로 읽는다.
                    $data = $zip->getFromIndex($item['index'], $limit);
                    $ok   = $data !== false && @file_put_contents($dest, $data) !== false;
                }
                clearstatcache(true, $dest);
                if (!$ok || (int) filesize($dest) > $item['size']) {
                    return "{$path}: zip 에서 꺼내지 못했습니다.";
                }
            }
            @chmod($dest, 0664);
        }
    } finally {
        foreach ($zips as $zip) {
            $zip->close();
        }
    }
    return null;
}

/**
 * 임시 폴더를 버전 폴더 자리에 놓는다. 덮어쓸 때는 기존 폴더를 먼저 비켜 두고
 * 바꿔 끼운 뒤 지운다 — 도중에 실패해도 반쯤 섞인 버전이 남지 않는다.
 */
function page_install(string $staged, string $target): ?string
{
    $parent = dirname($target);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
        return '프로젝트 폴더를 만들지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.';
    }
    $old = null;
    if (is_dir($target)) {
        $old = page_temp_dir('old');
        if ($old === null || !@rmdir($old) || !@rename($target, $old)) {
            return '기존 버전을 치우지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.';
        }
    }
    if (!@rename($staged, $target)) {
        if ($old !== null) {
            @rename($old, $target);
        }
        return '버전 폴더를 만들지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.';
    }
    if ($old !== null) {
        page_rmtree($old);
    }
    return null;
}

/** pages/ 안의 폴더만 지운다. 밖을 가리키면 아무것도 하지 않는다. */
function page_rmtree(string $dir): bool
{
    $root = realpath(pages_dir());
    $real = realpath($dir);
    if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        // 심볼릭 링크는 링크만 지운다. 따라가서 대상을 지우지 않는다.
        $ok = ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        if (!$ok) {
            return false;
        }
    }
    return @rmdir($real);
}

/**
 * 작업 폴더 찌꺼기를 치운다. 도중에 죽은 요청이 남긴 것들이다.
 * 지금 다른 탭에서 올리는 중일 수 있으니 한 시간 넘은 것만 지운다.
 */
function page_cleanup_work(): void
{
    $work = pages_dir() . '/.work';
    foreach (is_dir($work) ? (scandir($work) ?: []) : [] as $name) {
        $path = $work . '/' . $name;
        if ($name !== '.' && $name !== '..' && is_dir($path) && (int) filemtime($path) < time() - 3600) {
            page_rmtree($path);
        }
    }
}

/* ── 프로젝트 모델 ─────────────────────────────────────────── */

function page_project_dir(string $code): ?string
{
    $code = page_code_clean($code);
    return $code === null ? null : pages_dir() . '/' . $code;
}

/** 폴더 안의 파일 수와 크기 (숨김 제외). */
function page_dir_stats(string $dir): array
{
    $files = 0;
    $bytes = 0;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename()[0] !== '.') {
                $files++;
                $bytes += (int) $f->getSize();
            }
        }
    }
    return [$files, $bytes];
}

/** 버전 폴더 안의 파일 경로 목록 (처음 열 파일을 다시 고를 때 쓴다). */
function page_dir_paths(string $dir): array
{
    $out = [];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
            }
        }
    }
    return $out;
}

/**
 * 프로젝트 하나를 읽는다. 버전은 실제 하위 폴더 기준이고 project.json 의 값을 덧입힌다.
 * project.json 에 없는 폴더(손으로 넣은 것)도 목록에 나온다.
 */
function page_project_load(string $code): ?array
{
    $dir = page_project_dir($code);
    if ($dir === null || !is_dir($dir)) {
        return null;
    }
    $raw  = @file_get_contents($dir . '/project.json');
    $json = $raw !== false ? json_decode($raw, true) : null;
    $json = is_array($json) ? $json : [];

    $meta = [];
    foreach ((array) ($json['versions'] ?? []) as $v) {
        if (is_array($v) && isset($v['version'])) {
            $meta[(string) $v['version']] = $v;
        }
    }

    $versions = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || !is_dir($dir . '/' . $name)) {
            continue;
        }
        if (page_version_clean($name) !== $name) {
            continue;
        }
        $m = $meta[$name] ?? [];
        $entry = (string) ($m['entry'] ?? '');
        if ($entry === '' || !is_file($dir . '/' . $name . '/' . $entry)) {
            $entry = (string) page_entry_pick(page_dir_paths($dir . '/' . $name));
        }
        if (!isset($m['files'], $m['bytes'])) {
            [$m['files'], $m['bytes']] = page_dir_stats($dir . '/' . $name);
        }
        $versions[$name] = [
            'version'    => $name,
            'uploadedAt' => (string) ($m['uploadedAt'] ?? date('c', (int) filemtime($dir . '/' . $name))),
            'entry'      => $entry,
            'files'      => (int) $m['files'],
            'bytes'      => (int) $m['bytes'],
            'source'     => (string) ($m['source'] ?? ''),
            'note'       => (string) ($m['note'] ?? ''),
        ];
    }
    uksort($versions, 'strnatcasecmp');

    $latest = (string) ($json['latest'] ?? '');
    if (!isset($versions[$latest])) {
        $latest = $versions ? (string) array_key_last($versions) : '';
    }

    return [
        'code'      => $code,
        'title'     => (string) ($json['title'] ?? '') ?: $code,
        'createdAt' => (string) ($json['createdAt'] ?? ''),
        'latest'    => $latest,
        'versions'  => $versions,
    ];
}

/** 모든 프로젝트. 최근에 올린 순. */
function pages_list(): array
{
    $root = pages_dir();
    $out  = [];
    foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $name) {
        if (page_code_clean($name) !== $name || !is_dir($root . '/' . $name)) {
            continue;
        }
        $p = page_project_load($name);
        if ($p !== null) {
            $out[] = $p;
        }
    }
    $last = static fn(array $p): string => $p['versions'] ? max(array_column($p['versions'], 'uploadedAt')) : '';
    usort($out, static fn(array $a, array $b): int => strcmp($last($b), $last($a)));
    return $out;
}

/** project.json 과 최신 버전 스텁을 쓴다. */
function page_project_save(array $p): bool
{
    $dir = page_project_dir($p['code']);
    if ($dir === null || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
        return false;
    }
    $json = json_encode([
        'code'      => $p['code'],
        'title'     => $p['title'],
        'createdAt' => $p['createdAt'] ?: date('c'),
        'latest'    => $p['latest'],
        'versions'  => array_values($p['versions']),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $writes = [$dir . '/project.json' => $json . "\n", $dir . '/index.php' => PAGE_STUB_BODY];
    foreach ($writes as $path => $body) {
        if (is_file($path) && @file_get_contents($path) === $body) {
            continue;
        }
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0664);
    }
    return true;
}

/** 버전 이름을 비워 두면 붙일 다음 번호. v1, v2, … */
function page_next_version(?array $p): string
{
    $n = 0;
    foreach (array_keys($p['versions'] ?? []) as $v) {
        if (preg_match('/^v(\d+)$/i', (string) $v, $m)) {
            $n = max($n, (int) $m[1]);
        }
    }
    return 'v' . ($n + 1);
}
