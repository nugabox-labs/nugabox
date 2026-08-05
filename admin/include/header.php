<?php
/**
 * 관리자 공통 헤더. 각 페이지에서 $PAGE_TITLE / $PAGE_KEY 를 정의한 뒤 include 한다.
 *  $PAGE_KEY: 'dashboard' | 'files'
 */
declare(strict_types=1);

if (!defined('ADMIN_DIR')) {
    require_once __DIR__ . '/bootstrap.php';
}

$PAGE_TITLE = $PAGE_TITLE ?? '관리자';
$PAGE_KEY   = $PAGE_KEY ?? '';

$NAV = [
    'dashboard' => ['label' => '대시보드', 'href' => '/admin/index.php', 'icon' => 'fa-solid fa-table-cells-large'],
    'files'     => ['label' => '파일 관리', 'href' => '/admin/files.php', 'icon' => 'fa-solid fa-folder-open'],
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <title><?= h($PAGE_TITLE) ?> · NUGABOX 관리자</title>
  <link rel="icon" type="image/png" href="/admin/assets/images/admin_favicon.png">

  <link rel="stylesheet" href="/admin/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="/admin/assets/css/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/admin/assets/css/pretendard.css">
  <link rel="stylesheet" href="/admin/assets/css/fontawesome.min.css">
  <link rel="stylesheet" href="/admin/assets/css/admin.css">
  <link rel="stylesheet" href="/admin/assets/css/admin-custom.css">
</head>
<body>
<div class="app" id="adminApp">
  <div class="scrim" id="scrim"></div>

  <!-- 사이드바 -->
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/admin/index.php">
      <div class="brand-mark"><i class="fa-solid fa-chess-king"></i></div>
      <div>
        <div class="brand-name">홈페이지 관리자</div>
        <div class="brand-sub">NUGABOX</div>
      </div>
    </a>

    <nav class="nav-section">
      <div class="nav-label">메인</div>
      <?php foreach ($NAV as $key => $item): ?>
        <a class="nav-item<?= $PAGE_KEY === $key ? ' active' : '' ?>" href="<?= h($item['href']) ?>">
          <span class="ic"><i class="<?= h($item['icon']) ?>"></i></span>
          <?= h($item['label']) ?>
        </a>
      <?php endforeach; ?>

      <div class="nav-label">바로가기</div>
      <a class="nav-item" href="<?= h((string) cfg('public_base_url')) ?>" target="_blank" rel="noopener">
        <span class="ic"><i class="fa-solid fa-arrow-up-right-from-square"></i></span>
        홈페이지 보기
      </a>
    </nav>

    <div class="side-footer">
      <div class="avatar-square"><i class="fa-solid fa-user"></i></div>
      <div class="who flex-grow-1">
        <div class="name text-truncate"><?= h((string) ($_SESSION['admin_user'] ?? '관리자')) ?></div>
      </div>
      <a class="icon-btn" href="/admin/logout.php" title="로그아웃"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <!-- 메인 영역 -->
  <div class="main">
    <div class="panel">
      <header class="crumb-bar">
        <button class="toggle-side" id="sideToggle" title="사이드바 접기/펼치기">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div class="crumbs">
          <span class="root">관리자</span>
          <span class="sep">/</span>
          <span class="cur" id="crumb"><?= h($PAGE_TITLE) ?></span>
        </div>
        <div class="actions">
          <button class="icon-btn" id="themeToggle" title="다크/라이트 전환">
            <i class="fa-solid fa-moon" id="themeIcon"></i>
          </button>
          <a class="btn-dark-pill ms-1 btn-crumb-dash" href="/admin/logout.php">로그아웃</a>
        </div>
      </header>
      <main class="content">
