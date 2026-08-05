/* ============================================================
   admin.js — 관리자 공통 스크립트 (테마 · 사이드바 · 모달 · 토스트)
   페이지별 로직은 files.js 등 별도 파일에 작성한다.
   ============================================================ */

/* ── 테마 토글 ──────────────────────────────────────────────── */
const themeBtn = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');

function setAdminTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  if (themeIcon) themeIcon.className = t === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
  try { localStorage.setItem('admin-theme', t); } catch (e) {}
}
themeBtn?.addEventListener('click', () => {
  const cur = document.documentElement.getAttribute('data-theme') || 'light';
  setAdminTheme(cur === 'dark' ? 'light' : 'dark');
});
try {
  const saved = localStorage.getItem('admin-theme');
  if (saved) setAdminTheme(saved);
} catch (e) {}

/* ── 사이드바 토글 ──────────────────────────────────────────── */
const sb = document.getElementById('sidebar');
const sc = document.getElementById('scrim');
const appEl = document.getElementById('adminApp') || document.querySelector('.app');
const sideToggle = document.getElementById('sideToggle');

sideToggle?.addEventListener('click', () => {
  if (window.matchMedia('(max-width: 768px)').matches) {
    sb?.classList.add('open');
    sc?.classList.add('show');
  } else {
    appEl?.classList.toggle('collapsed');
  }
});
sc?.addEventListener('click', () => {
  sb?.classList.remove('open');
  sc?.classList.remove('show');
});

/* ── 모달 ─────────────────────────────────────────────────── */
function openModal(id) {
  const el = document.getElementById('modal-' + id);
  if (el) {
    el.classList.add('show');
    document.body.style.overflow = 'hidden';
    el.querySelector('input, textarea, select, button')?.focus();
  }
}
function closeModal(el) {
  el.classList.remove('show');
  if (!document.querySelector('.modal-bd.show')) document.body.style.overflow = '';
}
function closeAllModals() {
  document.querySelectorAll('.modal-bd.show').forEach(closeModal);
}

document.addEventListener('click', (e) => {
  const trigger = e.target.closest('[data-open-modal]');
  if (trigger) {
    e.preventDefault();
    openModal(trigger.dataset.openModal);
    return;
  }
  if (e.target.closest('[data-close-modal]')) {
    const m = e.target.closest('.modal-bd');
    if (m) closeModal(m);
    return;
  }
  if (e.target.classList.contains('modal-bd')) closeModal(e.target);
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeAllModals();
});

/* ── 세그먼트 토글 ────────────────────────────────────────── */
document.querySelectorAll('.seg').forEach((seg) => {
  seg.querySelectorAll('button').forEach((b) => {
    b.addEventListener('click', () => {
      seg.querySelectorAll('button').forEach((x) => x.classList.remove('on'));
      b.classList.add('on');
    });
  });
});

/* ── 토스트 ───────────────────────────────────────────────── */
function toast(message, kind = 'info', timeout = 4000) {
  let stack = document.getElementById('toastStack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'toastStack';
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
  }
  const icons = { ok: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
  const el = document.createElement('div');
  el.className = 'toast-item toast-' + kind;
  el.innerHTML = `<i class="fa-solid ${icons[kind] || icons.info}"></i><span></span>`;
  el.querySelector('span').textContent = message;
  stack.appendChild(el);
  requestAnimationFrame(() => el.classList.add('in'));
  setTimeout(() => {
    el.classList.remove('in');
    setTimeout(() => el.remove(), 250);
  }, timeout);
}
