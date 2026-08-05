<?php
/**
 * 파일 관리 API — upload 폴더의 목록 조회 / 업로드 / 이름변경 / 삭제.
 * 변경이 생긴 작업은 곧바로 git 커밋 · 푸시까지 수행한다.
 *
 *   GET  ?action=list
 *   POST action=upload  (multipart, file=<파일>)
 *   POST action=rename  (name, new_name)
 *   POST action=delete  (name)
 *   POST action=sync    (밀린 변경분 수동 동기화)
 */
declare(strict_types=1);

require_once __DIR__ . '/../include/bootstrap.php';
require_once __DIR__ . '/../include/git.php';

admin_session_start();
if (!admin_is_logged_in()) {
    json_fail('로그인이 필요합니다.', 401);
}

$action = (string) ($_REQUEST['action'] ?? '');

/* ── 조회 ──────────────────────────────────────────────────── */
if ($action === 'list') {
    $files = list_upload_files();
    json_out([
        'ok'         => true,
        'files'      => $files,
        'count'      => count($files),
        'total_size' => human_size(array_sum(array_column($files, 'size'))),
        'git'        => git_status_summary(),
    ]);
}

/* ── 이하 변경 작업: POST + CSRF 필수 ──────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST 요청만 허용됩니다.', 405);
}
if (!csrf_check($_POST['csrf'] ?? null)) {
    json_fail('보안 토큰이 만료되었습니다. 페이지를 새로고침해 주세요.', 419);
}

$dir = upload_dir();
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_fail('upload 폴더를 만들 수 없습니다: ' . $dir, 500);
}
if (!is_writable($dir)) {
    json_fail('upload 폴더에 쓸 권한이 없습니다. 웹서버 사용자 권한을 확인해 주세요.', 500);
}

switch ($action) {

    /* ── 업로드 ────────────────────────────────────────────── */
    case 'upload': {
        if (!isset($_FILES['file'])) {
            // post_max_size 를 넘기면 $_FILES 와 $_POST 가 통째로 비어버린다.
            json_fail('업로드된 파일이 없습니다. 파일이 서버 허용 크기를 넘지 않았는지 확인해 주세요.');
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => '서버가 허용하는 크기(upload_max_filesize)를 초과했습니다.',
                UPLOAD_ERR_FORM_SIZE  => '허용 크기를 초과했습니다.',
                UPLOAD_ERR_PARTIAL    => '파일이 일부만 전송되었습니다.',
                UPLOAD_ERR_NO_FILE    => '파일이 선택되지 않았습니다.',
                UPLOAD_ERR_NO_TMP_DIR => '서버에 임시 폴더가 없습니다.',
                UPLOAD_ERR_CANT_WRITE => '서버가 파일을 저장하지 못했습니다.',
                UPLOAD_ERR_EXTENSION  => 'PHP 확장에 의해 업로드가 중단되었습니다.',
            ];
            json_fail($messages[$f['error']] ?? '업로드에 실패했습니다.');
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            json_fail('업로드된 파일이 아닙니다.', 400);
        }

        $max = effective_max_upload();
        if ((int) $f['size'] > $max) {
            json_fail('파일이 너무 큽니다. 최대 ' . human_size($max) . ' 까지 올릴 수 있습니다.');
        }

        $name = safe_filename((string) $f['name']);
        if ($name === null) {
            json_fail('사용할 수 없는 파일명입니다. 한글·영문·숫자와 . _ - ( ) [ ] 공백만 쓸 수 있습니다.');
        }
        if ($err = check_extension($name)) {
            json_fail($err);
        }

        // 같은 이름이 있으면 name-1.ext, name-2.ext … 로 비켜 간다.
        $overwrite = !empty($_POST['overwrite']);
        $target    = $dir . '/' . $name;
        if (!$overwrite && file_exists($target)) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            for ($i = 1; $i < 1000 && file_exists($target); $i++) {
                $name   = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
                $target = $dir . '/' . $name;
            }
            if (file_exists($target)) {
                json_fail('같은 이름의 파일이 너무 많습니다. 파일명을 바꿔 주세요.');
            }
        }

        if (!move_uploaded_file($f['tmp_name'], $target)) {
            json_fail('파일을 저장하지 못했습니다.', 500);
        }
        @chmod($target, 0644);

        $git = git_sync_upload();
        json_out([
            'ok'   => true,
            'name' => $name,
            'size' => human_size((int) filesize($target)),
            'url'  => public_url($name),
            'git'  => $git,
        ]);
    }

    /* ── 이름 변경 ─────────────────────────────────────────── */
    case 'rename': {
        $from = upload_path((string) ($_POST['name'] ?? ''));
        if ($from === null || !is_file($from)) {
            json_fail('대상 파일을 찾을 수 없습니다.', 404);
        }

        $newName = safe_filename((string) ($_POST['new_name'] ?? ''));
        if ($newName === null) {
            json_fail('사용할 수 없는 파일명입니다. 한글·영문·숫자와 . _ - ( ) [ ] 공백만 쓸 수 있습니다.');
        }
        if ($err = check_extension($newName)) {
            json_fail($err);
        }

        $to = $dir . '/' . $newName;
        if (realpath($from) === realpath($to)) {
            json_fail('이름이 기존과 같습니다.');
        }
        if (file_exists($to)) {
            json_fail('같은 이름의 파일이 이미 있습니다.');
        }
        if (!rename($from, $to)) {
            json_fail('이름을 바꾸지 못했습니다.', 500);
        }

        $git = git_sync_upload();
        json_out([
            'ok'   => true,
            'name' => $newName,
            'url'  => public_url($newName),
            'git'  => $git,
        ]);
    }

    /* ── 삭제 ──────────────────────────────────────────────── */
    case 'delete': {
        $names = $_POST['names'] ?? ($_POST['name'] ?? null);
        $names = is_array($names) ? $names : [$names];

        $deleted = [];
        foreach ($names as $raw) {
            $path = upload_path((string) $raw);
            if ($path === null || !is_file($path)) {
                continue;
            }
            if (unlink($path)) {
                $deleted[] = basename($path);
            }
        }
        if (!$deleted) {
            json_fail('삭제할 파일을 찾지 못했습니다.', 404);
        }

        $git = git_sync_upload();
        json_out([
            'ok'      => true,
            'deleted' => $deleted,
            'git'     => $git,
        ]);
    }

    /* ── 수동 동기화 ───────────────────────────────────────── */
    case 'sync': {
        $git = git_sync_upload();
        json_out(['ok' => $git['ok'], 'git' => $git]);
    }
}

json_fail('알 수 없는 요청입니다: ' . $action, 400);
