<?php
/**
 * NUGABOX 첫 화면.
 *
 * 앱 아이콘은 data/icons.json 에서 읽어 그린다. 예전에는 이 파일의 마크업과
 * style.css 의 #id 규칙을 손으로 같이 고쳐야 했는데, 이제 관리자(/admin)가
 * JSON 한 곳만 고치고 커밋·푸시까지 한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/site.php';

$icons = icons_load();
$site  = site_meta();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta property="og:image" content="/assets/images/social/meta_nugabox_2021.png">
  <meta property="og:title" content="NUGABOX">
  <meta property="og:type" content="website">
  <meta property="og:description" content="made by NUGA">
  <title>NUGABOX</title>
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/favicon/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon/favicon-16x16.png">
  <link rel="manifest" href="/assets/images/favicon/site.webmanifest">
  <link rel="mask-icon" href="/assets/images/favicon/safari-pinned-tab.svg" color="#272b35">
  <meta name="msapplication-TileColor" content="#272b35">
  <meta name="theme-color" content="#ffffff">
  <link href="/assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" crossorigin="anonymous">
  <link href="/assets/css/fontAwesome.min.css" rel="stylesheet" type="text/css" crossorigin="anonymous">
  <link href="/assets/css/style.css" rel="stylesheet" type="text/css" crossorigin="anonymous">
  <!-- <link href="/assets/css/jquery-sakura.css" rel="stylesheet" type="text/css" crossorigin="anonymous"> -->
  <link href="/assets/css/sakura.min.css" rel="stylesheet" type="text/css" crossorigin="anonymous">
</head>
<body>
  <div class="row top-bar">
    <div class="col-6 icon-toggle-btn">
      <div class="effect-toggle-wrapper">
        <button type="button" class="effect-toggle-btn hover-ani" id="effect-toggle" aria-label="계절 효과 토글">🍃</button>
        <button type="button" class="effect-menu-btn" id="effect-menu-btn" aria-label="효과 선택" aria-expanded="false" aria-haspopup="true">
          <span class="effect-menu-caret"></span>
        </button>
        <div class="effect-dropdown" id="effect-dropdown" role="menu"></div>
      </div>
    </div>
    <div class="col-6 text-right">
      <div class="theme-toggle-wrapper">
        <div class="theme-toggle">
          <button class="theme-toggle-btn" data-theme="light">
            <ion-icon name="sunny-outline"></ion-icon>
          </button>
          <button class="theme-toggle-btn" data-theme="dark">
            <ion-icon name="moon-outline"></ion-icon>
          </button>
          <span class="theme-toggle-slider"></span>
        </div>
      </div>
    </div>
  </div>
  <div class="middle-box">
    <div class="banner_img"></div>
    <div class="profile hover-ani">
      <div class="content">
        <span>NUGA</span>
      </div>
      <div class="profile-cover"></div>
    </div>
    <div class="app-line">
<?= render_icon_rows($icons) ?>
    </div>
    <div class="welcome">
      <a class="arrow-icon">
        <span class="left-bar"></span>
        <span class="right-bar"></span>
      </a>
      <p class="font-eng" id="welcome-text">"This is only his box.<br>The sheep you asked for is inside."</p>
      <p>made by <a class="insta-gradient" href="https://www.instagram.com/nugabox" target="_blank">@nugabox</a></p>
    </div>
    <div class="sns-link">
      <a href="https://github.com/nugaBox" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Github"><ion-icon name="logo-github"></ion-icon></a>
      <a href="mailto:root@nugabox.com" target="_blank"data-bs-toggle="tooltip" data-bs-placement="bottom" title="Email"><ion-icon name="mail-outline"></ion-icon></a>
      <a href="https://www.instagram.com/nugabox" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Instagram"><ion-icon name="logo-instagram"></ion-icon></a>
      <a href="https://www.rocketpunch.com/@nugabox" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="RocketPunch"><ion-icon name="rocket-sharp"></ion-icon></a>
      <a href="https://www.linkedin.com/in/nugabox/" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Linkedin"><ion-icon name="logo-linkedin"></ion-icon></a>
      <a href="https://www.buymeacoffee.com/nugabox" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Buy Me a Coffee"><ion-icon name="cafe"></ion-icon></a>
      <a href="https://campaign.naver.com/memory/" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Memory"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-ribbon-icon lucide-ribbon" aria-hidden="true"><path d="M12 11.22C11 9.997 10 9 10 8a2 2 0 0 1 4 0c0 1-.998 2.002-2.01 3.22"/><path d="m12 18 2.57-3.5"/><path d="M6.243 9.016a7 7 0 0 1 11.507-.009"/><path d="M9.35 14.53 12 11.22"/><path d="M9.35 14.53C7.728 12.246 6 10.221 6 7a6 5 0 0 1 12 0c-.005 3.22-1.778 5.235-3.43 7.5l3.557 4.527a1 1 0 0 1-.203 1.43l-1.894 1.36a1 1 0 0 1-1.384-.215L12 18l-2.679 3.593a1 1 0 0 1-1.39.213l-1.865-1.353a1 1 0 0 1-.203-1.422z"/></svg></a>
<!--      <a id="email" href="mailto:root@nugabox.com" target="_blank"data-bs-toggle="tooltip" data-bs-placement="bottom" title="Email"></a>-->
<!--      <a id="youtube" href="https://www.youtube.com/channel/UClyiG3nsS6r1Z36jUDUADDw" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Youtube"></a>-->
<!--      <a id="instagram" href="https://www.instagram.com/codenuga__" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Instagram"></a>-->
<!--      <a id="memory" href="https://campaign.naver.com/memory/" target="_blank" data-bs-toggle="tooltip" data-bs-placement="top" title="Memory"></a>-->
    </div>
    <div class="site-stats" aria-label="사이트 업데이트·방문 수">
      <span class="stat-item">
        <span class="stat-badge">Update</span>
        <span class="stat-value"><?= e($site['updated']) ?></span>
      </span>
      <span class="stat-item">
        <span class="stat-badge">Visit</span>
        <span class="stat-value" id="visit-count">&mdash;</span>
      </span>
    </div>
    <footer>
      <p>ⓒ <script>document.write(new Date().getFullYear());</script> NUGABOX. All rights reserved.</p>
      <!-- <a href="#"><img src="https://hits.seeyoufarm.com/api/count/incr/badge.svg?url=https%3A%2F%2Fnugabox.github.io&count_bg=%238E8E84&title_bg=%23555555&icon=&icon_color=%23E7E7E7&title=Visit&edge_flat=false" alt="Visit"/></a> -->
    </footer>
  </div>
  <!-- JavaScript -->
  <script src="/assets/js/jquery.min.js" type="text/javascript" crossorigin="anonymous"></script>
  <script src="/assets/js/popper.min.js" type="text/javascript" crossorigin="anonymous"></script>
  <script src="/assets/js/bootstrap.min.js" type="text/javascript" crossorigin="anonymous"></script>
  <!-- <script src="/assets/js/jquery-sakura.js" type="text/javascript" crossorigin="anonymous"></script> -->
  <script src="/assets/js/sakura.min.js" type="text/javascript" crossorigin="anonymous"></script>
  <script src="/assets/js/snowfall.jquery.js" type="text/javascript" crossorigin="anonymous"></script>
  <script src="/assets/js/hello.js" type="text/javascript" crossorigin="anonymous"></script>
  <script src="/assets/js/script.js" type="text/javascript" crossorigin="anonymous"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
  <script>
    (function () {
      // cyworld와 동일: Abacus (https://abacus.jasoncameron.dev/)
      // namespace/key 고정 → nugabox.github.io / nugabox.com / nugabox.io 방문이 한 카운터로 합산됨
      var NAMESPACE = 'nugabox.com';
      var KEY = 'home';
      var KEY_RE = /^[A-Za-z0-9_\-.]{3,64}$/;
      var el = document.getElementById('visit-count');
      if (!el || !KEY_RE.test(NAMESPACE) || !KEY_RE.test(KEY)) return;

      fetch('https://abacus.jasoncameron.dev/hit/'
        + encodeURIComponent(NAMESPACE) + '/'
        + encodeURIComponent(KEY))
        .then(function (res) {
          if (!res.ok) throw new Error('abacus ' + res.status);
          return res.json();
        })
        .then(function (data) {
          var num = Number(data.value);
          el.textContent = isFinite(num) ? Math.floor(num).toLocaleString('en-US') : '-';
        })
        .catch(function () {
          el.textContent = '-';
        });
    })();
  </script>
</body>
</html>
