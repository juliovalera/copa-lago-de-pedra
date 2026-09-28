(() => {
  'use strict';
  const data = window.COPA_DATA;
  const $ = (selector) => document.querySelector(selector);
  const escape = (value) => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const percentage = p => p.played ? p.points / (p.played * 3) * 100 : null;
  const formatPercentage = p => percentage(p) === null ? '—' : percentage(p).toLocaleString('pt-BR', {maximumFractionDigits:1}) + '%';
  const formatGameDate = value => value ? new Date(`${value}T12:00:00`).toLocaleDateString('pt-BR') : '';
  const initials = name => name.split(/\s+/).slice(0,2).map(n => n[0]).join('');
  const signed = value => value > 0 ? '+' + value : String(value);
  const favoriteKey = 'copa-lago-de-pedra-favorites-v2';
  const textSizeKey = 'copa-lago-de-pedra-text-size';
  const textScales = [1, 1.12, 1.25];
  let textSize = 0;
  try {
    const storedSize = Number(localStorage.getItem(textSizeKey));
    if (Number.isInteger(storedSize) && storedSize >= 0 && storedSize < textScales.length) textSize = storedSize;
  } catch {}
  function applyTextSize() {
    document.documentElement.style.setProperty('--text-zoom', textScales[textSize]);
    $('#decrease-text').disabled = textSize === 0;
    $('#increase-text').disabled = textSize === textScales.length - 1;
  }
  applyTextSize();
  $('#decrease-text').addEventListener('click', () => {
    if (textSize === 0) return;
    textSize--;
    try { localStorage.setItem(textSizeKey, textSize); } catch {}
    applyTextSize();
    toast(`Texto reduzido para ${Math.round(textScales[textSize] * 100)}%.`);
  });
  $('#increase-text').addEventListener('click', () => {
    if (textSize === textScales.length - 1) return;
    textSize++;
    try { localStorage.setItem(textSizeKey, textSize); } catch {}
    applyTextSize();
    toast(`Texto aumentado para ${Math.round(textScales[textSize] * 100)}%.`);
  });
  let saved = [];
  try {
    const stored = localStorage.getItem(favoriteKey);
    if (stored !== null) {
      const value = JSON.parse(stored);
      if (Array.isArray(value)) saved = value.filter(v => typeof v === 'string');
    } else {
      const legacy = JSON.parse(localStorage.getItem('copa-lago-de-pedra-favorites') || '[]');
      if (Array.isArray(legacy)) saved = data.players.filter(p => legacy.includes(p.name)).map(p => p.id);
      localStorage.setItem(favoriteKey, JSON.stringify(saved));
    }
  } catch {}
  const favorites = new Set(saved);
  let filter = 'all';
  let dialogPlayer = null;
  let toastTimer;
  const players = data.players;
  const leader = [...players].sort((a,b) => a.position-b.position)[0];
  const scorers = [...players].sort((a,b) => b.goalsFor-a.goalsFor || a.position-b.position);
  const bestScorers = scorers.filter(p => p.goalsFor === scorers[0].goalsFor);
  $('#competition-players').textContent = players.length;
  $('#competition-games').textContent = data.games.length;
  $('#import-date').textContent = 'Dados importados em ' + new Date(data.importedAt).toLocaleDateString('pt-BR');
  $('#summary').innerHTML = [
    ['PARTICIPANTES', players.length, `${players.filter(p => p.played > 0).length} já entraram em jogo`, '◉', false],
    ['NA LIDERANÇA', leader.name, `${leader.points} pontos · classificação recalculada`, '⚑', true],
    ['MAIS GOLS MARCADOS', bestScorers.length > 1 ? `${bestScorers.length} empatados` : bestScorers[0].name.split(' ')[0], `${scorers[0].goalsFor} gols · placares registrados`, '◎', true],
    ['PONTOS DISTRIBUÍDOS', data.totals.points, '3 por vitória · 1 por empate', '↗', false]
  ].map(([label,value,sub,symbol,name]) => `<article class="stat"><span class="stat-label">${label}</span><span class="stat-symbol" aria-hidden="true">${symbol}</span><strong class="stat-value${name?' name':''}">${escape(value)}</strong><span class="stat-sub">${escape(sub)}</span></article>`).join('');

  function toast(text) {
    $('#toast').textContent = text;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { $('#toast').textContent = ''; }, 3500);
  }
  function toggleFavorite(p) {
    if (favorites.has(p.id)) favorites.delete(p.id); else favorites.add(p.id);
    try { localStorage.setItem(favoriteKey, JSON.stringify([...favorites])); }
    catch { toast('Favoritos disponíveis apenas enquanto esta página estiver aberta.'); }
    renderRanking();
    if (dialogPlayer) renderProfile(dialogPlayer);
  }
  function currentPlayers() {
    const query = normalize($('#search').value.trim());
    const list = players.filter(p => normalize(p.name).includes(query) && (filter !== 'favorites' || favorites.has(p.id)) && (filter !== 'played' || p.played > 0));
    const sort = $('#sort').value;
    return list.sort((a,b) => {
      if (sort === 'name') return a.name.localeCompare(b.name,'pt-BR');
      if (sort === 'percentage') return (percentage(b) ?? -1) - (percentage(a) ?? -1) || a.position-b.position;
      return sort === 'position' ? a.position-b.position : b[sort]-a[sort] || a.position-b.position;
    });
  }
  function renderRanking() {
    const list = currentPlayers();
    $('#result-count').textContent = `${list.length} de ${players.length} botonistas`;
    $('#ranking-body').innerHTML = list.map(p => {
      const differenceIssue = data.issues.some(i => i.cell === `K${p.sourceRow}`);
      const flag = '<span class="flag" aria-label="Valor a conferir" title="Valor a conferir; veja o perfil">*</span>';
      return `<tr><td><span class="rank ${p.position===1?'first':p.position<=3?'top':''}">${p.position}</span></td><td><button class="player-button" data-player="${p.id}" aria-label="Ver perfil de ${escape(p.name)}"><span class="avatar" aria-hidden="true">${escape(initials(p.name))}</span><span class="player-name">${escape(p.name)}</span></button></td><td>${p.points}</td><td>${p.played}</td><td>${p.wins}</td><td>${p.draws}</td><td>${p.losses}</td><td>${p.goalsFor}</td><td>${p.goalsAgainst}</td><td>${signed(p.goalDifference)}${differenceIssue?flag:''}</td><td class="percent">${formatPercentage(p)}<span class="percent-bar" aria-hidden="true"><i style="width:${Math.min(100,Math.max(0,percentage(p) || 0))}%"></i></span></td><td><button class="fav" data-favorite="${p.id}" aria-pressed="${favorites.has(p.id)}" aria-label="${favorites.has(p.id)?'Remover':'Adicionar'} ${escape(p.name)} ${favorites.has(p.id)?'dos':'aos'} favoritos">${favorites.has(p.id)?'★':'☆'}</button></td></tr>`;
    }).join('');
    $('#empty').hidden = list.length > 0;
    $('.table-wrap').hidden = list.length === 0;
    $('#empty-message').textContent = filter === 'favorites' ? 'Marque a estrela ao lado de um nome na lista de todos, ou ajuste a busca.' : 'Tente outro nome ou remova os filtros da busca.';
  }
  $('#search').addEventListener('input', renderRanking);
  $('#sort').addEventListener('change', renderRanking);
  $('.chips').addEventListener('click', event => {
    const button = event.target.closest('[data-filter]');
    if (!button) return;
    filter = button.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach(b => { b.classList.toggle('active', b === button); b.setAttribute('aria-pressed', String(b === button)); });
    renderRanking();
  });
  $('#clear-filters').addEventListener('click', () => { $('#search').value=''; $('[data-filter="all"]').click(); $('#search').focus(); });
  $('#ranking-body').addEventListener('click', event => {
    const favorite = event.target.closest('[data-favorite]');
    const profile = event.target.closest('[data-player]');
    if (favorite) {
      const id = favorite.dataset.favorite;
      toggleFavorite(players.find(p => p.id === id));
      (document.querySelector(`[data-favorite="${id}"]`) || $('[data-filter="favorites"]')).focus();
    }
    if (profile) openProfile(players.find(p => p.id === profile.dataset.player));
  });

  function renderProfile(p) {
    const issues = data.issues.filter(i => i.player === p.name);
    const fields = [['Pontos',p.points],['Jogos',p.played],['Aproveitamento',formatPercentage(p)],['Vitórias',p.wins],['Empates',p.draws],['Derrotas',p.losses],['Gols marcados',p.goalsFor],['Gols sofridos',p.goalsAgainst],['Saldo de gols',signed(p.goalDifference)]];
    $('#player-content').innerHTML = `<h2 id="player-title">${escape(p.name)}</h2><p class="player-subtitle">${p.position}º na classificação · ${p.played ? `${p.played} jogos registrados` : 'Ainda sem jogos registrados'}</p><div class="player-stats">${fields.map(([name,value]) => `<div class="player-stat"><strong>${escape(value)}</strong><span>${name}</span></div>`).join('')}</div>${issues.length?`<div class="profile-warning"><strong>Valores a conferir</strong>${issues.map(i => `<p>${escape(i.message)}</p>`).join('')}</div>`:''}<p class="player-subtitle">${p.history.length ? `${p.history.length} posições registradas no histórico.` : 'Ainda não há resultados com data para exibir a evolução.'}</p><div class="dialog-actions"><button class="primary" id="profile-favorite" aria-pressed="${favorites.has(p.id)}">${favorites.has(p.id)?'★ Remover favorito':'☆ Adicionar favorito'}</button><button class="secondary" id="profile-games">Ver jogos</button><button class="secondary" id="profile-history">Ver evolução</button></div>`;
    $('#profile-favorite').addEventListener('click', () => { toggleFavorite(p); $('#profile-favorite').focus(); });
    $('#profile-games').addEventListener('click', () => { $('#game-player').value=p.name; $('#game-round').value=''; $('#game-status').value=''; gamePage=1; renderGames(); $('#player-dialog').close(); location.hash='jogos'; });
    $('#profile-history').addEventListener('click', () => { $('#history-player').value = p.id; renderHistory(); $('#player-dialog').close(); location.hash = 'evolucao'; });
  }
  function openProfile(p) { dialogPlayer=p; renderProfile(p); $('#player-dialog').showModal(); }
  $('#close-dialog').addEventListener('click', () => $('#player-dialog').close());
  $('#player-dialog').addEventListener('close', () => { dialogPlayer=null; });
  $('#player-dialog').addEventListener('click', event => {
    if (event.target === $('#player-dialog')) { const rect = event.target.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) event.target.close(); }
  });

  $('#history-player').innerHTML = [...players].sort((a,b)=>a.name.localeCompare(b.name,'pt-BR')).map(p => `<option value="${p.id}">${escape(p.name)}</option>`).join('');
  function renderHistory() {
    const p = players.find(p => p.id === $('#history-player').value);
    const history = [...p.history].sort((a,b) => (a.date || '').localeCompare(b.date || ''));
    const dateLabel = date => date ? date.split('-').reverse().join('/') : '';
    const warning = data.historyUndatedGames ? `<p role="note">Histórico parcial: ${data.historyUndatedGames} jogo(s) com resultado estão sem data válida e não entram neste gráfico. Eles continuam contando na classificação atual.</p>` : '';
    if (!history.length) {
      $('#history-content').innerHTML = `<div class="history-empty"><h3>A história está começando</h3><p>A evolução aparecerá quando houver resultados com a data do jogo registrada.</p>${warning}</div>`;
      return;
    }
    const x = i => history.length === 1 ? 310 : 65 + i / (history.length - 1) * 490;
    const y = position => 20 + (position-1) / Math.max(1,players.length-1) * 200;
    $('#history-content').innerHTML = `${warning}<p>Posição ao final de cada data com jogos, considerando os resultados registrados. ${history.length === 1 ? 'Esta é a primeira data registrada; novas datas mostrarão a evolução.' : ''}</p><svg class="history-chart" viewBox="0 0 620 260" role="img" aria-label="Evolução de ${escape(p.name)} por data. Posições menores ficam no alto. Valores na tabela abaixo."><path d="M45 20 V220 H575" fill="none" stroke="#ced8c3"/><text x="10" y="24">1º</text><text x="5" y="220">${players.length}º</text>${history.map((h,i) => `${i ? `<line x1="${x(i-1)}" y1="${y(history[i-1].position)}" x2="${x(i)}" y2="${y(h.position)}" stroke="#347758" stroke-width="3"/>` : ''}<circle cx="${x(i)}" cy="${y(h.position)}" r="5" fill="#123e32"><title>${escape(dateLabel(h.date))}: ${h.position}º</title></circle>`).join('')}<text x="${x(0)}" y="246" text-anchor="middle">${escape(dateLabel(history[0].date))}</text>${history.length > 1 ? `<text x="555" y="246" text-anchor="middle">${escape(dateLabel(history[history.length-1].date))}</text>` : ''}</svg><table class="history-table"><caption>Posições de ${escape(p.name)} por data dos jogos</caption><thead><tr><th scope="col">Data</th><th scope="col">Posição</th><th scope="col">Pontos</th></tr></thead><tbody>${history.map(h => `<tr><td>${escape(dateLabel(h.date))}</td><td>${h.position}º</td><td>${h.points}</td></tr>`).join('')}</tbody></table>`;
  }
  $('#history-player').addEventListener('change', renderHistory);
  let gamePage=1;
  const pageSize=26;
  $('#games-summary').textContent = `${data.games.length} jogos previstos · ${data.playedGames} com resultado · ${data.pendingGames} sem resultado`;
  $('#game-player').insertAdjacentHTML('beforeend', [...players].sort((a,b)=>a.name.localeCompare(b.name,'pt-BR')).map(p=>`<option value="${escape(p.name)}">${escape(p.name)}</option>`).join(''));
  $('#game-round').insertAdjacentHTML('beforeend',Array.from({length:50},(_,i)=>`<option value="${i+1}">Rodada ${i+1} · ${i<25?'Turno':'Returno'}</option>`).join(''));
  function renderGames() {
    const name=$('#game-player').value, round=$('#game-round').value, status=$('#game-status').value;
    const filtered=data.games.filter(g=>(!name || g.a===name || g.b===name) && (!round || g.round===Number(round)) && (!status || g.status===status));
    const pages=Math.max(1,Math.ceil(filtered.length/pageSize));
    gamePage=Math.min(gamePage,pages);
    $('#games-count').textContent=`${filtered.length} de ${data.games.length} jogos`;
    $('#games-list').innerHTML=filtered.slice((gamePage-1)*pageSize,gamePage*pageSize).map(g=>`<article class="game-card"><div class="game-meta"><span>Rodada ${g.round} · ${g.turn===1?'Turno':'Returno'} · Jogo ${g.game}${g.status==='played' ? ` · ${formatGameDate(g.playedAt)}` : ''}</span><span class="game-status ${g.status}">${g.status==='played'?'Com resultado':'Sem resultado'}</span></div><div class="game-match"><span>${escape(g.a)}</span><strong aria-label="${g.status==='played'?`${g.scoreA} a ${g.scoreB}`:'Placar não informado'}">${g.status==='played'?`${g.scoreA} <small>×</small> ${g.scoreB}`:'— <small>×</small> —'}</strong><span>${escape(g.b)}</span></div>${window.COPA_SUMULA_LOGIN && g.status==='pending' ? `<div class="sumula-controls"><button class="game-sumula" type="button" data-sumula-game="${g.databaseId}" data-sumula-title="${escape(g.a)} × ${escape(g.b)}">▦ Gerar súmula com QR</button><button class="sumula-help-button" type="button" data-sumula-help aria-expanded="false" aria-controls="sumula-help-${g.id}" aria-label="Para que serve a súmula e por que é preciso autorização?">?</button></div><div class="sumula-help" id="sumula-help-${g.id}" hidden><p><strong>Para que serve?</strong> A súmula é a ficha da partida, que pode ser impressa. Ela traz os jogadores e um QR Code para o árbitro registrar o placar pelo celular.</p><p><strong>Por que pede login e senha?</strong> O QR Code permite enviar um resultado que altera a classificação. Por isso, somente a organização ou um usuário autorizado para este jogo pode gerar a súmula. Assim, evitamos registros feitos por pessoas sem permissão.</p><p>O QR vale na data do jogo escolhida ao gerar a súmula e aceita um único envio. Depois que o resultado é salvo, correções são feitas pelo painel. Para apenas consultar os jogos e a classificação, não é preciso login.</p></div>` : ''}</article>`).join('') || '<div class="empty"><h3>Nenhum jogo com esses filtros</h3><p>Altere a rodada, a situação ou o botonista.</p></div>';
    $('#games-page').textContent=`Página ${gamePage} de ${pages}`;
    $('#games-prev').disabled=gamePage===1;
    $('#games-next').disabled=gamePage===pages;
    $('.games-pagination').hidden=filtered.length===0;
  }
  ['#game-player','#game-round','#game-status'].forEach(id=>$(id).addEventListener('change',()=>{gamePage=1;renderGames();}));
  $('#clear-games').addEventListener('click',()=>{['#game-player','#game-round','#game-status'].forEach(id=>$(id).value='');gamePage=1;renderGames();});
  $('#games-prev').addEventListener('click',()=>{gamePage--;renderGames();$('#games-count').scrollIntoView({block:'start'});});
  $('#games-next').addEventListener('click',()=>{gamePage++;renderGames();$('#games-count').scrollIntoView({block:'start'});});
  renderGames();
  if (window.COPA_SUMULA_LOGIN) {
    const sumulaDialog = $('#sumula-login-dialog');
    const sumulaError = $('#sumula-login-error');
    $('#games-list').addEventListener('click', event => {
      const helpButton = event.target.closest('[data-sumula-help]');
      if (helpButton) {
        const expanded = helpButton.getAttribute('aria-expanded') === 'true';
        helpButton.setAttribute('aria-expanded', String(!expanded));
        document.getElementById(helpButton.getAttribute('aria-controls')).hidden = expanded;
        return;
      }
      const button = event.target.closest('[data-sumula-game]');
      if (!button) return;
      $('#sumula-login-form').reset();
      sumulaError.hidden = true;
      $('#sumula-login-game-id').value = button.dataset.sumulaGame;
      $('#sumula-login-game').textContent = button.dataset.sumulaTitle;
      sumulaDialog.showModal();
      sumulaDialog.scrollTop = 0;
    });
    $('#close-sumula-login').addEventListener('click', () => sumulaDialog.close());
    sumulaDialog.addEventListener('click', event => { if (event.target === sumulaDialog) sumulaDialog.close(); });
    $('#sumula-login-form').addEventListener('submit', async event => {
      event.preventDefault();
      const submit = event.currentTarget.querySelector('[type="submit"]');
      submit.disabled = true;
      sumulaError.hidden = true;
      try {
        const response = await fetch(window.COPA_SUMULA_LOGIN.endpoint, { method: 'POST', credentials: 'same-origin', body: new FormData(event.currentTarget) });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'Não foi possível autorizar a súmula.');
        window.open(result.url, '_blank', 'noopener');
        sumulaDialog.close();
      } catch (error) {
        sumulaError.textContent = error.message || 'Não foi possível validar o acesso. Tente novamente.';
        sumulaError.hidden = false;
      } finally { submit.disabled = false; }
    });
  }
  function route() {
    const hash = location.hash.slice(1);
    const page = ['classificacao','jogos','evolucao'].includes(hash) ? hash : 'classificacao';
    document.querySelectorAll('.page').forEach(p => { p.hidden = p.id !== page; });
    document.querySelectorAll('[data-page]').forEach(link => { if (link.dataset.page===page) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current'); });
    document.title = `${{classificacao:'Classificação',jogos:'Jogos',evolucao:'Evolução'}[page]} · I Copa Lago de Pedra`;
  }
  window.addEventListener('hashchange', route);
  $('#download').addEventListener('click', () => {
    const rows = [['Posição (PG, V, SG, GM)','Jogador','J','V','E','D','PG','GM','GS','SG','Aproveitamento','Critérios'], ...currentPlayers().map(p => [p.position,p.name,p.played,p.wins,p.draws,p.losses,p.points,p.goalsFor,p.goalsAgainst,p.goalDifference,formatPercentage(p),'PG > V > SG > GM'])];
    const cell = value => { let text = String(value); if (/^[=+@\-\t\r]/.test(text)) text="'"+text; return '"'+text.replace(/"/g,'""')+'"'; };
    const blob = new Blob(['\uFEFF'+rows.map(row=>row.map(cell).join(';')).join('\r\n')],{type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href=url; a.download='copa-lago-de-pedra.csv'; document.body.append(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000); toast('Tabela exportada com os filtros atuais e os critérios de cálculo.');
  });
  renderRanking(); renderHistory(); route();
})();
