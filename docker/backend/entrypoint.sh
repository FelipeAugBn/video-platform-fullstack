#!/bin/sh
#
# Entrypoint dos servicos que compartilham a imagem PHP.
#
# Encaminha o comando recebido e nada mais. E o que permite que `api`, `worker`,
# `simulator-worker` e `setup` sejam a mesma imagem com comandos diferentes:
# quem define o processo e o `command` do servico, nao este arquivo.
#
# O que deliberadamente nao acontece aqui:
#
#   - instalar dependencia — a imagem ja chega pronta, e instalar na subida
#     tornaria o tempo de inicializacao dependente da rede;
#   - ajustar permissao — mexer no bind mount da raiz da aplicacao alteraria
#     arquivos do repositorio no host;
#   - rodar migration ou seed — pertencem ao servico `setup`, que roda uma vez
#     antes dos demais. Executadas por cada container, quatro processos
#     disputariam o mesmo banco na subida;
#   - esperar banco ou storage — a ordem de subida vem dos healthchecks do
#     Compose, que ja e onde ela e declarada e observavel;
#   - iniciar processo em segundo plano — um container, um processo principal:
#     e assim que o sinal de parada chega a quem precisa recebe-lo.
#
# `exec` substitui este shell pelo processo final, em vez de deixa-lo como pai.
# Sem isso o processo da aplicacao nao seria o PID 1 e nao receberia SIGTERM
# diretamente: a parada viraria timeout seguido de SIGKILL.

set -eu

exec "$@"
