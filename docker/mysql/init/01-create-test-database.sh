#!/bin/sh
#
# Cria o schema de testes e concede ao usuario da aplicacao acesso aos dois
# bancos.
#
# A imagem oficial cria automaticamente apenas o banco de MYSQL_DATABASE. A
# suite de testes precisa de um schema proprio, para que rodar os testes nunca
# apague os dados preparados para avaliacao.
#
# E outro schema na mesma instancia, nao um segundo container: o banco de teste
# tem que ser o mesmo MySQL que a aplicacao usa em execucao, porque e
# exatamente o comportamento de lock, constraint e transacao dele que os testes
# precisam exercer (plan §17.2).
#
# Executado pelo entrypoint da imagem somente na primeira inicializacao, quando
# o volume ainda esta vazio. Ainda assim o SQL e idempotente: reexecutar depois
# de recriar o volume nao pode falhar pela metade.
#
# Entrada, por variavel de ambiente, todas definidas no servico do Compose:
#   MYSQL_TEST_DATABASE  nome do schema de testes
#   MYSQL_DATABASE       nome do schema principal
#   MYSQL_USER           usuario da aplicacao, criado pelo entrypoint
#   MYSQL_ROOT_PASSWORD  credencial administrativa

set -eu

erro() {
  echo "criar-banco-de-testes: $*" >&2
  exit 1
}

for nome in MYSQL_TEST_DATABASE MYSQL_DATABASE MYSQL_USER MYSQL_ROOT_PASSWORD; do
  eval "valor=\${$nome:-}"
  [ -n "$valor" ] || erro "variavel de ambiente $nome ausente ou vazia"
done

# Nome de banco e de usuario entram na consulta como identificador, e
# identificador nao aceita parametro vinculado. A protecao, entao, e recusar
# antes de montar o SQL: so letras, digitos e sublinhado, ate 64 caracteres, que
# e o limite do MySQL. Qualquer coisa fora disso aborta em vez de ser escapada.
validar_identificador() {
  case "$2" in
    *[!A-Za-z0-9_]* | '')
      erro "$1 invalido: use apenas letras, digitos e sublinhado"
      ;;
  esac

  [ "${#2}" -le 64 ] || erro "$1 excede 64 caracteres"
}

validar_identificador MYSQL_TEST_DATABASE "$MYSQL_TEST_DATABASE"
validar_identificador MYSQL_DATABASE "$MYSQL_DATABASE"
validar_identificador MYSQL_USER "$MYSQL_USER"

# Os dois schemas precisam ser distintos. Apontar ambos para o mesmo nome faria
# a preparacao e a limpeza da suite de testes atuarem sobre os dados destinados
# a avaliacao. A mensagem de erro cita os nomes das variaveis, sem repetir os
# valores configurados.
[ "$MYSQL_TEST_DATABASE" != "$MYSQL_DATABASE" ] \
  || erro "MYSQL_TEST_DATABASE deve ser diferente de MYSQL_DATABASE"

echo "criar-banco-de-testes: preparando '$MYSQL_TEST_DATABASE'"

# A senha vai por MYSQL_PWD, nunca como argumento: argumento de processo e
# legivel por qualquer `ps` dentro do container. O SQL chega pela entrada
# padrao, sem arquivo temporario com credencial no disco.
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql \
  --protocol=socket \
  --user=root \
  --batch \
  <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_TEST_DATABASE}\`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${MYSQL_TEST_DATABASE}\`.* TO '${MYSQL_USER}'@'%';

FLUSH PRIVILEGES;
SQL

echo "criar-banco-de-testes: '$MYSQL_TEST_DATABASE' pronto e concedido"
