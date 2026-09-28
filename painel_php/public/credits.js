(() => {
  const dialog = document.getElementById('copa-credits');
  if (!dialog) return;
  let trigger;
  document.querySelectorAll('[data-copa-credits]').forEach(button => {
    button.addEventListener('click', () => {
      trigger = button;
      dialog.showModal();
    });
  });
  dialog.querySelectorAll('[data-close-credits]').forEach(button => {
    button.addEventListener('click', () => dialog.close());
  });
  dialog.addEventListener('click', event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right ||
        event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
  });
  dialog.addEventListener('close', () => trigger?.focus());
})();
