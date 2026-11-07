<?php
declare(strict_types=1);
$version = copaVersion();
return [
    'head' => '<link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="copa-icon-180.png"><meta name="apple-mobile-web-app-title" content="Copa Lago de Pedra"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="stylesheet" href="install.css?v='.$version.'"><script src="install.js?v='.$version.'" defer></script>',
    'body' => <<<'HTML'
<section class="install-banner" id="install-banner" aria-label="Acesso fácil no celular">
<img src="copa-icon-192.png" width="64" height="64" alt="">
<div><strong>A Copa a um toque</strong><p>Coloque o ícone no celular e abra sem procurar o endereço.</p></div>
<button class="primary" id="install-open" type="button" aria-haspopup="dialog" aria-controls="install-dialog" hidden>Colocar no celular</button>
<button class="secondary" id="install-dismiss" type="button" hidden>Não mostrar novamente</button>
<noscript><p>No Android, abra o menu do navegador e procure “Instalar app” ou “Adicionar à tela inicial”. No iPhone, use Compartilhar → Adicionar à Tela de Início.</p></noscript>
</section>
<dialog id="install-dialog" aria-labelledby="install-title">
<div class="dialog-top"><h2 id="install-title">Colocar no celular</h2><button class="icon-button" id="install-close" type="button" aria-label="Fechar instruções">×</button></div>
<p>Depois, toque no ícone <strong>Copa Lago de Pedra</strong> para abrir o campeonato. É preciso internet.</p>
<p id="install-status" role="status" aria-live="polite"></p>
<button class="primary" id="install-confirm" type="button" hidden>Instalar agora</button>
<div id="install-manual">
<details id="install-android"><summary>Android</summary><ol><li>Abra este site no <strong>Chrome</strong>.</li><li>Toque no menu <strong>⋮</strong> do navegador.</li><li>Escolha <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong> e confirme.</li></ol></details>
<details id="install-ios"><summary>iPhone ou iPad</summary><ol><li>Abra este site no <strong>Safari</strong>.</li><li>Toque em <strong>Compartilhar</strong> <svg class="install-share-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V2m-4 4 4-4 4 4M6 10H3v12h18V10h-3"/></svg> (quadrado com seta para cima). A opção pode estar dentro do menu do navegador.</li><li>Escolha <strong>Adicionar à Tela de Início</strong>. Se aparecer “Abrir como App da Web”, deixe ativado.</li><li>Toque em <strong>Adicionar</strong>.</li></ol></details>
<details id="install-other"><summary>No computador ou em outro navegador</summary><p>Procure “Instalar” no menu ou na barra de endereço. Se não existir essa opção, salve o site nos favoritos.</p></details>
<p>Abriu pelo WhatsApp? Use o menu dessa tela e escolha <strong>Abrir no navegador</strong>, Chrome ou Safari. Se não encontrar, copie o endereço abaixo e cole no navegador.</p>
<button class="secondary" id="install-copy" type="button">Copiar endereço do site</button><label class="install-address-label" for="install-address">Endereço do campeonato<input id="install-address" type="text" readonly></label>
</div></dialog>
HTML,
];
