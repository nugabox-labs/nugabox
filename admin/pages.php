<?php
/**
 * 페이지 관리 — 목업 · HTML 을 프로젝트 단위로 올리고 버전을 관리한다.
 * 올린 페이지는 /pages/<코드>/ (최신 버전) 와 /pages/<코드>/<버전>/ (고정) 로 열린다.
 * 어떤 동작이든 파일을 건드린 뒤 곧바로 커밋 · 푸시한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/layout.php';
require_once __DIR__ . '/include/pages.php';

admin_require_login();
csrf_guard();

$SELF      = '/admin/pages.php';
$MAX_FILE  = effective_max_upload('pages_max_bytes');
$MAX_TOTAL = (int) cfg('pages_max_total_bytes', 100 * 1024 * 1024);
$MAX_COUNT = (int) cfg('pages_max_files', 2000);
$MAX_POST  = (int) ini_get('max_file_uploads') ?: 20;

/* ── 변경 ──────────────────────────────────────────────────── */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        if (!isset($_FILES['files'])) {
            // post_max_size 를 넘기면 PHP 가 $_POST · $_FILES 를 통째로 비운다. CSRF 도 비어서
            // 여기까지 오지 못하지만, 혹시 모를 경우를 위해 남겨 둔다.
            flash_redirect($SELF, 'fail', '업로드된 파일이 없습니다. 파일이 서버 제한('
                . human_size($MAX_FILE) . ')보다 크지 않은지 확인해 주세요.');
        }

        $picked = (string) ($_POST['project'] ?? '');
        $code   = page_code_clean($picked !== '' ? $picked : (string) ($_POST['code'] ?? ''));
        if ($code === null) {
            flash_redirect($SELF, 'fail', '프로젝트 코드는 영문 소문자 · 숫자 · _ · - 로 40자까지 쓸 수 있습니다.');
        }
        $project = page_project_load($code);
        if ($project === null && $picked !== '') {
            flash_redirect($SELF, 'fail', "프로젝트를 찾을 수 없습니다: {$code}");
        }
        if ($project !== null && $picked === '') {
            // 새 프로젝트로 만들려던 코드가 이미 있으면 몰래 버전을 얹지 않는다.
            flash_redirect($SELF, 'fail', "이미 있는 프로젝트 코드입니다: {$code}. 목록에서 골라 새 버전으로 올려 주세요.");
        }

        $rawVersion = trim((string) ($_POST['version'] ?? ''));
        $version    = $rawVersion === '' ? page_next_version($project) : page_version_clean($rawVersion);
        if ($version === null) {
            flash_redirect($SELF, 'fail', '버전 이름은 글자 · 숫자로 시작하고 글자 · 숫자 · . · _ · - 만 쓸 수 있습니다.');
        }
        $exists = isset($project['versions'][$version]);
        if ($exists && empty($_POST['overwrite'])) {
            flash_redirect($SELF, 'fail', "{$code}/{$version} 은(는) 이미 있습니다. 버전 이름을 바꾸거나 덮어쓰기를 켜 주세요.");
        }

        $got = page_collect($_FILES['files'], (array) ($_POST['paths'] ?? []), $MAX_FILE, $MAX_TOTAL, $MAX_COUNT);
        if ($got['error'] !== null) {
            flash_redirect($SELF, 'fail', $got['error']);
        }

        page_cleanup_work();
        $staged = page_temp_dir('new');
        if ($staged === null) {
            flash_redirect($SELF, 'fail', '작업 폴더를 만들지 못했습니다. ' . pages_rel() . '/ 쓰기 권한을 확인해 주세요.');
        }
        $err = page_stage($got['items'], $staged)
            ?? page_install($staged, (string) page_project_dir($code) . '/' . $version);
        if ($err !== null) {
            page_rmtree($staged);
            flash_redirect($SELF, 'fail', $err);
        }

        $project = $project ?? [
            'code' => $code, 'title' => '', 'createdAt' => '', 'latest' => '', 'versions' => [],
        ];
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($project['title'] === '' || $project['title'] === $code) {
            $project['title'] = $title !== '' ? $title : $code;
        }
        $project['versions'][$version] = [
            'version'    => $version,
            'uploadedAt' => date('c'),
            'entry'      => (string) page_entry_pick(array_keys($got['items'])),
            'files'      => count($got['items']),
            'bytes'      => (int) array_sum(array_column($got['items'], 'size')),
            'source'     => $got['source'],
            'note'       => trim((string) ($_POST['note'] ?? '')),
        ];
        uksort($project['versions'], 'strnatcasecmp');
        if (!empty($_POST['make_latest']) || $project['latest'] === '') {
            $project['latest'] = $version;
        }
        if (!page_project_save($project)) {
            flash_redirect($SELF, 'fail', 'project.json 을 쓰지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }

        $note = $got['skipped'] ? ' (제외한 파일: ' . page_summarize($got['skipped']) . ')' : '';
        save_and_sync(
            $SELF . '#p-' . $code,
            [pages_rel()],
            "페이지 업로드 : {$code}/{$version}" . ($exists ? ' (덮어쓰기)' : ''),
            "{$code}/{$version} 에 " . count($got['items']) . '개 파일을 올렸습니다'
                . ($project['latest'] === $version ? ' · 최신 버전' : '') . $note
        );
    }

    $code    = page_code_clean((string) ($_POST['code'] ?? '')) ?? '';
    $project = $code !== '' ? page_project_load($code) : null;
    if ($project === null) {
        flash_redirect($SELF, 'fail', '프로젝트를 찾을 수 없습니다.');
    }
    $back    = $SELF . '#p-' . $code;
    $version = (string) ($_POST['version'] ?? '');

    if ($action === 'latest') {
        if (!isset($project['versions'][$version])) {
            flash_redirect($back, 'fail', '버전을 찾을 수 없습니다.');
        }
        $project['latest'] = $version;
        if (!page_project_save($project)) {
            flash_redirect($back, 'fail', 'project.json 을 쓰지 못했습니다.');
        }
        save_and_sync($back, [pages_rel()], "페이지 최신 버전 : {$code} → {$version}",
            "{$code} 의 최신 버전을 {$version} 로 바꿨습니다");
    }

    if ($action === 'title') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 80) {
            flash_redirect($back, 'fail', '제목은 1~80자로 적어 주세요.');
        }
        $project['title'] = $title;
        if (!page_project_save($project)) {
            flash_redirect($back, 'fail', 'project.json 을 쓰지 못했습니다.');
        }
        save_and_sync($back, [pages_rel()], "페이지 제목변경 : {$code} → {$title}", "제목을 {$title} 로 바꿨습니다");
    }

    if ($action === 'note') {
        if (!isset($project['versions'][$version])) {
            flash_redirect($back, 'fail', '버전을 찾을 수 없습니다.');
        }
        $project['versions'][$version]['note'] = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200);
        if (!page_project_save($project)) {
            flash_redirect($back, 'fail', 'project.json 을 쓰지 못했습니다.');
        }
        save_and_sync($back, [pages_rel()], "페이지 메모 : {$code}/{$version}", "{$code}/{$version} 메모를 고쳤습니다");
    }

    if ($action === 'delete_version') {
        if (!isset($project['versions'][$version])) {
            flash_redirect($back, 'fail', '버전을 찾을 수 없습니다.');
        }
        if (count($project['versions']) === 1) {
            flash_redirect($back, 'fail', '마지막 남은 버전은 지울 수 없습니다. 프로젝트째 지워 주세요.');
        }
        if (!page_rmtree((string) page_project_dir($code) . '/' . $version)) {
            flash_redirect($back, 'fail', '버전 폴더를 지우지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }
        unset($project['versions'][$version]);
        $moved = '';
        if ($project['latest'] === $version) {
            $project['latest'] = (string) array_key_last($project['versions']);
            $moved = " 최신 버전은 {$project['latest']} 로 옮겼습니다.";
        }
        if (!page_project_save($project)) {
            flash_redirect($back, 'fail', 'project.json 을 쓰지 못했습니다.');
        }
        save_and_sync($back, [pages_rel()], "페이지 버전삭제 : {$code}/{$version}",
            "{$code}/{$version} 을(를) 지웠습니다.{$moved} (git 기록에서 되살릴 수 있습니다)");
    }

    if ($action === 'delete_project') {
        if (!page_rmtree((string) page_project_dir($code))) {
            flash_redirect($back, 'fail', '프로젝트 폴더를 지우지 못했습니다. 폴더 쓰기 권한을 확인해 주세요.');
        }
        save_and_sync($SELF, [pages_rel()], "페이지 프로젝트삭제 : {$code}",
            "{$code} 프로젝트를 지웠습니다. (git 기록에서 되살릴 수 있습니다)");
    }

    flash_redirect($SELF, 'fail', '알 수 없는 동작입니다.');
}

/* ── 화면 ──────────────────────────────────────────────────── */

page_cleanup_work();
$projects = pages_list();
$csrf     = csrf_token();
$preset   = page_code_clean((string) ($_GET['project'] ?? '')) ?? '';
$hasZip   = class_exists('ZipArchive');
$groups   = PAGE_EXT_GROUPS;

layout_header('페이지 관리', 'pages');
?>
<div class="pagehead">
  <h1>페이지 관리</h1>
  <span class="muted"><?= count($projects) ?>개 프로젝트 · <?= h(pages_rel()) ?>/</span>
</div>

<?php if (!$hasZip): ?>
  <div class="banner warn">서버 PHP 에 zip 확장이 없어 zip 을 풀 수 없습니다. 폴더 선택으로 올려 주세요.</div>
<?php endif; ?>

<section class="extbox">
  <h2>올릴 수 있는 파일</h2>
  <dl class="extgroups">
    <?php foreach ($groups as $label => $exts): ?>
      <dt><?= h($label) ?></dt>
      <dd><?php foreach ($exts as $e): ?><code>.<?= h($e) ?></code><?php endforeach; ?></dd>
    <?php endforeach; ?>
  </dl>
  <p class="muted">
    HTML 파일 하나, 여러 파일, 폴더째, zip 모두 됩니다. 폴더 · zip 은 안의 구조를 그대로 살리고,
    최상위 폴더가 하나뿐이면 그 안쪽을 기준으로 삼습니다. 목록에 없는 파일(<code>.psd</code>
    <code>.scss</code> 등)은 빼고 올린 뒤 알려 드리고, 서버에서 실행되는 파일(<code>.php</code>
    <code>.jsp</code> <code>.cgi</code> 등)은 언제나 뺍니다.
  </p>
</section>

<form class="uploadbox pageform" method="post" enctype="multipart/form-data" id="upload">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">

  <div class="pagegrid">
    <label>프로젝트
      <select name="project" class="js-project">
        <option value="" data-next="v1">+ 새 프로젝트</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= h($p['code']) ?>" data-next="<?= h(page_next_version($p)) ?>"
            <?= $p['code'] === $preset ? 'selected' : '' ?>><?= h($p['title']) ?> (<?= h($p['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="js-new">프로젝트 코드
      <input type="text" name="code" maxlength="40" pattern="[a-z0-9][a-z0-9_\-]*" placeholder="예: 2026000100, nuga-shop"
             autocapitalize="off" spellcheck="false">
    </label>
    <label class="js-new">제목
      <input type="text" name="title" maxlength="80" placeholder="예: 쇼핑몰 리뉴얼 목업">
    </label>
    <label>버전
      <input type="text" name="version" maxlength="40" class="js-version" placeholder="비우면 v1">
    </label>
    <label class="span2">메모
      <input type="text" name="note" maxlength="200" placeholder="예: 2차 검토 의견 반영">
    </label>
  </div>

  <div class="dropzone js-drop">
    <input type="file" name="files[]" multiple class="js-files" hidden>
    <div class="js-paths"></div>
    <p class="js-summary">HTML · zip 을 여기에 끌어다 놓거나</p>
    <div class="dropzone-btns">
      <button type="button" class="js-pick-files">파일 선택</button>
      <button type="button" class="js-pick-folder">폴더 선택</button>
    </div>
  </div>

  <div class="pageform-foot">
    <label class="inline"><input type="checkbox" name="make_latest" value="1" checked> 최신 버전으로 지정</label>
    <label class="inline"><input type="checkbox" name="overwrite" value="1"> 같은 버전 덮어쓰기</label>
    <button type="submit" class="primary">올리고 푸시</button>
  </div>
  <p class="muted">
    파일당 · zip 최대 <?= h(human_size($MAX_FILE)) ?>, 한 버전에 풀린 크기 최대 <?= h(human_size($MAX_TOTAL)) ?> ·
    <?= (int) $MAX_COUNT ?>개. 폴더 선택은 한 번에 <?= (int) $MAX_POST ?>개 파일까지 받을 수 있어(서버 설정)
    파일이 많으면 zip 으로 묶어 올려 주세요.
  </p>
</form>

<?php if (!$projects): ?>
  <p class="muted emptynote">올린 페이지가 없습니다.</p>
<?php endif; ?>

<?php foreach ($projects as $p): ?>
  <?php $latestUrl = pages_url($p['code']); ?>
  <section class="project" id="p-<?= h($p['code']) ?>">
    <div class="project-head">
      <div class="project-title">
        <h2><?= h($p['title']) ?></h2>
        <code><?= h($p['code']) ?></code>
      </div>
      <div class="project-link">
        <a href="<?= h($latestUrl) ?>" target="_blank" rel="noopener"><?= h(absolute_url($latestUrl)) ?></a>
        <span class="muted">→ 최신 <?= h($p['latest']) ?></span>
      </div>
      <div class="actionbar">
        <button type="button" class="iconbtn js-copy" title="최신 주소 복사" aria-label="최신 주소 복사"
                data-url="<?= h(absolute_url($latestUrl)) ?>"><?= ICON_LINK ?></button>
        <a class="iconbtn" title="새 버전 올리기" aria-label="새 버전 올리기"
           href="<?= h($SELF) ?>?project=<?= rawurlencode($p['code']) ?>#upload"><?= ICON_UPLOAD ?></a>
        <form method="post" class="js-title" data-title="<?= h($p['title']) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="title">
          <input type="hidden" name="code" value="<?= h($p['code']) ?>">
          <input type="hidden" name="title" value="">
          <button class="iconbtn" type="submit" title="제목 변경" aria-label="제목 변경"><?= ICON_PENCIL ?></button>
        </form>
        <form method="post" class="js-delete-project" data-code="<?= h($p['code']) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="delete_project">
          <input type="hidden" name="code" value="<?= h($p['code']) ?>">
          <button class="iconbtn danger" type="submit" title="프로젝트 삭제" aria-label="프로젝트 삭제"><?= ICON_TRASH ?></button>
        </form>
      </div>
    </div>

    <table class="filelist versionlist">
      <thead>
        <tr><th>버전</th><th>처음 열 파일</th><th>메모</th><th>파일</th><th>올린 때</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach (array_reverse($p['versions']) as $v): ?>
        <?php
          $isLatest = $v['version'] === $p['latest'];
          $url      = pages_url($p['code'], $v['version'], $v['entry']);
          $time     = strtotime($v['uploadedAt']);
        ?>
        <tr<?= $isLatest ? ' class="is-latest"' : '' ?>>
          <td class="vercell">
            <a href="<?= h($url) ?>" target="_blank" rel="noopener"><strong><?= h($v['version']) ?></strong></a>
            <?php if ($isLatest): ?><span class="badge">최신</span><?php endif; ?>
          </td>
          <td class="urlcell"><code><?= h($v['entry'] !== '' ? $v['entry'] : '-') ?></code></td>
          <td class="notecell"><?= h($v['note']) ?></td>
          <td class="num"><?= (int) $v['files'] ?>개 · <?= h(human_size($v['bytes'])) ?></td>
          <td class="num muted"><?= $time ? h(date('Y-m-d H:i', $time)) : '-' ?></td>
          <td class="actions">
            <div class="actionbar">
              <?php if (!$isLatest): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="latest">
                  <input type="hidden" name="code" value="<?= h($p['code']) ?>">
                  <input type="hidden" name="version" value="<?= h($v['version']) ?>">
                  <button class="textbtn" type="submit" title="최신 주소가 이 버전을 가리키게 합니다">최신으로</button>
                </form>
              <?php endif; ?>
              <button type="button" class="iconbtn js-copy" title="이 버전 주소 복사" aria-label="이 버전 주소 복사"
                      data-url="<?= h(absolute_url($url)) ?>"><?= ICON_LINK ?></button>
              <a class="iconbtn" title="열기" aria-label="열기" href="<?= h($url) ?>"
                 target="_blank" rel="noopener"><?= ICON_EXTERNAL ?></a>
              <form method="post" class="js-note" data-note="<?= h($v['note']) ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="note">
                <input type="hidden" name="code" value="<?= h($p['code']) ?>">
                <input type="hidden" name="version" value="<?= h($v['version']) ?>">
                <input type="hidden" name="note" value="">
                <button class="iconbtn" type="submit" title="메모 고치기" aria-label="메모 고치기"><?= ICON_PENCIL ?></button>
              </form>
              <form method="post" class="js-delete-version" data-name="<?= h($p['code'] . '/' . $v['version']) ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete_version">
                <input type="hidden" name="code" value="<?= h($p['code']) ?>">
                <input type="hidden" name="version" value="<?= h($v['version']) ?>">
                <button class="iconbtn danger" type="submit" title="버전 삭제" aria-label="버전 삭제"
                  <?= count($p['versions']) === 1 ? 'disabled' : '' ?>><?= ICON_TRASH ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>

<script>
(function () {
  var form    = document.getElementById('upload');
  var input   = form.querySelector('.js-files');
  var paths   = form.querySelector('.js-paths');
  var summary = form.querySelector('.js-summary');
  var drop    = form.querySelector('.js-drop');
  var select  = form.querySelector('.js-project');
  var version = form.querySelector('.js-version');
  var MAX_POST = <?= (int) $MAX_POST ?>;
  var EMPTY   = summary.textContent;

  /* 새 프로젝트일 때만 코드 · 제목 칸을 보인다. 버전 칸의 안내는 다음 번호로 바꾼다. */
  function syncProject() {
    var isNew = select.value === '';
    form.querySelectorAll('.js-new').forEach(function (el) {
      el.hidden = !isNew;
      el.querySelector('input').required = isNew && el.querySelector('input').name === 'code';
    });
    version.placeholder = '비우면 ' + select.options[select.selectedIndex].dataset.next;
  }
  select.addEventListener('change', syncProject);
  syncProject();

  /* 폴더를 고르면 브라우저가 파일마다 상대 경로를 알려 준다. PHP 8.1 미만은 이를
     받지 못하므로 같은 순서로 paths[] 를 함께 보낸다. */
  function syncFiles() {
    paths.innerHTML = '';
    var files = input.files;
    var bytes = 0;
    for (var i = 0; i < files.length; i++) {
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = 'paths[]';
      h.value = files[i].webkitRelativePath || files[i].name;
      paths.appendChild(h);
      bytes += files[i].size;
    }
    if (!files.length) {
      summary.textContent = EMPTY;
      drop.classList.remove('has-files');
      return;
    }
    var first = files[0].webkitRelativePath ? files[0].webkitRelativePath.split('/')[0] + '/' : files[0].name;
    summary.textContent = (files.length === 1 ? first : first + ' 외 ' + (files.length - 1) + '개')
      + ' · ' + (bytes / 1024 / 1024).toFixed(1) + ' MB';
    drop.classList.add('has-files');
  }
  input.addEventListener('change', syncFiles);

  form.querySelector('.js-pick-files').addEventListener('click', function () {
    input.webkitdirectory = false;
    input.click();
  });
  form.querySelector('.js-pick-folder').addEventListener('click', function () {
    input.webkitdirectory = true;
    input.click();
  });

  /* 끌어다 놓기는 파일만 받는다. 폴더를 놓으면 브라우저가 내용을 넘겨주지 않는다. */
  ['dragenter', 'dragover'].forEach(function (t) {
    drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (t) {
    drop.addEventListener(t, function () { drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (ev) {
    ev.preventDefault();
    var items = ev.dataTransfer.items || [];
    for (var i = 0; i < items.length; i++) {
      var entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
      if (entry && entry.isDirectory) {
        alert('폴더는 끌어다 놓을 수 없습니다. [폴더 선택] 으로 골라 주세요.');
        return;
      }
    }
    input.webkitdirectory = false;
    input.files = ev.dataTransfer.files;
    syncFiles();
  });

  form.addEventListener('submit', function (ev) {
    if (!input.files.length) {
      ev.preventDefault();
      alert('올릴 파일을 골라 주세요.');
      return;
    }
    if (input.files.length > MAX_POST) {
      ev.preventDefault();
      alert('파일이 ' + input.files.length + '개입니다. 서버는 한 번에 ' + MAX_POST
        + '개까지 받습니다.\n폴더를 zip 으로 묶어 올려 주세요.');
    }
  });
})();

document.addEventListener('submit', function (ev) {
  var f = ev.target;

  if (f.classList.contains('js-title')) {
    var t = prompt('프로젝트 제목', f.dataset.title);
    if (!t) { ev.preventDefault(); return; }
    f.elements.title.value = t;   // f.title 은 폼 자신의 title 속성이다
    return;
  }

  if (f.classList.contains('js-note')) {
    var n = prompt('메모', f.dataset.note);
    if (n === null) { ev.preventDefault(); return; }
    f.elements.note.value = n;
    return;
  }

  if (f.classList.contains('js-delete-version')) {
    if (!confirm(f.dataset.name + ' 을(를) 지웁니다.\n이 버전 주소로 걸어 둔 링크는 더 이상 열리지 않습니다. 계속할까요?')) {
      ev.preventDefault();
    }
    return;
  }

  if (f.classList.contains('js-delete-project')) {
    var c = prompt('프로젝트의 모든 버전을 지웁니다. 지우려면 프로젝트 코드(' + f.dataset.code + ')를 그대로 입력하세요.');
    if (c !== f.dataset.code) {
      ev.preventDefault();
      if (c !== null) { alert('코드가 맞지 않아 지우지 않았습니다.'); }
    }
  }
});
</script>
<?php layout_footer(); ?>
