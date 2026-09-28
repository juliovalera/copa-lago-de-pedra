<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/version.php';

/* O visual público usa os mesmos HTML, CSS, JS e imagens do site estático atual. */
$html = file_get_contents(dirname(__DIR__, 2) . '/site/index.html');
if ($html === false) {
    http_response_code(500);
    exit('Não foi possível carregar o modelo do site público.');
}
$html = preg_replace('/(?:src|href)="data\.js(?:\?v=[^"]*)?"/', 'src="data.php"', $html);
$assets = [
    'styles.css' => 'styles.css',
    'app.js' => 'app.js',
    'icon.svg' => 'icon.svg',
    'lago_de_pedra.png' => 'lago_de_pedra_256.png',
    'liga_mogiana.png' => 'liga_mogiana_256.png',
];
$html = preg_replace_callback(
    '/(src|href)="(styles\.css|app\.js|icon\.svg|lago_de_pedra\.png|liga_mogiana\.png)(?:\?v=[^"]*)?"/',
    function (array $m) use ($assets): string {
        $asset = $assets[$m[2]];
        $file = dirname(__DIR__, 2) . '/site/' . $asset;
        return $m[1] . '="asset.php?file=' . $asset . '&v=' . filemtime($file) . '"';
    },
    $html
);
$html = str_replace('</footer>', '<a class="panel-access" href="admin.php">Acesso ao painel</a><a class="panel-access" href="guia.php">Guia de uso</a>' . copaVersionButton() . '</footer>', $html);
$sumulaModal = <<<'HTML'
<dialog id="sumula-login-dialog" aria-labelledby="sumula-login-title"><div class="dialog-top"><span class="eyebrow dark">SÚMULA DA PARTIDA</span><button id="close-sumula-login" class="icon-button" type="button" aria-label="Fechar">×</button></div><h2 id="sumula-login-title">Gerar súmula com QR</h2><p id="sumula-login-game" class="player-subtitle"></p><p class="player-subtitle">Informe seu login ou e-mail e senha. O acesso será liberado somente se sua conta tiver permissão para este jogo.</p><p id="sumula-login-error" class="sumula-login-error" role="alert" hidden></p><form id="sumula-login-form"><input id="sumula-login-game-id" name="game_id" type="hidden"><label>Login ou e-mail<input id="sumula-login-name" name="login" type="text" autocomplete="username" required autofocus></label><label>Senha<input id="sumula-login-password" name="password" type="password" autocomplete="current-password" required></label><div class="dialog-actions"><button class="primary" type="submit">Gerar súmula</button></div></form></dialog>
HTML;
$html = str_replace('</body>', '<script>window.COPA_SUMULA_LOGIN = { endpoint: "sumula-login.php" };</script>' . $sumulaModal . copaCredits() . '</body>', $html);
header('Content-Type: text/html; charset=utf-8');
echo $html;
