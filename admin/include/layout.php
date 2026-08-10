<?php
/** 관리자 페이지 공통 뼈대 — 머리말, 탭, 저장 결과 배너, 꼬리말. */
declare(strict_types=1);

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/git.php';

/** $wide 는 카드가 4열로 늘어서는 아이콘 배치 화면처럼 폭이 필요한 페이지에 쓴다. */
function layout_header(string $title, string $active, bool $wide = false): void
{
    $tabs = [
        'index' => ['아이콘 배치', '/admin/index.php'],
        'files' => ['아이콘 파일', '/admin/files.php'],
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
