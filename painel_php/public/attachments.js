(() => {
  'use strict';
  const form = document.querySelector('.attachment-upload');
  if (!form) return;
  const input = form.querySelector('input[type=file]');
  const button = form.querySelector('button[value=upload]');
  const status = document.querySelector('#attachment-preparation');
  const preview = document.querySelector('#attachment-preview');
  const previewImage = preview.querySelector('img');
  const previewLink = preview.querySelector('a');
  const limit = 2 * 1024 * 1024;
  let generation = 0, busy = false, previewUrl = '';
  const size = bytes => (bytes / 1024 / 1024).toLocaleString('pt-BR', {maximumFractionDigits: 2}) + ' MB';
  function clearPreview() {
    preview.hidden = true;
    previewImage.removeAttribute('src');
    previewLink.removeAttribute('href');
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = '';
  }
  async function loadImage(file) {
    const url = URL.createObjectURL(file);
    try {
      const image = new Image();
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error('Não foi possível abrir esta foto. Escolha outra imagem JPG ou PNG.'));
        image.src = url;
      });
      return image;
    } finally { URL.revokeObjectURL(url); }
  }
  async function compress(image) {
    const longest = Math.max(image.naturalWidth, image.naturalHeight);
    const canvas = document.createElement('canvas');
    try {
      for (const edge of [3200, 2600, 2000, 1600]) {
        const scale = Math.min(1, edge / longest);
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Este navegador não conseguiu preparar a foto. Tente outro navegador ou uma imagem menor.');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        for (const quality of [0.9, 0.82, 0.74, 0.66, 0.58]) {
          const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
          if (blob && blob.type === 'image/jpeg' && blob.size > 0 && blob.size <= limit) return blob;
        }
      }
      throw new Error('Não foi possível reduzir a foto para 2 MB. Escolha outra foto, mais próxima da súmula e com boa iluminação.');
    } finally { canvas.width = canvas.height = 1; }
  }
  input.addEventListener('change', async () => {
    const current = ++generation;
    clearPreview();
    input.setCustomValidity('');
    const file = input.files[0];
    status.textContent = '';
    busy = false;
    button.disabled = false;
    if (!file) return;
    busy = true;
    button.disabled = true;
    status.textContent = 'Preparando arquivo… Aguarde antes de enviar.';
    try {
      const extension = file.name.split('.').pop().toLowerCase();
      if (extension === 'pdf') {
        if (file.size > limit) throw new Error('PDFs devem ter até 2 MB. A redução automática é apenas para fotos JPG e PNG.');
        status.textContent = 'PDF selecionado: ' + size(file.size) + '. Pronto para enviar.';
        return;
      }
      if (!['jpg', 'jpeg', 'png'].includes(extension)) throw new Error('Escolha uma foto JPG/PNG ou um PDF.');
      if (file.size > 40 * 1024 * 1024) throw new Error('Esta foto ultrapassa 40 MB e é muito grande para preparar aqui. Escolha outra foto.');
      const image = await loadImage(file);
      if (current !== generation) return;
      if (image.naturalWidth * image.naturalHeight > 80000000) throw new Error('Esta imagem tem dimensões muito grandes. Escolha outra foto.');
      let prepared = file;
      if (file.size > limit) {
        const blob = await compress(image);
        if (current !== generation) return;
        prepared = new File([blob], file.name.replace(/\.[^.]+$/, '') + '-reduzida.jpg', {type: 'image/jpeg'});
        try {
          const transfer = new DataTransfer();
          transfer.items.add(prepared);
          input.files = transfer.files;
          if (input.files[0]?.size !== prepared.size) throw new Error();
        } catch {
          throw new Error('Este navegador não conseguiu selecionar a foto reduzida. Tente outro navegador ou escolha uma foto de até 2 MB.');
        }
      }
      previewUrl = URL.createObjectURL(prepared);
      previewImage.src = previewUrl;
      previewLink.href = previewUrl;
      preview.hidden = false;
      status.textContent = prepared === file
        ? 'Foto de ' + size(file.size) + ': não precisou reduzir. Confira a leitura antes de enviar.'
        : 'Foto reduzida de ' + size(file.size) + ' para ' + size(prepared.size) + '. Confira os resultados e as assinaturas na prévia antes de enviar. A original permanece no seu aparelho.';
    } catch (error) {
      if (current !== generation) return;
      const message = error instanceof Error ? error.message : 'Não foi possível preparar a foto. Escolha outro arquivo.';
      input.setCustomValidity(message);
      status.textContent = message;
    } finally {
      if (current === generation) {
        busy = false;
        button.disabled = !input.validity.valid;
      }
    }
  });
  form.addEventListener('submit', event => {
    if (busy || !input.validity.valid || (input.files[0]?.size ?? 0) > limit) {
      event.preventDefault();
      if (busy) status.textContent = 'Aguarde a preparação da foto antes de enviar.';
    }
  });
})();
