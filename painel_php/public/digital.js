(() => {
  'use strict';
  document.querySelector('#digital-print')?.addEventListener('click', () => window.print());
  const form = document.querySelector('#digital-form');
  if (!form) return;
  const pads = new Map();
  const status = document.querySelector('#signature-status');
  const clear = who => {
    const p = pads.get(who); p.strokes = []; p.active = null;
    p.ctx.clearRect(0, 0, 1000, 500); p.input.value = '[]';
  };
  for (const canvas of form.querySelectorAll('canvas[data-signature]')) {
    const who = canvas.dataset.signature;
    const p = { canvas, ctx: canvas.getContext('2d'), strokes: [], active: null, input: form.elements['signature_' + who] };
    pads.set(who, p);
    const point = e => {
      const r = canvas.getBoundingClientRect();
      return [Math.round(Math.max(0, Math.min(1000, (e.clientX-r.left)*1000/r.width))), Math.round(Math.max(0, Math.min(500, (e.clientY-r.top)*500/r.height)))];
    };
    canvas.addEventListener('pointerdown', e => {
      if (p.active || (e.pointerType === 'mouse' && e.button !== 0)) return;
      if (p.strokes.length >= 100 || p.strokes.flat().length >= 3990) { status.textContent = 'Assinatura muito extensa. Limpe o campo e assine novamente.'; return; }
      e.preventDefault(); canvas.setPointerCapture(e.pointerId);
      p.pointer = e.pointerId; p.active = [point(e)]; p.strokes.push(p.active);
    });
    canvas.addEventListener('pointermove', e => {
      if (!p.active || e.pointerId !== p.pointer || p.strokes.flat().length >= 4000) return;
      const next = point(e), last = p.active[p.active.length-1];
      p.ctx.strokeStyle = '#123e32'; p.ctx.lineWidth = 4; p.ctx.lineCap = 'round';
      p.ctx.beginPath(); p.ctx.moveTo(...last); p.ctx.lineTo(...next); p.ctx.stroke(); p.active.push(next);
    });
    const end = e => {
      if (!p.active || e.pointerId !== p.pointer) return;
      if (p.active.length < 2) p.strokes.pop();
      p.active = null; p.input.value = JSON.stringify(p.strokes);
    };
    canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end);
  }
  form.querySelectorAll('[data-clear]').forEach(b => b.addEventListener('click', () => clear(b.dataset.clear)));
  const total = who => {
    const a = form.elements['first_'+who].value, b = form.elements['second_'+who].value;
    document.querySelector('#total-'+who).value = a === '' || b === '' ? '—' : Number(a)+Number(b);
  };
  form.addEventListener('input', e => {
    if (e.target.name === 'consent') return;
    if ([...pads.values()].some(p => p.strokes.length)) {
      pads.forEach((p, who) => clear(who)); form.elements.consent.checked = false;
      status.textContent = 'Os dados mudaram. Confira a ficha e colete novamente as três assinaturas.';
    }
    total('a'); total('b');
  });
  total('a'); total('b');
  const finalize = document.querySelector('#digital-final');
  finalize.disabled = finalize.dataset.available !== 'yes';
  form.addEventListener('submit', e => {
    if (e.submitter?.value !== 'final') return;
    if (!form.elements.consent.checked || [...pads.values()].some(p => p.strokes.flat().length < 8)) {
      e.preventDefault(); status.textContent = 'Colete as três assinaturas e marque a confirmação antes de finalizar.'; status.scrollIntoView({block:'center'});
    }
  });
})();
