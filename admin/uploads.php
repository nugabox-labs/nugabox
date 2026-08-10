<?php
/**
 * 업로드 파일 관리 — /upload 폴더에 그냥 파일을 올린다.
 * 아이콘과 달리 이미지로 제한하지 않는다. 올린 파일은 /upload/이름 으로 바로 열린다.
 * 어떤 동작이든 파일을 건드린 뒤 곧바로 커밋 · 푸시한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/layout.php';

admin_require_login();
csrf_guard();

$SELF        = '/admin/uploads.php';
$UPLOADS_REL = 'upload';
$ALLOWED     = upload_allowed_ext();
$MAX         = effective_max_upload('upload_max_bytes');

/* ── 내려받기 (커밋 대상 아님) ─────────────────────────────── */

if (($_GET['download'] ?? '') !== '') {
    $name = (string) $_GET['download'];
    $path = upload_path($name);
    if ($path === null || !is_file($path)) {
        http_response_code(404);
        exit('파일을 찾을 수 없습니다.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ── 변경 ──────────────────────────────────────────────────── */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        $files  = $_FILES['files'] ?? null;
        $saved  = [];
        $errors = [];

        if (!is_array($files) || !isset($files['name'])) {
            // post_max_size 를 넘기면 PHP 가 $_POST · $_FILES 를 통째로 비운다.
            flash_redirect($SELF, 'fail', '업로드된 파일이 없습니다. 파일이 서버 제한('
                . human_size($MAX) . ')보다 크지 않은지 확인해 주세요.');
        }

        $dir = uploads_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            flash_redirect($SELF, 'fail', '업로드 폴더를 만들지 못했습니다: ' . $dir);
        }

        $count = is_array($files['name']) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $orig = (string) $files['name'][$i];
            if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "{$orig}: 업로드에 실패했습니다 (오류 코드 {$files['error'][$i]}).";
                continue;
            }
            if ((int) $files['size'][$i] > $MAX) {
                $errors[] = "{$orig}: 너무 큽니다 (최대 " . human_size($MAX) . ').';
                continue;
            }
            $name = safe_filename($orig);
            if ($name === null) {
                $errors[] = "{$orig}: 파일 이름에 영문·숫자·_·-·. 만 쓸 수 있습니다.";
                continue;
            }
            // 화이트리스트에 없으면 거부한다. 실행 · 스크립트 확장자는 그와 별개로
            // 이름 어디에 숨어 있어도(a.php.pdf) 막힌다.
            if (($msg = check_extension($name, $ALLOWED)) !== null) {
                $errors[] = "{$orig}: {$msg}";
                continue;
            }
            $dest = upload_path($name);
            if ($dest === null) {
                $errors[] = "{$orig}: 저장 경로를 만들지 못했습니다.";
                continue;
            }
            if (is_file($dest) && empty($_POST['overwrite'])) {
                $errors[] = "{$name}: 같은 이름이 이미 있습니다. (덮어쓰기를 켜세요)";
                continue;
            }
            if (!@move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $errors[] = "{$name}: 저장하지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.";
                continue;
            }
            @chmod($dest, 0664);
            $saved[] = $name;
        }

        if (!$saved) {
            flash_redirect($SELF, 'fail', $errors ? implode(' / ', $errors) : '올린 파일이 없습니다.');
        }
        $note = $errors ? ' (실패: ' . implode(' / ', $errors) . ')' : '';
        save_and_sync(
            $SELF,
            [$UPLOADS_REL],
            '파일 업로드 : ' . implode(', ', $saved),
            count($saved) . '개를 올렸습니다' . $note
        );
    }

    if ($action === 'rename') {
        $from = (string) ($_POST['name'] ?? '');
        $to   = (string) ($_POST['newname'] ?? '');

        $fromPath = upload_path($from);
        $toName   = safe_filename($to);
        $toPath   = $toName !== null ? upload_path($toName) : null;

        if ($fromPath === null || !is_file($fromPath)) {
            flash_redirect($SELF, 'fail', '원본 파일을 찾을 수 없습니다.');
        }
        if ($toName === null || $toPath === null) {
            flash_redirect($SELF, 'fail', '새 이름에 영문·숫자·_·-·. 만 쓸 수 있습니다.');
        }
        if (($msg = check_extension($toName, $ALLOWED)) !== null) {
            flash_redirect($SELF, 'fail', $msg);
        }
        if (is_file($toPath)) {
            flash_redirect($SELF, 'fail', "이미 있는 이름입니다: {$toName}");
        }
        if (!@rename($fromPath, $toPath)) {
            flash_redirect($SELF, 'fail', '이름을 바꾸지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }

        // 이름이 바뀌면 예전 주소로 걸어 둔 링크는 끊긴다. 알려만 주고 막지는 않는다.
        save_and_sync(
            $SELF,
            [$UPLOADS_REL],
            "파일 이름변경 : {$from} → {$toName}",
            "{$from} → {$toName} 로 바꿨습니다. 예전 주소로 걸어 둔 링크는 더 이상 열리지 않습니다."
        );
    }

    if ($action === 'delete') {
        $name = (string) ($_POST['name'] ?? '');
        $path = upload_path($name);

        if ($path === null || !is_file($path)) {
            flash_redirect($SELF, 'fail', '파일을 찾을 수 없습니다.');
        }
        if (!@unlink($path)) {
            flash_redirect($SELF, 'fail', '파일을 지우지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }
        save_and_sync($SELF, [$UPLOADS_REL], "파일 삭제 : {$name}", "{$name} 을(를) 지웠습니다");
    }

    flash_redirect($SELF, 'fail', '알 수 없는 동작입니다.');
}

/* ── 화면 ──────────────────────────────────────────────────── */

$files  = list_upload_files();
$groups = upload_ext_groups($ALLOWED);
$csrf   = csrf_token();

layout_header('업로드 파일 관리', 'uploads');
?>
<div class="pagehead">
  <h1>업로드 파일 관리</h1>
  <span class="muted"><?= count($files) ?>개 · <?= h($UPLOADS_REL) ?>/</span>
</div>

<?php /* 올릴 수 있는 확장자를 맨 위에 그대로 펼쳐 둔다. 올려 보고 거부당한 뒤에야
         정책을 알게 되는 상황을 만들지 않는다. */ ?>
<section class="extbox">
  <h2>올릴 수 있는 파일</h2>
  <dl class="extgroups">
    <?php foreach ($groups as $label => $exts): ?>
      <dt><?= h($label) ?></dt>
      <dd><?php foreach ($exts as $e): ?><code>.<?= h($e) ?></code><?php endforeach; ?></dd>
    <?php endforeach; ?>
  </dl>
  <p class="muted">
    목록에 없는 확장자는 올릴 수 없습니다. 실행 · 스크립트 · 소스 파일
    (<code>.php</code> <code>.js</code> <code>.html</code> <code>.svg</code> <code>.exe</code>
    <code>.sh</code> <code>.jar</code> <code>.sql</code> 등)은 이름 중간에 숨겨 두어도
    (<code>파일.php.pdf</code>) 거부됩니다.
  </p>
</section>

<form class="uploadbox" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <input class="filepick" type="file" name="files[]" multiple required
         accept="<?= h('.' . implode(',.', $ALLOWED)) ?>">
  <label class="inline"><input type="checkbox" name="overwrite" value="1"> 같은 이름 덮어쓰기</label>
  <button type="submit" class="primary">올리고 푸시</button>
  <p class="muted">
    최대 <?= h(human_size($MAX)) ?>. 파일 이름은 영문·숫자·_·-·. 만 쓸 수 있습니다.
    올린 파일은 <code><?= h(absolute_url($UPLOADS_REL . '/')) ?></code> 아래에서 그대로 열립니다.
  </p>
</form>

<table class="filelist">
  <thead>
    <tr><th>이름</th><th>주소</th><th>크기</th><th>수정</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$files): ?>
    <tr><td colspan="5" class="muted">올린 파일이 없습니다.</td></tr>
  <?php endif; ?>
  <?php foreach ($files as $f): ?>
    <tr>
      <td>
        <code><?= h($f['name']) ?></code>
        <span class="muted"><?= h($f['ext'] !== '' ? '.' . $f['ext'] : '') ?></span>
      </td>
      <td class="urlcell">
        <a href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h($f['url']) ?></a>
      </td>
      <td class="num"><?= h($f['size_human']) ?></td>
      <td class="num muted"><?= h($f['modified']) ?></td>
      <td class="actions">
        <div class="actionbar">
          <button type="button" class="iconbtn js-copy" title="링크 복사" aria-label="링크 복사"
                  data-url="<?= h(absolute_url($f['url'])) ?>"><?= ICON_LINK ?></button>

          <a class="iconbtn" title="내려받기" aria-label="내려받기"
             href="<?= h($SELF) ?>?download=<?= rawurlencode($f['name']) ?>"><?= ICON_DOWNLOAD ?></a>

          <form method="post" class="js-rename" data-name="<?= h($f['name']) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="name" value="<?= h($f['name']) ?>">
            <input type="hidden" name="newname" value="">
            <button class="iconbtn" type="submit" title="이름변경" aria-label="이름변경"><?= ICON_PENCIL ?></button>
          </form>

          <form method="post" class="js-delete" data-name="<?= h($f['name']) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" value="<?= h($f['name']) ?>">
            <button class="iconbtn danger" type="submit" title="삭제" aria-label="삭제"><?= ICON_TRASH ?></button>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<script>
document.addEventListener('submit', function (ev) {
  var f = ev.target;

  if (f.classList.contains('js-rename')) {
    var v = prompt('새 이름', f.dataset.name);
    if (!v) { ev.preventDefault(); return; }
    f.newname.value = v;
    return;
  }

  if (f.classList.contains('js-delete')) {
    if (!confirm(f.dataset.name + ' 을(를) 지웁니다.\n이 주소로 걸어 둔 링크는 더 이상 열리지 않습니다. 계속할까요?')) {
      ev.preventDefault();
    }
  }
});
</script>
<?php layout_footer(); ?>
