import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Curso, CursosPaginados, PaginacaoMeta } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'
import Cursos from '~/pages/producer/courses/index.vue'

/**
 * A listagem e a criacao de cursos do produtor (RF-CUR-001, RF-CUR-002;
 * AC-PROD-001, AC-UI-001, AC-UI-002).
 *
 * ## Por que o dublê e `useApi`, e nao `$fetch`
 *
 * Substituindo o composable, um segundo cliente HTTP escrito dentro da pagina
 * **nao seria exercitado** — ele chamaria `$fetch` de verdade, e o teste falharia
 * por falta de rede em vez de passar silenciosamente. Substituir `$fetch` teria o
 * efeito oposto: qualquer caminho ate a rede passaria, inclusive um que ignorasse
 * credenciais, token de protecao e traducao de erro.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))

interface Opcoes {
  method?: string
  body?: Record<string, unknown>
  query?: Record<string, unknown>
}

const CRIADO_EM = '2026-09-05T13:45:12+00:00'

function curso(id: string, title: string, extras: Partial<Curso> = {}): Curso {
  return {
    id,
    title,
    description: `Descricao de ${title}`,
    owner_id: 'produtor-1',
    state: 'draft',
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

function problema(status: number, code: string, extras: Record<string, unknown> = {}): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem publica.', code, ...extras },
  })
}

function chamadas(metodo: 'GET' | 'POST'): unknown[][] {
  return requisitar.mock.calls.filter(([, opcoes]) => ((opcoes as Opcoes | undefined)?.method ?? 'GET') === metodo)
}

interface Adiada<T> {
  promessa: Promise<T>
  resolver: (valor: T) => void
  rejeitar: (causa: unknown) => void
}

/**
 * Uma resposta que so termina quando o teste mandar.
 *
 * E o que permite intercalar duas leituras: segurar a primeira, deixar a segunda
 * chegar inteira, e so entao liberar a primeira — a ordem que a rede produz
 * sozinha de vez em quando, e que nenhum `mockResolvedValue` reproduz.
 */
function adiada<T>(): Adiada<T> {
  let resolver!: (valor: T) => void
  let rejeitar!: (causa: unknown) => void

  const promessa = new Promise<T>((paraResolver, paraRejeitar) => {
    resolver = paraResolver
    rejeitar = paraRejeitar
  })

  return { promessa, resolver, rejeitar }
}

/** Duas rodadas: a criacao emite, a pagina relê, e a releitura tambem e assincrona. */
async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

async function preencherCurso(tela: Awaited<ReturnType<typeof mountSuspended>>, titulo: string, descricao: string) {
  await tela.find('#campo-titulo-curso').setValue(titulo)
  await tela.find('#campo-descricao-curso').setValue(descricao)
}

beforeEach(() => {
  requisitar.mockReset()
})

describe('leitura da lista', () => {
  it('mostra carregamento, e nao lista vazia, enquanto a resposta nao chega', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Cursos)

    // A confusao que este teste existe para impedir: "ainda nao chegou" exibido
    // como "voce nao tem nada" faz o produtor concluir que perdeu os cursos.
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)
    expect(tela.find('[data-vazio="cursos"]').exists()).toBe(false)
    expect(tela.find('[data-lista="cursos"]').exists()).toBe(false)
  })

  it('distingue lista vazia de falha e sugere a proxima acao', async () => {
    requisitar.mockResolvedValue(paginaDe([]))

    const tela = await mountSuspended(Cursos)
    await assentar()

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)

    const vazio = tela.find('[data-vazio="cursos"]')
    expect(vazio.exists()).toBe(true)
    expect(vazio.text()).toContain('Crie o primeiro curso')

    // A proxima acao precisa estar ao alcance, e nao apenas mencionada.
    expect(tela.find('[data-formulario="curso"]').exists()).toBe(true)
  })

  it('preserva a ordem devolvida pela API', async () => {
    // Deliberadamente fora de ordem alfabetica e de data: qualquer `sort` no
    // cliente produziria outra sequencia.
    const recebidos = [
      curso('c3', 'Zebra', { created_at: '2026-01-01T00:00:00+00:00' }),
      curso('c1', 'Alfa', { created_at: '2026-09-01T00:00:00+00:00' }),
      curso('c2', 'Meio', { created_at: '2026-05-01T00:00:00+00:00' }),
    ]

    requisitar.mockResolvedValue(paginaDe(recebidos))

    const tela = await mountSuspended(Cursos)
    await assentar()

    const exibidos = tela.findAll('[data-curso-id]').map(item => item.attributes('data-curso-id'))
    expect(exibidos).toEqual(['c3', 'c1', 'c2'])
  })

  it('exibe titulo, descricao, estado e data de criacao', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos', { state: 'available' })]))

    const tela = await mountSuspended(Cursos)
    await assentar()

    const item = tela.find('[data-curso-id="c1"]')
    expect(item.text()).toContain('Fundamentos')
    expect(item.text()).toContain('Descricao de Fundamentos')
    expect(item.find('[data-estado-do-curso="available"]').exists()).toBe(true)

    // O instante legivel por maquina, sem depender do fuso de quem roda o teste.
    expect(item.find('time').attributes('datetime')).toBe(CRIADO_EM)
  })

  it('leva ao detalhe por um link cujo texto e o nome do curso', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos')]))

    const tela = await mountSuspended(Cursos)
    await assentar()

    const link = tela.find('[data-curso-id="c1"] [data-acao="abrir-curso"]')
    expect(link.attributes('href')).toBe('/producer/courses/c1')
    expect(link.text()).toBe('Fundamentos')
  })
})

describe('paginacao', () => {
  it('navega entre paginas sem perder o tamanho efetivo da pagina', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      const numero = Number(opcoes.query?.page ?? 1)

      return Promise.resolve(paginaDe([curso(`c${numero}`, `Curso da pagina ${numero}`)], {
        current_page: numero,
        // O teto de 50 ja foi aplicado pela API: 25 e o valor **efetivo**.
        per_page: 25,
        total: 60,
        last_page: 3,
      }))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    // Na primeira chamada nao ha tamanho confirmado para repetir.
    expect(requisitar).toHaveBeenNthCalledWith(1, '/api/courses', { query: { page: 1, per_page: undefined } })
    expect(tela.find('[data-paginacao-posicao]').text()).toBe('Pagina 1 de 3')

    await tela.find('[data-acao="pagina-proxima"]').trigger('click')
    await assentar()

    expect(requisitar).toHaveBeenNthCalledWith(2, '/api/courses', { query: { page: 2, per_page: 25 } })
    expect(tela.find('[data-curso-id="c2"]').exists()).toBe(true)

    await tela.find('[data-acao="pagina-anterior"]').trigger('click')
    await assentar()

    expect(requisitar).toHaveBeenNthCalledWith(3, '/api/courses', { query: { page: 1, per_page: 25 } })
  })

  it('desabilita as bordas em vez de esconde-las', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Unico')], { last_page: 2, total: 2 }))

    const tela = await mountSuspended(Cursos)
    await assentar()

    // Um controle que some muda o alcance do teclado a cada pagina.
    expect(tela.find('[data-acao="pagina-anterior"]').attributes('disabled')).toBeDefined()
    expect(tela.find('[data-acao="pagina-proxima"]').attributes('disabled')).toBeUndefined()
  })

  it('nao oferece navegacao quando ha uma unica pagina', async () => {
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Unico')]))

    const tela = await mountSuspended(Cursos)
    await assentar()

    expect(tela.find('[data-paginacao]').exists()).toBe(false)
  })
})

describe('criacao', () => {
  it('envia exatamente titulo e descricao', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return Promise.resolve(opcoes?.method === 'POST'
        ? { data: curso('c9', 'Novo curso') }
        : paginaDe([]))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    expect(requisitar).toHaveBeenCalledWith('/api/courses', {
      method: 'POST',
      body: { title: 'Novo curso', description: 'Uma descricao.' },
    })

    // A afirmacao forte: nenhum campo alem desses dois. `owner_id` vem da sessao
    // e `state` e decisao do backend — enviados daqui, seriam ignorados, e a tela
    // estaria prometendo um controle que nao tem.
    const [, opcoes] = chamadas('POST')[0] as [string, Opcoes]
    expect(Object.keys(opcoes.body ?? {}).sort()).toEqual(['description', 'title'])
  })

  it('impede a segunda submissao enquanto a primeira esta em voo', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return opcoes?.method === 'POST'
        ? new Promise(() => {})
        : Promise.resolve(paginaDe([]))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')

    await tela.find('[data-formulario="curso"]').trigger('submit')
    await flushPromises()

    expect(tela.find('[data-formulario="curso"] button[type="submit"]').attributes('disabled')).toBeDefined()

    // A submissao por `Enter` nao passa pelo estado do botao: sem a guarda no
    // manipulador, este segundo envio criaria um curso duplicado.
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await flushPromises()

    expect(chamadas('POST')).toHaveLength(1)
  })

  it('confirma o sucesso, limpa o formulario e relê a lista pela API', async () => {
    let criado = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criado = true

        return Promise.resolve({ data: curso('c9', 'Novo curso') })
      }

      // Criacao decrescente: o curso recem-criado e o mais novo, e a API o
      // devolve **primeiro**. Simular o contrario esconderia uma ordenacao no
      // cliente em vez de flagra-la.
      return Promise.resolve(paginaDe(criado
        ? [curso('c9', 'Novo curso'), curso('c1', 'Antigo')]
        : [curso('c1', 'Antigo')]))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    const sucesso = tela.find('[data-estado="sucesso"]')
    expect(sucesso.exists()).toBe(true)
    expect(sucesso.text()).toContain('Novo curso')

    // O curso novo esta na tela porque a **releitura** o trouxe, e nao porque a
    // pagina o inseriu na lista que ja tinha.
    expect(chamadas('GET')).toHaveLength(2)
    expect(tela.findAll('[data-curso-id]').map(item => item.attributes('data-curso-id'))).toEqual(['c9', 'c1'])

    expect((tela.find('#campo-titulo-curso').element as HTMLInputElement).value).toBe('')
    expect((tela.find('#campo-descricao-curso').element as HTMLTextAreaElement).value).toBe('')
  })

  it('volta a primeira pagina depois de criar', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return Promise.resolve(opcoes?.method === 'POST'
        ? { data: curso('c9', 'Novo curso') }
        : paginaDe([curso('c1', 'Um')], {
            current_page: Number(opcoes.query?.page ?? 1),
            last_page: 3,
            total: 40,
          }))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await tela.find('[data-acao="pagina-proxima"]').trigger('click')
    await assentar()

    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    // A ordem e criacao decrescente: o curso recem-criado esta na primeira
    // pagina, e continuar na terceira esconderia justamente o que acabou de ser
    // confirmado.
    const leituras = chamadas('GET')
    const [, ultima] = leituras[leituras.length - 1] as [string, Opcoes]
    expect(ultima.query?.page).toBe(1)
  })
})

describe('validacao e falha', () => {
  it('exibe o 422 junto dos campos e preserva o que foi digitado', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return opcoes?.method === 'POST'
        ? Promise.reject(problema(422, 'VALIDATION_FAILED', {
            errors: {
              title: ['O campo titulo e obrigatorio.'],
              description: ['O campo descricao e obrigatorio.'],
            },
          }))
        : Promise.resolve(paginaDe([]))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await preencherCurso(tela, 'T', 'D')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    expect(tela.find('#erro-titulo-curso').text()).toContain('O campo titulo e obrigatorio.')
    expect(tela.find('#erro-descricao-curso').text()).toContain('O campo descricao e obrigatorio.')
    expect(tela.find('#campo-titulo-curso').attributes('aria-invalid')).toBe('true')
    expect(tela.find('#campo-titulo-curso').attributes('aria-describedby')).toBe('erro-titulo-curso')

    // Redigitar tudo por causa de um erro num campo e o que AC-UI-001 impede.
    expect((tela.find('#campo-titulo-curso').element as HTMLInputElement).value).toBe('T')
    expect((tela.find('#campo-descricao-curso').element as HTMLTextAreaElement).value).toBe('D')
  })

  it('leva o foco ao primeiro campo recusado', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return opcoes?.method === 'POST'
        ? Promise.reject(problema(422, 'VALIDATION_FAILED', {
            errors: { description: ['O campo descricao e obrigatorio.'] },
          }))
        : Promise.resolve(paginaDe([]))
    })

    const tela = await mountSuspended(Cursos, { attachTo: document.body })
    await assentar()

    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    expect(document.activeElement?.id).toBe('campo-descricao-curso')
  })

  it('sai do carregamento e oferece nova tentativa quando a leitura falha', async () => {
    requisitar.mockRejectedValueOnce(ErroDeApi.de(new Error('Failed to fetch')))
    requisitar.mockResolvedValue(paginaDe([curso('c1', 'Fundamentos')]))

    const tela = await mountSuspended(Cursos)
    await assentar()

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-vazio="cursos"]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('rede')

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    expect(tela.find('[data-curso-id="c1"]').exists()).toBe(true)
  })

  it('nao apresenta a criacao como fracassada quando so a releitura falha', async () => {
    let criado = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criado = true

        return Promise.resolve({ data: curso('c9', 'Novo curso') })
      }

      // A releitura logo apos a criacao falha; a seguinte volta a funcionar.
      return criado && chamadas('GET').length === 2
        ? Promise.reject(problema(500, 'INTERNAL_ERROR'))
        : Promise.resolve(paginaDe(criado ? [curso('c9', 'Novo curso')] : []))
    })

    const tela = await mountSuspended(Cursos)
    await assentar()

    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()

    // O `201` chegou: dizer o contrario levaria o produtor a criar o mesmo curso
    // de novo, e a segunda criacao seria aceita.
    expect(tela.find('[data-estado="sucesso"]').exists()).toBe(true)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)
    expect(chamadas('POST')).toHaveLength(1)

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    // A nova tentativa repetiu **somente** a leitura.
    expect(chamadas('POST')).toHaveLength(1)
    expect(chamadas('GET')).toHaveLength(3)
    expect(tela.find('[data-curso-id="c9"]').exists()).toBe(true)
  })
})

describe('leituras concorrentes', () => {
  /**
   * As duas leituras que se cruzam.
   *
   * A primeira e a inicial, ainda pendente quando a criacao dispara a segunda.
   * Nada na rede garante que voltem na ordem em que sairam, e uma resposta
   * antiga chegando por ultimo carregaria consigo a lista **de antes da
   * criacao** — a tela apagaria o curso logo depois de confirmar que ele
   * existe.
   */
  function comLeituraInicialPendente(segunda: () => Promise<unknown>) {
    const inicial = adiada<CursosPaginados>()
    let leituras = 0

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        return Promise.resolve({ data: curso('c9', 'Novo curso') })
      }

      leituras += 1

      return leituras === 1 ? inicial.promessa : segunda()
    })

    return inicial
  }

  async function criar(tela: Awaited<ReturnType<typeof mountSuspended>>) {
    await preencherCurso(tela, 'Novo curso', 'Uma descricao.')
    await tela.find('[data-formulario="curso"]').trigger('submit')
    await assentar()
  }

  it('ignora a resposta antiga que chega depois da releitura da criacao', async () => {
    const inicial = comLeituraInicialPendente(() => Promise.resolve(paginaDe([curso('c9', 'Novo curso')])))

    const tela = await mountSuspended(Cursos)
    await assentar()

    // A leitura inicial esta em voo, e e nela que a lista antiga viaja.
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)

    await criar(tela)

    expect(chamadas('GET')).toHaveLength(2)
    expect(tela.find('[data-curso-id="c9"]').exists()).toBe(true)

    // Só agora a leitura inicial termina, trazendo o mundo anterior a criacao.
    inicial.resolver(paginaDe([curso('c1', 'Antigo')]))
    await assentar()

    expect(tela.findAll('[data-curso-id]').map(item => item.attributes('data-curso-id'))).toEqual(['c9'])
    expect(tela.find('[data-curso-id="c1"]').exists()).toBe(false)

    // O `finally` da leitura antiga tambem nao pode encerrar — nem reabrir — o
    // carregamento de outra: a tela ja terminou de carregar.
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-estado="sucesso"]').exists()).toBe(true)
  })

  it('ignora a falha antiga que chega depois de uma leitura nova bem-sucedida', async () => {
    const inicial = comLeituraInicialPendente(() => Promise.resolve(paginaDe([curso('c9', 'Novo curso')])))

    const tela = await mountSuspended(Cursos)
    await assentar()

    await criar(tela)

    expect(tela.find('[data-curso-id="c9"]').exists()).toBe(true)

    inicial.rejeitar(ErroDeApi.de(new Error('Failed to fetch')))
    await assentar()

    // Uma lista carregada trocada por um erro que descreve uma requisicao ja
    // superada — e com um botao convidando a repetir o que nao precisa ser
    // repetido.
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-curso-id="c9"]').exists()).toBe(true)
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
  })

  it('nao deixa a leitura antiga encerrar o carregamento da leitura em voo', async () => {
    const segunda = adiada<CursosPaginados>()
    const inicial = comLeituraInicialPendente(() => segunda.promessa)

    const tela = await mountSuspended(Cursos)
    await assentar()

    await criar(tela)

    // As duas em voo ao mesmo tempo. A antiga termina primeiro.
    inicial.resolver(paginaDe([curso('c1', 'Antigo')]))
    await assentar()

    // O carregamento e da leitura nova, e so ela pode encerra-lo.
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)
    expect(tela.find('[data-curso-id="c1"]').exists()).toBe(false)

    segunda.resolver(paginaDe([curso('c9', 'Novo curso')]))
    await assentar()

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-curso-id="c9"]').exists()).toBe(true)
  })
})
