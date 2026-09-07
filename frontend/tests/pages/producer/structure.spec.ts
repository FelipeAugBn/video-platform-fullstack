import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Aula, EstruturaEnvelope, ModuloComAulas } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'
import Estrutura from '~/pages/producer/courses/[id].vue'

/**
 * A estrutura do curso: arvore, criacao de modulo e de aula (RF-EST-001,
 * RF-EST-002, RF-EST-004; RF-MOD-002, RF-AUL-002; RN-ORD-002; AC-PROD-003).
 *
 * A afirmacao central de todo o arquivo e uma so: **a ordem e a posicao vem do
 * backend**. Os testes de ordem falham se a tela ordenar, filtrar ou numerar por
 * conta propria, e os de criacao falham se algum corpo carregar `position`.
 *
 * Como na listagem, o dublê e `useApi`: uma pagina que abrisse seu proprio
 * cliente HTTP nao passaria por aqui.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: { id: 'c1' } }))

interface Opcoes {
  method?: string
  body?: Record<string, unknown>
}

const CAMINHO = '/api/courses/c1/structure'
const CRIADO_EM = '2026-09-05T13:45:12+00:00'

function aula(id: string, title: string, position: number, extras: Partial<Aula> = {}): Aula {
  return {
    id,
    module_id: 'm1',
    title,
    position,
    published_at: null,
    video_state: null,
    ...extras,
  }
}

function modulo(id: string, title: string, position: number, lessons: Aula[] = []): ModuloComAulas {
  return { id, course_id: 'c1', title, position, lessons }
}

function arvore(modules: ModuloComAulas[]): EstruturaEnvelope {
  return {
    data: {
      id: 'c1',
      title: 'Fundamentos de Video',
      description: 'Curso de demonstracao.',
      owner_id: 'produtor-1',
      state: 'draft',
      created_at: CRIADO_EM,
      modules,
    },
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

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  requisitar.mockReset()
})

describe('leitura da arvore', () => {
  it('mostra carregamento antes da resposta', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Estrutura)

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(true)
    expect(tela.find('[data-lista="modulos"]').exists()).toBe(false)
    expect(tela.find('[data-vazio="modulos"]').exists()).toBe(false)
  })

  it('usa uma unica leitura como fonte do curso e da arvore', async () => {
    requisitar.mockResolvedValue(arvore([]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    // Uma segunda chamada a `GET /api/courses/{course}` devolveria os mesmos
    // campos do curso e abriria a chance de as duas respostas discordarem.
    expect(requisitar).toHaveBeenCalledTimes(1)
    expect(requisitar).toHaveBeenCalledWith(CAMINHO)

    const cabecalho = tela.find('[data-curso-id="c1"]')
    expect(cabecalho.text()).toContain('Fundamentos de Video')
    expect(cabecalho.text()).toContain('Curso de demonstracao.')
    expect(cabecalho.find('[data-estado-do-curso="draft"]').exists()).toBe(true)
    expect(cabecalho.find('time').attributes('datetime')).toBe(CRIADO_EM)
  })

  it('sai do carregamento e oferece nova tentativa quando a leitura falha', async () => {
    requisitar.mockRejectedValueOnce(problema(500, 'INTERNAL_ERROR'))
    requisitar.mockResolvedValue(arvore([modulo('m1', 'Introducao', 1)]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('indisponivel')

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    expect(tela.find('[data-modulo-id="m1"]').exists()).toBe(true)
  })

  it('distingue curso sem modulos de curso nao carregado', async () => {
    requisitar.mockResolvedValue(arvore([]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    const vazio = tela.find('[data-vazio="modulos"]')
    expect(vazio.exists()).toBe(true)
    expect(vazio.text()).toContain('Crie o primeiro modulo')
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)

    // A proxima acao esta na tela, e nao apenas sugerida no texto.
    expect(tela.find('[data-formulario="modulo"]').exists()).toBe(true)
  })

  it('distingue modulo sem aulas de modulo nao carregado', async () => {
    requisitar.mockResolvedValue(arvore([modulo('m1', 'Introducao', 1)]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    const modulo1 = tela.find('[data-modulo-id="m1"]')
    expect(modulo1.find('[data-vazio="aulas"]').exists()).toBe(true)
    expect(modulo1.find('[data-lista="aulas"]').exists()).toBe(false)
    expect(modulo1.find('[data-formulario="aula"]').exists()).toBe(true)
  })
})

describe('ordem e estado exibidos', () => {
  it('renderiza modulos e aulas na ordem exata dos arrays recebidos', async () => {
    // Titulos em ordem alfabetica decrescente de proposito: um `sort` por titulo
    // inverteria a sequencia, e um `sort` por `position` a manteria — so a
    // ausencia de ordenacao reproduz o array como veio.
    requisitar.mockResolvedValue(arvore([
      modulo('m1', 'Zebra', 1, [
        aula('a1', 'Zulu', 1),
        aula('a2', 'Mike', 2),
        aula('a3', 'Alfa', 3),
      ]),
      modulo('m2', 'Alfa', 2, [aula('a4', 'Unica', 1)]),
    ]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    expect(tela.findAll('[data-modulo-id]').map(item => item.attributes('data-modulo-id')))
      .toEqual(['m1', 'm2'])

    const modulo1 = tela.find('[data-modulo-id="m1"]')
    expect(modulo1.findAll('[data-aula-id]').map(item => item.attributes('data-aula-id')))
      .toEqual(['a1', 'a2', 'a3'])

    // A posicao exibida e a da API, e nao o indice do laco — que comecaria em 0.
    expect(modulo1.findAll('[data-aula-posicao]').map(item => item.text())).toEqual(['1.', '2.', '3.'])
  })

  it('inclui rascunhos e distingue publicada de rascunho', async () => {
    requisitar.mockResolvedValue(arvore([
      modulo('m1', 'Introducao', 1, [
        aula('a1', 'Publicada', 1, { published_at: '2026-09-06T10:00:00+00:00', video_state: 'ready' }),
        aula('a2', 'Rascunho', 2),
      ]),
    ]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    // A arvore do produtor traz rascunhos de proposito: e como ele acompanha o
    // que ainda nao foi publicado. A do consumidor nao os traz.
    expect(tela.find('[data-aula-id="a1"] [data-publicacao="publicada"]').exists()).toBe(true)
    expect(tela.find('[data-aula-id="a2"] [data-publicacao="rascunho"]').exists()).toBe(true)
  })

  it('exibe o estado do video, inclusive quando nao ha video', async () => {
    requisitar.mockResolvedValue(arvore([
      modulo('m1', 'Introducao', 1, [
        aula('a1', 'Pronta', 1, { video_state: 'ready' }),
        aula('a2', 'Processando', 2, { video_state: 'processing' }),
        aula('a3', 'Falhou', 3, { video_state: 'failed' }),
        aula('a4', 'Sem video', 4, { video_state: null }),
      ]),
    ]))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    expect(tela.find('[data-aula-id="a1"] [data-estado-do-video="ready"]').exists()).toBe(true)
    expect(tela.find('[data-aula-id="a2"] [data-estado-do-video="processing"]').exists()).toBe(true)
    // O valor tecnico e o rotulo visivel sao conferidos separadamente: `failed`
    // tambem resulta da verificacao do envio (`uploading -> failed`), e um texto
    // que nomeasse o processamento apontaria a etapa errada sem que o atributo
    // — correto — denunciasse nada.
    const falhou = tela.find('[data-aula-id="a3"] [data-estado-do-video="failed"]')
    expect(falhou.exists()).toBe(true)
    expect(falhou.text()).toBe('Falha no video')

    // Nulo tem rotulo proprio: "sem video" e uma informacao, nao um espaco vazio.
    const semVideo = tela.find('[data-aula-id="a4"] [data-estado-do-video="sem-video"]')
    expect(semVideo.exists()).toBe(true)
    expect(semVideo.text()).toBe('Sem video')
  })
})

describe('criacao de modulo', () => {
  it('envia somente o titulo e relê a estrutura inteira', async () => {
    let criado = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criado = true

        return Promise.resolve({ data: { id: 'm2', course_id: 'c1', title: 'Producao', position: 2 } })
      }

      return Promise.resolve(arvore(criado
        ? [modulo('m1', 'Introducao', 1), modulo('m2', 'Producao', 2)]
        : [modulo('m1', 'Introducao', 1)]))
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-modulo').setValue('Producao')
    await tela.find('[data-formulario="modulo"]').trigger('submit')
    await assentar()

    expect(requisitar).toHaveBeenCalledWith('/api/courses/c1/modules', {
      method: 'POST',
      body: { title: 'Producao' },
    })

    // Nenhum campo de posicao: ela e a proxima livre no curso, calculada sob
    // trava. Enviada daqui, seria ignorada — e duas criacoes simultaneas
    // escolheriam o mesmo numero.
    const [, opcoes] = chamadas('POST')[0] as [string, Opcoes]
    expect(Object.keys(opcoes.body ?? {})).toEqual(['title'])

    expect(chamadas('GET')).toHaveLength(2)
    expect(tela.find('[data-estado="sucesso"]').text()).toContain('Producao')
  })

  it('mostra o modulo novo no fim porque a nova resposta o traz la', async () => {
    let criado = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criado = true

        return Promise.resolve({ data: { id: 'm3', course_id: 'c1', title: 'Terceiro', position: 3 } })
      }

      return Promise.resolve(arvore(criado
        ? [modulo('m1', 'Um', 1), modulo('m2', 'Dois', 2), modulo('m3', 'Terceiro', 3)]
        : [modulo('m1', 'Um', 1), modulo('m2', 'Dois', 2)]))
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-modulo').setValue('Terceiro')
    await tela.find('[data-formulario="modulo"]').trigger('submit')
    await assentar()

    // A sequencia e a do array relido, inteira — nao a antiga com um item
    // acrescentado no fim pela tela.
    expect(tela.findAll('[data-modulo-id]').map(item => item.attributes('data-modulo-id')))
      .toEqual(['m1', 'm2', 'm3'])
  })

  it('exibe o 422 no campo e preserva o titulo digitado', async () => {
    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      return opcoes?.method === 'POST'
        ? Promise.reject(problema(422, 'VALIDATION_FAILED', {
            errors: { title: ['O campo titulo e obrigatorio.'] },
          }))
        : Promise.resolve(arvore([modulo('m1', 'Introducao', 1)]))
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-modulo').setValue('Producao')
    await tela.find('[data-formulario="modulo"]').trigger('submit')
    await assentar()

    expect(tela.find('#erro-titulo-modulo').text()).toContain('O campo titulo e obrigatorio.')
    expect(tela.find('#campo-titulo-modulo').attributes('aria-invalid')).toBe('true')
    expect((tela.find('#campo-titulo-modulo').element as HTMLInputElement).value).toBe('Producao')

    // A estrutura nao foi relida: nada mudou no servidor.
    expect(chamadas('GET')).toHaveLength(1)
  })
})

describe('criacao de aula', () => {
  it('envia somente o titulo e relê a estrutura inteira', async () => {
    let criada = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criada = true

        return Promise.resolve({ data: aula('a2', 'Segunda', 2) })
      }

      return Promise.resolve(arvore([
        modulo('m1', 'Introducao', 1, criada
          ? [aula('a1', 'Primeira', 1), aula('a2', 'Segunda', 2)]
          : [aula('a1', 'Primeira', 1)]),
      ]))
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-aula-m1').setValue('Segunda')
    await tela.find('[data-formulario-modulo="m1"]').trigger('submit')
    await assentar()

    expect(requisitar).toHaveBeenCalledWith('/api/modules/m1/lessons', {
      method: 'POST',
      body: { title: 'Segunda' },
    })

    // Nem `position`, nem `published_at`, nem estado de video: a aula nasce em
    // rascunho e sem video, e nao ha parametro capaz de mudar isso na criacao.
    const [, opcoes] = chamadas('POST')[0] as [string, Opcoes]
    expect(Object.keys(opcoes.body ?? {})).toEqual(['title'])

    expect(chamadas('GET')).toHaveLength(2)
    expect(tela.find('[data-modulo-id="m1"]').findAll('[data-aula-id]').map(item => item.attributes('data-aula-id')))
      .toEqual(['a1', 'a2'])
  })

  it('mantem erro e envio independentes entre os modulos', async () => {
    requisitar.mockImplementation((caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method !== 'POST') {
        return Promise.resolve(arvore([modulo('m1', 'Um', 1), modulo('m2', 'Dois', 2)]))
      }

      return caminho === '/api/modules/m1/lessons'
        ? Promise.reject(problema(422, 'VALIDATION_FAILED', { errors: { title: ['O campo titulo e obrigatorio.'] } }))
        : Promise.resolve({ data: aula('a9', 'Outra', 1) })
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-aula-m1').setValue('Recusada')
    await tela.find('[data-formulario-modulo="m1"]').trigger('submit')
    await assentar()

    // Um formulario unico com seletor de modulo faria o erro do primeiro
    // aparecer tambem no segundo.
    expect(tela.find('#erro-titulo-aula-m1').exists()).toBe(true)
    expect(tela.find('#erro-titulo-aula-m2').exists()).toBe(false)
    expect((tela.find('#campo-titulo-aula-m1').element as HTMLInputElement).value).toBe('Recusada')

    // Os identificadores carregam o modulo: com a lista inteira renderizada,
    // `label for` e `aria-describedby` continuam apontando para um unico campo.
    expect(tela.find('label[for="campo-titulo-aula-m2"]').exists()).toBe(true)
  })
})

describe('criacao confirmada com releitura falha', () => {
  it('nao apresenta a criacao como fracassada nem repete a mutacao', async () => {
    let criado = false

    requisitar.mockImplementation((_caminho: string, opcoes: Opcoes) => {
      if (opcoes?.method === 'POST') {
        criado = true

        return Promise.resolve({ data: { id: 'm2', course_id: 'c1', title: 'Producao', position: 2 } })
      }

      return criado && chamadas('GET').length === 2
        ? Promise.reject(ErroDeApi.de(new Error('Failed to fetch')))
        : Promise.resolve(arvore(criado
            ? [modulo('m1', 'Introducao', 1), modulo('m2', 'Producao', 2)]
            : [modulo('m1', 'Introducao', 1)]))
    })

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('#campo-titulo-modulo').setValue('Producao')
    await tela.find('[data-formulario="modulo"]').trigger('submit')
    await assentar()

    expect(tela.find('[data-estado="sucesso"]').text()).toContain('Producao')
    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('rede')
    expect(chamadas('POST')).toHaveLength(1)

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    // A nova tentativa repetiu a leitura, e nao a criacao: um segundo `POST`
    // criaria um modulo duplicado, na posicao 3.
    expect(chamadas('POST')).toHaveLength(1)
    expect(chamadas('GET')).toHaveLength(3)
    expect(tela.find('[data-modulo-id="m2"]').exists()).toBe(true)
  })
})
