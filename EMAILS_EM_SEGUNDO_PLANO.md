# E-mails sem tarefa agendada — versão 1.34

O agendamento introduzido na versão 1.33 foi removido. Não é necessário cadastrar Cron Jobs ou executar comandos no servidor.

Os avisos aos jogadores e ao organizador voltam a ser enviados após o salvamento, usando o SMTP já configurado. Falhas não desfazem o resultado. O administrador máximo pode usar **Tentar enviar aviso novamente** na Auditoria, inclusive para avisos pendentes da versão 1.33.

O arquivo `process-emails.php` foi neutralizado para substituir com segurança o arquivo antigo na hospedagem. Ele não acessa o banco nem envia e-mails. Caso tenha criado uma tarefa cron para ele, pode excluí-la; ela não é utilizada pelo sistema.

A validação de datas e os favoritos por identificador continuam funcionando. O banco e o `config.php` devem ser preservados.
