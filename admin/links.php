<?php
/**
 * 주소 연결 — /blog → 티스토리 처럼 바깥으로 보내는 경로를 관리한다.
 *
 * 연결 목록은 data/redirects.json 한 곳에만 있다. 다만 nginx 설정을 건드릴 수 없어서
 * /blog 라는 주소가 열리려면 실제 폴더가 있어야 하므로, 저장할 때 각 slug 폴더에
 * _redirect.php 를 불러오는 한 줄짜리 index.php 를 자동으로 만들고 지운다.
 */
declare(strict_types=1);

require_once __DIR__ . '/include/layout.php';

admin_require_login();
csrf_guard();

$SELF = '/admin/links.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rows   = (array) ($_POST['link'] ?? []);
    $errors = [];
    $list   = [];
    $seen   = [];

    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['delete'])) {
            continue;
        }
        $slugRaw = trim((string) ($row['slug'] ?? ''));
        $url     = trim((string) ($row['url'] ?? ''));

        if ($slugRaw === '' && $url === '') {
            continue;   // 새 항목 칸을 안 쓴 경우
        }
        $slug = redirect_slug_clean($slugRaw);
        if ($slug === null) {
            $errors[] = "'{$slugRaw}' 는 주소로 쓸 수 없습니다. "
                . '영문 소문자·숫자·_·- 만 되고, admin·assets·data·include·upload 는 쓸 수 없습니다.';
            continue;
        }
        if (!url_valid($url)) {
            $errors[] = "/{$slug} 의 연결 주소가 올바르지 않습니다: {$url}";
            continue;
        }
        if (isset($seen[$slug])) {
            $errors[] = "주소가 중복됩니다: /{$slug}";
            continue;
        }
        $seen[$slug] = true;
        $list[] = ['slug' => $slug, 'url' => $url, 'note' => trim((string) ($row['note'] ?? ''))];
    }

    if ($errors) {
        flash_redirect($SELF, 'fail', implode(' / ', $errors));
    }
    if (!redirects_save($list)) {
        flash_redirect($SELF, 'fail', 'data/redirects.json 을 쓰지 못했습니다. 쓰기 권한을 확인해 주세요.');
    }

    $sync = redirects_sync_stubs($list);
    if ($sync['errors']) {
        flash_redirect($SELF, 'fail', '폴더를 만들거나 지우지 못했습니다: ' . implode(' / ', $sync['errors']));
    }

    $note = [];
    if ($sync['changed']) {
        $note[] = '폴더 생성 ' . implode(', ', $sync['changed']);
    }
    if ($sync['removed']) {
        $note[] = '폴더 삭제 ' . implode(', ', $sync['removed']);
    }

    // 스테이징 대상은 명확히 짚는다. '.' 로 싸잡으면 관계없는 변경까지 커밋된다.
    // 지워진 폴더도 경로로 넘겨야 삭제가 커밋에 담긴다.
    $paths = array_merge(
        ['data'],
        array_map(static fn(array $r): string => $r['slug'], $list),
        $sync['removed']
    );

    save_and_sync(
        $SELF,
        $paths,
        '주소 연결을 수정한다.',
        '주소 연결을 저장했습니다' . ($note ? ' (' . implode(' · ', $note) . ')' : '')
    );
}

/* ── 화면 ──────────────────────────────────────────────────── */

$links = redirects_load();
$csrf  = csrf_token();
?>
<?php layout_header('주소 연결', 'links'); ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="pagehead">
    <h1>주소 연결</h1>
    <button type="submit" class="primary">저장하고 푸시</button>
  </div>
  <p class="muted">
    <code>nugabox.com/블로그주소</code> 로 들어오면 연결 주소로 보냅니다. 목록은 이 화면
    한 곳에서만 관리하고, 저장할 때 필요한 폴더가 자동으로 만들어지고 지워집니다.
  </p>

  <table class="linklist">
    <thead>
      <tr><th>주소</th><th>연결할 곳</th><th>메모</th><th></th></tr>
    </thead>
    <tbody>
    <?php $k = 0; foreach ($links as $link): $k++; ?>
      <tr>
        <td class="slugcell">
          <span class="muted">/</span>
          <input type="text" name="link[<?= $k ?>][slug]" value="<?= h($link['slug']) ?>"
                 pattern="[a-z0-9][a-z0-9_\-]*" required>
        </td>
        <td><input type="text" class="wide" name="link[<?= $k ?>][url]" value="<?= h($link['url']) ?>" required></td>
        <td><input type="text" name="link[<?= $k ?>][note]" value="<?= h($link['note']) ?>"></td>
        <td class="actions">
          <a href="/<?= h($link['slug']) ?>/" target="_blank" rel="noopener">열기</a>
          <label class="inline del"><input type="checkbox" name="link[<?= $k ?>][delete]" value="1"> 삭제</label>
        </td>
      </tr>
    <?php endforeach; ?>
      <tr class="newrow-tr">
        <?php $n = 9999; ?>
        <td class="slugcell">
          <span class="muted">/</span>
          <input type="text" name="link[<?= $n ?>][slug]" placeholder="새 주소" pattern="[a-z0-9][a-z0-9_\-]*">
        </td>
        <td><input type="text" class="wide" name="link[<?= $n ?>][url]" placeholder="https://…"></td>
        <td><input type="text" name="link[<?= $n ?>][note]" placeholder="메모"></td>
        <td class="muted">새 항목</td>
      </tr>
    </tbody>
  </table>

  <div class="sticky-save">
    <button type="submit" class="primary">저장하고 푸시</button>
  </div>
</form>
<?php layout_footer(); ?>
