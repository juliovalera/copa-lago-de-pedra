(() => {
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
