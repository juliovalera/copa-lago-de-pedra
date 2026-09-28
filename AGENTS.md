# Manutenção do projeto PHP

- A versão fica centralizada em `painel_php/version.php`, em centésimos inteiros.
- Esta entrega estabelece a versão 1.22. A cada nova implementação concluída, incrementar `COPA_VERSION_NUMBER` em 1: 1.23, 1.24 etc. Não incrementar por teste, empacotamento ou tentativa durante a mesma implementação.
- Atualizar `CHANGELOG.md` e o guia de uso quando o comportamento mudar.
- Publicar pacotes PHP sem banco SQLite nem config.php, salvo instrução explícita diferente do usuário. Preservar dados e configurações da hospedagem.
- Testar alterações de dados e permissões com bancos isolados, nunca no banco real.
