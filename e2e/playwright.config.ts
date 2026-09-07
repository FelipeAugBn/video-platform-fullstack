import { defineConfig, devices } from '@playwright/test'

/**
 * A jornada integrada, em navegador real contra a pilha completa.
 *
 * ## Quem sobe os servicos nao e daqui
 *
 * Nao ha `webServer`. A pilha e orquestrada pelo Compose e preparada por
 * `scripts/e2e.sh`, que para os consumidores da fila, recria e semeia a base,
 * devolve os consumidores e espera pelas verificacoes de saude antes de o
 * navegador comecar. Um `webServer` declarado aqui subiria um segundo servidor,
 * paralelo ao do ambiente, e o teste passaria a atravessar uma pilha que
 * ninguem usa.
 *
 * Por isso a base tambem nao tem valor padrao: rodar a ferramenta por fora do
 * caminho oficial precisa falhar com uma mensagem que diz qual e ele, e nao
 * apontar em silencio para um endereco plausivel.
 */
const BASE = process.env.E2E_BASE_URL

if (BASE === undefined || BASE === '') {
  throw new Error(
    'E2E_BASE_URL nao esta definida. A suite e executada por `make e2e`, que sobe a pilha, '
    + 'recria a base de dados e configura o encaminhamento das origens publicas.',
  )
}

export default defineConfig({
  testDir: './tests',

  /*
  | Fora da arvore do repositorio.
  |
  | O diretorio da suite e montado somente leitura, e mesmo que nao fosse, um
  | anexo de execucao nao deveria aparecer no `git status` de quem acabou de
  | rodar o teste. Como nao ha rastreamento, video nem captura de tela ligados,
  | o diretorio permanece vazio — e o container e descartado ao fim.
  */
  outputDir: '/tmp/playwright-artefatos',

  // Uma unica jornada, executada por um unico trabalhador. Paralelismo aqui nao
  // teria o que distribuir e disputaria a mesma base de dados recem-semeada.
  workers: 1,
  fullyParallel: false,

  /*
  | Sem repeticao.
  |
  | Um cenario que so passa na segunda tentativa nao provou a integracao: provou
  | que ela funciona as vezes. As esperas do teste sao por mudanca observavel da
  | interface, com limite proprio — e uma delas estourar e uma informacao que a
  | repeticao apagaria.
  */
  retries: 0,

  // Um `.only` esquecido reduziria a suite em silencio. Aqui ele quebra.
  forbidOnly: true,

  /*
  | O teto da jornada inteira.
  |
  | Ela atravessa autenticacao, criacao de modulo e aula, envio multipart real,
  | processamento assincrono por fila, publicacao, troca de sessao e reproducao.
  | O limite e generoso porque o custo de estourar por pouco e uma falha que nao
  | descreve defeito nenhum; as esperas internas e que sao especificas.
  */
  timeout: 240_000,

  expect: { timeout: 20_000 },

  // Saida legivel no terminal, e nada escrito em disco: relatorio em HTML
  // deixaria um diretorio para tras a cada execucao.
  reporter: [['list']],

  use: {
    baseURL: BASE,

    // Nenhum anexo. Sao uteis para investigar uma falha e inuteis no verde, e
    // gravados por padrao encheriam o `outputDir` a cada execucao.
    trace: 'off',
    video: 'off',
    screenshot: 'off',

    actionTimeout: 20_000,

    /*
    | A primeira navegacao espera o servidor de desenvolvimento do Nuxt compilar
    | a aplicacao sob demanda, o que na primeira vez leva mais que o padrao de
    | 30 segundos. As navegacoes seguintes sao do roteador do lado do cliente e
    | nem chegam a usar este limite.
    */
    navigationTimeout: 120_000,
  },

  // Somente Chromium: um segundo navegador multiplicaria o tempo da jornada sem
  // acrescentar garantia sobre a integracao, que e o que este nivel prova.
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
