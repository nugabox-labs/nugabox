<?php
/**
 * 설정 로더 — 외부 의존성 없이 KEY=VALUE 형식을 읽는다.
 *
 * 설정 파일은 `admin/.env.php` 다. 내용은 평범한 .env 문법이지만
 * 앞뒤를 `<?php /*` … `*​/` 로 감싸 둔다. 이렇게 하면 주소창으로 직접 열어도
 * 웹서버가 PHP 로 실행해 빈 화면만 나오므로, nginx 설정을 건드릴 수 없는
 * 시놀로지 DSM 같은 환경에서도 자격증명이 새지 않는다.
 *
 * 탐색 순서 (먼저 찾은 하나만 사용):
 *   1. 환경변수 NUGABOX_ENV_FILE 이 가리키는 경로
 *   2. admin/.env.php
 *
 * 실제 OS 환경변수가 이미 있으면 파일 값보다 우선한다.
 */
declare(strict_types=1);

/** 설정 파일을 한 번만 읽어 캐시한다. */
function env_all(): array
{
    static $vars = null;
    if ($vars !== null) {
        return $vars;
    }

    $candidates = array_filter([
        getenv('NUGABOX_ENV_FILE') ?: null,
        dirname(__DIR__) . '/.env.php',
    ]);

    $vars = [];
    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $vars = env_parse((string) file_get_contents($path));
        $vars['__FILE__'] = $path;
        break;
    }
    return $vars;
}

/** .env 본문을 배열로 파싱한다. */
function env_parse(string $contents): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strncmp($line, 'export ', 7) === 0) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        // 따옴표로 감싼 값은 벗겨내고, 그렇지 않으면 뒤따르는 주석을 잘라낸다.
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
            $quote = $val[0];
            $val = substr($val, 1, -1);
            if ($quote === '"') {
                $val = str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $val);
            }
        } elseif (($hash = strpos($val, ' #')) !== false) {
            $val = rtrim(substr($val, 0, $hash));
        }

        if ($key !== '') {
            $out[$key] = $val;
        }
    }
    return $out;
}

/** 문자열 값. OS 환경변수 → .env → 기본값 순. */
function env_str(string $key, string $default = ''): string
{
    $osValue = getenv($key);
    if ($osValue !== false && $osValue !== '') {
        return $osValue;
    }
    $vars = env_all();
    $value = $vars[$key] ?? '';
    return $value !== '' ? $value : $default;
}

function env_bool(string $key, bool $default): bool
{
    $raw = strtolower(trim(env_str($key, $default ? 'true' : 'false')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function env_int(string $key, int $default): int
{
    $raw = trim(env_str($key, (string) $default));
    return is_numeric($raw) ? (int) $raw : $default;
}

/** 쉼표로 구분된 목록. 빈 값이면 기본값을 그대로 돌려준다. */
function env_list(string $key, array $default): array
{
    $raw = trim(env_str($key, ''));
    if ($raw === '') {
        return $default;
    }
    $items = array_filter(array_map(
        static fn(string $s): string => strtolower(trim($s)),
        explode(',', $raw)
    ), static fn(string $s): bool => $s !== '');

    return $items ? array_values($items) : $default;
}

/** 실제로 읽어들인 설정 파일 경로. 없으면 null. (대시보드 진단용) */
function env_file(): ?string
{
    return env_all()['__FILE__'] ?? null;
}
