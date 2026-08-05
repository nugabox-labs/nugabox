<?php
declare(strict_types=1);

require_once __DIR__ . '/include/bootstrap.php';
admin_require_login();

$PAGE_TITLE   = '파일 관리';
$PAGE_KEY     = 'files';
$PAGE_SCRIPTS = ['/admin/assets/js/files.js'];

$maxUpload = effective_max_upload();
$baseUrl   = rtrim((string) cfg('public_base_url', ''), '/')
    . '/' . trim((string) cfg('upload_url_path', '/upload'), '/') . '/';

require __DIR__ . '/include/header.php';
?>
<section class="page show">
  <div class="page-head">
    <div>
      <h1 class="page-title">파일 관리</h1>
      <p class="page-sub">
        <code><?= h($baseUrl) ?></code> 에 공개되는 파일입니다.
        업로드 · 이름변경 · 삭제하면 곧바로 git 에 커밋 · 푸시됩니다.
      </p>
    </div>
    <div class="actions d-flex gap-2 flex-wrap">
      <button class="btn-ghost" id="btnRefresh"><i class="fa-solid fa-rotate"></i> 새로고침</button>
      <button class="btn-ghost" id="btnSync"><i class="fa-solid fa-code-branch"></i> git 동기화</button>
      <button class="btn-dark-pill" id="btnPick"><i class="fa-solid fa-arrow-up-from-bracket"></i> 파일 업로드</button>
    </div>
  </div>

  <!-- 업로드 -->
  <div class="card-soft">
    <div class="ch">
      <h6>업로드</h6>
      <div class="sub">최대 <?= h(human_size($maxUpload)) ?> · 여러 개 동시 업로드 가능</div>
    </div>
    <div class="cb">
      <label class="dropzone" id="dropzone">
        <span class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
        <span class="dz-title">파일을 끌어다 놓거나 클릭해서 선택하세요</span>
        <span class="dz-sub">
          허용 확장자: <?= h(implode(', ', (array) cfg('allowed_ext', []))) ?><br>
          같은 이름이 있으면 <code>이름-1.확장자</code> 로 저장됩니다.
        </span>
        <input type="file" id="fileInput" multiple>
      </label>
      <div class="upload-queue" id="uploadQueue"></div>
    </div>
  </div>

  <!-- 목록 -->
  <div class="card-soft mt-3">
    <div class="ch">
      <h6>업로드된 파일</h6>
      <div class="sub" id="listSummary">불러오는 중…</div>
    </div>
    <div class="cb p-0">
      <div class="table-wrap">
        <table class="adm">
          <thead>
            <tr>
              <th>파일</th>
              <th class="col-hide-sm">크기</th>
              <th class="col-hide-sm">수정일</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="fileRows">
            <tr><td colspan="4" class="text-center text-muted py-4">불러오는 중…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <p class="hint-text">
    파일 조작 직후 <code>upload 파일 업로드</code> 메시지로 커밋하고 origin/<?= h((string) (cfg('git')['branch'] ?? 'main')) ?> 로 push 합니다.
    push 가 실패하면 파일은 서버에 남아 있으니, 원인을 해결한 뒤 <b>git 동기화</b> 버튼으로 다시 시도하세요.
  </p>
</section>

<!-- 이름 변경 모달 -->
<div class="modal-bd" id="modal-rename">
  <div class="modal-card">
    <div class="modal-h">
      <h3>이름 변경</h3>
      <button class="close-btn" data-close-modal="">닫기</button>
    </div>
    <div class="modal-b">
      <div class="fg">
        <label for="renameInput">새 파일명</label>
        <input class="form-control" type="text" id="renameInput" autocomplete="off">
        <div class="hint">확장자까지 함께 입력하세요. URL 이 새 이름으로 바뀝니다.</div>
      </div>
    </div>
    <div class="modal-f">
      <button class="btn-ghost" data-close-modal="">취소</button>
      <button class="btn-dark-pill" id="renameConfirm"><i class="fa-solid fa-check"></i> 변경</button>
    </div>
  </div>
</div>

<!-- 삭제 확인 모달 -->
<div class="modal-bd" id="modal-delete">
  <div class="modal-card">
    <div class="modal-h">
      <h3>파일 삭제</h3>
      <button class="close-btn" data-close-modal="">닫기</button>
    </div>
    <div class="modal-b">
      <p class="mb-0" id="deleteMessage"></p>
      <p class="hint-text">삭제한 파일은 저장소에서도 함께 제거됩니다. 되돌리려면 git 이력에서 복구해야 합니다.</p>
    </div>
    <div class="modal-f">
      <button class="btn-ghost" data-close-modal="">취소</button>
      <button class="btn-dark-pill" id="deleteConfirm"><i class="fa-solid fa-trash"></i> 삭제</button>
    </div>
  </div>
</div>

<script>
  window.ADMIN = {
    csrf: <?= json_encode(csrf_token()) ?>,
    api: '/admin/api/files.php',
    maxUpload: <?= (int) $maxUpload ?>,
    maxUploadHuman: <?= json_encode(human_size($maxUpload)) ?>
  };
</script>
<?php require __DIR__ . '/include/footer.php'; ?>
