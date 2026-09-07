#!/usr/bin/env bash

# Execucao da jornada integrada, do reset da base ao navegador.
#
#     make e2e
#
# **Esta e a unica entrada oficial.** Chamar o Playwright direto pularia o reset,
# o seed e a espera pelas verificacoes de saude, e a jornada passaria a depender
# do estado deixado pela execucao anterior.
#
# Sem modos e sem argumento: a sequencia e sempre a mesma, e um interruptor aqui
# criaria uma segunda forma de rodar que ninguem documentaria.
#
# A ordem nao e arbitraria.
#
#   1. Os consumidores da fila param **antes** do reset. `migrate:fresh` no meio
#      de um `queue:work` ativo e uma corrida: o consumidor pode ler ou gravar
#      numa tabela que esta sendo derrubada, e o desfecho varia a cada execucao.
#   2. A base e recriada e semeada. E o que torna a segunda execucao identica a
#      primeira, sem depender do que a anterior deixou.
#   3. Os consumidores voltam, agora sobre o esquema novo.
#   4. Espera ativa pelas verificacoes de saude. O reset derruba a conexao dos
#      processos PHP e o `web` leva alguns segundos para voltar a responder;
#      comecar antes disso produziria uma falha que nao descreve defeito algum.
#   5. O navegador roda a jornada, num container descartavel.
#
# O container do E2E nao recebe o socket do Docker: tudo o que exige o daemon
# acontece aqui, no host, antes de o navegador comecar.

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

COMPOSE=(docker compose)

# Servicos cuja saude a jornada exige antes de comecar: a porta de entrada da
# API, o banco e o armazenamento de objetos.
SAUDAVEIS=(web mysql rustfs)

# Teto da espera. Generoso porque o passo anterior acabou de recriar o esquema e
# reiniciar os consumidores; curto o bastante para nao prender uma execucao
# travada por meia hora.
LIMITE_DE_ESPERA=180
INTERVALO=2

registrar() {
  printf '\n==> %s\n' "$1"
}

# Estado de saude de um servico, lido do proprio daemon.
#
# `docker compose ps` mudaria de formato entre versoes; `docker inspect` sobre o
# identificador do container responde sempre o mesmo campo. Servico sem
# container ainda criado devolve `ausente`, que nao e `healthy` e mantem a espera.
saude_de() {
  local servico="$1" id

  id="$("${COMPOSE[@]}" ps --quiet "$servico" 2>/dev/null | head -n 1)"

  if [ -z "$id" ]; then
    echo 'ausente'
    return 0
  fi

  docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}sem-verificacao{{end}}' "$id" 2>/dev/null \
    || echo 'ausente'
}

diagnosticar() {
  printf '\nEstado dos servicos apos %ss de espera:\n\n' "$LIMITE_DE_ESPERA" >&2

  local servico
  for servico in "${SAUDAVEIS[@]}"; do
    printf '  %-8s %s\n' "$servico" "$(saude_de "$servico")" >&2
  done

  printf '\n' >&2
  "${COMPOSE[@]}" ps >&2
}

esperar_saude() {
  local decorrido=0 servico pendentes

  while true; do
    pendentes=()

    for servico in "${SAUDAVEIS[@]}"; do
      [ "$(saude_de "$servico")" = 'healthy' ] || pendentes+=("$servico")
    done

    if [ "${#pendentes[@]}" -eq 0 ]; then
      printf 'Todos saudaveis: %s\n' "${SAUDAVEIS[*]}"
      return 0
    fi

    if [ "$decorrido" -ge "$LIMITE_DE_ESPERA" ]; then
      printf '\nTempo esgotado esperando: %s\n' "${pendentes[*]}" >&2
      diagnosticar
      return 1
    fi

    sleep "$INTERVALO"
    decorrido=$((decorrido + INTERVALO))
  done
}

registrar 'Parando os consumidores da fila antes do reset'
"${COMPOSE[@]}" stop worker simulator-worker

registrar 'Recriando e semeando a base de dados'
#
# `--force` porque o ambiente nao declara `APP_ENV` e o framework assume
# producao: sem ele o comando abre uma pergunta de confirmacao e, sem terminal
# interativo, se cancela sozinho.
#
# Sem `--no-deps`: as dependencias do servico incluem a preparacao do ambiente,
# que garante banco e storage saudaveis e o bucket criado. Ela e idempotente, e
# roda em segundos quando nao ha o que fazer.
"${COMPOSE[@]}" run --rm api php artisan migrate:fresh --seed --force

registrar 'Retomando os consumidores da fila'
"${COMPOSE[@]}" up --detach worker simulator-worker

registrar 'Esperando as verificacoes de saude'
esperar_saude

registrar 'Executando a jornada integrada'
"${COMPOSE[@]}" --profile e2e run --rm e2e npx playwright test
