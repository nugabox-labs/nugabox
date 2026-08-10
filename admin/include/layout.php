<?php
/** 관리자 페이지 공통 뼈대 — 머리말, 탭, 저장 결과 배너, 꼬리말. */
declare(strict_types=1);

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/git.php';

/* 표 안의 동작 버튼에 쓰는 아이콘. 아이콘 폰트를 통째로 받아오지 않으려고
   필요한 것만 인라인 SVG 로 둔다. (currentColor 라서 버튼 색을 따라간다) */

const ICON_SVG_OPEN = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none"'
    . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
    . ' aria-hidden="true">';

const ICON_DOWNLOAD = ICON_SVG_OPEN
    . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
    . '<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';

const ICON_PENCIL = ICON_SVG_OPEN
    . '<path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>';

const ICON_TRASH = ICON_SVG_OPEN
    . '<polyline points="3 6 5 6 21 6"/>'
    . '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    . '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';

const ICON_LINK = ICON_SVG_OPEN
    . '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
    . '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';

const ICON_CHECK = ICON_SVG_OPEN . '<polyline points="20 6 9 17 4 12"/></svg>';

const ICON_EXTERNAL = ICON_SVG_OPEN
    . '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>'
    . '<polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

/** $wide 는 카드가 4열로 늘어서는 아이콘 배치 화면처럼 폭이 필요한 페이지에 쓴다. */
function layout_header(string $title, string $active, bool $wide = false): void
{
    $tabs = [
        'index' => ['아이콘 배치', '/admin/index.php'],
        'files' => ['아이콘 파일', '/admin/files.php'],
        'uploads' => ['업로드 파일 관리', '/admin/uploads.php'],
        'links' => ['주소 연결',   '/admin/links.php'],
    ];
    $csrf = csrf_token();
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($title) ?> · NUGABOX 관리자</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32x32.png">
  <link href="/admin/assets/admin.css" rel="stylesheet">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="/admin/index.php">NUGABOX <span>관리자</span></a>
    <nav>
      <?php foreach ($tabs as $key => [$label, $href]): ?>
        <a href="<?= h($href) ?>"<?= $key === $active ? ' class="on"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
      <a href="/" target="_blank" rel="noopener">사이트 보기</a>
      <a href="/admin/logout.php">로그아웃</a>
    </div>
  </header>
  <main<?= $wide ? ' class="wide"' : '' ?>>
    <?php layout_git_banner($csrf); ?>
    <?php layout_flash(); ?>
    <?php
}

/**
 * push 되지 않은 커밋을 상시 경고한다.
 *
 * 배포가 `git reset --hard origin/main` 이므로, 커밋만 되고 push 가 안 된 상태를
 * 방치하면 다음 배포 때 여기서 한 작업이 전부 사라진다. 조용히 넘어가면 안 되는
 * 유일한 상태라서 모든 페이지 맨 위에 띄운다.
 */
function layout_git_banner(string $csrf): void
{
    $g = git_status_summary();

    if (!$g['enabled']) {
        echo '<div class="banner warn">git 동기화가 꺼져 있습니다. 변경은 서버 파일에만 남고 '
            . '다음 배포 때 되돌아갑니다. (.env.php 의 GIT_ENABLED)</div>';
        return;
    }
    if (!empty($g['ahead'])) {
        ?>
        <div class="banner danger">
          <div>
            <strong>푸시되지 않은 커밋이 <?= (int) $g['ahead'] ?>개 있습니다.</strong>
            지금 배포가 돌면 여기서 한 작업이 되돌아갑니다.
          </div>
          <form method="post" action="/admin/index.php">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="push">
            <button type="submit">지금 푸시</button>
          </form>
        </div>
        <?php
        return;
    }
    if ($g['ahead'] === null) {
        echo '<div class="banner warn">원격(' . h((string) (cfg('git', [])['remote'] ?? 'origin'))
            . ')과 비교하지 못했습니다. push 상태를 확인할 수 없습니다.</div>';
    }
}

/** 직전 동작의 결과. 저장 후 리다이렉트해 왔기 때문에 새로고침해도 재전송되지 않는다. */
function layout_flash(): void
{
    $f = flash_take();
    if (!$f) {
        return;
    }
    $kind = $f['kind'] === 'ok' ? 'ok' : 'danger';
    echo '<div class="banner ' . $kind . '"><div>' . h($f['message']) . '</div></div>';
    if (!empty($f['log'])) {
        echo '<details class="gitlog"><summary>git 로그</summary><pre>' . h($f['log']) . '</pre></details>';
    }
}

function layout_footer(): void
{
    $g = git_status_summary();
    ?>
  </main>
  <footer class="foot">
    <span><?= h($g['branch']) ?></span>
    <span><?= h($g['last_commit']) ?></span>
  </footer>
  <script>
  /* 파일 목록의 링크 복사 버튼. navigator.clipboard 는 https 나 localhost 에서만
     쓸 수 있어서, 막힌 환경에서는 임시 textarea + execCommand 로 물러선다. */
  (function () {
    var DONE = <?= json_encode(ICON_CHECK) ?>;

    function legacyCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }

    function flash(btn) {
      if (btn.dataset.busy) { return; }
      btn.dataset.busy = '1';
      var html = btn.innerHTML;
      btn.innerHTML = DONE;
      btn.classList.add('ok');
      setTimeout(function () {
        btn.innerHTML = html;
        btn.classList.remove('ok');
        delete btn.dataset.busy;
      }, 1200);
    }

    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest ? ev.target.closest('.js-copy') : null;
      if (!btn) { return; }
      ev.preventDefault();

      var url = btn.dataset.url;
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(function () { flash(btn); }, function () {
          if (legacyCopy(url)) { flash(btn); } else { prompt('아래 주소를 복사하세요', url); }
        });
        return;
      }
      if (legacyCopy(url)) { flash(btn); } else { prompt('아래 주소를 복사하세요', url); }
    });
  })();
  </script>
</body>
</html>
    <?php
}

/**
 * 변경을 저장한 뒤 커밋 · 푸시하고 결과 배너를 띄운다.
 * 저장은 됐는데 push 가 실패한 경우를 성공처럼 보이게 하지 않는다.
 */
function save_and_sync(string $backTo, array $paths, string $message, string $okText): void
{
    $r = git_sync($paths, $message);

    if ($r['ok'] && $r['pushed']) {
        flash_redirect($backTo, 'ok', $okText . ' — ' . $r['message'], $r['log']);
    }
    if ($r['ok']) {
        // 커밋할 것이 없었던 경우 (내용이 그대로일 때)
        flash_redirect($backTo, 'ok', $okText . ' — ' . $r['message'], $r['log']);
    }
    flash_redirect(
        $backTo,
        'fail',
        '파일은 저장했지만 GitHub 반영에 실패했습니다. 이 상태로 두면 다음 배포 때 되돌아갑니다. — '
            . $r['message'],
        $r['log']
    );
}
