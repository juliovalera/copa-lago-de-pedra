(() => {
  'use strict';
  const container = document.getElementById('sumula-qr');
  const status = document.getElementById('sumula-qr-status');
  const printButton = document.getElementById('print-sumula');
  if (!container || !status || !printButton) return;
  try {
    qrcode.stringToBytes = text => Array.from(new TextEncoder().encode(text));
    const code = qrcode(0, 'M');
    code.addData(container.dataset.url, 'Byte');
    code.make();
    // Four white modules on each side form the quiet zone needed by scanners.
    container.innerHTML = code.createSvgTag({cellSize: 1, margin: 4, scalable: true});
    const svg = container.querySelector('svg');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    container.dataset.ready = 'true';
    status.hidden = true;
    printButton.disabled = false;
    printButton.addEventListener('click', () => window.print());
  } catch {
    status.textContent = 'Não foi possível gerar o QR Code. Recarregue a página antes de imprimir.';
    status.setAttribute('role', 'alert');
  }
})();
