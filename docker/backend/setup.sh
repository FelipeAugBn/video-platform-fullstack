#!/bin/sh
#
# Preparacao do ambiente, executada uma vez antes de a aplicacao subir.
#
# Nao e um servico: roda, termina e sai. O Compose so libera `api` — e, atras
# dele, `web` — depois que este script encerra com codigo zero (plan §16.2).
#
# Ordem: chave de criptografia primeiro, dependencias depois, storage em
# seguida, banco por ultimo — migrations e, entao, os dados de avaliacao.
#
# A chave vem antes de tudo, inclusive da conferencia do autoload, porque a
# validacao dela usa apenas o binario do interpretador e nao depende de nenhuma
# biblioteca instalada. Sem chave a aplicacao nao autentica ninguem: preparar
# bucket e banco para um ambiente que nao vai subir e trabalho jogado fora, e o
# erro apareceria longe da causa.
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
#   - recriar schema, desfazer migracao ou sobrescrever dado existente — este
#     script nunca destroi estado. O seeder insere o que falta e nao toca no que
#     ja esta la, o que o torna seguro em toda subida do ambiente.

set -eu

APP_ROOT="${APP_ROOT:-/app}"
AUTOLOAD="$APP_ROOT/vendor/autoload.php"
BOOTSTRAP_STORAGE="/opt/storage-bootstrap/criar-bucket.sh"

erro() {
  echo "setup: $*" >&2
  exit 1
}

# ---------------------------------------------------------------------------
# 1. Chave de criptografia da aplicacao.
#
# Ela assina o cookie de sessao e cifra o que ele guarda. Sem chave valida nao ha
# autenticacao, e a falha apareceria adiante como sessao que nao persiste — longe
# da causa e dificil de diagnosticar.
#
# A conferencia e de formato, nao de valor: prefixo `base64:` e exatamente 32
# bytes depois de decodificar, que e o que o framework espera. Uma chave truncada
# ou com o prefixo errado e recusada aqui, antes de tocar em banco ou storage.
#
# O valor nunca e impresso, nem em erro.
# ---------------------------------------------------------------------------
[ -n "${APP_KEY:-}" ] \
  || erro "APP_KEY ausente ou vazia.
  Ela e gerada automaticamente pela subida oficial do ambiente:

      make up

  Uma execucao direta de 'docker compose up' com a chave vazia para aqui de
  proposito, em vez de subir uma aplicacao que nao consegue autenticar."

php -r '
    $chave = getenv("APP_KEY");
    if (! str_starts_with($chave, "base64:")) {
        fwrite(STDERR, "APP_KEY sem o prefixo base64: esperado pelo framework.\n");
        exit(1);
    }
    $bruta = base64_decode(substr($chave, 7), true);
    if ($bruta === false || strlen($bruta) !== 32) {
        fwrite(STDERR, "APP_KEY nao representa 32 bytes validos.\n");
        exit(1);
    }
' || erro "APP_KEY invalida. Remova a linha do .env da raiz e rode 'make up'
  novamente para gerar uma chave nova. O valor atual nao e exibido de proposito."

echo "setup: chave de criptografia presente e no formato esperado"

# ---------------------------------------------------------------------------
# 2. Dependencias da aplicacao.
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
# 3. Storage: bucket e politica de CORS.
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
# 4. Banco: migrations pendentes.
#
# `--force` porque, sem arquivo de ambiente, o ambiente declarado e o de
# producao e o migrador pede confirmacao interativa nele; aqui nao ha terminal
# para responder. `--no-interaction` cobre qualquer outra pergunta.
#
# Migration ja registrada nao roda de novo: o migrador compara a tabela de
# controle com os arquivos disponiveis e aplica so a diferenca.
# ---------------------------------------------------------------------------
php "$APP_ROOT/artisan" migrate --force --no-interaction

# ---------------------------------------------------------------------------
# 5. Dados de avaliacao.
#
# As contas de demonstracao, o curso da jornada e o cenario dedicado de falha.
# O seeder verifica cada registro pela chave primaria fixa e insere apenas o que
# falta, entao repetir a subida nao duplica nada nem reescreve o que a
# demonstracao tiver alterado.
#
# `set -eu` no topo garante o resto: uma falha aqui interrompe o script com
# codigo diferente de zero, e o Compose nao libera `api`, `worker` e
# `simulator-worker` sobre um banco preparado pela metade.
# ---------------------------------------------------------------------------
php "$APP_ROOT/artisan" db:seed --force --no-interaction

echo "setup: banco, storage e dados de avaliacao preparados"
exit 0
