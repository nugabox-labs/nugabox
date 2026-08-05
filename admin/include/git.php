<?php
/**
 * upload 폴더 변경분을 저장소에 커밋하고 origin 으로 push 한다.
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
 * push 에 쓸 자격증명 헬퍼 설정을 준비한다.
 *
 * PHP-FPM 사용자(시놀로지는 보통 http)는 자기 홈에 자격증명이 없어
 * "could not read Username for 'https://github.com'" 으로 실패한다.
 * 토큰을 명령줄에 노출하지 않으려고, 0600 임시 파일에 담아
 * credential.helper=store 로 넘긴다. (경로만 argv 에 남는다)
 *
 * @return array{0: string[], 1: ?string} [git -c 인자, 정리해야 할 임시 파일]
 */
function git_credential_config(): array
{
    $g = (array) cfg('git', []);

    // 이미 만들어 둔 자격증명 파일을 쓰는 경우
    $shared = (string) ($g['credentials_file'] ?? '');
    if ($shared !== '' && is_readable($shared)) {
        return [['credential.helper=store --file=' . $shared], null];
    }

    $token = (string) ($g['token'] ?? '');
    if ($token === '') {
        return [[], null];   // 서버에 이미 설정된 헬퍼에 맡긴다
    }

    // 원격 URL 에서 호스트와 사용자명을 얻는다.
    [$code, $url] = git_exec(['remote', 'get-url', $g['remote'] ?? 'origin']);
    if ($code !== 0 || $url === '') {
        return [[], null];
    }
    $parts = parse_url(trim($url));
    if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])) {
        return [[], null];   // ssh 원격이면 토큰을 쓸 일이 없다
    }
    $origin = $parts['scheme'] . '://%s:%s@' . $parts['host']
        . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $user = (string) ($g['username'] ?? '');
    if ($user === '') {
        $user = (string) ($parts['user'] ?? '');
    }
    if ($user === '') {
        $user = 'x-access-token';   // GitHub 은 사용자명이 무엇이든 토큰만 맞으면 된다
    }

    $file = tempnam(sys_get_temp_dir(), 'nbgit');
    if ($file === false) {
        return [[], null];
    }
    chmod($file, 0600);
    file_put_contents($file, sprintf($origin, rawurlencode($user), rawurlencode($token)) . "\n");

    return [['credential.helper=store --file=' . $file], $file];
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

/** push 실패 원인을 사람이 읽을 수 있는 안내로 바꾼다. */
function git_push_hint(string $stderr): string
{
    $s = trim($stderr);
    if (stripos($s, 'could not read Username') !== false
        || stripos($s, 'Authentication failed') !== false
        || stripos($s, 'terminal prompts disabled') !== false) {
        return 'GitHub 인증에 실패했습니다. 웹서버 사용자에게 자격증명이 없습니다. '
            . 'admin/.env.php 의 GIT_TOKEN 에 GitHub 개인 액세스 토큰을 넣어 주세요. (원본: ' . $s . ')';
    }
    if (stripos($s, 'Permission denied') !== false || stripos($s, '403') !== false) {
        return '저장소에 쓸 권한이 없습니다. 토큰 권한(contents: write)을 확인해 주세요. (원본: ' . $s . ')';
    }
    if (stripos($s, 'Could not resolve host') !== false || stripos($s, 'timed out') !== false) {
        return '네트워크에서 GitHub 에 접근하지 못했습니다. (원본: ' . $s . ')';
    }
    return 'git push 실패: ' . $s;
}

/**
 * upload 폴더를 스테이징 → 커밋 → push.
 *
 * @return array{ok:bool, committed:bool, pushed:bool, sha:?string, message:string, log:string}
 */
function git_sync_upload(?string $message = null): array
{
    $g = (array) cfg('git', []);
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

    $lockFile = runtime_file('git-sync.lock');
    $lock = fopen($lockFile, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        $result['ok']      = false;
        $result['message'] = '다른 작업이 진행 중입니다. 잠시 후 다시 시도해 주세요.';
        return $result;
    }

    try {
        $repoDir   = $g['repo_dir'] ?? ROOT_DIR;
        $branch    = $g['branch'] ?? 'main';
        $remote    = $g['remote'] ?? 'origin';
        $uploadRel = ltrim(str_replace($repoDir, '', upload_dir()), '/');
        $commitMsg = $message ?? ($g['commit_message'] ?? 'upload 파일 업로드');

        // 원격에 접근하는 명령에만 자격증명 헬퍼를 붙인다.
        [$credConfig, $credFile] = git_credential_config();

        $run = static function (array $args, string $label, array $config = []) use (&$log): array {
            [$code, $out, $err] = git_exec($args, $config);
            // 헬퍼 설정에는 파일 경로만 들어가지만, 로그에는 아예 남기지 않는다.
            $log[] = "$ git " . implode(' ', $args) . "\n" . trim($out . "\n" . $err);
            return [$code, $out, $err, $label];
        };

        // 1) 스테이징
        [$code, , $err] = $run(['add', '-A', '--', $uploadRel], 'add');
        if ($code !== 0) {
            throw new RuntimeException('git add 실패: ' . $err);
        }

        // 2) 스테이징된 변경이 있을 때만 커밋
        [$code] = $run(['diff', '--cached', '--quiet', '--', $uploadRel], 'diff');
        $hasChanges = ($code !== 0);

        if ($hasChanges) {
            [$code, , $err] = $run(['commit', '-m', $commitMsg, '--', $uploadRel], 'commit');
            if ($code !== 0) {
                throw new RuntimeException('git commit 실패: ' . $err);
            }
            $result['committed'] = true;
        }

        [, $sha] = $run(['rev-parse', '--short', 'HEAD'], 'rev-parse');
        $result['sha'] = $sha ?: null;

        // 3) 이전에 push 가 실패해 밀려 있는 커밋이 있는지 확인한다.
        //    (원격 추적 브랜치가 없으면 rev-list 가 실패 → push 를 시도한다)
        [$aheadCode, $ahead] = $run(['rev-list', '--count', "{$remote}/{$branch}..HEAD"], 'ahead');
        $behindRemote = ($aheadCode !== 0) || ((int) $ahead > 0);

        if (!$hasChanges && !$behindRemote) {
            $result['message'] = '변경된 내용이 없어 커밋하지 않았습니다.';
            $result['log']     = implode("\n\n", $log);
            return $result;
        }

        // 4) push — 원격이 앞서 있을 때만 rebase 후 한 번 더 시도한다.
        [$code, , $err] = $run(['push', $remote, "HEAD:{$branch}"], 'push', $credConfig);
        if ($code !== 0) {
            if (!git_push_rejected($err)) {
                // 인증 실패 · 네트워크 오류 등은 rebase 로 해결되지 않는다.
                throw new RuntimeException(git_push_hint($err), 0, null);
            }
            $run(['fetch', $remote, $branch], 'fetch', $credConfig);
            // --autostash: 배포 디렉터리에 커밋되지 않은 변경이 남아 있어도 rebase 가 멈추지 않게 한다.
            [$rebaseCode] = $run(['rebase', '--autostash', "{$remote}/{$branch}"], 'rebase');
            if ($rebaseCode !== 0) {
                $run(['rebase', '--abort'], 'rebase-abort');
                throw new RuntimeException('원격과 충돌해 push 하지 못했습니다: ' . $err);
            }
            [$code, , $err] = $run(['push', $remote, "HEAD:{$branch}"], 'push-retry', $credConfig);
            if ($code !== 0) {
                throw new RuntimeException(git_push_hint($err));
            }
        }

        $result['pushed']  = true;
        $result['message'] = $result['committed']
            ? '커밋 · 푸시 완료'
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

/** 대시보드용 저장소 상태 요약. */
function git_status_summary(): array
{
    $g = (array) cfg('git', []);
    if (empty($g['enabled'])) {
        return [
            'enabled' => false, 'branch' => '-', 'last_commit' => 'git 동기화 꺼짐',
            'clean' => null, 'ahead' => null,
        ];
    }
    $remote       = $g['remote'] ?? 'origin';
    $remoteBranch = $g['branch'] ?? 'main';

    [$bc, $branch] = git_exec(['rev-parse', '--abbrev-ref', 'HEAD']);
    [$lc, $last]   = git_exec(['log', '-1', '--pretty=%h %s (%cr)']);
    [$sc, $status] = git_exec(['status', '--porcelain', '--', 'upload']);

    // push 가 실패해 로컬에만 남은 커밋이 있는지 확인한다. 이 상태로 두면
    // 다음 배포의 git reset --hard 가 업로드 파일까지 지워버린다.
    [$ac, $ahead] = git_exec(['rev-list', '--count', "{$remote}/{$remoteBranch}..HEAD"]);

    return [
        'enabled'     => true,
        'branch'      => $bc === 0 ? $branch : '-',
        'last_commit' => $lc === 0 && $last !== '' ? $last : '커밋 정보를 읽지 못했습니다.',
        'clean'       => $sc === 0 ? ($status === '') : null,
        'ahead'       => $ac === 0 && is_numeric($ahead) ? (int) $ahead : null,
    ];
}
