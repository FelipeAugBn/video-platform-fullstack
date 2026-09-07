import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { expect, test } from '@playwright/test'
import type { Page, Request } from '@playwright/test'

/**
 * AC-E2E-001 — a jornada atravessa frontend e backend reais.
 *
 * Um unico cenario, e ele existe para provar o que nenhum outro nivel consegue:
 * que as pecas funcionam **juntas**. Tudo acontece pela interface, em navegador
 * real, contra Nuxt, Laravel, MySQL, fila, simulador e armazenamento de objetos
 * de verdade. Nada e preparado por chamada direta a API, nada e escrito no banco
 * e nada e simulado.
 *
 * ## Por que so um cenario
 *
 * Os caminhos de erro obrigatorios — publicacao bloqueada, consumidor sem
 * concessao, falha de processamento, API indisponivel, sessao expirada — ja sao
 * provados nas suites de backend e de frontend, cada um no nivel em que a regra
 * vive. Repeti-los aqui acrescentaria minutos e uma classe propria de
 * instabilidade sem acrescentar garantia.
 *
 * ## O que o cenario parte do seed
 *
 * O curso `Fundamentos de Producao de Video` e a concessao de acesso do
 * consumidor a ele. A jornada nao cria um curso novo porque nao existe operacao
 * para conceder acesso a um curso recem-criado (spec §4). Criar curso e provado
 * em AC-PROD-001, no nivel de feature.
 *
 * ## Sincronizacao
 *
 * Nenhuma espera fixa. Toda espera e por mudanca observavel da interface, com
 * limite proprio: o texto de uma confirmacao, um atributo que muda de valor, um
 * elemento que aparece. O unico limite generoso e o do processamento
 * assincrono, que atravessa fila, simulador e callback assinado.
 */

const CURSO = 'Fundamentos de Producao de Video'
const MODULO = 'Modulo da Jornada Integrada'
const AULA = 'Aula da Jornada Integrada'

const SENHA = 'VideoDemo2026!'
const PRODUTOR = 'producer@video-platform.test'
const CONSUMIDOR = 'consumer@video-platform.test'

/*
| As tres origens publicas, escritas por extenso.
|
| Elas sao o objeto do teste, e nao um detalhe de ambiente: e por continuarem
| sendo exatamente estas que o cookie host-only, o Sanctum, a politica de CORS e
| a assinatura da URL pre-assinada valem aqui como valem fora daqui.
*/
const INTERFACE = 'http://localhost:3000'
const API = 'http://localhost:8080'
const ARMAZENAMENTO = 'http://localhost:19000'

const CAMINHO_DA_FIXTURE = fileURLToPath(new URL('../fixtures/video-curto.mp4', import.meta.url))
const TAMANHO_DA_FIXTURE = readFileSync(CAMINHO_DA_FIXTURE).byteLength

/** Fila, simulador, callback assinado e a consulta de acompanhamento a cada 3s. */
const ESPERA_DO_PROCESSAMENTO = 120_000

interface RequisicaoMedida {
  metodo: string
  url: string
  /** Bytes de corpo que sairam pela rede. */
  corpo: number
}

/**
 * O tamanho do corpo **declarado ao servidor**, requisicao por requisicao.
 *
 * A medida vem de `Content-Length`, e nao do corpo capturado: `postDataBuffer()`
 * devolve `null` para um `Blob`, que e exatamente o caso do `PUT` de cada parte,
 * e `sizes()` deriva do mesmo corpo capturado, entao relata zero pelo mesmo
 * motivo. O cabecalho e o numero que o navegador enviou ao outro lado, e e ele
 * que responde as duas perguntas desta jornada: quantos bytes chegaram ao
 * armazenamento, e quantos chegaram a aplicacao.
 */
async function medir(requisicoes: Request[]): Promise<RequisicaoMedida[]> {
  return Promise.all(requisicoes.map(async requisicao => ({
    metodo: requisicao.method(),
    url: requisicao.url(),
    corpo: Number((await requisicao.allHeaders())['content-length'] ?? 0),
  })))
}

/**
 * A primeira navegacao, tolerante ao servidor de desenvolvimento ainda subindo.
 *
 * O servico do Nuxt nao tem verificacao de saude — e um servidor de
 * desenvolvimento, e a espera do script cobre `web`, `mysql` e `rustfs`. Numa
 * pilha recem-criada, a porta pode ainda nao estar aceitando conexao quando o
 * navegador chega. Isto **nao** e uma repeticao de teste: e uma sondagem de
 * prontidao, com limite proprio, e ela so recusa erro de conexao. Qualquer outra
 * falha sobe na hora.
 */
async function abrirLogin(page: Page): Promise<void> {
  const limite = Date.now() + 90_000

  for (;;) {
    try {
      await page.goto('/login')

      return
    }
    catch (causa) {
      const mensagem = causa instanceof Error ? causa.message : String(causa)
      const recusada = mensagem.includes('ERR_CONNECTION_REFUSED') || mensagem.includes('ECONNREFUSED')

      if (!recusada || Date.now() > limite) {
        throw causa
      }
    }
  }
}

async function autenticar(page: Page, email: string): Promise<void> {
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill(SENHA)
  await page.getByRole('button', { name: 'Entrar' }).click()
}

test('o produtor prepara a aula e o consumidor a assiste', async ({ page }) => {
  /*
  | Tudo o que o navegador pediu, na ordem.
  |
  | Duas perguntas sao respondidas com esta lista, e as duas sao o coracao de
  | RF-UPL-004: o arquivo subiu **direto** para o armazenamento, e ele nao
  | atravessou nem a API nem o servidor do Nuxt.
  */
  const concluidas: Request[] = []

  page.on('request', requisicao => concluidas.push(requisicao))

  // ---------------------------------------------------------------------
  // Produtor: entrar.
  // ---------------------------------------------------------------------
  await abrirLogin(page)
  await autenticar(page, PRODUTOR)

  await expect(page).toHaveURL(`${INTERFACE}/producer/courses`)
  await expect(page.locator('[data-barra-de-sessao] [data-usuario]')).toHaveText('Produtora de Demonstracao')

  // ---------------------------------------------------------------------
  // Abrir o curso preparado por seed. Nenhum curso novo e criado.
  // ---------------------------------------------------------------------
  const cartaoDoCurso = page.locator('[data-curso]').filter({ hasText: CURSO })
  await expect(cartaoDoCurso).toHaveCount(1)

  await cartaoDoCurso.getByRole('link', { name: CURSO }).click()

  await expect(page.getByRole('heading', { level: 1, name: CURSO })).toBeVisible()

  // O curso comeca em rascunho: e essa transicao que a publicacao vai provocar.
  await expect(page.locator('[data-curso] [data-estado-do-curso]')).toHaveAttribute('data-estado-do-curso', 'draft')

  // ---------------------------------------------------------------------
  // Criar o modulo.
  // ---------------------------------------------------------------------
  await page.getByLabel('Titulo do modulo').fill(MODULO)
  await page.getByRole('button', { name: 'Criar modulo' }).click()

  const modulo = page.locator('[data-modulo]').filter({ hasText: MODULO })
  await expect(modulo).toHaveCount(1)
  await expect(modulo.getByRole('heading', { level: 3, name: MODULO })).toBeVisible()

  // ---------------------------------------------------------------------
  // Criar a aula dentro dele.
  // ---------------------------------------------------------------------
  await modulo.getByLabel('Nova aula').fill(AULA)
  await modulo.getByRole('button', { name: 'Criar aula' }).click()

  const aula = page.locator('[data-aula]').filter({ hasText: AULA })
  await expect(aula).toHaveCount(1)
  await expect(aula.locator('[data-estado-do-video]')).toHaveAttribute('data-estado-do-video', 'sem-video')
  await expect(aula.locator('[data-publicacao]').first()).toHaveAttribute('data-publicacao', 'rascunho')

  // ---------------------------------------------------------------------
  // Enviar o video: multipart real, parte a parte, direto ao armazenamento.
  //
  // Escolher o arquivo e a unica acao. A abertura do envio, o pedido da URL
  // assinada, o `PUT` da parte e a conclusao acontecem porque a interface os
  // encadeia — nenhum deles e disparado por este teste.
  // ---------------------------------------------------------------------
  const entregaDaParte = page.waitForResponse(resposta => (
    resposta.request().method() === 'PUT' && resposta.url().startsWith(`${ARMAZENAMENTO}/`)
  ))

  await aula.getByLabel('Arquivo de video').setInputFiles(CAMINHO_DA_FIXTURE)

  // O armazenamento aceitou a parte e devolveu o comprovante que a conclusao
  // usa. A resposta vem dele, e nao da API: o `PUT` nunca passou pela aplicacao.
  const entrega = await entregaDaParte
  expect(entrega.status()).toBe(200)
  expect((await entrega.allHeaders()).etag).toBeTruthy()

  const painel = aula.locator('[data-painel-do-video]')
  await expect(painel).toContainText('Envio concluido', { timeout: 60_000 })

  // ---------------------------------------------------------------------
  // O `PUT` foi direto ao armazenamento, e o arquivo nao passou pela API nem
  // pelo Nuxt.
  // ---------------------------------------------------------------------
  const requisicoes = await medir(concluidas)

  const partes = requisicoes.filter(r => r.metodo === 'PUT' && r.url.startsWith(`${ARMAZENAMENTO}/`))

  // A fixture cabe numa unica parte do multipart, entao ha exatamente um `PUT`.
  expect(partes).toHaveLength(1)
  expect(partes[0]?.corpo).toBe(TAMANHO_DA_FIXTURE)

  // A parte foi assinada pela API — o pedido da URL — e entregue pelo navegador.
  expect(requisicoes.some(r => (
    r.metodo === 'POST' && /^http:\/\/localhost:8080\/api\/video-uploads\/[^/]+\/parts\/1\/url$/.test(r.url)
  ))).toBe(true)

  /*
  | A prova de que o arquivo nao trafegou pela aplicacao.
  |
  | Nenhuma requisicao a interface ou a API levou um corpo do tamanho do arquivo.
  | As chamadas de controle sao pequenas — JSON com identificadores e
  | comprovantes —, e sao elas que sobram aqui.
  */
  const pelaAplicacao = requisicoes.filter(r => r.url.startsWith(`${API}/`) || r.url.startsWith(`${INTERFACE}/`))
  expect(pelaAplicacao.length).toBeGreaterThan(0)
  expect(pelaAplicacao.filter(r => r.corpo >= TAMANHO_DA_FIXTURE)).toEqual([])

  // ---------------------------------------------------------------------
  // Processamento: fila, simulador e callback assinado, acompanhados pela
  // interface ate o video ficar pronto.
  // ---------------------------------------------------------------------
  await expect(painel).toHaveAttribute('data-situacao-do-video', 'pronto', { timeout: ESPERA_DO_PROCESSAMENTO })
  await expect(aula.locator('[data-estado-do-video]')).toHaveAttribute('data-estado-do-video', 'ready')

  // ---------------------------------------------------------------------
  // Publicar. O curso passa a `available` na mesma transacao (RN-CUR-002).
  // ---------------------------------------------------------------------
  await aula.locator('[data-acao="publicar"]').click()

  /*
  | A confirmacao fica na pagina, e nao dentro da aula.
  |
  | Publicar releva a estrutura inteira — e a primeira publicacao do curso que o
  | torna `available`, e esse estado esta no cabecalho, nao na aula —, entao a
  | lista e redesenhada e o aviso local da acao nao sobrevive a releitura. Quem
  | confirma e a pagina.
  */
  await expect(page.locator('[data-estado="sucesso"]').filter({ hasText: 'Aula publicada' })).toBeVisible()
  await expect(aula.locator('[data-publicacao="publicada"]')).toBeVisible()
  await expect(page.locator('[data-curso] [data-estado-do-curso]')).toHaveAttribute('data-estado-do-curso', 'available')

  // ---------------------------------------------------------------------
  // Encerrar a sessao pela interface. Nenhum cookie e apagado por fora: e o
  // backend que invalida a sessao, e e isso que precisa funcionar.
  // ---------------------------------------------------------------------
  await page.locator('[data-barra-de-sessao] [data-acao="sair"]').click()

  await expect(page).toHaveURL(`${INTERFACE}/login`)
  await expect(page.locator('[data-barra-de-sessao]')).toHaveCount(0)

  // ---------------------------------------------------------------------
  // Consumidor: entrar e encontrar o mesmo curso no catalogo.
  // ---------------------------------------------------------------------
  await autenticar(page, CONSUMIDOR)

  await expect(page).toHaveURL(`${INTERFACE}/catalog`)
  await expect(page.locator('[data-barra-de-sessao] [data-usuario]')).toHaveText('Consumidor de Demonstracao')

  const cursoNoCatalogo = page.locator('[data-curso]').filter({ hasText: CURSO })
  await expect(cursoNoCatalogo).toHaveCount(1)

  await cursoNoCatalogo.getByRole('link', { name: CURSO }).click()

  // ---------------------------------------------------------------------
  // Navegar pelo modulo e pela aula publicados.
  // ---------------------------------------------------------------------
  await expect(page.getByRole('heading', { level: 1, name: CURSO })).toBeVisible()

  const moduloPublicado = page.locator('[data-modulo]').filter({ hasText: MODULO })
  await expect(moduloPublicado).toHaveCount(1)

  const aulaPublicada = moduloPublicado.locator('[data-aula]').filter({ hasText: AULA })
  await expect(aulaPublicada).toHaveCount(1)

  /*
  | A resposta de reproducao, capturada da propria navegacao.
  |
  | Ela e lida para conferir que o que chegou ao elemento de video e o que a API
  | entregou — e nao para preparar ou concluir etapa alguma.
  */
  const reproducao = page.waitForResponse(
    resposta => /\/api\/lessons\/[^/]+\/playback$/.test(resposta.url()) && resposta.request().method() === 'GET',
  )

  await aulaPublicada.locator('[data-acao="abrir-aula"]').click()

  const resposta = await reproducao
  expect(resposta.status()).toBe(200)

  const corpo = await resposta.json()
  const dados = corpo.data as { playback_url: string, content_type: string, expires_at: string }

  expect(dados.content_type).toBe('video/mp4')

  // A permissao tem prazo, e ele ainda nao passou: a URL foi emitida agora, para
  // esta sessao, e nao e um endereco permanente do arquivo (RF-PLB-005).
  expect(Date.parse(dados.expires_at)).toBeGreaterThan(Date.now())

  // URL pre-assinada, emitida pelo backend sobre o host publico do armazenamento.
  expect(dados.playback_url.startsWith(`${ARMAZENAMENTO}/`)).toBe(true)
  expect(dados.playback_url).toContain('X-Amz-Signature=')

  // ---------------------------------------------------------------------
  // Os dados reais alimentaram o elemento de video.
  // ---------------------------------------------------------------------
  const video = page.locator('video[data-reprodutor]')
  await expect(video).toBeVisible()
  await expect(video).toHaveAttribute('aria-label', 'Video da aula')
  await expect(video.locator('[data-fonte]')).toHaveAttribute('src', dados.playback_url)
  await expect(video.locator('[data-fonte]')).toHaveAttribute('type', 'video/mp4')

  /*
  | E a URL entregue serve o objeto que subiu.
  |
  | O elemento de video prova que a interface recebeu o endereco certo; esta
  | leitura prova que o endereco resolve para os bytes que a jornada enviou. Sem
  | ela, uma URL bem formada e vazia passaria despercebida — e decodificar o
  | video no navegador provaria o codec, nao a integracao.
  */
  const objeto = await page.request.get(dados.playback_url)
  expect(objeto.status()).toBe(200)
  expect((await objeto.body()).byteLength).toBe(TAMANHO_DA_FIXTURE)
})
