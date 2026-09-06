document.addEventListener('htmx:beforeSwap', (event) => {
  if (event.detail.xhr.status === 422) event.detail.shouldSwap = true;
});

document.addEventListener('notification', (event) => {
  const target = document.querySelector('[data-notification]');
  if (target) target.textContent = event.detail.message;
});
