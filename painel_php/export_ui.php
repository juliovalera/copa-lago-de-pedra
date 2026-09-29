<?php
declare(strict_types=1);
function exportForm(): string
{
    return <<<'HTML'
<form class="export-form" action="exportar.php" method="get">
<p>Escolha o formato e o conteúdo. O arquivo inclui todos os registros da opção escolhida, sem aplicar os filtros da tela.</p>
<label for="export-format">Formato do arquivo</label>
<select id="export-format" name="format"><option value="xlsx">Excel (.xlsx) — planilha</option><option value="docx">Word (.docx) — documento editável</option><option value="pdf" disabled>PDF (.pdf) — pronto para imprimir</option></select>
<label for="export-content">O que deseja baixar?</label>
<select id="export-content" name="content"><option value="all">Classificação e jogos/resultados</option><option value="ranking">Somente classificação</option><option value="games">Somente jogos e resultados</option></select>
<p>Word e PDF têm os dois logos, tabelas em páginas na horizontal e numeração de páginas. Gols em branco indicam jogo sem resultado.</p>
<noscript><p>Para baixar em PDF, ative o JavaScript do navegador. Excel e Word continuam disponíveis.</p></noscript>
<p class="export-status" role="status" aria-live="polite"></p>
<a class="export-ready" hidden>Salvar arquivo preparado</a>
<button class="primary" type="submit">Baixar arquivo</button>
</form>
HTML;
}
function exportAssets(): string
{
    return '<link rel="stylesheet" href="export.css?v='.filemtime(__DIR__.'/public/export.css').'"><script src="export.js?v='.filemtime(__DIR__.'/public/export.js').'" defer></script>';
}
