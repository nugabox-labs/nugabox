<?php
declare(strict_types=1);

require_once __DIR__ . '/include/bootstrap.php';
require_once __DIR__ . '/include/git.php';
admin_require_login();

$PAGE_TITLE = '대시보드';
$PAGE_KEY   = 'dashboard';

$files     = list_upload_files();
$totalSize = array_sum(array_column($files, 'size'));
$recent    = array_slice($files, 0, 6);

$imageExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];
$imageCount = count(array_filter($files, static fn(array $f): bool => in_array($f['ext'], $imageExt, true)));

$today      = date('Y-m-d');
$todayCount = count(array_filter($files, static fn(array $f): bool => date('Y-m-d', $f['mtime']) === $today));

$git       = git_status_summary();
$maxUpload = effective_max_upload();
$uploadDir = upload_dir();

// 권한 문제는 업로드를 눌러야 드러나므로 미리 표시해 준다.
$gitDir       = rtrim((string) (cfg('git')['repo_dir'] ?? ROOT_DIR), '/') . '/.git';
$uploadWrite  = is_writable($uploadDir);
$gitWrite     = is_dir($gitDir) && is_writable($gitDir);
$phpUser      = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '알 수 없음')
    : (getenv('USER') ?: '알 수 없음');

require __DIR__ . '/include/header.php';
?>
<section class="page show">
  <div class="page-head">
    <div>
      <h1 class="page-title">대시보드</h1>
      <p class="page-sub"><?= h((string) cfg('public_base_url')) ?> 의 업로드 현황입니다.</p>
    </div>
    <div class="actions d-flex gap-2 flex-wrap">
      <a class="btn-ghost" href="<?= h((string) cfg('public_base_url')) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> 홈페이지 보기
      </a>
      <a class="btn-dark-pill" href="/admin/files.php">
        <i class="fa-solid fa-arrow-up-from-bracket"></i> 파일 업로드
      </a>
    </div>
  </div>

  <!-- KPI -->
  <div class="kpi">
    <div class="kpi-card">
      <div class="label">
        <span class="ic-pill"><i class="fa-solid fa-folder-open"></i></span>
        전체 파일
      </div>
      <div class="val"><?= number_format(count($files)) ?><span class="unit">개</span></div>
      <div class="delta">upload 폴더 기준</div>
    </div>
    <div class="kpi-card">
      <div class="label">
        <span class="ic-pill"><i class="fa-solid fa-database"></i></span>
        총 용량
      </div>
      <div class="val"><?= h(human_size((int) $totalSize)) ?></div>
      <div class="delta">파일당 최대 <?= h(human_size($maxUpload)) ?></div>
    </div>
    <div class="kpi-card">
      <div class="label">
        <span class="ic-pill"><i class="fa-solid fa-image"></i></span>
        이미지
      </div>
      <div class="val"><?= number_format($imageCount) ?><span class="unit">개</span></div>
      <div class="delta">전체의 <?= $files ? round($imageCount / count($files) * 100) : 0 ?>%</div>
    </div>
    <div class="kpi-card">
      <div class="label">
        <span class="ic-pill"><i class="fa-solid fa-clock-rotate-left"></i></span>
        오늘 변경
      </div>
      <div class="val"><?= number_format($todayCount) ?><span class="unit">개</span></div>
      <div class="delta"><?= h($today) ?></div>
    </div>
  </div>

  <div class="mt-3 grid-2">
    <!-- 최근 업로드 -->
    <div class="card-soft">
      <div class="ch">
        <h6>최근 업로드</h6>
        <div class="sub"><a href="/admin/files.php">전체 보기</a></div>
      </div>
      <div class="cb p-0">
        <?php if (!$recent): ?>
          <div class="empty p-4 text-center">아직 업로드된 파일이 없습니다.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="adm">
              <thead>
                <tr><th>파일</th><th class="col-hide-sm">크기</th><th class="col-hide-sm">수정일</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $f): ?>
                  <tr>
                    <td>
                      <div class="file-cell">
                        <span class="file-thumb">
                          <?php if (in_array($f['ext'], $imageExt, true)): ?>
                            <img src="<?= h($f['url']) ?>" alt="" loading="lazy">
                          <?php else: ?>
                            <i class="fa-solid fa-file"></i>
                          <?php endif; ?>
                        </span>
                        <div class="file-meta">
                          <div class="fname"><?= h($f['name']) ?></div>
                          <a class="furl" href="<?= h($f['url']) ?>" target="_blank" rel="noopener"><?= h(rawurldecode($f['url'])) ?></a>
                        </div>
                      </div>
                    </td>
                    <td class="col-hide-sm col-num"><?= h($f['size_human']) ?></td>
                    <td class="col-hide-sm col-num"><?= h($f['modified']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 저장소 상태 -->
    <div class="card-soft">
      <div class="ch">
        <h6>저장소 · 환경</h6>
        <div class="sub">
          <?php if (!$git['enabled']): ?>
            <span class="tag warn">git 꺼짐</span>
          <?php elseif (!empty($git['ahead'])): ?>
            <span class="tag danger dot">푸시 안 된 커밋 <?= (int) $git['ahead'] ?>개</span>
          <?php elseif ($git['clean'] === false): ?>
            <span class="tag danger dot">커밋 안 된 변경 있음</span>
          <?php elseif ($git['clean'] === true): ?>
            <span class="tag success dot">동기화됨</span>
          <?php else: ?>
            <span class="tag warn">상태 확인 불가</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="cb">
        <div class="stat-list">
          <div class="row-i"><span class="k">브랜치</span><span class="v"><?= h($git['branch']) ?></span></div>
          <div class="row-i"><span class="k">마지막 커밋</span><span class="v"><?= h($git['last_commit']) ?></span></div>
          <div class="row-i"><span class="k">업로드 폴더</span><span class="v"><?= h($uploadDir) ?></span></div>
          <div class="row-i"><span class="k">공개 URL</span><span class="v"><?= h(rtrim((string) cfg('public_base_url'), '/') . '/' . trim((string) cfg('upload_url_path'), '/') . '/') ?></span></div>
          <div class="row-i">
            <span class="k">upload 쓰기</span>
            <span class="v"><span class="tag <?= $uploadWrite ? 'success dot' : 'danger dot' ?>"><?= $uploadWrite ? '가능' : '불가' ?></span></span>
          </div>
          <div class="row-i">
            <span class="k">.git 쓰기</span>
            <span class="v"><span class="tag <?= $gitWrite ? 'success dot' : 'danger dot' ?>"><?= $gitWrite ? '가능' : '불가' ?></span></span>
          </div>
          <div class="row-i"><span class="k">설정 파일</span><span class="v"><?= h(env_file() ?? '없음 — 기본값으로 동작 중') ?></span></div>
          <div class="row-i"><span class="k">실행 사용자 · PHP</span><span class="v"><?= h($phpUser) ?> · <?= h(PHP_VERSION) ?></span></div>
        </div>
        <?php if (!$uploadWrite || !$gitWrite): ?>
          <p class="hint-text">
            <?= $phpUser !== '알 수 없음' ? '웹서버 사용자(<b>' . h($phpUser) . '</b>)' : '웹서버 사용자' ?>에게
            <?= !$uploadWrite ? '<code>upload/</code>' : '' ?><?= !$uploadWrite && !$gitWrite ? ' 와 ' : '' ?><?= !$gitWrite ? '<code>.git/</code>' : '' ?>
            쓰기 권한이 없습니다. 이 상태로는 <?= !$uploadWrite ? '업로드가 실패하고, ' : '' ?>커밋 · 푸시가 되지 않습니다.
            설정 방법은 <a href="/admin/README.md" target="_blank" rel="noopener">README</a> 를 참고하세요.
          </p>
        <?php elseif (!empty($git['ahead'])): ?>
          <p class="hint-text">
            <b>푸시되지 않은 커밋이 <?= (int) $git['ahead'] ?>개 있습니다.</b>
            이 상태로 다른 배포가 돌면 <code>git reset --hard</code> 가 방금 올린 파일까지 되돌립니다.
            <a href="/admin/files.php">파일 관리</a> 의 <b>git 동기화</b> 를 눌러 먼저 올려 주세요.
          </p>
        <?php elseif ($git['clean'] === false): ?>
          <p class="hint-text">
            커밋되지 않은 변경이 남아 있습니다. <a href="/admin/files.php">파일 관리</a> 의 <b>git 동기화</b> 버튼으로 밀린 변경을 올릴 수 있습니다.
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/include/footer.php'; ?>
