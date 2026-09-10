document.addEventListener('htmx:beforeSwap', (event) => {
  if (event.detail.xhr.status === 422) event.detail.shouldSwap = true;
});

document.addEventListener('notification', (event) => {
  const target = document.querySelector('[data-notification]');
  if (target) target.textContent = event.detail.message;
});

let lastActiveTrigger = null;

function openModal(modal, trigger) {
  if (!modal) return;
  lastActiveTrigger = trigger || null;

  if (modal.id === 'modal-price' && trigger) {
    const nameEl = document.getElementById('modal-price-product-name');
    const priceEl = document.getElementById('modal-price-current-value');
    const inputEl = document.getElementById('modal-price-input');
    const formEl = document.getElementById('form-update-price');

    if (nameEl) nameEl.textContent = trigger.getAttribute('data-product-name') || '';
    if (priceEl) priceEl.textContent = trigger.getAttribute('data-product-price') || '';
    if (inputEl) inputEl.value = trigger.getAttribute('data-product-price') || '';
    if (formEl) formEl.action = '/products/' + (trigger.getAttribute('data-product-id') || '') + '/price';
  }

  if (modal.id === 'modal-deactivate' && trigger) {
    const nameEl = document.getElementById('modal-deactivate-product-name');
    const formEl = document.getElementById('form-deactivate-product');

    if (nameEl) nameEl.textContent = trigger.getAttribute('data-product-name') || '';
    if (formEl) formEl.action = '/products/' + (trigger.getAttribute('data-product-id') || '') + '/deactivate';
  }

  if (modal.id === 'modal-activate' && trigger) {
    const nameEl = document.getElementById('modal-activate-product-name');
    const formEl = document.getElementById('form-activate-product');

    if (nameEl) nameEl.textContent = trigger.getAttribute('data-product-name') || '';
    if (formEl) formEl.action = '/products/' + (trigger.getAttribute('data-product-id') || '') + '/activate';
  }


  modal.classList.add('is-active');
  const focusTarget = modal.querySelector('input:not([type="hidden"]), textarea, select, button[type="submit"]');
  if (focusTarget) focusTarget.focus();
}

function closeModal(modal) {
  if (!modal) return;
  modal.classList.remove('is-active');
  if (lastActiveTrigger && typeof lastActiveTrigger.focus === 'function') {
    lastActiveTrigger.focus();
  }
}

document.addEventListener('click', (e) => {
  const openTrigger = e.target.closest('[data-modal-open]');
  if (openTrigger) {
    e.preventDefault();
    const modalId = openTrigger.getAttribute('data-modal-open');
    const modal = document.getElementById(modalId);
    openModal(modal, openTrigger);
    return;
  }

  const closeTrigger = e.target.closest('[data-modal-close]');
  if (closeTrigger) {
    e.preventDefault();
    const modal = closeTrigger.closest('.modal');
    closeModal(modal);
    return;
  }

  const navToggle = e.target.closest('#nav-toggle');
  if (navToggle) {
    e.preventDefault();
    const sidebar = document.getElementById('app-sidebar');
    if (sidebar && sidebar.classList.contains('is-open')) {
      closeDrawer();
    } else {
      openDrawer();
    }
    return;
  }

  const drawerClose = e.target.closest('[data-drawer-close]');
  if (drawerClose) {
    e.preventDefault();
    closeDrawer();
    return;
  }
});

function openDrawer() {
  const sidebar = document.getElementById('app-sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  const navToggle = document.getElementById('nav-toggle');
  if (!sidebar) return;
  sidebar.classList.add('is-open');
  if (backdrop) backdrop.classList.add('is-active');
  if (navToggle) navToggle.setAttribute('aria-expanded', 'true');
  document.body.classList.add('drawer-open');
  const firstItem = sidebar.querySelector('a.nav-item, [data-drawer-close]');
  if (firstItem && typeof firstItem.focus === 'function') {
    firstItem.focus();
  }
}

function closeDrawer() {
  const sidebar = document.getElementById('app-sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  const navToggle = document.getElementById('nav-toggle');
  if (!sidebar) return;
  sidebar.classList.remove('is-open');
  if (backdrop) backdrop.classList.remove('is-active');
  if (navToggle) {
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.focus();
  }
  document.body.classList.remove('drawer-open');
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' || e.key === 'Esc') {
    const sidebar = document.getElementById('app-sidebar');
    if (sidebar && sidebar.classList.contains('is-open')) {
      closeDrawer();
      return;
    }
    const activeModal = document.querySelector('.modal.is-active');
    if (activeModal) {
      closeModal(activeModal);
    }
  }
});

window.addEventListener('resize', () => {
  if (window.innerWidth >= 1024) {
    const sidebar = document.getElementById('app-sidebar');
    if (sidebar && sidebar.classList.contains('is-open')) {
      closeDrawer();
    }
  }
});
