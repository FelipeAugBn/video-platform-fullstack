import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Curso, CursosPaginados, EstruturaDoConsumidorEnvelope, ReproducaoEnvelope } from '~/types/catalogo'
import type { EstruturaEnvelope } from '~/types/video'
import { ErroDeApi } from '~/utils/erroDeApi'
import Login from '~/pages/login.vue'
import CursosDoProdutor from '~/pages/producer/courses/index.vue'
import EstruturaDoProdutor from '~/pages/producer/courses/[id].vue'
import Catalogo from '~/pages/catalog/index.vue'
import CursoDoCatalogo from '~/pages/catalog/courses/[id].vue'
import Reproducao from '~/pages/catalog/lessons/[id].vue'
import BarraDeSessao from '~/components/ui/BarraDeSessao.vue'

/**
 * Acessibilidade das duas jornadas (RF-UI-016; desafio §9.3).
 *
 * ## O que da para afirmar aqui, e o que nao da
 *
 * Acessibilidade **e** verificavel neste ambiente: marcos de regiao, hierarquia
 * de titulos, nomes acessiveis, associacao de rotulo, papeis, regioes dinamicas
 * e alcance por teclado sao afirmacoes sobre a arvore renderizada, e o DOM de
 * teste as enxerga.
 *
 * Responsividade **nao** e. Nao ha layout, nao ha consulta de midia e nao ha
 * viewport: uma assercao de largura aqui passaria sem que nada estivesse
 * responsivo, e o teste verde seria pior do que nenhum teste. A conferencia em
 * largura reduzida e inspecao manual em navegador, e nao esta neste arquivo.
 *
 * ## Alcance por teclado, provado pela construcao
 *
 * Em vez de simular teclas — que este ambiente nao traduz em ativacao como um
 * navegador faz —, os testes afirmam que **todo controle e um elemento nativo**:
 * `a[href]` ou `button`. Um `div` com escuta de clique passaria por qualquer
 * simulacao de clique e continuaria invisivel para o teclado; o elemento nativo
 * traz foco, ativacao por `Enter` e papel corretos de fabrica.
 */

const requisitar = vi.hoisted(() => vi.fn())
const entrar = vi.hoisted(() => vi.fn())
const sair = vi.hoisted(() => vi.fn())
const navegar = vi.hoisted(() => vi.fn())
const parametros = vi.hoisted(() => ({ id: 'c1' }))

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: parametros, query: {} }))
mockNuxtImport('navigateTo', () => navegar)

mockNuxtImport('useAuth', () => () => {
  const { usuario, estado } = useSessionState()

  return {
    usuario,
    estado,
    entrar,
    sair,
    autenticado: computed(() => estado.value === 'autenticada' && usuario.value !== null),
    carregando: computed(() => estado.value === 'verificando'),
    expirada: computed(() => estado.value === 'expirada'),
    verificada: computed(() => estado.value !== 'naoVerificada'),
    recuperarSessao: vi.fn(),
    destinoDoPerfil: (perfil: 'producer' | 'consumer') => (
      perfil === 'producer' ? '/producer/courses' : '/catalog'
    ),
  }
})

type Tela = Awaited<ReturnType<typeof mountSuspended>>

const CRIADO_EM = '2026-09-05T13:45:12+00:00'
const PUBLICADA_EM = '2026-09-06T10:00:00+00:00'

function curso(id: string, title: string): Curso {
  return {
    id,
    title,
    description: `Descricao de ${title}`,
    owner_id: 'produtor-1',
    state: 'available',
    created_at: CRIADO_EM,
  }
}

function paginaDe(cursos: Curso[], ultima = 1): CursosPaginados {
  return {
    data: cursos,
    meta: { current_page: 1, per_page: 15, total: cursos.length, last_page: ultima },
    links: { first: null, prev: null, next: null, last: null },
  }
}

function arvore(): EstruturaDoConsumidorEnvelope {
  return {
    data: {
      id: 'c1',
      title: 'Fundamentos de Video',
      description: 'Curso de demonstracao.',
      owner_id: 'produtor-1',
      state: 'available',
      created_at: CRIADO_EM,
      modules: [
        {
          id: 'm1',
          course_id: 'c1',
          title: 'Introducao',
          position: 1,
          lessons: [
            { id: 'l1', module_id: 'm1', title: 'Primeiros passos', position: 1, published_at: PUBLICADA_EM },
            { id: 'l2', module_id: 'm1', title: 'Indo alem', position: 2, published_at: PUBLICADA_EM },
          ],
        },
      ],
    },
  }
}

function reproducao(): ReproducaoEnvelope {
  return {
    data: {
      playback_url: 'https://storage.exemplo.test/videos/exemplo/original.mp4?assinatura=x',
      expires_at: '2026-09-06T12:05:00+00:00',
      content_type: 'video/mp4',
    },
  }
}

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

function autenticar(perfil: 'producer' | 'consumer' = 'consumer'): void {
  const { usuario, estado } = useSessionState()

  usuario.value = { id: 'u1', name: 'Pessoa Avaliadora', email: 'p@video.test', role: perfil }
  estado.value = 'autenticada'
}

/** Os niveis dos titulos, na ordem em que aparecem no documento. */
function niveisDeTitulo(tela: Tela): number[] {
  return tela.findAll('h1, h2, h3, h4, h5, h6')
    .map(titulo => Number(titulo.element.tagName.slice(1)))
}

/** Tudo que o teclado alcanca, na ordem do DOM. */
function focaveis(tela: Tela): Element[] {
  return [...tela.element.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )]
}

async function telaDoCatalogo(): Promise<Tela> {
  requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos'), curso('c2', 'Avancado')]))

  const tela = await mountSuspended(Catalogo)
  await assentar()

  return tela
}

async function telaDoCurso(): Promise<Tela> {
  requisitar.mockResolvedValue(arvore())

  const tela = await mountSuspended(CursoDoCatalogo)
  await assentar()

  return tela
}

async function telaDaReproducao(): Promise<Tela> {
  parametros.id = 'l1'
  requisitar.mockResolvedValue(reproducao())

  const tela = await mountSuspended(Reproducao)
  await assentar()

  return tela
}

/**
 * A estrutura do produtor, com as aulas ainda sem video.
 *
 * `video_state` nulo em todas: o painel de video so consulta o endereco proprio
 * de uma aula quando ha estado transitorio a acompanhar, entao a arvore e a
 * unica leitura e o dublê nao precisa distinguir caminhos.
 */
async function telaDaEstruturaDoProdutor(): Promise<Tela> {
  const arvoreDoProdutor: EstruturaEnvelope = {
    data: {
      id: 'c1',
      title: 'Fundamentos de Video',
      description: 'Curso de demonstracao.',
      owner_id: 'produtor-1',
      state: 'draft',
      created_at: CRIADO_EM,
      modules: [
        {
          id: 'm1',
          course_id: 'c1',
          title: 'Introducao',
          position: 1,
          lessons: [
            { id: 'l1', module_id: 'm1', title: 'Primeiros passos', position: 1, published_at: null, video_state: null },
          ],
        },
      ],
    },
  }

  requisitar.mockResolvedValue(arvoreDoProdutor)

  const tela = await mountSuspended(EstruturaDoProdutor)
  await assentar()

  return tela
}

async function telaDoProdutor(): Promise<Tela> {
  requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos')], 3))

  const tela = await mountSuspended(CursosDoProdutor)
  await assentar()

  return tela
}

beforeEach(() => {
  requisitar.mockReset()
  entrar.mockReset()
  sair.mockReset()
  navegar.mockReset()
  parametros.id = 'c1'

  const { usuario, estado } = useSessionState()
  usuario.value = null
  estado.value = 'anonima'
})

describe('marcos de regiao e hierarquia de titulos', () => {
  const TELAS: Array<[string, () => Promise<Tela>]> = [
    ['login', async () => mountSuspended(Login)],
    ['cursos do produtor', telaDoProdutor],
    ['estrutura do produtor', telaDaEstruturaDoProdutor],
    ['catalogo do consumidor', telaDoCatalogo],
    ['curso do consumidor', telaDoCurso],
    ['reproducao', telaDaReproducao],
  ]

  it.each(TELAS)('%s tem um unico main e comeca em h1', async (_nome, montar) => {
    const tela = await montar()

    // Um `main` por tela: dois marcos com o mesmo papel obrigam quem navega por
    // regioes a adivinhar qual deles e o conteudo.
    expect(tela.findAll('main')).toHaveLength(1)

    const niveis = niveisDeTitulo(tela)

    expect(niveis[0]).toBe(1)
    expect(niveis.filter(nivel => nivel === 1)).toHaveLength(1)
  })

  it.each(TELAS)('%s nao pula nivel de titulo', async (_nome, montar) => {
    const tela = await montar()
    const niveis = niveisDeTitulo(tela)

    // Saltar de `h1` para `h3` deixa um degrau vazio na arvore: quem navega por
    // titulos procura uma secao que nao existe.
    for (const [posicao, nivel] of niveis.entries()) {
      if (posicao > 0) {
        expect(nivel).toBeLessThanOrEqual(niveis[posicao - 1]! + 1)
      }
    }
  })

  it('as secoes sao nomeadas pelo proprio titulo', async () => {
    const tela = await telaDoCatalogo()
    const secao = tela.find('section[aria-labelledby]')

    const id = secao.attributes('aria-labelledby')

    // `aria-labelledby` apontando para um id inexistente nao nomeia nada, e a
    // secao volta a ser anonima sem que nada acuse o erro.
    expect(tela.find(`#${id}`).exists()).toBe(true)
    expect(tela.find(`#${id}`).text()).toBe('Cursos disponiveis')
  })
})

describe('nomes acessiveis de links e controles', () => {
  it('o link do curso e nomeado pelo titulo do curso', async () => {
    const tela = await telaDoCatalogo()
    const links = tela.findAll('[data-acao="abrir-curso"]')

    // "Ver detalhes" repetido em cada linha nao distingue um item do outro na
    // lista de links de um leitor de tela.
    expect(links.map(link => link.text())).toEqual(['Fundamentos', 'Avancado'])
  })

  it('o link da aula e nomeado pelo titulo da aula', async () => {
    const tela = await telaDoCurso()

    expect(tela.findAll('[data-acao="abrir-aula"]').map(link => link.text()))
      .toEqual(['Primeiros passos', 'Indo alem'])
  })

  it('nenhum link fica sem texto acessivel', async () => {
    for (const montar of [telaDoCatalogo, telaDoCurso, telaDaReproducao]) {
      requisitar.mockReset()

      const tela = await montar()

      for (const link of tela.findAll('a')) {
        const nome = link.text().trim() || link.attributes('aria-label')?.trim() || ''

        expect(nome.length).toBeGreaterThan(0)
      }
    }
  })

  it('a navegacao entre paginas nomeia a si mesma e anuncia a posicao', async () => {
    const tela = await telaDoProdutor()
    const paginacao = tela.find('[data-paginacao]')

    expect(paginacao.attributes('aria-label')).toBe('Paginacao')

    // A troca de pagina nao move o foco; sem o anuncio, quem usa leitor de tela
    // aciona "Proxima" e nada indica que a posicao mudou.
    expect(tela.find('[data-paginacao-posicao]').attributes('aria-live')).toBe('polite')
  })
})

describe('associacao de rotulos nos formularios', () => {
  const COM_FORMULARIO: Array<[string, () => Promise<Tela>]> = [
    ['login', async () => mountSuspended(Login)],
    ['cursos do produtor', telaDoProdutor],
    ['estrutura do produtor', telaDaEstruturaDoProdutor],
  ]

  it.each(COM_FORMULARIO)('%s liga cada rotulo a um campo existente', async (_nome, montar) => {
    const tela = await montar()
    const rotulos = tela.findAll('label[for]')

    expect(rotulos.length).toBeGreaterThan(0)

    for (const rotulo of rotulos) {
      const alvo = rotulo.attributes('for')

      // `for` apontando para um id que nao existe e pior do que rotulo nenhum:
      // parece correto na revisao e nao anuncia nada no uso.
      expect(tela.find(`#${alvo}`).exists()).toBe(true)
      expect(rotulo.text().trim().length).toBeGreaterThan(0)
    }
  })

  it('o erro de validacao e anunciado e ligado ao campo', async () => {
    entrar.mockRejectedValue(ErroDeApi.de({
      response: { status: 422 },
      status: 422,
      data: {
        type: 'https://x/y',
        title: 'Falha',
        status: 422,
        detail: 'Mensagem publica.',
        code: 'VALIDATION_FAILED',
        errors: { email: ['O campo e-mail e obrigatorio.'] },
      },
    }))

    const tela = await mountSuspended(Login)
    await tela.find('form').trigger('submit')
    await assentar()

    const campo = tela.find('#campo-email')

    expect(campo.attributes('aria-invalid')).toBe('true')
    expect(campo.attributes('aria-describedby')).toBe('erro-email')
    expect(tela.find('#erro-email').attributes('role')).toBe('alert')
  })
})

describe('papeis e regioes dinamicas', () => {
  it('o carregamento e anunciado sem interromper', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Catalogo)
    const carregando = tela.find('[data-estado="carregando"]')

    expect(carregando.attributes('role')).toBe('status')
    expect(carregando.attributes('aria-busy')).toBe('true')
    expect(carregando.attributes('aria-live')).toBe('polite')
  })

  it('a falha interrompe, e a lista vazia nao', async () => {
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const comFalha = await mountSuspended(Catalogo)
    await assentar()

    // `alert` interrompe a leitura para anunciar; `status` espera a proxima
    // pausa. Anunciar tudo com a mesma urgencia treina a ignorar o anuncio.
    expect(comFalha.find('[data-estado="falha"]').attributes('role')).toBe('alert')

    requisitar.mockReset()
    requisitar.mockResolvedValue(paginaDe([]))

    const semCursos = await mountSuspended(Catalogo)
    await assentar()

    expect(semCursos.find('[data-estado="vazio"]').attributes('role')).toBe('status')
  })

  it('a negativa de acesso tambem e anunciada como alerta', async () => {
    requisitar.mockRejectedValue(ErroDeApi.de({
      response: { status: 404 },
      status: 404,
      data: { type: 'https://x/y', title: 'Falha', status: 404, detail: 'Mensagem publica.', code: 'NOT_FOUND' },
    }))

    parametros.id = 'l1'

    const tela = await mountSuspended(Reproducao)
    await assentar()

    expect(tela.find('[data-estado="acesso-negado"]').attributes('role')).toBe('alert')
  })
})

describe('ordem de DOM e de foco', () => {
  it('o caminho de volta vem antes do conteudo na arvore do curso', async () => {
    const tela = await telaDoCurso()
    const ordem = focaveis(tela)

    expect(ordem[0]?.getAttribute('data-acao')).toBe('voltar-para-catalogo')
    expect(ordem.slice(1).map(elemento => elemento.getAttribute('data-acao')))
      .toEqual(['abrir-aula', 'abrir-aula'])
  })

  it('a regiao de foco pos-falha fica fora da ordem de tabulacao', async () => {
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))
    parametros.id = 'l1'

    const tela = await mountSuspended(Reproducao)
    await assentar()

    // Ela e destino de foco por codigo depois de uma tentativa que falhou, e nao
    // uma parada normal do teclado: entrar na tabulacao acrescentaria um passo
    // sem conteudo a cada volta.
    expect(tela.find('[data-regiao="negativa"]').attributes('tabindex')).toBe('-1')
    expect(focaveis(tela).map(elemento => elemento.getAttribute('data-regiao'))).not.toContain('negativa')
  })

  it('o controle de pagina desabilitado permanece no lugar', async () => {
    const tela = await telaDoProdutor()
    const anterior = tela.find('[data-acao="pagina-anterior"]')

    // Desabilitado, e nao escondido: um botao que some muda o alcance do teclado
    // a cada pagina, e quem navega por tabulacao perde a referencia.
    expect(anterior.exists()).toBe(true)
    expect(anterior.attributes('disabled')).toBeDefined()
  })
})

describe('todo controle e um elemento nativo', () => {
  const NATIVOS = new Set(['A', 'BUTTON', 'INPUT'])

  const TELAS: Array<[string, () => Promise<Tela>]> = [
    ['catalogo do consumidor', telaDoCatalogo],
    ['curso do consumidor', telaDoCurso],
    ['reproducao', telaDaReproducao],
    ['cursos do produtor', telaDoProdutor],
    ['estrutura do produtor', telaDaEstruturaDoProdutor],
  ]

  it.each(TELAS)('%s so usa link, botao ou campo', async (_nome, montar) => {
    const tela = await montar()
    const acoes = tela.findAll('[data-acao]')

    expect(acoes.length).toBeGreaterThan(0)

    for (const acao of acoes) {
      // Um `div` com escuta de clique passaria por qualquer simulacao de clique
      // e continuaria inalcancavel pelo teclado.
      expect(NATIVOS.has(acao.element.tagName)).toBe(true)
    }
  })
})

describe('o video traz controles e nome', () => {
  it('expoe os controles nativos e se anuncia', async () => {
    const tela = await telaDaReproducao()
    const video = tela.find('video[data-reprodutor]')

    // Os controles nativos ja vem com foco, teclado e rotulos que o navegador
    // mantem; reconstrui-los custaria a acessibilidade que eles trazem de fabrica.
    expect(video.attributes('controls')).toBeDefined()
    expect(video.attributes('aria-label')?.trim().length).toBeGreaterThan(0)
  })
})

describe('encerrar a sessao pela interface', () => {
  it('e um botao alcancavel pelo teclado, com nome visivel', async () => {
    autenticar('producer')

    const tela = await mountSuspended(BarraDeSessao, { attachTo: document.body })
    const botao = tela.find('[data-acao="sair"]')

    expect(botao.element.tagName).toBe('BUTTON')
    expect(botao.attributes('type')).toBe('button')
    expect(botao.text()).toContain('Sair')

    ;(botao.element as HTMLButtonElement).focus()

    expect(document.activeElement).toBe(botao.element)
  })

  it('leva ao login somente depois de o logout ser confirmado', async () => {
    autenticar('producer')
    sair.mockResolvedValue(undefined)

    const tela = await mountSuspended(BarraDeSessao)
    await tela.find('[data-acao="sair"]').trigger('click')
    await assentar()

    expect(sair).toHaveBeenCalledTimes(1)
    expect(navegar).toHaveBeenCalledWith('/login')
  })

  it('preserva a sessao visual quando o logout falha', async () => {
    autenticar('consumer')
    sair.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await mountSuspended(BarraDeSessao)
    await tela.find('[data-acao="sair"]').trigger('click')
    await assentar()

    // A sessao do outro lado continua de pe: deslogar visualmente aqui faria a
    // proxima tela parecer expirada sem ter expirado.
    expect(navegar).not.toHaveBeenCalled()
    expect(tela.find('[data-acao="sair"]').exists()).toBe(true)
    expect(tela.find('[data-estado="falha"]').attributes('role')).toBe('alert')

    // E o botao volta a ficar disponivel: ele continua sendo o caminho de saida.
    expect(tela.find('[data-acao="sair"]').attributes('disabled')).toBeUndefined()
  })

  it('a navegacao do perfil e um link nomeado para a propria area', async () => {
    autenticar('consumer')

    const tela = await mountSuspended(BarraDeSessao)
    const link = tela.find('[data-acao="ir-para-minha-area"]')

    expect(tela.find('nav[aria-label="Principal"]').exists()).toBe(true)
    expect(link.attributes('href')).toBe('/catalog')
    expect(link.text()).toBe('Catalogo')
  })

  it('nao aparece enquanto nao ha sessao', async () => {
    const tela = await mountSuspended(BarraDeSessao)

    // O login nao tem de onde sair, e um botao "Sair" ali sugeriria uma sessao
    // que nao existe.
    expect(tela.find('[data-barra-de-sessao]').exists()).toBe(false)
  })
})
