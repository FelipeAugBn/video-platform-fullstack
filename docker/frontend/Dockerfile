# Imagem de desenvolvimento do frontend Nuxt.
#
# Referencia com tag exata e digest. A tag descreve a intencao e o digest
# garante o conteudo, porque tag de registry e mutavel e digest nao — e o que
# sustenta a promessa de ambiente reproduzivel (RNF-020).
#
# Nenhuma tag movel: uma referencia que sempre aponta para a construcao mais
# recente tornaria irreprodutivel exatamente o ambiente cujo proposito e ser
# reproduzivel.
FROM node:24.20.0-bookworm-slim@sha256:ba849c60be29959425b8734d57b8b4b7d56f98edd9504c9af091d5281095a71e

# Sem pacote de sistema adicional. A imagem `slim` ja traz Node e npm, que e
# tudo que o Nuxt precisa; acrescentar compilador ou utilitario de rede so
# aumentaria a superficie e o tempo de construcao sem uso previsto.

WORKDIR /app

# Sem `COPY` da aplicacao e sem instalar dependencia na construcao. O codigo e o
# `node_modules` chegam por bind mount, entao uma edicao no host aparece no
# container sem reconstruir a imagem, e trocar de dependencia nao invalida
# layer nenhum.
#
# A consequencia deliberada e que esta imagem nao carrega versao de biblioteca:
# quem determina o que sera instalado e o `package-lock.json` do repositorio,
# via `npm ci`. A imagem fixa apenas o runtime.

# Porta do servidor de desenvolvimento do Nuxt. Declaracao documental — quem
# publica de fato e o servico do Compose, em T012.
EXPOSE 3000

# `--host 0.0.0.0` faz o servidor escutar em todas as interfaces do container.
# No padrao, ele atende apenas em `localhost` de dentro do container, e o
# encaminhamento de porta do host nao alcancaria nada.
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
