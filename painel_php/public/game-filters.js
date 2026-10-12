(() => {
  document.querySelectorAll('.game-form').forEach(form => {
    const a = form.elements.score_a, b = form.elements.score_b, date = form.elements.played_at;
    if (!a || !b || !date) return;
    const validateDate = () => {
      const needsDate = a.value !== '' || b.value !== '';
      date.required = needsDate;
      date.setCustomValidity(needsDate && !date.value ? 'Preencha a data do jogo antes de salvar.' : '');
    };
    [a, b, date].forEach(input => {
      input.addEventListener('input', validateDate);
      input.addEventListener('change', validateDate);
    });
    form.addEventListener('submit', event => {
      validateDate();
      if (!date.checkValidity()) { event.preventDefault(); date.reportValidity(); date.focus(); }
    });
    validateDate();
  });
  const player = document.querySelector('#player');
  const opponent = document.querySelector('#opponent');
  if (!player || !opponent) return;
  const sync = () => {
    for (const [select, peer] of [[player, opponent], [opponent, player]]) {
      for (const option of select.options) option.disabled = !!option.value && option.value === peer.value;
    }
  };
  player.addEventListener('change', sync);
  opponent.addEventListener('change', sync);
  sync();
})();
