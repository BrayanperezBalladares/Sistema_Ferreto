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
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' || e.key === 'Esc') {
    const activeModal = document.querySelector('.modal.is-active');
    if (activeModal) {
      closeModal(activeModal);
    }
  }
});
