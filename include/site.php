<?php
/**
 * 사이트 공용 코어 — data/*.json 을 읽고 화면에 그린다.
 *
 * 이 파일은 index.php 와 관리자 양쪽이 쓴다. 세션 · 인증 같은 관리자 전용
 * 개념에 의존하지 않아야 하므로 admin/ 의 어떤 파일도 require 하지 않는다.
 *
 * 이 사이트의 데이터베이스는 git 이다. 배포가 `git reset --hard origin/main`
 * 이라 저장소 밖(SQLite 등)에 상태를 두면 배포마다 어긋나고 백업도 따로 만들어야
 * 한다. JSON 으로 두면 변경 내역이 diff 로 보이고 되돌리기는 git revert 로 끝난다.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__));
}

const SITE_DATA_DIR  = SITE_ROOT . '/data';
const SITE_ICONS_DIR = SITE_ROOT . '/assets/images/icons';
const SITE_ICONS_URL = '/assets/images/icons';

/** 아이콘 그리드의 열 수. 행은 얼마든지 늘어나지만 열은 항상 4 다. */
const ICON_COLUMNS = 4;

/* ── JSON 읽기 ─────────────────────────────────────────────── */

function site_data_path(string $name): string
{
    return SITE_DATA_DIR . '/' . $name;
}

/** JSON 파일을 읽는다. 없거나 깨졌으면 $default. */
function site_data_read(string $name, array $default = []): array
{
    $path = site_data_path($name);
    if (!is_file($path) || !is_readable($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

/* ── 사이트 메타 ───────────────────────────────────────────── */

/**
 * 첫 화면에 찍히는 값들. 지금은 업데이트 표시 날짜 하나뿐이다.
 * (예전에는 이 날짜를 바꾸려고 index.html 을 직접 커밋해야 했다)
 */
function site_meta(): array
{
    $d = site_data_read('site.json', []);
    $updated = (string) ($d['updated'] ?? '');
    if (!preg_match('/^\d{4}\. \d{1,2}\. \d{1,2}\.$/', $updated)) {
        $updated = date('Y. n. j.');
    }
    return ['updated' => $updated];
}

/* ── 아이콘 ────────────────────────────────────────────────── */

/** 아이콘 목록. row · order 순으로 정렬해서 돌려준다. */
function icons_load(): array
{
    $d     = site_data_read('icons.json', ['icons' => []]);
    $icons = is_array($d['icons'] ?? null) ? $d['icons'] : [];

    foreach ($icons as $i => $icon) {
        $icons[$i] = icon_normalize(is_array($icon) ? $icon : []);
    }
    // row 0 은 "배치되지 않음"(숨김) 이므로 맨 뒤로 보낸다.
    usort($icons, static function (array $a, array $b): int {
        $ra = $a['row'] === 0 ? PHP_INT_MAX : $a['row'];
        $rb = $b['row'] === 0 ? PHP_INT_MAX : $b['row'];
        return [$ra, $a['order']] <=> [$rb, $b['order']];
    });

    return $icons;
}

/** 빠진 필드를 채우고 타입을 맞춘다. 읽기 · 쓰기 양쪽에서 쓴다. */
function icon_normalize(array $icon): array
{
    $type = ($icon['type'] ?? 'app') === 'blank' ? 'blank' : 'app';
    $base = [
        // row 0 은 "배치되지 않음"(숨긴 아이콘) 을 뜻한다.
        'row'   => max(0, (int) ($icon['row'] ?? 1)),
        'order' => max(1, (int) ($icon['order'] ?? 1)),
        'type'  => $type,
    ];
    if ($type === 'blank') {
        return $base;
    }
    $status = (string) ($icon['status'] ?? '');
    if (!in_array($status, ['', 'updated', 'testing'], true)) {
        $status = '';
    }
    // 배경색은 #rgb / #rrggbb 만 받는다. 인라인 style 로 나가므로 CSS 주입을 막아야 한다.
    $bg = trim((string) ($icon['bg'] ?? ''));
    if ($bg !== '' && !preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $bg)) {
        $bg = '';
    }
    $file = (string) ($icon['icon'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $file)) {
        $file = '';
    }

    return $base + [
        'id'      => (string) ($icon['id'] ?? ''),
        'label'   => (string) ($icon['label'] ?? ''),
        'url'     => (string) ($icon['url'] ?? ''),
        'icon'    => $file,
        'bg'      => $bg !== '' ? $bg : null,
        'status'  => $status,
        'tooltip' => (string) ($icon['tooltip'] ?? ''),
        'newtab'  => (bool) ($icon['newtab'] ?? true),
        'hidden'  => (bool) ($icon['hidden'] ?? false),
    ];
}

/** 아이콘 id 로 쓸 수 있는지. HTML id · CSS 선택자 양쪽에서 안전한 형태만. */
function icon_id_valid(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,40}$/', $id);
}

/** http(s) · 내부 경로 · mailto 만 허용한다. javascript: 같은 것을 막는다. */
function url_valid(string $url): bool
{
    if ($url === '') {
        return false;
    }
    if ($url[0] === '/' && strncmp($url, '//', 2) !== 0) {
        return true;
    }
    if (preg_match('#^mailto:[^\s<>"]+@[^\s<>"]+$#i', $url)) {
        return true;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST);
}

/**
 * 배열에 놓인 순서대로 4개씩 끊어 행을 만들고, 마지막 행에서 남는 자리를
 * 빈칸으로 채운다. 들어온 row · order 값은 무시하고 배열 순서만 본다.
 *
 * 숨긴 아이콘은 그리드 자리를 차지하지 않는다. row 0 (배치되지 않음) 으로 밀어
 * 두기 때문에, 아이콘을 숨겨도 뒤쪽 아이콘들이 앞으로 당겨져 4열이 유지된다.
 * 기존 빈칸은 버리고 매번 새로 만든다.
 */
function icons_reflow(array $icons): array
{
    $visible = $hidden = [];
    foreach ($icons as $icon) {
        if ($icon['type'] === 'blank') {
            continue;
        }
        if (!empty($icon['hidden'])) {
            $hidden[] = $icon;
        } else {
            $visible[] = $icon;
        }
    }

    $out = [];
    foreach ($visible as $i => $icon) {
        $icon['row']   = intdiv($i, ICON_COLUMNS) + 1;
        $icon['order'] = ($i % ICON_COLUMNS) + 1;
        $out[] = $icon;
    }
    // 마지막 행의 남는 자리를 빈칸으로 자동 생성
    $rows = (int) ceil(count($visible) / ICON_COLUMNS);
    for ($i = count($visible); $i < $rows * ICON_COLUMNS; $i++) {
        $out[] = [
            'row'   => intdiv($i, ICON_COLUMNS) + 1,
            'order' => ($i % ICON_COLUMNS) + 1,
            'type'  => 'blank',
        ];
    }
    foreach ($hidden as $i => $icon) {
        $icon['row']   = 0;   // 배치되지 않음
        $icon['order'] = $i + 1;
        $out[] = $icon;
    }
    return $out;
}

/* ── 렌더링 ────────────────────────────────────────────────── */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 아이콘 하나를 <a> 로 그린다.
 *
 * 배경 이미지 · 배경색을 인라인 style 로 내보낸다. 예전에는 style.css 에
 * `#id { background-image: ... }` 규칙을 아이콘마다 손으로 넣었는데, 그러면
 * 관리자가 CSS 파일까지 고쳐야 한다. 인라인으로 두면 icons.json 하나만 고치면 된다.
 * (script.js 의 preloadAppIcons 는 getComputedStyle 을 쓰므로 그대로 동작한다)
 */
function render_icon(array $icon): string
{
    if ($icon['type'] === 'blank') {
        return '<a class="icon icon-none"></a>';
    }

    $style = [];
    if ($icon['icon'] !== '') {
        $style[] = 'background-image:url(' . SITE_ICONS_URL . '/' . rawurlencode($icon['icon']) . ')';
    }
    if (!empty($icon['bg'])) {
        $style[] = 'background-color:' . $icon['bg'];
    }

    $attrs = [
        'class' => 'icon hover-ani',
        'id'    => $icon['id'],
        'href'  => $icon['url'],
        'style' => implode(';', $style),
    ];
    if ($icon['newtab']) {
        $attrs['target'] = '_blank';
        // 외부 링크는 opener 를 넘기지 않는다.
        $attrs['rel'] = 'noopener';
    }
    if ($icon['tooltip'] !== '') {
        $attrs['data-tooltip'] = $icon['tooltip'];
    }

    $html = '<a';
    foreach ($attrs as $k => $v) {
        if ($v !== '') {
            $html .= ' ' . $k . '="' . e((string) $v) . '"';
        }
    }
    $html .= '><span>';
    if ($icon['status'] !== '') {
        $html .= '<i class="status-dot status-' . e($icon['status']) . '"></i>';
    }
    return $html . e($icon['label']) . '</span></a>';
}

/** .app-line 안쪽 전체 (행 단위 .app-icons 묶음). */
function render_icon_rows(array $icons, string $indent = '      '): string
{
    $rows = [];
    foreach ($icons as $icon) {
        if ($icon['type'] === 'app' && !empty($icon['hidden'])) {
            continue;   // 숨긴 아이콘은 그리지 않되 데이터에는 남겨 둔다
        }
        $rows[(int) $icon['row']][] = $icon;
    }
    ksort($rows);

    $out = [];
    foreach ($rows as $row) {
        // 숨긴 아이콘 때문에 생긴 빈자리도 빈칸으로 메워 4열을 유지한다.
        $cells = array_map('render_icon', $row);
        while (count($cells) < ICON_COLUMNS) {
            $cells[] = '<a class="icon icon-none"></a>';
        }
        $out[] = $indent . '<div class="app-icons">' . "\n"
            . $indent . '  ' . implode("\n" . $indent . '  ', $cells) . "\n"
            . $indent . '</div>';
    }
    return implode("\n", $out);
}

/* ── 리다이렉트 ────────────────────────────────────────────── */

/**
 * nginx 설정을 건드릴 수 없으므로 /blog 같은 주소는 실제 폴더가 있어야 열린다.
 * 폴더 안에는 한 줄짜리 스텁만 두고, 주소 목록은 이 JSON 한 곳에서만 관리한다.
 *
 * @return array<string, array{slug:string, url:string, note:string}>
 */
function redirects_load(): array
{
    $d    = site_data_read('redirects.json', ['redirects' => []]);
    $list = is_array($d['redirects'] ?? null) ? $d['redirects'] : [];

    $out = [];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $slug = redirect_slug_clean((string) ($r['slug'] ?? ''));
        $url  = (string) ($r['url'] ?? '');
        if ($slug === null || !url_valid($url)) {
            continue;
        }
        $out[$slug] = ['slug' => $slug, 'url' => $url, 'note' => (string) ($r['note'] ?? '')];
    }
    ksort($out);
    return $out;
}

/** 폴더 이름으로 쓸 수 있는 slug 인지. 통과하면 정규화된 값, 아니면 null. */
function redirect_slug_clean(string $slug): ?string
{
    $slug = strtolower(trim($slug, " \t\n\r/"));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $slug)) {
        return null;
    }
    // 사이트의 실제 폴더와 겹치면 리다이렉트가 그 폴더를 가려 버린다.
    $reserved = ['admin', 'assets', 'data', 'include', 'upload', 'api', 'index'];
    return in_array($slug, $reserved, true) ? null : $slug;
}
