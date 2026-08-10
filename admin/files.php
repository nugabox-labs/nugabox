<?php
/**
 * 아이콘 파일 관리 — 업로드 · 이름변경 · 삭제 · 내려받기.
 * 어떤 동작이든 파일을 건드린 뒤 곧바로 커밋 · 푸시한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/layout.php';

admin_require_login();
csrf_guard();

$SELF     = '/admin/files.php';
$ICONS_REL = 'assets/images/icons';

/** icons.json 에서 이 파일을 쓰고 있는 아이콘 아이디들. */
function icon_users(string $file): array
{
    $out = [];
    foreach (icons_load() as $icon) {
        if (($icon['type'] ?? '') === 'app' && $icon['icon'] === $file) {
            $out[] = $icon['id'];
        }
    }
    return $out;
}

/* ── 내려받기 (커밋 대상 아님) ─────────────────────────────── */

if (($_GET['download'] ?? '') !== '') {
    $name = (string) $_GET['download'];
    $path = icon_path($name);
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
                . human_size(effective_max_upload()) . ')보다 크지 않은지 확인해 주세요.');
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
            if ((int) $files['size'][$i] > effective_max_upload()) {
                $errors[] = "{$orig}: 너무 큽니다 (최대 " . human_size(effective_max_upload()) . ').';
                continue;
            }
            $name = safe_filename($orig);
            if ($name === null) {
                $errors[] = "{$orig}: 파일 이름에 영문·숫자·_·-·. 만 쓸 수 있습니다.";
                continue;
            }
            if (($msg = check_extension($name)) !== null) {
                $errors[] = "{$orig}: {$msg}";
                continue;
            }
            // 확장자만 믿지 않는다. 실제로 이미지인지 내용으로 확인한다.
            if (@getimagesize($files['tmp_name'][$i]) === false) {
                $errors[] = "{$orig}: 이미지 파일이 아닙니다.";
                continue;
            }
            $dest = icon_path($name);
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
            [$ICONS_REL],
            '아이콘 파일을 올린다: ' . implode(', ', $saved),
            count($saved) . '개를 올렸습니다' . $note
        );
    }

    if ($action === 'rename') {
        $from = (string) ($_POST['name'] ?? '');
        $to   = (string) ($_POST['newname'] ?? '');

        $fromPath = icon_path($from);
        $toName   = safe_filename($to);
        $toPath   = $toName !== null ? icon_path($toName) : null;

        if ($fromPath === null || !is_file($fromPath)) {
            flash_redirect($SELF, 'fail', '원본 파일을 찾을 수 없습니다.');
        }
        if ($toName === null || $toPath === null) {
            flash_redirect($SELF, 'fail', '새 이름에 영문·숫자·_·-·. 만 쓸 수 있습니다.');
        }
        if (($msg = check_extension($toName)) !== null) {
            flash_redirect($SELF, 'fail', $msg);
        }
        if (is_file($toPath)) {
            flash_redirect($SELF, 'fail', "이미 있는 이름입니다: {$toName}");
        }
        if (!@rename($fromPath, $toPath)) {
            flash_redirect($SELF, 'fail', '이름을 바꾸지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }

        // 이 파일을 쓰던 아이콘의 참조도 같이 옮긴다. 한쪽만 바뀌면 사이트에
        // 깨진 이미지가 그대로 배포된다.
        $icons   = icons_load();
        $touched = false;
        foreach ($icons as $i => $icon) {
            if (($icon['type'] ?? '') === 'app' && $icon['icon'] === $from) {
                $icons[$i]['icon'] = $toName;
                $touched = true;
            }
        }
        if ($touched) {
            icons_save($icons);
        }

        save_and_sync(
            $SELF,
            [$ICONS_REL, 'data'],
            "아이콘 파일 이름을 바꾼다: {$from} → {$toName}",
            "{$from} → {$toName} 로 바꿨습니다" . ($touched ? ' (아이콘 참조도 함께 수정)' : '')
        );
    }

    if ($action === 'delete') {
        $name = (string) ($_POST['name'] ?? '');
        $path = icon_path($name);

        if ($path === null || !is_file($path)) {
            flash_redirect($SELF, 'fail', '파일을 찾을 수 없습니다.');
        }
        $users = icon_users($name);
        if ($users && empty($_POST['force'])) {
            flash_redirect($SELF, 'fail', $name . ' 은(는) ' . implode(', ', $users)
                . ' 에서 쓰는 중이라 지우지 않았습니다.');
        }
        if (!@unlink($path)) {
            flash_redirect($SELF, 'fail', '파일을 지우지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }
        save_and_sync($SELF, [$ICONS_REL], "아이콘 파일을 지운다: {$name}", "{$name} 을(를) 지웠습니다");
    }

    flash_redirect($SELF, 'fail', '알 수 없는 동작입니다.');
}

/* ── 화면 ──────────────────────────────────────────────────── */

$files = list_icon_files();
$used  = [];
foreach (icons_load() as $icon) {
    if (($icon['type'] ?? '') === 'app' && $icon['icon'] !== '') {
        $used[$icon['icon']][] = $icon['id'];
    }
}
$csrf = csrf_token();

layout_header('아이콘 파일', 'files');
?>
<div class="pagehead">
  <h1>아이콘 파일</h1>
  <span class="muted"><?= count($files) ?>개 · <?= h($ICONS_REL) ?>/</span>
</div>

<form class="uploadbox" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <input class="filepick" type="file" name="files[]" multiple accept="image/*" required>
  <label class="inline"><input type="checkbox" name="overwrite" value="1"> 같은 이름 덮어쓰기</label>
  <button type="submit" class="primary">올리고 푸시</button>
  <p class="muted">
    <?= h(implode(', ', (array) cfg('allowed_ext', []))) ?> · 최대
    <?= h(human_size(effective_max_upload())) ?>. 파일 이름은 영문·숫자·_·-·. 만 쓸 수 있습니다.
  </p>
</form>

<table class="filelist">
  <thead>
    <tr><th></th><th>이름</th><th>쓰는 곳</th><th>크기</th><th>수정</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($files as $f): $u = $used[$f['name']] ?? []; ?>
    <tr<?= $u ? '' : ' class="unused"' ?>>
      <td><span class="thumb" style="background-image:url(<?= h($f['url']) ?>)"></span></td>
      <td>
        <code><?= h($f['name']) ?></code>
        <span class="muted"><?= h($f['dimensions']) ?></span>
      </td>
      <td>
        <?php if ($u): ?>
          <?= h(implode(', ', $u)) ?>
        <?php else: ?>
          <span class="muted">쓰지 않음</span>
        <?php endif; ?>
      </td>
      <td class="num"><?= h($f['size_human']) ?></td>
      <td class="num muted"><?= h($f['modified']) ?></td>
      <td class="actions">
        <div class="actionbar">
          <a class="iconbtn" title="내려받기" aria-label="내려받기"
             href="<?= h($SELF) ?>?download=<?= rawurlencode($f['name']) ?>"><?= ICON_DOWNLOAD ?></a>

          <form method="post" class="js-rename" data-name="<?= h($f['name']) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="name" value="<?= h($f['name']) ?>">
            <input type="hidden" name="newname" value="">
            <button class="iconbtn" type="submit" title="이름변경" aria-label="이름변경"><?= ICON_PENCIL ?></button>
          </form>

          <?php /* 쓰는 중인 파일은 확인 대화상자에서 한 번 더 동의해야 force 가 실린다.
                    스크립트가 막혀 있으면 force 가 비어 서버가 삭제를 거부한다. */ ?>
          <form method="post" class="js-delete" data-name="<?= h($f['name']) ?>"
                data-users="<?= h(implode(', ', $u)) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" value="<?= h($f['name']) ?>">
            <input type="hidden" name="force" value="">
            <button class="iconbtn danger" type="submit"
                    title="삭제<?= $u ? ' (쓰는 중)' : '' ?>" aria-label="삭제"><?= ICON_TRASH ?></button>
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
    var users = f.dataset.users;
    var msg = users
      ? f.dataset.name + ' 은(는) ' + users + ' 에서 쓰는 중입니다.\n'
        + '지우면 그 아이콘의 이미지가 깨집니다. 그래도 지울까요?'
      : f.dataset.name + ' 을(를) 지웁니다. 계속할까요?';
    if (!confirm(msg)) { ev.preventDefault(); return; }
    if (users) { f.force.value = '1'; }
  }
});
</script>
<?php layout_footer(); ?>
