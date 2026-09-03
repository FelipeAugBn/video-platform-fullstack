# Imagem PHP compartilhada pelos servicos `api`, `worker`, `simulator-worker` e
# `setup`. Sao ciclos de vida diferentes da mesma aplicacao, nao microservicos:
# o que muda entre eles e o comando, nunca a imagem (plan §16.1).
#
# Sem servidor HTTP aqui. Este container fala FastCGI; quem responde HTTP e o
# servico `web`, a frente dele. Um healthcheck HTTP apontado para ca falharia
# sempre (plan §16.2).
#
# Referencias de imagem com tag exata e digest. A tag descreve a intencao e o
# digest garante o conteudo: tags de registry sao mutaveis, digests nao.
#
# Nenhuma tag movel: uma referencia que sempre aponta para a construcao mais
# recente tornaria irreprodutivel exatamente o ambiente cujo proposito e ser
# reproduzivel (RNF-020).

# ---------------------------------------------------------------------------
# Estagio unico de origem: o binario do Composer.
#
# A imagem do Composer entra so como doadora do executavel. Usa-la como base
# traria junto o PHP dela — 8.5 — e a aplicacao seria construida sobre uma
# versao que nao e a declarada. O executavel e copiado sem trazer o PHP da
# imagem doadora, e foi validado rodando sobre o PHP 8.4.25 da imagem final.
# ---------------------------------------------------------------------------
FROM composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer

# ---------------------------------------------------------------------------
# Imagem final.
# ---------------------------------------------------------------------------
FROM php:8.4.25-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e

# `git` e `unzip` servem ao Composer: ele busca pacotes por VCS quando nao ha
# dist disponivel e extrai os pacotes baixados. As listas do apt sao removidas
# no mesmo layer — apagadas em um layer posterior elas continuariam ocupando
# espaco no layer que as criou.
RUN apt-get update \
 && apt-get install --no-install-recommends --yes \
      git \
      unzip \
 && rm -rf /var/lib/apt/lists/*

# Extensoes que a imagem oficial nao traz:
#
#   pdo_mysql  conexao com o MySQL 8. A imagem traz PDO e pdo_sqlite, nao o
#              driver do MySQL. O banco de teste tambem e MySQL real, entao nao
#              existe caminho em que o driver seja dispensavel (plan §17.2).
#   pcntl      sinais no processo. `queue:work` usa pcntl para desligar entre
#              jobs ao receber SIGTERM, em vez de morrer no meio de um. Sem ela
#              um `docker compose stop` pode interromper um job pela metade.
#
# As demais que Laravel e o SDK S3 exigem ja vem na imagem oficial e nao sao
# reinstaladas: curl, dom, fileinfo, mbstring, openssl, PDO, session,
# SimpleXML, tokenizer e xml.
RUN docker-php-ext-install --jobs "$(nproc)" \
      pdo_mysql \
      pcntl

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Nome fixo no diretorio de configuracoes adicionais, que o PHP le em ordem
# alfabetica. `99-app.ini` cai antes dos `docker-php-ext-*.ini` da imagem
# oficial — `9` precede `d` —, e isso nao gera conflito porque aqueles arquivos
# so declaram `extension=` e `zend_extension=`, sem definir diretiva alguma. Os
# valores efetivos sao conferidos na validacao da tarefa, nao presumidos pela
# posicao do arquivo.
COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

# Sem `COPY` da aplicacao: o codigo chega por bind mount, para que uma edicao no
# host apareca no container sem reconstruir a imagem.

# O entrypoint apenas encaminha. Trocar o comando basta para obter os outros
# servicos — o consumidor da fila, o do simulador e o de inicializacao — sobre
# esta mesma imagem.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
