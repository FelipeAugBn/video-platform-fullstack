# Subida do ambiente local com um comando.
#
#     make up
#
# Nada e exigido da maquina alem de Docker. As dependencias de PHP e de Node sao
# instaladas pelas proprias imagens do projeto, em containers descartaveis: nem
# interpretador, nem gerenciador de pacotes, nem cliente de banco precisam
# existir no host.
#
# A ordem das etapas nao e detalhe, e esta declarada nas dependencias entre os
# alvos:
#
#   1. conferir o arquivo de ambiente
#   2. construir ou atualizar as imagens
#   3. instalar as dependencias das aplicacoes, com essas imagens
#   4. subir os servicos
#   5. mostrar o estado
#
# Trocar a 2 pela 3 e o erro que passa despercebido: as dependencias seriam
# instaladas pela imagem anterior. Um interpretador novo declarado no Dockerfile
# so valeria a partir da subida seguinte, e ate la o codigo teria sido resolvido
# contra a versao velha — com o agravante de o resultado parecer correto.
#
# O alvo e idempotente no estado final: nao apaga volume, nao recria banco, nao
# reinstala dependencia ja atualizada e nao toca em arquivo de ambiente ja
# preenchido.

COMPOSE := docker compose

# Dono dos arquivos gerados dentro da arvore montada.
#
# Os containers escrevem em diretorios que vem do host por bind mount: as
# dependencias das duas aplicacoes e o build intermediario do Nuxt. Escritos por
# outro dono, esses diretorios ficariam inacessiveis a execucao seguinte, e a
# preparacao deixaria de ser repetivel. Exportadas, as duas variaveis chegam ao
# Compose e valem tambem para o servico da interface.
export APP_UID := $(shell id -u)
export APP_GID := $(shell id -g)
USUARIO := $(APP_UID):$(APP_GID)

AMBIENTE := .env
EXEMPLO := .env.example

# As duas imagens que o projeto constroi.
#
# `api` produz a imagem que `setup`, `worker` e `simulator-worker` tambem usam:
# sao ciclos de vida diferentes da mesma aplicacao, e o que muda entre eles e o
# comando, nunca a imagem. Nomear os quatro aqui mandaria construir a mesma
# coisa quatro vezes.
CANONICAS := api frontend

# Marcos de instalacao: arquivos reais, produzidos pelos proprios instaladores.
# Existindo e mais novos que os pre-requisitos, nao ha o que refazer — e o que
# torna a segunda subida barata.
AUTOLOAD := backend/vendor/autoload.php
MODULOS := frontend/node_modules/.package-lock.json

.DEFAULT_GOAL := up
.PHONY: up dependencias imagens ambiente

# Alvo oficial da entrega. As etapas 1 a 3 chegam pela cadeia de pre-requisitos.
up: dependencias
	$(COMPOSE) up --detach
	$(COMPOSE) ps

dependencias: $(AUTOLOAD) $(MODULOS)

# Etapa 1. Conferencia do arquivo de ambiente.
#
# Ele nunca e criado nem sobrescrito aqui. Um `.env` ja preenchido e a
# configuracao real da maquina, e substitui-lo por uma copia do exemplo apagaria
# o que estivesse la. Variavel faltando dentro do arquivo e reportada pelo
# proprio Compose, que nomeia cada uma.
ambiente:
	@test -f $(AMBIENTE) || { \
	  echo "$(AMBIENTE) nao existe. Crie-o a partir do exemplo e ajuste os valores locais:"; \
	  echo ""; \
	  echo "    cp $(EXEMPLO) $(AMBIENTE)"; \
	  echo ""; \
	  exit 1; \
	}

# Etapa 2. Construcao das imagens, sempre antes de qualquer instalacao.
#
# Aproveita o cache, entao custa poucos segundos quando nada mudou. A imagem do
# backend sai com o mesmo identificador a cada construcao; a do frontend ganha
# identificador novo mesmo sem alteracao alguma, e por isso o Compose recria
# aquele container na etapa 4. E inofensivo: nenhum outro servico depende do
# endereco dele, e quem encaminha requisicao ao interpretador nao fica preso ao
# endereco da inicializacao — resolve o nome dinamicamente, com a resposta em
# cache por ate cinco segundos.
imagens: | ambiente
	$(COMPOSE) build $(CANONICAS)

# Etapa 3. Instalacao das dependencias, com as imagens recem-construidas.
#
# O Dockerfile e pre-requisito de verdade, e nao apenas de ordem: ele carrega a
# versao do interpretador, e trocar de versao exige resolver as dependencias
# outra vez. Ja `imagens` entra como pre-requisito de ordem, para garantir a
# sequencia sem que construir sozinho dispare uma reinstalacao.
#
# `--no-deps` e obrigatorio: sem ele o Compose subiria a preparacao do ambiente,
# que exige exatamente o autoload que ainda nao existe. Instalar em separado
# rompe esse ciclo.
$(AUTOLOAD): backend/composer.json backend/composer.lock docker/backend/Dockerfile | imagens
	$(COMPOSE) run --rm --no-deps --user $(USUARIO) \
	  --env COMPOSER_HOME=/tmp/composer \
	  api composer install --no-interaction --prefer-dist
	@touch $@

# `npm ci` instala exatamente o que o arquivo de trava descreve, e falha quando
# ele diverge do manifesto — e o que mantem a instalacao reproduzivel.
$(MODULOS): frontend/package.json frontend/package-lock.json docker/frontend/Dockerfile | imagens
	$(COMPOSE) run --rm --no-deps --user $(USUARIO) \
	  --env HOME=/tmp \
	  frontend npm ci
	@touch $@
