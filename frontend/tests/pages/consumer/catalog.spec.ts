import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type {
  AulaDoConsumidor,
  Curso,
  CursosPaginados,
  EstruturaDoConsumidorEnvelope,
  ModuloComAulasPublicadas,
  PaginacaoMeta,
  Reproducao,
  ReproducaoEnvelope,
} from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'
import Catalogo from '~/pages/catalog/index.vue'
import CursoDoCatalogo from '~/pages/catalog/courses/[id].vue'
import Reproducao_ from '~/pages/catalog/lessons/[id].vue'

/**
 * A jornada do consumidor: catalogo, arvore e reproducao (RF-CONS-001 a 005;
 * RF-PLB-005; AC-CONS-001, AC-CONS-004; RF-UI-001, RF-UI-002).
 *
 * ## O dublê e `useApi`, como nas telas do produtor
 *
 * Substituindo o composable, uma pagina que abrisse seu proprio cliente HTTP
 * **nao seria exercitada por estes testes** — ela chamaria `$fetch` de verdade e
 * falharia por falta de rede. Substituir `$fetch` teria o efeito contrario:
 * qualquer caminho ate a rede passaria, inclusive um sem credenciais e sem
 * traducao de erro.
 *
 * ## A afirmacao que atravessa o arquivo
 *
 * **A tela nao decide o que aparece.** O recorte por concessao, o filtro de
 * rascunhos e a contagem da paginacao acontecem na consulta; os testes de ordem
 * falham se a tela ordenar, e os de conteudo falham se ela esconder algo que a
 * resposta trouxe.
 */

const requisitar = vi.hoisted(() => vi.fn())
const parametros = vi.hoisted(() => ({ id: 'c1' }))

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: parametros }))

interface Opcoes {
  method?: string
  query?: Record<string, unknown>
}

interface Adiada<T> {
  promessa: Promise<T>
  resolver: (valor: T) => void
  rejeitar: (causa: unknown) => void
}

const CRIADO_EM = '2026-09-05T13:45:12+00:00'
const PUBLICADA_EM = '2026-09-06T10:00:00+00:00'
const EXPIRA_EM = '2026-09-06T12:05:00+00:00'
const URL_ASSINADA = 'https://storage.exemplo.test/videos/exemplo/original.mp4?assinatura=x'

function curso(id: string, title: string, extras: Partial<Curso> = {}): Curso {
  return {
    id,
    title,
    description: `Descricao de ${title}`,
    owner_id: 'produtor-1',
    state: 'available',
    created_at: CRIADO_EM,
    ...extras,
  }
}

function paginaDe(cursos: Curso[], meta: Partial<PaginacaoMeta> = {}): CursosPaginados {
  return {
    data: cursos,
    meta: {
      current_page: 1,
      per_page: 15,
      total: cursos.length,
      last_page: 1,
      ...meta,
    },
    links: { first: null, prev: null, next: null, last: null },
  }
}

function aula(id: string, title: string, position: number): AulaDoConsumidor {
  return { id, module_id: 'm1', title, position, published_at: PUBLICADA_EM }
}

function modulo(id: string, title: string, position: number, lessons: AulaDoConsumidor[] = []): ModuloComAulasPublicadas {
  return { id, course_id: 'c1', title, position, lessons }
}

function arvore(modules: ModuloComAulasPublicadas[], estado: Curso['state'] = 'available'): EstruturaDoConsumidorEnvelope {
  return {
    data: {
      id: 'c1',
      title: 'Fundamentos de Video',
      description: 'Curso de demonstracao.',
      owner_id: 'produtor-1',
      state: estado,
      created_at: CRIADO_EM,
      modules,
    },
  }
}

function reproducao(extras: Partial<Reproducao> = {}): ReproducaoEnvelope {
  return {
    data: {
      playback_url: URL_ASSINADA,
      expires_at: EXPIRA_EM,
      content_type: 'video/mp4',
      ...extras,
    },
  }
}

function adiada<T>(): Adiada<T> {
  let resolver!: (valor: T) => void
  let rejeitar!: (causa: unknown) => void

  const promessa = new Promise<T>((paraResolver, paraRejeitar) => {
    resolver = paraResolver
    rejeitar = paraRejeitar
  })

  return { promessa, resolver, rejeitar }
}

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

function leituras(): unknown[][] {
  return requisitar.mock.calls
}

function mutacoes(): unknown[][] {
  return requisitar.mock.calls.filter(([, opcoes]) => ((opcoes as Opcoes | undefined)?.method ?? 'GET') !== 'GET')
}

beforeEach(() => {
  requisitar.mockReset()
  parametros.id = 'c1'
})

describe('catalogo — estados da listagem', () => {
  it('mostra carregamento, e nao lista vazia, enquanto a resposta nao chega', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Catalogo)

    // Sem esta distincao, "ainda nao chegou" e "nao ha nada liberado" ficam
    // identicos, e a pessoa conclui a segunda coisa (RF-UI-001).
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)
    expect(tela.find('[data-vazio="catalogo"]').exists()).toBe(false)
    expect(tela.find('[data-lista="cursos"]').exists()).toBe(false)
  })

  it('lista vazia e um estado proprio, e nao um erro', async () => {
    requisitar.mockResolvedValue(paginaDe([]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    const vazio = tela.find('[data-vazio="catalogo"]')

    expect(vazio.exists()).toBe(true)
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-estado="acesso-negado"]').exists()).toBe(false)

    // `status`, e nao `alert`: nao ter curso liberado nao e uma urgencia, e
    // anunciar tudo com a mesma interrupcao treina a ignorar o anuncio.
    expect(vazio.find('[data-estado="vazio"]').attributes('role')).toBe('status')
  })

  it('o vazio fala do acesso, e nao do acervo', async () => {
    requisitar.mockResolvedValue(paginaDe([]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    const texto = tela.find('[data-vazio="catalogo"]').text().toLowerCase()

    expect(texto).toContain('nenhum curso disponivel para este acesso')

    // Dizer que nao ha cursos "na plataforma" contaria a quem nao tem concessao
    // o que existe fora dela — a mesma revelacao que RN-PROP-005 fecha na API.
    for (const vazamento of ['nao existe', 'nao existem', 'nenhum curso foi criado', 'plataforma', 'ainda nao ha cursos']) {
      expect(texto).not.toContain(vazamento)
    }
  })

  it('falha recuperavel repete somente a leitura', async () => {
    requisitar
      .mockRejectedValueOnce(ErroDeApi.de(new Error('Failed to fetch')))
      .mockResolvedValueOnce(paginaDe([curso('c1', 'Fundamentos')]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('rede')

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    expect(tela.find('[data-curso-id="c1"]').exists()).toBe(true)

    // Duas leituras do mesmo endereco, e nenhuma mutacao: a nova tentativa
    // repete o `GET`, e nada alem dele.
    expect(leituras()).toHaveLength(2)
    expect(leituras().every(([caminho]) => caminho === '/api/catalog/courses')).toBe(true)
    expect(mutacoes()).toHaveLength(0)
  })
})

describe('catalogo — ordem, paginacao e ausencia de filtro', () => {
  it('preserva a ordem recebida', async () => {
    // Titulos em ordem alfabetica **inversa** a de chegada: uma ordenacao por
    // titulo inverteria a lista, e so a ausencia de ordenacao reproduz o array.
    requisitar.mockResolvedValue(paginaDe([
      curso('c1', 'Zebra'),
      curso('c2', 'Meio'),
      curso('c3', 'Alfa'),
    ]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    expect(tela.findAll('[data-curso-id]').map(item => item.attributes('data-curso-id')))
      .toEqual(['c1', 'c2', 'c3'])
  })

  it('nao refaz a regra de disponibilidade sobre o que a API devolveu', async () => {
    // A API so devolve cursos em `available`; se um dia devolver outra coisa, a
    // tela mostra o que recebeu. Refiltrar aqui nao acrescentaria protecao — o
    // backend ja decidiu — e esvaziaria paginas que `meta` prometeu cheias.
    requisitar.mockResolvedValue(paginaDe([
      curso('c1', 'Disponivel'),
      curso('c2', 'Rascunho', { state: 'draft' }),
    ]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    expect(tela.findAll('[data-curso-id]')).toHaveLength(2)
    expect(tela.find('[data-curso-id="c2"]').exists()).toBe(true)

    // Nenhum parametro de filtro sai daqui: paginacao, e nada mais.
    const [, opcoes] = leituras()[0] as [string, Opcoes]
    expect(Object.keys(opcoes.query ?? {}).sort()).toEqual(['page', 'per_page'])
  })

  it('navega pelas paginas usando o meta da resposta', async () => {
    requisitar
      .mockResolvedValueOnce(paginaDe([curso('c1', 'Primeiro')], { current_page: 1, last_page: 3, per_page: 20, total: 41 }))
      .mockResolvedValueOnce(paginaDe([curso('c2', 'Segundo')], { current_page: 2, last_page: 3, per_page: 20, total: 41 }))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    expect(tela.find('[data-paginacao-posicao]').text()).toBe('Pagina 1 de 3')

    await tela.find('[data-acao="pagina-proxima"]').trigger('click')
    await assentar()

    const [, opcoes] = leituras()[1] as [string, Opcoes]

    // O tamanho pedido e o **confirmado** pela API, e nao o que a tela imaginou:
    // voltar ao padrao na virada pularia itens entre uma pagina e outra.
    expect(opcoes.query).toEqual({ page: 2, per_page: 20 })
    expect(tela.find('[data-paginacao-posicao]').text()).toBe('Pagina 2 de 3')
    expect(tela.find('[data-curso-id="c2"]').exists()).toBe(true)
  })

  it('nao mostra paginacao quando ha uma unica pagina', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Unico')]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    expect(tela.find('[data-paginacao]').exists()).toBe(false)
  })
})

describe('catalogo — leituras concorrentes', () => {
  /**
   * Duas paginas pedidas antes de a primeira responder.
   *
   * E o caso real de dois cliques seguidos: o segundo acontece no mesmo quadro,
   * antes de a tela trocar para o carregamento. Nada na rede garante que as
   * respostas voltem na ordem em que sairam, e a antiga chegando por ultimo
   * recolocaria a pagina anterior na tela — com a numeracao dizendo outra coisa.
   */
  async function comDuasPaginasEmVoo() {
    const emVoo: Adiada<CursosPaginados>[] = []

    requisitar.mockImplementation(() => {
      const proxima = adiada<CursosPaginados>()
      emVoo.push(proxima)

      return proxima.promessa
    })

    const tela = await mountSuspended(Catalogo)
    emVoo[0]!.resolver(paginaDe([curso('c2', 'Segundo')], { current_page: 2, last_page: 3, per_page: 20, total: 41 }))
    await assentar()

    // Os dois cliques sem `await` entre eles: o segundo alcanca o botao antes de
    // a re-renderizacao esconder a navegacao.
    void tela.find('[data-acao="pagina-anterior"]').trigger('click')
    void tela.find('[data-acao="pagina-proxima"]').trigger('click')
    await nextTick()

    expect(emVoo).toHaveLength(3)

    return { tela, emVoo }
  }

  it('ignora a resposta antiga que chega depois da mais recente', async () => {
    const { tela, emVoo } = await comDuasPaginasEmVoo()

    emVoo[2]!.resolver(paginaDe([curso('c3', 'Terceiro')], { current_page: 3, last_page: 3, per_page: 20, total: 41 }))
    await assentar()

    expect(tela.find('[data-curso-id="c3"]').exists()).toBe(true)

    // So agora chega a leitura que saiu antes, trazendo a pagina 1.
    emVoo[1]!.resolver(paginaDe([curso('c1', 'Primeiro')], { current_page: 1, last_page: 3, per_page: 20, total: 41 }))
    await assentar()

    expect(tela.findAll('[data-curso-id]').map(item => item.attributes('data-curso-id'))).toEqual(['c3'])
    expect(tela.find('[data-paginacao-posicao]').text()).toBe('Pagina 3 de 3')

    // O `finally` da leitura antiga tambem nao pode reabrir o carregamento de
    // outra: a tela ja terminou de carregar.
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
  })

  it('ignora a falha antiga que chega depois de uma leitura nova bem-sucedida', async () => {
    const { tela, emVoo } = await comDuasPaginasEmVoo()

    emVoo[2]!.resolver(paginaDe([curso('c3', 'Terceiro')], { current_page: 3, last_page: 3, per_page: 20, total: 41 }))
    await assentar()

    emVoo[1]!.rejeitar(ErroDeApi.de(new Error('Failed to fetch')))
    await assentar()

    // Uma lista carregada trocada por um erro que descreve uma requisicao ja
    // superada — e com um botao convidando a repetir o que nao precisa ser.
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-curso-id="c3"]').exists()).toBe(true)
  })
})

describe('navegacao — curso, modulos e aulas', () => {
  it('o cartao do catalogo leva para a arvore do consumidor', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos')]))

    const tela = await mountSuspended(Catalogo)
    await assentar()

    const link = tela.find('[data-curso-id="c1"] [data-acao="abrir-curso"]')

    // O prefixo vem da pagina, e nao do componente: o mesmo cartao serve as duas
    // jornadas, e cada uma diz para onde ele leva.
    expect(link.attributes('href')).toBe('/catalog/courses/c1')
    expect(link.text()).toBe('Fundamentos')
  })

  it('renderiza modulos e aulas na ordem recebida, com as posicoes da resposta', async () => {
    // Posicoes nao contiguas de proposito: a arvore do consumidor omite
    // rascunhos, entao a numeracao tem buracos. Um contador local renumeraria e
    // contradiria o que o produtor ve.
    requisitar.mockResolvedValue(arvore([
      modulo('m2', 'Zebra', 2, [aula('l5', 'Ultima', 5), aula('l2', 'Segunda', 2)]),
      modulo('m1', 'Alfa', 1, [aula('l1', 'Primeira', 1)]),
    ]))

    const tela = await mountSuspended(CursoDoCatalogo)
    await assentar()

    expect(requisitar).toHaveBeenCalledWith('/api/catalog/courses/c1')
    expect(tela.findAll('[data-modulo-id]').map(item => item.attributes('data-modulo-id'))).toEqual(['m2', 'm1'])
    expect(tela.findAll('[data-aula-id]').map(item => item.attributes('data-aula-id'))).toEqual(['l5', 'l2', 'l1'])
    expect(tela.findAll('[data-aula-posicao]').map(item => item.text())).toEqual(['5.', '2.', '1.'])
  })

  it('a aula e um link cujo texto e o titulo', async () => {
    requisitar.mockResolvedValue(arvore([modulo('m1', 'Introducao', 1, [aula('l1', 'Primeiros passos', 1)])]))

    const tela = await mountSuspended(CursoDoCatalogo)
    await assentar()

    const link = tela.find('[data-aula-id="l1"] [data-acao="abrir-aula"]')

    expect(link.attributes('href')).toBe('/catalog/lessons/l1')
    expect(link.text()).toBe('Primeiros passos')
  })

  it('modulo sem aula disponivel continua visivel e nao afirma inexistencia', async () => {
    requisitar.mockResolvedValue(arvore([
      modulo('m1', 'Introducao', 1, [aula('l1', 'Primeiros passos', 1)]),
      modulo('m2', 'Em preparacao', 2),
    ]))

    const tela = await mountSuspended(CursoDoCatalogo)
    await assentar()

    // Esconder o modulo apagaria a organizacao do curso; o que o consumidor
    // deixa de ver e o conteudo nao publicado, nao a estrutura.
    expect(tela.find('[data-modulo-id="m2"]').exists()).toBe(true)

    const texto = tela.find('[data-modulo-id="m2"] [data-vazio="aulas"]').text().toLowerCase()

    expect(texto).toContain('nenhuma aula disponivel neste modulo')

    for (const vazamento of ['nao existe', 'nao ha aulas', 'nenhuma aula foi criada', 'rascunho']) {
      expect(texto).not.toContain(vazamento)
    }
  })

  it('nao reconstroi a regra de publicacao sobre a arvore recebida', async () => {
    const modulos = [modulo('m1', 'Introducao', 1, [aula('l1', 'Uma', 1), aula('l2', 'Duas', 2)])]
    requisitar.mockResolvedValue(arvore(modulos, 'draft'))

    const tela = await mountSuspended(CursoDoCatalogo)
    await assentar()

    // Toda aula recebida e renderizada. A ausencia de rascunhos e producao da
    // consulta, e o tipo prova isso: `AulaDoConsumidor` nao tem `video_state`.
    expect(tela.findAll('[data-aula-id]')).toHaveLength(2)

    // E o curso em rascunho — alcancavel pelo endereco direto — nao e escondido:
    // a tela mostra o estado que veio, em vez de decidir se ele pode aparecer.
    expect(tela.find('[data-estado-do-curso="draft"]').exists()).toBe(true)
    expect(leituras()).toHaveLength(1)
  })

  it('o caminho de volta ao catalogo sobrevive a falha da leitura', async () => {
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await mountSuspended(CursoDoCatalogo)
    await assentar()

    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)
    expect(tela.find('[data-acao="voltar-para-catalogo"]').attributes('href')).toBe('/catalog')
  })
})

describe('reproducao bem-sucedida', () => {
  beforeEach(() => {
    parametros.id = 'l1'
  })

  it('alimenta o elemento de video com a URL temporaria e o tipo do conteudo', async () => {
    requisitar.mockResolvedValue(reproducao())

    const tela = await mountSuspended(Reproducao_)
    await assentar()

    expect(requisitar).toHaveBeenCalledWith('/api/lessons/l1/playback')

    const video = tela.find('video[data-reprodutor]')
    const fonte = tela.find('[data-fonte]')

    expect(video.exists()).toBe(true)
    expect(video.attributes('controls')).toBeDefined()
    expect(fonte.attributes('src')).toBe(URL_ASSINADA)
    expect(fonte.attributes('type')).toBe('video/mp4')
  })

  it('avisa quando o acesso ao video expira, sem exibir a URL', async () => {
    requisitar.mockResolvedValue(reproducao())

    const tela = await mountSuspended(Reproducao_)
    await assentar()

    expect(tela.find('time[datetime="2026-09-06T12:05:00+00:00"]').exists()).toBe(true)

    // A URL precisa estar no atributo para o video tocar, mas nao e texto da
    // pagina e nao ha link de download: exibi-la seria transformar a limitacao
    // assumida em convite a redistribuicao.
    expect(tela.text()).not.toContain(URL_ASSINADA)
    expect(tela.find('a[download]').exists()).toBe(false)
  })

  it('nao mostra o video enquanto a reproducao nao chega', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Reproducao_)

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)
    expect(tela.find('video').exists()).toBe(false)
  })
})
