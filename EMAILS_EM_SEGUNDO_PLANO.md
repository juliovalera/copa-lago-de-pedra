# Avisos por e-mail em segundo plano — versão 1.33

Ao salvar um resultado, o sistema grava o aviso em uma fila no SQLite. O navegador não espera a conexão com o servidor de e-mail. Uma tarefa agendada processa essa fila separadamente.

## Configuração na hospedagem

**Configure a tarefa ao instalar esta versão. Sem ela, os avisos ficam aguardando na fila.**

No cPanel, abra **Tarefas cron / Cron Jobs**, selecione execução a cada minuto e informe:

```text
* * * * * /usr/local/bin/php /home/SEU_USUARIO/public_html/ligamogiana/copa-lago-de-pedra/painel_php/process-emails.php
```

Se o painel tiver campos separados para a frequência, coloque `*` nos cinco campos e apenas o comando a partir de `/usr/local/bin/php` no campo **Comando**.

Substitua `SEU_USUARIO` e confirme com a hospedagem o caminho do PHP CLI e o caminho completo da instalação. Não use uma URL: este arquivo é executado pelo PHP no servidor, sem acesso público HTTP. O PHP CLI precisa das extensões SQLite e OpenSSL e da mesma configuração de SMTP usada pelo site.

Antes de ativar o agendamento, execute o comando uma vez pelo terminal da hospedagem. A saída informa a quantidade de avisos processados; a Auditoria informa o resultado dos envios. Se a configuração usa variáveis de ambiente, disponibilize-as também à tarefa cron; ela não necessariamente herda as variáveis do servidor web.

No Windows, use o **Agendador de Tarefas**, apontando para o executável do PHP e passando o caminho completo de `process-emails.php` como argumento. Configure a repetição a cada minuto e execução em segundo plano. Não execute o comando no banco real apenas para testar: ele entrega os avisos pendentes.

## Comportamento da fila

- Cada execução processa até 20 destinatários. Um bloqueio de arquivo impede duas tarefas de processarem a mesma fila simultaneamente.
- O primeiro envio acontece na próxima execução da tarefa.
- Se falhar, as novas tentativas aguardam 1, 5, 15 e 60 minutos, respectivamente. Há no máximo cinco tentativas automáticas por destinatário.
- Após esse limite, o administrador máximo pode selecionar **Agendar nova tentativa** na Auditoria. Esse botão recoloca os avisos não confirmados na fila; não envia durante a navegação e não repete os já confirmados.
- Se uma execução for interrompida, avisos marcados como em envio há mais de 30 minutos podem ser recuperados. Se o SMTP tiver aceitado o e-mail antes da interrupção, essa recuperação pode gerar uma duplicata; SMTP não garante entrega exatamente uma vez.
- Avisos antigos pendentes ou com falha e menos de cinco tentativas também serão processados. Avisos já enviados são preservados na migração.
- O arquivo de bloqueio fica junto ao banco; o usuário da tarefa precisa de escrita nesse diretório.

Esta fila abrange os avisos de resultados e as cópias de ações ao organizador. Os convites para definição de senha continuam sendo enviados pela tela de Usuários.

## Destinatário do organizador

Desde a versão 1.32, as cópias ao organizador usam `notification_email` no `config.php` ou a variável `COPA_NOTIFY_EMAIL`. Preencha esse endereço privado para manter as cópias. Isso não altera os avisos destinados às contas dos jogadores.

O pacote de atualização não contém banco nem `config.php`. Faça backup antes de instalar e preserve ambos na hospedagem.
