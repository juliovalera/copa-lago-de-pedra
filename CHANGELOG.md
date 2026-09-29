# Histórico de versões

## 1.40 — 29/09/2026

- Salvamento do painel confere a revisão do resultado exibido e bloqueia formulários desatualizados, preservando alterações feitas por outras pessoas ou pelo QR.
- Proteção cobre placares, datas e exclusões; conflito orienta a conferir o jogo na rodada correspondente, sem enviar avisos ou registrar alteração de resultado.
- Migração automática adiciona controle de revisão sem modificar os resultados existentes.

## 1.39 — 29/09/2026

- Tela de usuários reorganizada em cartões responsivos, com dados e formulários de privilégios visíveis sem arrastar tabelas horizontalmente.
- Tipografia legível, campos adaptados ao celular e controles com área de toque ampliada.

## 1.38 — 29/09/2026

- Nível de acesso separado do vínculo com o botonista: administradores podem receber avisos das suas partidas sem perder acesso aos demais jogos.
- Migração automática e restauração de backups antigos preservam os privilégios existentes; associações de administradores são feitas explicitamente pelo acesso principal.
- Ações administrativas também avisam a organização no endereço configurado, sem duplicar destinatários na mesma ação.
- Auditoria mostra destinatários, situação e tentativas de cada aviso; sem reenvio retroativo ou tarefas agendadas.

## 1.37 — 28/09/2026

- Geração e reemissão de súmula passam a avisar os dois jogadores com e-mail válido na conta vinculada.
- Aviso inclui confronto e data, sem divulgar o token do QR; cópias existentes ao organizador preservadas.

## 1.36 — 28/09/2026

- QR da súmula gerado localmente em SVG, sem enviar o link de registro a terceiros.
- Biblioteca MIT incluída no pacote, com revisão fixada e licença preservada; sem Composer, CDN ou instalações adicionais.
- Margem branca e impressão liberada somente após gerar o QR; mantidas as regras de permissão, data e uso único.

## 1.35 — 28/09/2026

- Avisos ao titular ao corrigir nome de conta ou botonista e ao definir/redefinir senha, sem divulgar senhas ou tokens.
- Edição de nome e e-mail de contas pelo administrador máximo; troca de e-mail avisa ambos os endereços e cancela convites antigos.
- Envios após salvar, com falhas registradas para reenvio manual.

## 1.34 — 28/09/2026

- Removida a exigência de tarefa agendada: avisos voltam a ser enviados após salvar, com reenvio manual na Auditoria.
- Processador antigo neutralizado para atualizações de instalações 1.33. Avisos pendentes preservados para reenvio.
- Mantidas a validação de datas pelo calendário e os favoritos por identificador.

## 1.33 — 28/09/2026

- Datas de resultados validadas pelo calendário, incluindo anos bissextos.
- Avisos enviados por fila e tarefa CLI, com limite de cinco tentativas, espera progressiva e proteção contra execuções simultâneas. Requer configurar a tarefa agendada na hospedagem.
- Favoritos passam a usar o identificador do jogador; nomes antigos são convertidos quando ainda correspondem ao cadastro.

## 1.32 — 28/09/2026

- Publicação do código com licença MIT, README, configuração de exemplo e demonstração fictícia.
- Destinatário dos avisos ao organizador movido para notification_email na configuração privada ou COPA_NOTIFY_EMAIL no ambiente.
- Proteções de publicação para excluir bancos, backups, credenciais e dados reais.

## 1.31 — 27/09/2026

- Avisos de resultado para os e-mails das contas vinculadas aos dois jogadores, pelo painel e QR, com placar anterior nas correções.
- Controle e reenvio por destinatário, preservando avisos do organizador e os estados de envio anteriores na migração automática.
- Sem envio para e-mails vazios ou inválidos, sem alteração efetiva e sem avisos retroativos.

## 1.30 — 27/09/2026

- Evolução calculada por data dos jogos, com gráfico e tabela de posições e pontos.
- Histórico recalculado após correções, usando PG, vitórias, saldo e gols marcados; datas ausentes ou inválidas sinalizadas como histórico parcial.

## 1.29 — 27/09/2026

- Versão clicável abre os créditos com autoria, atuação profissional e contato de Júlio César Valera.
- Janela adaptada ao celular, com navegação por teclado e fechamento por botão, Esc ou clique fora.

## 1.28 — 27/09/2026

- Bloqueio de 15 minutos após 5 senhas incorretas em uma janela de 15 minutos, combinando conta e IP.
- Limite compartilhado entre painel/súmula e entre login/e-mail da mesma conta.
- Tentativas, bloqueios, fim do bloqueio e autenticações aceitas registrados sem senha; consulta na auditoria restrita ao acesso principal.
- Sessões abertas e consulta pública não são interrompidas.

## 1.27 — 27/09/2026

- Desativar uma conta cancela seus convites pendentes na mesma transação, impedindo reativação por links antigos.
- Mensagem de convite indisponível e guia atualizados.

## 1.26 — 27/09/2026

- Terminologia corrigida para turno e returno no banner, na tabela de jogos, no filtro de rodadas e nos confrontos.

## 1.25 — 27/09/2026

- Exemplo de correção de nome no guia substituído por nome fictício, identificado como ilustração.

## 1.24 — 27/09/2026

- Classificação corrigida para PG > V > SG > GM. Vitórias passam a ser o primeiro desempate após pontos.
- Indicadores, descrições acessíveis, CSV e guia atualizados.
- Nenhum placar ou cadastro é modificado; a classificação PHP é recalculada na consulta.

## 1.23 — 27/09/2026

- Avisos ao organizador sobre resultados, nomes, súmulas e ativação de contas de botonistas, além dos placares enviados por QR.
- Envio após o salvamento, com estado na auditoria e nova tentativa pelo acesso principal.
- Falhas de SMTP não desfazem os dados salvos. Sem envio retroativo dos registros antigos.

## 1.22 — 27/09/2026

- Botão para voltar à área pública na tela de entrada.
- Versão centralizada e exibida no site e no painel.
- Guia de uso público, responsivo, ilustrado e imprimível.
- Esta versão reúne também as implementações anteriores: auditoria, correção de nomes, gestão de privilégios pelo acesso principal e data de jogo na súmula.

Próxima implementação: 1.29. O incremento é de 0,01 por entrega, sem arredondamento acumulado.
