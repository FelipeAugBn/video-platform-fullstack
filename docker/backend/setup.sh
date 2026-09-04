#!/bin/sh
#
# Preparacao do ambiente, executada uma vez antes de a aplicacao subir.
#
# Nao e um servico: roda, termina e sai. O Compose so libera `api` — e, atras
# dele, `web` — depois que este script encerra com codigo zero (plan §16.2).
#
# Ordem: storage primeiro, banco depois. As duas etapas sao independentes, e
# falhar cedo no storage evita migrar um banco que a aplicacao nao conseguiria
# usar de qualquer forma.
#
# Idempotente por construcao: bucket ja existente e aceito, a politica de CORS e
# reaplicada e conferida, e o migrador executa apenas o que ainda falta. Nenhuma
# etapa apaga dado.
#
# O que deliberadamente nao acontece aqui:
#
#   - esperar banco ou storage — essa ordem vem dos healthchecks e do
#     `depends_on` do Compose, onde ja e declarada e observavel;
#   - instalar dependencia — as dependencias precisam ja estar instaladas na
#     raiz do backend antes da subida: elas chegam por bind mount, nao dentro da
#     imagem, e aqui o autoload e apenas conferido. Instala-las na inicializacao
#     tornaria a subida dependente da rede. O comando unico que faz essa
#     preparacao e consolidado na T012;
#   - popular dados — os registros de avaliacao pertencem ao seeder da T022;
#   - recriar schema ou desfazer migracao — este script nunca destroi estado.

set -eu

APP_ROOT="${APP_ROOT:-/app}"
AUTOLOAD="$APP_ROOT/vendor/autoload.php"
BOOTSTRAP_STORAGE="/opt/storage-bootstrap/criar-bucket.sh"

erro() {
  echo "setup: $*" >&2
  exit 1
}

# ---------------------------------------------------------------------------
# 1. Dependencias da aplicacao.
#
# Elas chegam pelo bind mount, nao pela imagem. Sem esta conferencia a ausencia
# apareceria adiante como classe nao encontrada, no bootstrap do storage ou no
# migrador — longe da causa real.
# ---------------------------------------------------------------------------
[ -f "$AUTOLOAD" ] \
  || erro "autoload do Composer nao encontrado em $AUTOLOAD.
  A aplicacao chega por bind mount: instale as dependencias na raiz do backend
  antes de subir o ambiente."

echo "setup: autoload do Composer disponivel em $AUTOLOAD"

# ---------------------------------------------------------------------------
# 2. Storage: bucket e politica de CORS.
#
# A logica vive no script promovido junto do storage, que ja trata bucket
# ausente e bucket existente, aplica e rele a politica, e sanitiza credencial em
# mensagem de erro. Aqui ele e apenas invocado: reescrever aquele comportamento
# criaria duas versoes da mesma regra, livres para divergir.
# ---------------------------------------------------------------------------
[ -x "$BOOTSTRAP_STORAGE" ] \
  || erro "bootstrap do storage ausente ou sem permissao de execucao em
  $BOOTSTRAP_STORAGE."

APP_ROOT="$APP_ROOT" "$BOOTSTRAP_STORAGE"

# ---------------------------------------------------------------------------
# 3. Banco: migrations pendentes.
#
# `--force` porque, sem arquivo de ambiente, o ambiente declarado e o de
# producao e o migrador pede confirmacao interativa nele; aqui nao ha terminal
# para responder. `--no-interaction` cobre qualquer outra pergunta.
#
# Migration ja registrada nao roda de novo: o migrador compara a tabela de
# controle com os arquivos disponiveis e aplica so a diferenca.
# ---------------------------------------------------------------------------
php "$APP_ROOT/artisan" migrate --force --no-interaction

echo "setup: banco e storage preparados"
exit 0
