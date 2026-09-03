#!/bin/sh
#
# Cliente EXTERNO do spike.
#
# Roda com --network host, FORA da rede do Compose, e so alcanca o storage pelo
# endereco publicado no host. Representa o navegador.
#
# Nunca ve TROCA_DIR. Dentro do container o diretorio compartilhado e sempre
# /troca, caminho fixo.
#
# Uso: validar-publico.sh enviar|ler

set -eu
set -o pipefail

TROCA=/troca
ETAPA="${1:-}"

case "$ETAPA" in
  enviar|ler) ;;
  *)
    echo "uso: $(basename "$0") enviar|ler" >&2
    exit 64
    ;;
esac

[ -d "$TROCA" ] || {
  echo "erro: $TROCA nao esta montado; faltou -v \"\$TROCA_DIR:/troca\"" >&2
  exit 78
}

ORIGEM="${SPIKE_ORIGEM_FRONTEND:-http://localhost:3000}"
TIPO="${SPIKE_CONTENT_TYPE:-video/mp4}"
HOST_INTERNO="${SPIKE_HOST_INTERNO:-rustfs}"
PORTA_INTERNA="${SPIKE_PORTA_INTERNA:-9000}"

ponto() {
  printf '%s|OK|%s\n' "$1" "$2" >>"$TROCA/pontos.txt"
  echo "  [externo] ponto $1: $2"
}

# Descreve uma URL assinada sem a query string: esquema, host, porta, caminho.
sem_query() {
  echo "$1" | sed 's/?.*//'
}

cabecalho() {
  # cabecalho <arquivo> <nome>  -> valor, sem CR, em minusculas no nome
  awk -v alvo="$(echo "$2" | tr 'A-Z' 'a-z')" '
    { linha = $0; sub(/\r$/, "", linha)
      pos = index(linha, ":")
      if (pos > 0) {
        nome = tolower(substr(linha, 1, pos - 1))
        valor = substr(linha, pos + 1)
        sub(/^[ \t]+/, "", valor)
        if (nome == alvo) print valor
      } }' "$1"
}

if [ "$ETAPA" = "enviar" ]; then
  [ -f "$TROCA/put.url" ] || { echo "erro: put.url ausente" >&2; exit 78; }
  [ -f "$TROCA/amostra.bin" ] || { echo "erro: amostra.bin ausente" >&2; exit 78; }
  URL_PUT="$(cat "$TROCA/put.url")"

  # --- ponto 8: preflight de CORS -------------------------------------------
  codigo="$(curl -sS --max-time 20 -o /dev/null -D "$TROCA/preflight.h" \
    -w '%{http_code}' -X OPTIONS \
    -H "Origin: $ORIGEM" \
    -H "Access-Control-Request-Method: PUT" \
    -H "Access-Control-Request-Headers: content-type" \
    "$URL_PUT")"
  permitida="$(cabecalho "$TROCA/preflight.h" access-control-allow-origin)"
  metodos="$(cabecalho "$TROCA/preflight.h" access-control-allow-methods)"
  [ "$codigo" = "200" ] || { echo "erro: preflight respondeu $codigo" >&2; exit 1; }
  [ "$permitida" = "$ORIGEM" ] || {
    echo "erro: preflight liberou '$permitida', esperado '$ORIGEM'" >&2; exit 1; }
  case "$metodos" in
    *PUT*) ;;
    *) echo "erro: preflight nao liberou PUT (metodos: $metodos)" >&2; exit 1 ;;
  esac
  ponto 8 "OPTIONS com Origin $ORIGEM respondeu 200 liberando [$metodos]"

  # --- ponto 9: envio real da parte -----------------------------------------
  codigo="$(curl -sS --max-time 120 -o /dev/null -D "$TROCA/put.h" \
    -w '%{http_code}' -X PUT \
    -H "Origin: $ORIGEM" \
    -H "Content-Type: $TIPO" \
    --data-binary "@$TROCA/amostra.bin" \
    "$URL_PUT")"
  [ "$codigo" = "200" ] || { echo "erro: PUT respondeu $codigo" >&2; exit 1; }
  etag="$(cabecalho "$TROCA/put.h" etag)"
  [ -n "$etag" ] || { echo "erro: PUT nao devolveu ETag" >&2; exit 1; }
  printf '%s' "$etag" >"$TROCA/etag.txt"
  bytes="$(wc -c <"$TROCA/amostra.bin" | tr -d ' ')"
  ponto 9 "PUT de $bytes bytes por --network host em $(sem_query "$URL_PUT") \
respondeu 200 e devolveu ETag, gravado em /troca"

  # --- ponto 10: CORS na resposta real ---------------------------------------
  permitida="$(cabecalho "$TROCA/put.h" access-control-allow-origin)"
  expostos="$(cabecalho "$TROCA/put.h" access-control-expose-headers)"
  [ "$permitida" = "$ORIGEM" ] || {
    echo "erro: PUT nao trouxe allow-origin correto (veio '$permitida')" >&2
    exit 1; }
  case "$(echo "$expostos" | tr 'A-Z' 'a-z')" in
    *etag*) ;;
    *) echo "erro: PUT nao expos ETag ao navegador (expose: '$expostos')" >&2
       exit 1 ;;
  esac
  ponto 10 "resposta do proprio PUT trouxe allow-origin $permitida e \
expose-headers $expostos, sem os quais o navegador nao leria o ETag"

else  # ler
  [ -f "$TROCA/get.url" ] || { echo "erro: get.url ausente" >&2; exit 78; }
  URL_GET="$(cat "$TROCA/get.url")"

  # Controle negativo: sem acesso a rede do Compose, o nome do servico nao
  # resolve. Se resolvesse, o ponto 14 nao provaria nada.
  if curl -sS --max-time 5 -o /dev/null \
      "http://$HOST_INTERNO:$PORTA_INTERNA/health" 2>/dev/null; then
    echo "erro: o cliente externo alcancou '$HOST_INTERNO' pela rede do \
Compose; a validacao do endpoint publico seria falsa" >&2
    exit 1
  fi

  codigo="$(curl -sS --max-time 120 -o "$TROCA/baixado.bin" \
    -w '%{http_code}' "$URL_GET")"
  [ "$codigo" = "200" ] || { echo "erro: GET respondeu $codigo" >&2; exit 1; }
  cmp -s "$TROCA/amostra.bin" "$TROCA/baixado.bin" || {
    echo "erro: conteudo baixado difere do enviado" >&2; exit 1; }
  bytes="$(wc -c <"$TROCA/baixado.bin" | tr -d ' ')"
  ponto 14 "GET em $(sem_query "$URL_GET") pelo endereco publicado no host, \
sem resolver '$HOST_INTERNO'; $bytes bytes identicos byte a byte a amostra"
fi
