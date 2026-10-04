(() => {
  'use strict';
  document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
  document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    const input = document.getElementById(button.dataset.copy);
    try { await navigator.clipboard.writeText(input.value); document.getElementById('copy-status').textContent = 'Link copiado. Envie em conversa privada ao jogador indicado.'; }
    catch { input.focus(); input.select(); document.getElementById('copy-status').textContent = 'Selecione e copie o link no campo acima.'; }
  }));
  const form = document.getElementById('confirmation-sign');
  if (!form) return;
  const canvas = form.querySelector('canvas'), ctx = canvas.getContext('2d'), status = document.getElementById('signature-status');
  let strokes = [], active = null, pointer = null, count = 0;
  const point = e => { const r = canvas.getBoundingClientRect(); return [Math.round(Math.max(0, Math.min(1000, (e.clientX-r.left)*1000/r.width))), Math.round(Math.max(0, Math.min(500, (e.clientY-r.top)*500/r.height)))]; };
  canvas.addEventListener('pointerdown', e => {
    if (active || (e.pointerType === 'mouse' && e.button !== 0)) return;
    if (count >= 3900 || strokes.length >= 100) { status.textContent = 'Limpe a assinatura e tente novamente com um traço mais curto.'; return; }
    e.preventDefault(); pointer=e.pointerId; canvas.setPointerCapture(pointer); active=[point(e)]; strokes.push(active); count++;
  });
  canvas.addEventListener('pointermove', e => {
    if (!active || pointer!==e.pointerId || count>=4000) return;
    const next=point(e), last=active[active.length-1]; ctx.strokeStyle='#123e32'; ctx.lineWidth=4; ctx.lineCap='round';
    ctx.beginPath(); ctx.moveTo(...last); ctx.lineTo(...next); ctx.stroke(); active.push(next); count++;
  });
  const end=e=>{ if (!active || pointer!==e.pointerId) return; if (active.length<2) { strokes.pop(); count--; } active=null; form.elements.signature.value=JSON.stringify(strokes); };
  canvas.addEventListener('pointerup',end); canvas.addEventListener('pointercancel',end);
  form.querySelector('[data-clear-signature]').addEventListener('click',()=>{strokes=[];active=null;count=0;ctx.clearRect(0,0,1000,500);form.elements.signature.value='[]';status.textContent='Assinatura limpa. Assine novamente.';});
  form.addEventListener('submit',e=>{if(active || count<8){e.preventDefault();status.textContent='Complete sua assinatura antes de salvar.';}});
})();
