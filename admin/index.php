<?php
/**
 * 아이콘 배치 — 순서 · 라벨 · 주소 · 배경색 · 상태 점을 고치고 저장하면
 * data/icons.json 을 쓰고 곧바로 커밋 · 푸시한다.
 *
 * 열은 항상 4 다. 순번대로 4개씩 끊어 행을 만들고, 마지막 행에서 모자란 자리는
 * 지금까지처럼 빈칸으로 자동으로 채운다. 행은 얼마든지 늘어난다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/layout.php';

admin_require_login();
csrf_guard();

$SELF = '/admin/index.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');

    /* 밀려 있던 커밋만 다시 푸시 (상단 경고 배너의 버튼) */
    if ($action === 'push') {
        $r = git_sync(['data'], '데이터 동기화');
        flash_redirect(
            $SELF,
            $r['ok'] && $r['pushed'] ? 'ok' : 'fail',
            $r['message'],
            $r['log']
        );
    }

    /* 아이콘 저장 */
    $rows    = (array) ($_POST['icon'] ?? []);
    $errors  = [];
    $icons   = [];

    foreach ($rows as $i => $row) {
        if (!is_array($row) || !empty($row['delete'])) {
            continue;
        }
        // 빈칸은 폼에서 다루지 않는다. 저장할 때 4열을 맞추며 자동으로 생성된다.
        $seq = (int) ($row['seq'] ?? 0);

        $id    = trim((string) ($row['id'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        $url   = trim((string) ($row['url'] ?? ''));

        if ($id === '' && $label === '' && $url === '') {
            continue;   // 빈 줄은 무시 (새 아이콘 칸을 안 쓴 경우)
        }
        if (!icon_id_valid($id)) {
            $errors[] = "아이디 '{$id}' 는 쓸 수 없습니다. 영문으로 시작하고 영문·숫자·_·- 만 씁니다.";
            continue;
        }
        if (!url_valid($url)) {
            $errors[] = "'{$id}' 의 주소가 올바르지 않습니다: {$url}";
            continue;
        }
        $iconFile = trim((string) ($row['icon'] ?? ''));
        if ($iconFile !== '' && !is_file(icons_dir() . '/' . $iconFile)) {
            $errors[] = "'{$id}' 의 아이콘 파일이 없습니다: {$iconFile}";
            continue;
        }

        $icons[] = icon_normalize([
            '__seq'   => $seq,
            'type'    => 'app',
            'row'     => 1,
            'order'   => 1,
            'id'      => $id,
            'label'   => $label,
            'url'     => $url,
            'icon'    => $iconFile,
            'bg'      => (string) ($row['bg'] ?? ''),
            'status'  => (string) ($row['status'] ?? ''),
            'tooltip' => trim((string) ($row['tooltip'] ?? '')),
            'newtab'  => !empty($row['newtab']),
            'hidden'  => !empty($row['hidden']),
        ]) + ['__seq' => $seq];
    }

    // 같은 아이디가 둘이면 HTML id 가 중복돼 CSS · 스크립트가 엉킨다.
    $seen = [];
    foreach ($icons as $icon) {
        if (isset($seen[$icon['id']])) {
            $errors[] = "아이디가 중복됩니다: {$icon['id']}";
        }
        $seen[$icon['id']] = true;
    }

    if ($errors) {
        flash_redirect($SELF, 'fail', implode(' / ', $errors));
    }

    // 순번대로 줄 세운 다음 4열로 다시 흘린다. (행 번호 · 빈칸은 reflow 가 매긴다)
    usort($icons, static fn(array $a, array $b): int => $a['__seq'] <=> $b['__seq']);
    foreach ($icons as $i => $icon) {
        unset($icons[$i]['__seq']);
    }
    $icons = icons_reflow($icons);

    if (!icons_save($icons)) {
        flash_redirect($SELF, 'fail', 'data/icons.json 을 쓰지 못했습니다. 웹서버의 쓰기 권한을 확인해 주세요.');
    }

    // 업데이트 표시 날짜
    $updated = trim((string) ($_POST['updated'] ?? ''));
    if (preg_match('/^\d{4}\. \d{1,2}\. \d{1,2}\.$/', $updated)) {
        data_write('site.json', ['updated' => $updated]);
    }

    save_and_sync($SELF, ['data'], '아이콘 배치를 수정한다.', '아이콘 배치를 저장했습니다');
}

/* ── 화면 ──────────────────────────────────────────────────── */

// 빈칸은 화면에 카드로 띄우지 않는다 — 저장할 때 자동으로 다시 채워진다.
$icons = array_values(array_filter(
    icons_reflow(icons_load()),
    static fn(array $i): bool => $i['type'] !== 'blank'
));
$files    = list_icon_files();
$fileNames = array_column($files, 'name');
$meta      = site_meta();
$csrf      = csrf_token();

// 카드가 4열로 늘어서는 화면이라 전체 너비를 쓴다.
layout_header('아이콘 배치', 'index', true);
?>
<form method="post" id="icon-form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="save">

  <div class="pagehead">
    <h1>아이콘 배치</h1>
    <div class="pagehead-actions">
      <label class="inline">업데이트 표시
        <input type="text" name="updated" value="<?= h($meta['updated']) ?>" size="12"
               pattern="\d{4}\. \d{1,2}\. \d{1,2}\." title="예: 2026. 8. 10.">
      </label>
      <button type="submit" class="primary">저장하고 푸시</button>
    </div>
  </div>
  <p class="muted">
    순번대로 4개씩 끊어 한 줄이 됩니다. 줄은 얼마든지 늘어나고, 마지막 줄에서 남는 자리는
    빈칸으로 자동으로 채워집니다.
  </p>

  <div class="cards">
    <?php $seq = 0; foreach ($icons as $icon): $seq++; $k = $seq; ?>
        <div class="card<?= $icon['hidden'] ? ' is-hidden' : '' ?>" data-seq="<?= $k ?>">
          <input type="hidden" name="icon[<?= $k ?>][type]" value="app">
          <input type="hidden" name="icon[<?= $k ?>][seq]" value="<?= $k ?>" class="seq">
          <div class="card-top">
            <span class="pos"><?= $k ?></span>
            <span class="preview" style="<?= $icon['icon'] !== ''
                ? 'background-image:url(' . h(icon_url($icon['icon'])) . ');' : '' ?><?= $icon['bg']
                ? 'background-color:' . h($icon['bg']) . ';' : '' ?>"></span>
            <input class="grow" type="text" name="icon[<?= $k ?>][label]"
                   value="<?= h($icon['label']) ?>" placeholder="이름">
            <span class="spacer"></span>
            <button type="button" class="mv" data-dir="-1" title="앞으로">▲</button>
            <button type="button" class="mv" data-dir="1" title="뒤로">▼</button>
          </div>

          <label>아이디
            <input type="text" name="icon[<?= $k ?>][id]" value="<?= h($icon['id']) ?>"
                   pattern="[A-Za-z][A-Za-z0-9_\-]*" required>
          </label>
          <label>주소
            <input type="text" name="icon[<?= $k ?>][url]" value="<?= h($icon['url']) ?>" required>
          </label>
          <label>아이콘 파일
            <select name="icon[<?= $k ?>][icon]">
              <option value="">— 없음 —</option>
              <?php foreach ($fileNames as $name): ?>
                <option value="<?= h($name) ?>"<?= $name === $icon['icon'] ? ' selected' : '' ?>><?= h($name) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="pair">
            <label>배경색
              <input type="text" name="icon[<?= $k ?>][bg]" value="<?= h((string) $icon['bg']) ?>"
                     placeholder="#3A3E48" pattern="^$|^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$">
            </label>
            <label>상태 점
              <select name="icon[<?= $k ?>][status]">
                <option value="">없음</option>
                <option value="updated"<?= $icon['status'] === 'updated' ? ' selected' : '' ?>>업데이트(파랑)</option>
                <option value="testing"<?= $icon['status'] === 'testing' ? ' selected' : '' ?>>준비중(주황)</option>
              </select>
            </label>
          </div>
          <label>말풍선
            <input type="text" name="icon[<?= $k ?>][tooltip]" value="<?= h($icon['tooltip']) ?>"
                   placeholder="업데이트됨">
          </label>
          <div class="flags">
            <label class="inline"><input type="checkbox" name="icon[<?= $k ?>][newtab]" value="1"
                   <?= $icon['newtab'] ? 'checked' : '' ?>> 새 탭</label>
            <label class="inline"><input type="checkbox" name="icon[<?= $k ?>][hidden]" value="1"
                   <?= $icon['hidden'] ? 'checked' : '' ?>> 숨김</label>
            <label class="inline del"><input type="checkbox" name="icon[<?= $k ?>][delete]" value="1"> 삭제</label>
          </div>
        </div>
    <?php endforeach; ?>
  </div>

  <fieldset class="newbox">
    <legend>새로 추가</legend>
    <?php $n = 10000; ?>
    <input type="hidden" name="icon[<?= $n ?>][type]" value="app">
    <input type="hidden" name="icon[<?= $n ?>][seq]" value="<?= $n ?>">
    <input type="hidden" name="icon[<?= $n ?>][newtab]" value="1">
    <div class="newrow">
      <input type="text" name="icon[<?= $n ?>][id]" placeholder="아이디 (예: notion)">
      <input type="text" name="icon[<?= $n ?>][label]" placeholder="이름">
      <input type="text" name="icon[<?= $n ?>][url]" placeholder="https://…">
      <select name="icon[<?= $n ?>][icon]">
        <option value="">아이콘 없음</option>
        <?php foreach ($fileNames as $name): ?>
          <option value="<?= h($name) ?>"><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="muted">맨 뒤에 붙습니다. 저장한 뒤 ▲▼ 로 자리를 옮기세요.</p>
  </fieldset>

  <div class="sticky-save">
    <button type="submit" class="primary">저장하고 푸시</button>
  </div>
</form>

<script>
// ▲▼ 는 순번 값만 바꾼다. 스크립트가 막혀 있어도 저장은 그대로 동작한다.
document.getElementById('icon-form').addEventListener('click', function (ev) {
  var btn = ev.target.closest('.mv');
  if (!btn) return;
  var card = btn.closest('.card');
  var dir  = Number(btn.dataset.dir);
  var next = dir < 0 ? card.previousElementSibling : card.nextElementSibling;
  if (!next || !next.classList.contains('card')) return;

  var a = card.querySelector('.seq'), b = next.querySelector('.seq');
  var t = a.value; a.value = b.value; b.value = t;
  if (dir < 0) card.parentNode.insertBefore(card, next);
  else card.parentNode.insertBefore(next, card);

  card.parentNode.querySelectorAll('.card .pos').forEach(function (el, i) {
    el.textContent = i + 1;
  });
});
</script>
<?php layout_footer(); ?>
