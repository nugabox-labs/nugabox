<?php
/**
 * 변경분을 저장소에 커밋하고 origin 으로 push 한다.
 * 서버의 배포 디렉터리가 곧 이 저장소의 작업 트리이기 때문에,
 * 파일 조작 직후 커밋해 두지 않으면 다음 배포의 `git reset --hard` 에 지워진다.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * git 명령 하나를 실행하고 [exit code, stdout, stderr] 를 돌려준다.
 * $extraConfig 는 `-c key=value` 로 앞에 덧붙는다. (자격증명 헬퍼 지정용)
 */
function git_exec(array $args, array $extraConfig = []): array
{
    $g = (array) cfg('git', []);

    $base = [
        $g['bin'] ?? 'git',
        '-C', $g['repo_dir'] ?? ROOT_DIR,
        '-c', 'safe.directory=' . ($g['repo_dir'] ?? ROOT_DIR),
        '-c', 'user.name=' . ($g['author_name'] ?? 'nugabox admin'),
        '-c', 'user.email=' . ($g['author_email'] ?? 'admin@nugabox.com'),
        '-c', 'core.quotepath=false',
    ];
    foreach ($extraConfig as $kv) {
        $base[] = '-c';
        $base[] = $kv;
    }
    $cmd = implode(' ', array_map('escapeshellarg', array_merge($base, $args)));

    $env = [
        'GIT_TERMINAL_PROMPT' => '0',   // 자격증명 없으면 프롬프트 대신 즉시 실패
        'GIT_ASKPASS'         => '',
        'PATH'                => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
    ];
    // LC_ALL 을 고정하면 시놀로지처럼 해당 로케일이 없는 환경에서
    // setlocale 경고가 stderr 에 섞인다. 시스템 설정을 그대로 쓴다.
    if (!empty($g['home'])) {
        $env['HOME'] = $g['home'];
    } elseif (getenv('HOME')) {
        $env['HOME'] = getenv('HOME');
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $g['repo_dir'] ?? ROOT_DIR, $env);
    if (!is_resource($proc)) {
        return [-1, '', 'git 프로세스를 시작하지 못했습니다.'];
    }

    $timeout  = (int) ($g['timeout_sec'] ?? 180);
    $deadline = microtime(true) + $timeout;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = $stderr = '';
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $stderr .= "\n{$timeout}초 안에 끝나지 않아 중단했습니다.";
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return [-1, $stdout, $stderr];
        }
        usleep(50_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [$status['exitcode'] ?? $code, trim($stdout), trim($stderr)];
}

/**
 * push 에 쓸 자격증명을 어떻게 마련할지 판단한다.
 *
 * PHP-FPM 사용자(시놀로지는 보통 http)는 자기 홈에 자격증명이 없어
 * "could not read Username for 'https://github.com'" 으로 실패한다.
 * 토큰을 명령줄에 노출하지 않으려고, 0600 임시 파일에 담아
 * credential.helper=store 로 넘긴다. (경로만 argv 에 남는다)
 *
 * $dryRun 이면 임시 파일을 만들지 않고 판단 결과만 돌려준다. (진단용)
 *
 * @return array{ok:bool, source:string, reason:string, config:string[], file:?string}
 */
function git_credential_resolve(bool $dryRun = false): array
{
    $g = (array) cfg('git', []);
    $fail = static fn(string $source, string $reason): array
        => ['ok' => false, 'source' => $source, 'reason' => $reason, 'config' => [], 'file' => null];

    // 1) 이미 만들어 둔 자격증명 파일
    $shared = (string) ($g['credentials_file'] ?? '');
    if ($shared !== '') {
        if (!is_readable($shared)) {
            return $fail('file', "GIT_CREDENTIALS_FILE 을 읽을 수 없습니다: {$shared}");
        }
        return [
            'ok' => true, 'source' => 'file', 'reason' => '자격증명 파일 사용',
            'config' => ['credential.helper=store --file=' . $shared], 'file' => null,
        ];
    }

    // 2) 토큰
    $token = (string) ($g['token'] ?? '');
    if ($token === '') {
        return $fail('server', 'GIT_TOKEN 이 비어 있습니다. 서버에 이미 설정된 자격증명에 의존합니다.');
    }

    $remote = $g['remote'] ?? 'origin';
    [$code, $url, $err] = git_exec(['remote', 'get-url', $remote]);
    if ($code !== 0 || trim($url) === '') {
        // 오래된 git 은 remote get-url 이 없다.
        [$code, $url] = git_exec(['config', '--get', "remote.{$remote}.url"]);
    }
    $url = trim($url);
    if ($url === '') {
        return $fail('token', "원격 '{$remote}' 의 URL 을 읽지 못했습니다. " . trim($err));
    }

    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])) {
        return $fail('token', "원격이 https 가 아니라 토큰을 쓸 수 없습니다: {$url}");
    }

    $user = (string) ($g['username'] ?? '');
    if ($user === '') {
        $user = (string) ($parts['user'] ?? '');
    }
    if ($user === '') {
        $user = 'x-access-token';   // GitHub 은 사용자명이 무엇이든 토큰만 맞으면 된다
    }

    if ($dryRun) {
        return [
            'ok' => true, 'source' => 'token',
            'reason' => "토큰 사용 ({$user}@{$parts['host']})",
            'config' => [], 'file' => null,
        ];
    }

    $file = tempnam(sys_get_temp_dir(), 'nbgit');
    if ($file === false) {
        return $fail('token', '임시 자격증명 파일을 만들지 못했습니다. ' . sys_get_temp_dir() . ' 쓰기 권한을 확인해 주세요.');
    }
    chmod($file, 0600);

    $line = sprintf(
        '%s://%s:%s@%s%s',
        $parts['scheme'],
        rawurlencode($user),
        rawurlencode($token),
        $parts['host'],
        isset($parts['port']) ? ':' . $parts['port'] : ''
    );
    if (file_put_contents($file, $line . "\n") === false) {
        @unlink($file);
        return $fail('token', '임시 자격증명 파일에 쓰지 못했습니다.');
    }

    return [
        'ok' => true, 'source' => 'token',
        'reason' => "토큰 사용 ({$user}@{$parts['host']})",
        'config' => ['credential.helper=store --file=' . $file], 'file' => $file,
    ];
}

/** @return array{0: string[], 1: ?string} [git -c 인자, 정리해야 할 임시 파일] */
function git_credential_config(): array
{
    $r = git_credential_resolve();
    return [$r['config'], $r['file']];
}

/** 대시보드 진단용 — 토큰을 만들지 않고 상태만 본다. */
function git_credential_status(): array
{
    return git_credential_resolve(true);
}

/** push 거부가 "원격이 앞서 있어서"인지 판별한다. (rebase 로 풀 수 있는 경우) */
function git_push_rejected(string $stderr): bool
{
    foreach (['rejected', 'non-fast-forward', 'fetch first', 'behind its remote'] as $needle) {
        if (stripos($stderr, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * push 실패 원인을 사람이 읽을 수 있는 안내로 바꾼다.
 * $cred 는 git_credential_resolve() 결과 — 자격증명이 아예 없는지,
 * 있는데 거부된 것인지에 따라 안내가 달라야 한다.
 */
function git_push_hint(string $stderr, array $cred = []): string
{
    $s = trim($stderr);
    $isAuth = stripos($s, 'could not read Username') !== false
        || stripos($s, 'could not read Password') !== false
        || stripos($s, 'Authentication failed') !== false
        || stripos($s, 'terminal prompts disabled') !== false;

    if ($isAuth) {
        if (empty($cred['ok'])) {
            return 'GitHub 인증에 실패했습니다. 자격증명이 구성되지 않았습니다 — '
                . ($cred['reason'] ?? 'admin/.env.php 의 GIT_TOKEN 을 확인해 주세요.')
                . ' (원본: ' . $s . ')';
        }
        return 'GitHub 이 자격증명을 거부했습니다 (' . ($cred['reason'] ?? '') . '). '
            . '토큰이 만료되었거나 이 저장소에 대한 Contents: write 권한이 없을 수 있습니다. (원본: ' . $s . ')';
    }
    if (stripos($s, 'Permission denied') !== false || stripos($s, '403') !== false) {
        return '저장소에 쓸 권한이 없습니다. 토큰 권한(Contents: write)을 확인해 주세요. (원본: ' . $s . ')';
    }
    if (stripos($s, 'Could not resolve host') !== false || stripos($s, 'timed out') !== false) {
        return '네트워크에서 GitHub 에 접근하지 못했습니다. (원본: ' . $s . ')';
    }
    return 'git push 실패: ' . $s;
}

/**
 * 지정한 경로들을 스테이징 → 커밋 → push 한다.
 *
 * 배포가 `git reset --hard origin/main` 이므로 push 까지 성공해야 변경이 살아남는다.
 * 커밋만 되고 push 가 실패한 상태를 방치하면 다음 배포에서 통째로 되돌아간다.
 * 그래서 실패는 절대 조용히 넘기지 않고, 밀려 있는 커밋이 있으면
 * 변경이 없어도 push 를 다시 시도한다.
 *
 * @param string[] $paths 저장소 루트 기준 상대 경로
 * @return array{ok:bool, committed:bool, pushed:bool, sha:?string, message:string, log:string}
 */
function git_sync(array $paths, string $message): array
{
    $g   = (array) cfg('git', []);
    $log = [];
    $result = [
        'ok'        => true,
        'committed' => false,
        'pushed'    => false,
        'sha'       => null,
        'message'   => '',
        'log'       => '',
    ];

    if (empty($g['enabled'])) {
        $result['message'] = 'git 동기화가 꺼져 있어 파일만 저장했습니다.';
        return $result;
    }

    $paths = array_values(array_filter(array_map(
        static fn($p): string => ltrim(str_replace('\\', '/', (string) $p), '/'),
        $paths
    )));
    if (!$paths) {
        $result['ok']      = false;
        $result['message'] = '커밋할 경로가 지정되지 않았습니다.';
        return $result;
    }

    $lockFile = runtime_file('git-sync.lock');
    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        $result['ok']      = false;
        $result['message'] = '다른 작업이 진행 중입니다. 잠시 후 다시 시도해 주세요.';
        return $result;
    }

    $credFile = null;
    try {
        $branch = $g['branch'] ?? 'main';
        $remote = $g['remote'] ?? 'origin';

        // 원격에 접근하는 명령에만 자격증명 헬퍼를 붙인다.
        $cred       = git_credential_resolve();
        $credConfig = $cred['config'];
        $credFile   = $cred['file'];

        $run = static function (array $args, array $config = []) use (&$log): array {
            [$code, $out, $err] = git_exec($args, $config);
            // 헬퍼 설정에는 파일 경로만 들어가지만, 로그에는 아예 남기지 않는다.
            $log[] = '$ git ' . implode(' ', $args) . "\n" . trim($out . "\n" . $err);
            return [$code, $out, $err];
        };

        $pathArgs = array_merge(['--'], $paths);

        // 1) 스테이징
        [$code, , $err] = $run(array_merge(['add', '-A'], $pathArgs));
        if ($code !== 0) {
            throw new RuntimeException('git add 실패: ' . $err);
        }

        // 2) 스테이징된 변경이 있을 때만 커밋
        [$code] = $run(array_merge(['diff', '--cached', '--quiet'], $pathArgs));
        $hasChanges = ($code !== 0);

        if ($hasChanges) {
            [$code, , $err] = $run(array_merge(['commit', '-m', $message], $pathArgs));
            if ($code !== 0) {
                throw new RuntimeException('git commit 실패: ' . $err);
            }
            $result['committed'] = true;
        }

        [, $sha] = $run(['rev-parse', '--short', 'HEAD']);
        $result['sha'] = $sha ?: null;

        // 3) 이전에 push 가 실패해 밀려 있는 커밋이 있는지 확인한다.
        //    (원격 추적 브랜치가 없으면 rev-list 가 실패 → push 를 시도한다)
        [$aheadCode, $ahead] = $run(['rev-list', '--count', "{$remote}/{$branch}..HEAD"]);
        $behindRemote = ($aheadCode !== 0) || ((int) $ahead > 0);

        if (!$hasChanges && !$behindRemote) {
            $result['message'] = '변경된 내용이 없어 커밋하지 않았습니다.';
            $result['log']     = implode("\n\n", $log);
            return $result;
        }

        // 4) push — 원격이 앞서 있을 때만 rebase 후 한 번 더 시도한다.
        [$code, , $err] = $run(['push', $remote, "HEAD:{$branch}"], $credConfig);
        if ($code !== 0) {
            if (!git_push_rejected($err)) {
                // 인증 실패 · 네트워크 오류 등은 rebase 로 해결되지 않는다.
                throw new RuntimeException(git_push_hint($err, $cred));
            }
            $run(['fetch', $remote, $branch], $credConfig);
            // --autostash: 배포 디렉터리에 커밋되지 않은 변경이 남아 있어도 rebase 가 멈추지 않게 한다.
            [$rebaseCode] = $run(['rebase', '--autostash', "{$remote}/{$branch}"]);
            if ($rebaseCode !== 0) {
                $run(['rebase', '--abort']);
                throw new RuntimeException('원격과 충돌해 push 하지 못했습니다: ' . $err);
            }
            [$code, , $err] = $run(['push', $remote, "HEAD:{$branch}"], $credConfig);
            if ($code !== 0) {
                throw new RuntimeException(git_push_hint($err, $cred));
            }
        }

        $result['pushed']  = true;
        $result['message'] = $result['committed']
            ? '저장 · 커밋 · 푸시 완료'
            : '밀려 있던 커밋을 푸시했습니다.';
    } catch (Throwable $e) {
        $result['ok']      = false;
        $result['message'] = $e->getMessage();
    } finally {
        if (!empty($credFile)) {
            @unlink($credFile);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $result['log'] = implode("\n\n", $log);
    return $result;
}

/** 밀려 있는(로컬에만 있는) 커밋을 다시 push 한다. — 대시보드의 "지금 푸시" */
function git_push_pending(): array
{
    $g = (array) cfg('git', []);
    if (empty($g['enabled'])) {
        return ['ok' => false, 'pushed' => false, 'message' => 'git 동기화가 꺼져 있습니다.', 'log' => ''];
    }
    // 커밋할 게 없는 경로를 주면 add·commit 은 건너뛰고 push 만 시도한다.
    $r = git_sync(['data'], '데이터 동기화');
    return $r;
}

/** 대시보드용 저장소 상태 요약. */
function git_status_summary(): array
{
    $g = (array) cfg('git', []);
    if (empty($g['enabled'])) {
        return [
            'enabled' => false, 'branch' => '-', 'last_commit' => 'git 동기화 꺼짐',
            'clean' => null, 'ahead' => null, 'dirty' => '',
        ];
    }
    $remote       = $g['remote'] ?? 'origin';
    $remoteBranch = $g['branch'] ?? 'main';

    [$bc, $branch] = git_exec(['rev-parse', '--abbrev-ref', 'HEAD']);
    [$lc, $last]   = git_exec(['log', '-1', '--pretty=%h %s (%cr)']);
    [$sc, $status] = git_exec(['status', '--porcelain']);

    // push 가 실패해 로컬에만 남은 커밋이 있는지 확인한다. 이 상태로 두면
    // 다음 배포의 git reset --hard 가 변경분을 통째로 지워버린다.
    [$ac, $ahead] = git_exec(['rev-list', '--count', "{$remote}/{$remoteBranch}..HEAD"]);

    return [
        'enabled'     => true,
        'branch'      => $bc === 0 ? $branch : '-',
        'last_commit' => $lc === 0 && $last !== '' ? $last : '커밋 정보를 읽지 못했습니다.',
        'clean'       => $sc === 0 ? ($status === '') : null,
        'dirty'       => $sc === 0 ? $status : '',
        'ahead'       => $ac === 0 && is_numeric($ahead) ? (int) $ahead : null,
    ];
}
