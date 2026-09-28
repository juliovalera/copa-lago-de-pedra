<?php
declare(strict_types=1);
// Centésimos inteiros: a próxima implementação usa 138 (versão 1.38).
const COPA_VERSION_NUMBER = 137;
function copaVersion(): string { return number_format(COPA_VERSION_NUMBER / 100, 2, '.', ''); }
function copaVersionButton(): string
{
    return '<button class="copa-version-button" type="button" data-copa-credits aria-haspopup="dialog" aria-controls="copa-credits">Versão ' . copaVersion() . '</button>';
}
function copaCredits(): string
{
    $css = filemtime(__DIR__ . '/public/credits.css');
    $js = filemtime(__DIR__ . '/public/credits.js');
    return '<link rel="stylesheet" href="credits.css?v=' . $css . '"><script src="credits.js?v=' . $js . '" defer></script>
<dialog id="copa-credits" aria-labelledby="copa-credits-title">
<button class="copa-credits-x" type="button" data-close-credits aria-label="Fechar créditos" autofocus>×</button>
<h2 id="copa-credits-title">Sobre o sistema</h2>
<p><strong>I Copa Lago de Pedra</strong><br>Sistema de classificação, jogos e súmulas.</p>
<h3>Desenvolvimento e manutenção</h3>
<p class="copa-credits-name">Júlio César Valera</p>
<p>Professor de Matemática, Programação e Robótica da rede pública de ensino do Estado de São Paulo.</p>
<p>Contato: <a href="mailto:julio@projetos.tec.br">julio@projetos.tec.br</a></p>
<p>Versão instalada: <strong>' . copaVersion() . '</strong></p>
<button class="copa-credits-close" type="button" data-close-credits>Fechar</button>
</dialog>';
}
function copaHelpFooter(): string
{
    return '<footer class="app-help"><a href="index.php">Área pública</a><a href="guia.php">Guia de uso</a>' . copaVersionButton() . '</footer>' . copaCredits();
}
