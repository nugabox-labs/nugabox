/* ============================================================
   files.js — 파일 관리 페이지 (업로드 · 이름변경 · 삭제)
   ============================================================ */

const API = window.ADMIN.api;
const CSRF = window.ADMIN.csrf;

const fileRows = document.getElementById('fileRows');
const listSummary = document.getElementById('listSummary');
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');
const uploadQueue = document.getElementById('uploadQueue');

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];
const EXT_ICON = {
  pdf: 'fa-file-pdf', zip: 'fa-file-zipper', txt: 'fa-file-lines', md: 'fa-file-lines',
  csv: 'fa-file-csv', json: 'fa-file-code', xml: 'fa-file-code',
  doc: 'fa-file-word', docx: 'fa-file-word', hwp: 'fa-file-word', hwpx: 'fa-file-word',
  xls: 'fa-file-excel', xlsx: 'fa-file-excel', ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint',
  mp3: 'fa-file-audio', wav: 'fa-file-audio', mp4: 'fa-file-video', webm: 'fa-file-video', mov: 'fa-file-video'
};

/* ── 공통 ─────────────────────────────────────────────────── */
function postForm(data) {
  data.append('csrf', CSRF);
  return fetch(API, { method: 'POST', body: data, credentials: 'same-origin' })
    .then(async (res) => {
      const json = await res.json().catch(() => ({ ok: false, error: `서버 응답을 읽지 못했습니다 (HTTP ${res.status})` }));
      if (res.status === 401) {
        toast('세션이 만료되었습니다. 다시 로그인해 주세요.', 'error');
        setTimeout(() => (location.href = '/admin/login.php'), 1200);
      }
      return json;
    });
}

/** git 결과를 토스트로 알린다. */
function reportGit(git) {
  if (!git) return;
  if (!git.ok) {
    toast('파일은 저장했지만 git 동기화에 실패했습니다: ' + git.message, 'error', 9000);
    if (git.log) console.warn('[admin] git log\n' + git.log);
    return;
  }
  if (git.pushed) toast(`git 커밋 · 푸시 완료 (${git.sha || 'HEAD'})`, 'ok');
  else if (git.message) toast(git.message, 'info');
}

/* ── 목록 ─────────────────────────────────────────────────── */
const escapeHtml = (s) =>
  String(s).replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));

/** 화면에는 사람이 읽을 수 있게, 링크에는 인코딩된 URL 을 쓴다. */
function prettyUrl(url) {
  try {
    return decodeURI(url);
  } catch (e) {
    return url;
  }
}

function thumbHtml(f) {
  if (IMAGE_EXT.includes(f.ext)) {
    return `<span class="file-thumb"><img src="${f.url}" alt="" loading="lazy"></span>`;
  }
  const icon = EXT_ICON[f.ext] || 'fa-file';
  return `<span class="file-thumb"><i class="fa-solid ${icon}"></i></span>`;
}

function rowHtml(f) {
  const name = escapeHtml(f.name);
  return `<tr data-name="${name}">
    <td>
      <div class="file-cell">
        ${thumbHtml(f)}
        <div class="file-meta">
          <div class="fname">${name}</div>
          <a class="furl" href="${escapeHtml(f.url)}" target="_blank" rel="noopener">${escapeHtml(prettyUrl(f.url))}</a>
        </div>
      </div>
    </td>
    <td class="col-hide-sm col-num">${f.size_human}</td>
    <td class="col-hide-sm col-num">${f.modified}</td>
    <td>
      <div class="file-actions">
        <button class="btn-ghost" data-act="copy" title="URL 복사"><i class="fa-regular fa-copy"></i> URL</button>
        <button class="btn-ghost" data-act="rename" title="이름 변경"><i class="fa-solid fa-pen"></i></button>
        <button class="btn-ghost" data-act="delete" title="삭제"><i class="fa-solid fa-trash"></i></button>
      </div>
    </td>
  </tr>`;
}

async function loadList() {
  try {
    const res = await fetch(API + '?action=list', { credentials: 'same-origin' });
    if (res.status === 401) {
      location.href = '/admin/login.php';
      return;
    }
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || '목록을 불러오지 못했습니다.');

    fileRows.innerHTML = data.files.length
      ? data.files.map(rowHtml).join('')
      : '<tr><td colspan="4" class="text-center text-muted py-5">아직 업로드된 파일이 없습니다.</td></tr>';

    const git = data.git || {};
    const gitLabel = git.enabled ? `${git.branch} · ${git.last_commit}` : 'git 동기화 꺼짐';
    listSummary.textContent = `총 ${data.count}개 · ${data.total_size} — ${gitLabel}`;
  } catch (err) {
    fileRows.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-5">${err.message}</td></tr>`;
    listSummary.textContent = '목록을 불러오지 못했습니다.';
  }
}

/* ── 업로드 ───────────────────────────────────────────────── */
function queueItem(fileName) {
  const el = document.createElement('div');
  el.className = 'uq-item';
  el.innerHTML = `
    <span class="uq-ic"><i class="fa-solid fa-arrow-up"></i></span>
    <span class="uq-body"><span class="uq-name"></span></span>
    <span class="uq-bar"><i></i></span>`;
  el.querySelector('.uq-name').textContent = fileName;
  uploadQueue.appendChild(el);

  const finish = (state, icon, label, message) => {
    el.classList.add(state);
    el.querySelector('.uq-ic i').className = 'fa-solid ' + icon;
    el.querySelector('.uq-bar').outerHTML = `<span class="col-num">${label}</span>`;
    const msg = document.createElement('span');
    msg.className = 'uq-msg';
    msg.textContent = message;
    el.querySelector('.uq-body').appendChild(msg);
  };

  return {
    progress(pct) {
      el.querySelector('.uq-bar > i').style.width = pct + '%';
    },
    done(url) {
      finish('done', 'fa-check', '완료', prettyUrl(url));
    },
    fail(message) {
      finish('fail', 'fa-xmark', '실패', message);
    }
  };
}

/** XHR 로 올려 진행률을 보여준다. (fetch 는 업로드 진행률을 못 준다) */
function uploadOne(file) {
  const ui = queueItem(file.name);

  if (file.size > window.ADMIN.maxUpload) {
    ui.fail(`파일이 너무 큽니다. 최대 ${window.ADMIN.maxUploadHuman}`);
    return Promise.resolve(null);
  }

  return new Promise((resolve) => {
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('csrf', CSRF);
    fd.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API);
    xhr.withCredentials = true;
    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) ui.progress(Math.round((e.loaded / e.total) * 100));
    });
    xhr.addEventListener('load', () => {
      let data;
      try {
        data = JSON.parse(xhr.responseText);
      } catch (e) {
        ui.fail(`서버 응답을 읽지 못했습니다 (HTTP ${xhr.status})`);
        return resolve(null);
      }
      if (xhr.status === 401) {
        ui.fail('세션이 만료되었습니다.');
        setTimeout(() => (location.href = '/admin/login.php'), 1200);
        return resolve(null);
      }
      if (!data.ok) {
        ui.fail(data.error || '업로드에 실패했습니다.');
        return resolve(null);
      }
      ui.done(data.url);
      resolve(data);
    });
    xhr.addEventListener('error', () => {
      ui.fail('네트워크 오류로 업로드하지 못했습니다.');
      resolve(null);
    });
    xhr.send(fd);
  });
}

/** 파일을 하나씩 순차 업로드한다 — git 커밋이 직렬화되어야 하므로. */
async function uploadFiles(files) {
  let lastGit = null;
  let okCount = 0;
  for (const file of files) {
    const res = await uploadOne(file);
    if (res) {
      okCount++;
      lastGit = res.git;
    }
  }
  if (okCount) {
    toast(`${okCount}개 파일 업로드 완료`, 'ok');
    reportGit(lastGit);
    await loadList();
  }
}

dropzone.addEventListener('dragover', (e) => {
  e.preventDefault();
  dropzone.classList.add('drag');
});
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag'));
dropzone.addEventListener('drop', (e) => {
  e.preventDefault();
  dropzone.classList.remove('drag');
  if (e.dataTransfer.files.length) uploadFiles([...e.dataTransfer.files]);
});
fileInput.addEventListener('change', () => {
  if (fileInput.files.length) uploadFiles([...fileInput.files]);
  fileInput.value = '';
});
document.getElementById('btnPick').addEventListener('click', () => fileInput.click());

/* ── 행 액션 ──────────────────────────────────────────────── */
let pendingName = null;

fileRows.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-act]');
  if (!btn) return;
  const row = btn.closest('tr');
  const name = row.dataset.name;
  const url = row.querySelector('.furl').href;

  if (btn.dataset.act === 'copy') {
    navigator.clipboard.writeText(url)
      .then(() => toast('URL 을 복사했습니다.', 'ok', 2000))
      .catch(() => toast('복사에 실패했습니다: ' + url, 'error'));
    return;
  }
  if (btn.dataset.act === 'rename') {
    pendingName = name;
    const input = document.getElementById('renameInput');
    input.value = name;
    openModal('rename');
    input.focus();
    input.setSelectionRange(0, name.lastIndexOf('.') > 0 ? name.lastIndexOf('.') : name.length);
    return;
  }
  if (btn.dataset.act === 'delete') {
    pendingName = name;
    document.getElementById('deleteMessage').textContent = `"${name}" 을(를) 삭제할까요?`;
    openModal('delete');
  }
});

document.getElementById('renameConfirm').addEventListener('click', async () => {
  const newName = document.getElementById('renameInput').value.trim();
  if (!newName || !pendingName) return;

  const fd = new FormData();
  fd.append('action', 'rename');
  fd.append('name', pendingName);
  fd.append('new_name', newName);

  const data = await postForm(fd);
  if (!data.ok) {
    toast(data.error || '이름을 바꾸지 못했습니다.', 'error');
    return;
  }
  closeAllModals();
  toast(`이름을 "${data.name}" 으로 바꿨습니다.`, 'ok');
  reportGit(data.git);
  loadList();
});

document.getElementById('deleteConfirm').addEventListener('click', async () => {
  if (!pendingName) return;

  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('name', pendingName);

  const data = await postForm(fd);
  if (!data.ok) {
    toast(data.error || '삭제하지 못했습니다.', 'error');
    return;
  }
  closeAllModals();
  toast(`${data.deleted.join(', ')} 삭제 완료`, 'ok');
  reportGit(data.git);
  loadList();
});

/* ── 툴바 ─────────────────────────────────────────────────── */
document.getElementById('btnRefresh').addEventListener('click', loadList);

document.getElementById('btnSync').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'sync');
  const data = await postForm(fd);
  btn.disabled = false;
  reportGit(data.git);
  if (!data.git) toast(data.error || '동기화에 실패했습니다.', 'error');
  loadList();
});

loadList();
