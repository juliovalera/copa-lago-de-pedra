(() => {
  'use strict';
  const open = document.querySelector('#install-open');
  const dialog = document.querySelector('#install-dialog');
  if (!open || !dialog) return;
  const confirm = document.querySelector('#install-confirm');
  const status = document.querySelector('#install-status');
  const standalone = matchMedia('(display-mode: standalone)');
  let promptEvent = null, installed = navigator.standalone === true || standalone.matches;
  const address = document.querySelector('#install-address');
  address.value = new URL('index.php', location.href).href;
  const isIOS = /iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  document.querySelector(isIOS ? '#install-ios' : /Android/i.test(navigator.userAgent) ? '#install-android' : '#install-other').open = true;
  function refresh() {
    open.hidden = installed;
    document.querySelector('#install-banner').hidden = installed;
    confirm.hidden = !promptEvent || installed;
    if (installed) status.textContent = 'O acesso pelo ícone está pronto. Procure Copa Lago de Pedra no seu aparelho.';
  }
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    promptEvent = event;
    refresh();
  });
  window.addEventListener('appinstalled', () => { installed = true; promptEvent = null; refresh(); });
  standalone.addEventListener('change', event => { if (event.matches) { installed = true; refresh(); } });
  open.addEventListener('click', () => {
    status.textContent = promptEvent ? 'Seu navegador permite instalar. Toque em Instalar agora e confirme no celular.' : 'Siga as instruções abaixo para colocar o ícone no seu aparelho.';
    dialog.showModal();
    document.querySelector('#install-close').focus();
  });
  document.querySelector('#install-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { if (!open.hidden) open.focus(); });
  confirm.addEventListener('click', async () => {
    if (!promptEvent) return;
    const event = promptEvent;
    promptEvent = null;
    confirm.disabled = true;
    try {
      await event.prompt();
      const choice = await event.userChoice;
      status.textContent = choice.outcome === 'accepted'
        ? 'Pedido de instalação aceito. Aguarde o celular concluir e procure o ícone Copa Lago de Pedra.'
        : 'Você pode instalar depois. O site continua funcionando normalmente.';
    } catch {
      status.textContent = 'Não foi possível abrir a instalação. Use as instruções abaixo.';
    } finally { confirm.disabled = false; refresh(); }
  });
  document.querySelector('#install-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(address.value);
      status.textContent = 'Endereço copiado. Abra o Chrome ou Safari e cole na barra de endereço.';
    } catch {
      address.focus(); address.select();
      status.textContent = 'O endereço está selecionado. Toque e segure para copiar.';
    }
  });
  refresh();
})();
