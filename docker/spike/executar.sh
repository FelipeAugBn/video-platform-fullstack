#!/usr/bin/env bash
#
# Orquestrador do spike de validacao do storage.
#
# Executa as quatro etapas do fluxo multipart, alternando entre o cliente
# interno (rede do Compose) e o cliente externo (--network host), e conclui
# afirmando os catorze pontos de verificacao.
#
#   TROCA_DIR  diretorio temporario NO HOST, criado com mktemp -d
#   /troca     onde esse diretorio aparece DENTRO dos containers
#
# Nada temporario e escrito na arvore do repositorio.
#
# Uso: docker/spike/executar.sh

set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ="$(cd "$DIR/../.." && pwd)"
cd "$RAIZ"

COMPOSE_FILE="docker/spike/compose.rustfs.yml"
CURL_REF="curlimages/curl:8.11.1@sha256:c1fe1679c34d9784c1b0d1e5f62ac0a79fca01fb6377cdd33e90473c6f9f9a69"
ORIGEM_FRONTEND="http://localhost:3000"
CONTENT_TYPE="video/mp4"
HOST_INTERNO="rustfs"
PORTA_INTERNA="9000"

TROCA_DIR="$(mktemp -d)"
trap 'rm -rf "$TROCA_DIR"' EXIT
: >"$TROCA_DIR/pontos.txt"

compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

ponto() {
  # Uma evidencia por linha: quebra de linha aqui truncaria o resumo final,
  # que localiza cada ponto por "^numero|".
  local evidencia="${2//$'\n'/ }"
  printf '%s|OK|%s\n' "$1" "$evidencia" >>"$TROCA_DIR/pontos.txt"
  echo "  [host]    ponto $1: $evidencia"
}

falhar() { echo; echo "FALHOU: $*" >&2; exit 1; }

# Regra de fixacao adotada: toda referencia de imagem carrega tag de versao
# completa E digest. A tag sozinha nao basta porque tag de registry e mutavel:
# so o digest identifica um conteudo. Como efeito colateral util, a regra e
# mecanicamente verificavel, sem julgar o que conta como "versao completa".
verificar_referencia() {
  local origem="$1" ref="$2"
  [ -n "$ref" ] || falhar "referencia de imagem ausente: $origem"
  [[ "$ref" != *latest* ]] \
    || falhar "referencia movel com 'latest' em $origem: $ref"
  [[ "$ref" =~ ^[A-Za-z0-9._/-]+:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}$ ]] \
    || falhar "referencia sem tag de versao completa e digest em $origem: $ref"
}

externo() {
  # Cliente externo: sem acesso a rede do Compose, com o uid do host para que
  # o diretorio de troca (criado 0700 pelo mktemp) seja legivel e gravavel.
  docker run --rm --network host \
    --user "$(id -u):$(id -g)" \
    -v "$TROCA_DIR:/troca" \
    -v "$PWD/docker/spike:/spike:ro" \
    -e SPIKE_ORIGEM_FRONTEND="$ORIGEM_FRONTEND" \
    -e SPIKE_CONTENT_TYPE="$CONTENT_TYPE" \
    -e SPIKE_HOST_INTERNO="$HOST_INTERNO" \
    -e SPIKE_PORTA_INTERNA="$PORTA_INTERNA" \
    --entrypoint /spike/validar-publico.sh \
    "$CURL_REF" "$1"
}

interno() {
  compose run --rm -v "$TROCA_DIR:/troca" \
    cliente-interno /spike/validar-interno.sh "$1"
}

echo "=============================================================="
echo " Spike de validacao do storage"
echo " TROCA_DIR (host) ...: $TROCA_DIR"
echo " /troca (containers) : mesmo diretorio, caminho fixo"
echo "=============================================================="
echo
echo "--- Pre-condicoes -------------------------------------------"

# --- ponto 2: a configuracao completa resolve ------------------------------
# `--profile cliente` e obrigatorio: sem ele o cliente interno fica de fora da
# configuracao gerada e a imagem dele escaparia da verificacao do ponto 1.
compose --profile cliente config -q \
  || falhar "docker compose config nao resolveu $COMPOSE_FILE com o profile cliente"
resolvido="$(compose --profile cliente config)"
ponto 2 "docker compose --profile cliente config resolveu $COMPOSE_FILE, \
incluindo o servico atras do profile"

# --- ponto 1: as tres imagens, com versao fixada ---------------------------
imagens="$(printf '%s\n' "$resolvido" | awk '$1=="image:"{print $2}' | sort -u)"
quantas="$(printf '%s\n' "$imagens" | grep -c . || true)"
[ "$quantas" -eq 2 ] || falhar "esperava 2 imagens na configuracao com o \
profile cliente (storage e cliente interno), encontrei $quantas: $imagens"

declarada_rustfs=""
declarada_python=""
while IFS= read -r img; do
  verificar_referencia "compose" "$img"
  case "$img" in
    rustfs/*) declarada_rustfs="$img" ;;
    python:*) declarada_python="$img" ;;
    *) falhar "imagem inesperada na configuracao: $img" ;;
  esac
done <<<"$imagens"
[ -n "$declarada_rustfs" ] || falhar "imagem do storage ausente na configuracao"
[ -n "$declarada_python" ] || falhar "imagem do cliente interno ausente na \
configuracao; o profile cliente entrou na geracao?"

# O cliente externo e descartavel e nao esta no compose: a referencia dele vive
# neste script e passa pela mesma regra.
verificar_referencia "executar.sh" "$CURL_REF"

echo "  [host]    tres imagens sob a regra de fixacao:"
echo "              storage ......... $declarada_rustfs"
echo "              cliente interno . $declarada_python"
echo "              cliente externo . $CURL_REF"
ponto 1 "tag de versao completa e digest nas tres, nenhuma latest \
— storage $declarada_rustfs \
— cliente interno $declarada_python \
— cliente externo $CURL_REF"

# --- ponto 3: container em execucao e saudavel ------------------------------
cid="$(compose ps -q rustfs || true)"
[ -n "$cid" ] || falhar "o servico rustfs nao esta no ar; rode antes:
  docker compose -f $COMPOSE_FILE up -d"

estado="$(docker inspect --format '{{.State.Status}}' "$cid")"
[ "$estado" = "running" ] || falhar "rustfs esta '$estado', esperado 'running'"

saude=""
for _ in $(seq 1 40); do
  saude="$(docker inspect --format '{{.State.Health.Status}}' "$cid")"
  [ "$saude" = "healthy" ] && break
  sleep 2
done
[ "$saude" = "healthy" ] || falhar "healthcheck ficou em '$saude'"
ponto 3 "container rustfs running e healthcheck healthy"

echo
echo "--- Etapa 1 — cliente interno: preparacao --------------------"
interno preparar

echo
echo "--- Etapa 2 — cliente externo: envio -------------------------"
externo enviar

echo
echo "--- Etapa 3 — cliente interno: conclusao ---------------------"
interno concluir

echo
echo "--- Etapa 4 — cliente externo: leitura -----------------------"
externo ler

echo
echo "=============================================================="
echo " Catorze pontos de verificacao"
echo "=============================================================="
faltando=""
for n in $(seq 1 14); do
  linha="$(grep "^${n}|" "$TROCA_DIR/pontos.txt" || true)"
  if [ -z "$linha" ]; then
    faltando="$faltando $n"
    printf ' %2s  FALTOU\n' "$n"
  else
    printf ' %2s  OK  %s\n' "$n" "${linha#*|OK|}"
  fi
done
[ -z "$faltando" ] || falhar "pontos sem evidencia:$faltando"

echo
echo "Os catorze pontos foram comprovados nas quatro etapas."
echo "Diretorio de troca removido ao sair; nada foi escrito no repositorio."
