# Imagem do navegador que executa a jornada integrada.
#
# **Nao e um nono servico do ambiente.** Ela vive num profile proprio no
# Compose, nao sobe com `docker compose up` e nao deixa container parado: existe
# enquanto a jornada roda e some depois.
#
# Referencia com tag exata e digest, como as demais imagens do projeto. A tag
# descreve a intencao e o digest garante o conteudo, porque tag de registry e
# mutavel e digest nao — e o que sustenta a promessa de ambiente reproduzivel
# (RNF-020).
#
# A imagem oficial ja traz os navegadores instalados e casados com a versao da
# ferramenta: `v1.63.0-noble` carrega exatamente os binarios que o
# `@playwright/test@1.63.0` do `package.json` espera encontrar. Versao da imagem
# e versao do pacote sao o mesmo numero de proposito — divergentes, a ferramenta
# recusa a execucao dizendo que o navegador nao esta instalado.
FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27

WORKDIR /e2e

# O manifesto vive **aqui**, ao lado do Dockerfile, e nao em `e2e/`.
#
# Isso mantem o contexto de construcao minimo — dois arquivos JSON e um script —
# e separa duas coisas que sao de fato diferentes: a imagem fixa a versao da
# ferramenta, e `e2e/` guarda a suite. A consequencia pratica e que o diretorio
# da suite, montado somente leitura na execucao, nao precisa conter dependencia
# alguma.
#
# O manifesto tambem declara `"type": "module"`, e nao por moda: ele e o
# `package.json` mais proximo da configuracao e do teste, e e ele que decide como
# os dois sao carregados. Sem essa linha, `import.meta.url` — que localiza a
# fixture a partir do proprio arquivo de teste — quebraria na leitura.
COPY package.json package-lock.json ./

# `npm ci` instala exatamente o que o arquivo de trava descreve e falha quando
# ele diverge do manifesto. O cache e descartado na mesma camada: guardado, ele
# so aumentaria a imagem, ja que nada mais sera instalado aqui.
#
# `--ignore-scripts` porque o unico script relevante do pacote baixaria
# navegadores, e eles ja estao na imagem, em `/ms-playwright`.
RUN npm ci --ignore-scripts \
 && npm cache clean --force

# O ponto de entrada prepara o encaminhamento das origens publicas antes de
# executar o comando recebido. Fica fora de `/e2e` para nao ser encoberto pelas
# montagens da suite.
COPY encaminhador.mjs /opt/e2e/encaminhador.mjs

# Sem `HOME` definido, o gerenciador de pacotes tentaria escrever cache no
# diretorio pessoal do usuario, que pode nao existir na imagem.
ENV HOME=/tmp

ENTRYPOINT ["node", "/opt/e2e/encaminhador.mjs"]

# A entrada oficial e `make e2e`, que passa este mesmo comando explicitamente. O
# padrao existe para que a imagem seja executavel sozinha durante um diagnostico.
CMD ["npx", "playwright", "test"]
