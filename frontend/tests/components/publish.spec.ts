import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Aula, EstadoDoVideo, EstruturaEnvelope, ModuloComAulas, TentativaDeVideo } from '~/types/video'
import { ErroDeApi } from '~/utils/erroDeApi'
import AcaoDePublicar from '~/components/lesson/AcaoDePublicar.vue'
import PainelDoVideo from '~/components/video/PainelDoVideo.vue'
import SeletorDeArquivo from '~/components/video/SeletorDeArquivo.vue'
import ItemDeAula from '~/components/lesson/ItemDeAula.vue'
import Estrutura from '~/pages/producer/courses/[id].vue'

/**
 * A publicacao e a integracao visual do video (RF-UI-005 a 008, RF-UI-010;
 * AC-PROD-004, AC-PROD-005, AC-UI-004, AC-VID-007).
 *
 * A afirmacao central: **fora de `ready` a acao nao existe na tela**. Um botao
 * desabilitado anunciaria que publicar e possivel agora e convidaria a descobrir
 * por que nao esta; ausente, ele conta a mesma verdade que o backend contaria.
 *
 * Isso e conveniencia visual e os testes nao pretendem outra coisa: a
 * autorizacao continua no backend, que reavalia a condicao a cada chamada e
 * responde `409` quando ela nao vale.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: { id: 'c1' } }))

const CRIADO_EM = '2026-09-05T13:45:12+00:00'

function aula(extras: Partial<Aula> = {}): Aula {
  return {
    id: 'a1',
    module_id: 'm1',
    title: 'Primeiros passos',
    position: 1,
    published_at: null,
    video_state: null,
    ...extras,
  }
}

function tentativa(state: EstadoDoVideo, extras: Partial<TentativaDeVideo> = {}): TentativaDeVideo {
  return {
    id: 't1',
    lesson_id: 'a1',
    state,
    filename: 'aula.mp4',
    failure_code: null,
    failure_message: null,
    ...extras,
  }
}

function arvore(aulas: Aula[]): EstruturaEnvelope {
  const modulo: ModuloComAulas = {
    id: 'm1',
    course_id: 'c1',
    title: 'Introducao',
    position: 1,
    lessons: aulas,
  }

  return {
    data: {
      id: 'c1',
      title: 'Fundamentos de Video',
      description: 'Curso de demonstracao.',
      owner_id: 'produtor-1',
      state: 'draft',
      created_at: CRIADO_EM,
      modules: [modulo],
    },
  }
}

function problema(status: number, code: string, detail: string, extras: Record<string, unknown> = {}): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail, code, ...extras },
  })
}

interface Opcoes {
  method?: string
  body?: unknown
}

function chamadas(sufixo: string): unknown[][] {
  return requisitar.mock.calls.filter(([caminho]) => String(caminho).endsWith(sufixo))
}

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

const enviarBytes = vi.hoisted(() => vi.fn())

beforeEach(() => {
  requisitar.mockReset()
  enviarBytes.mockReset()
  vi.stubGlobal('fetch', enviarBytes)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('quando a acao aparece', () => {
  const OCULTOS: Array<EstadoDoVideo | null> = [null, 'pending', 'uploading', 'uploaded', 'processing', 'failed']

  it.each(OCULTOS)('nao oferece publicar com o video em %s', async (estado) => {
    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: estado },
    })

    // Ausente, e nao desabilitado: publicar ainda nao e uma possibilidade.
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
  })

  it('oferece publicar com o video pronto e a aula em rascunho', async () => {
    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    expect(tela.find('[data-acao="publicar"]').exists()).toBe(true)
  })

  it('nao oferece publicar numa aula ja publicada', async () => {
    const tela = await mountSuspended(AcaoDePublicar, {
      props: {
        aula: aula({ published_at: '2026-09-06T10:00:00+00:00', video_state: 'ready' }),
        estadoDoVideo: 'ready',
      },
    })

    // Republicar responde `200` sem efeito novo; oferecer a acao sugeriria que
    // ha algo a fazer.
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
  })
})

describe('chamada e concorrencia', () => {
  it('publica sem corpo e confirma o sucesso com a aula devolvida', async () => {
    const publicada = aula({ published_at: '2026-09-06T10:00:00+00:00', video_state: 'ready' })
    requisitar.mockResolvedValue({ data: publicada })

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // Sem corpo: a aula esta na rota, e todo o resto o backend ja sabe.
    expect(requisitar).toHaveBeenCalledWith('/api/lessons/a1/publish', { method: 'POST' })

    const [, opcoes] = requisitar.mock.calls[0] as [string, Opcoes]
    expect(opcoes.body).toBeUndefined()

    expect(tela.find('[data-estado="sucesso"]').exists()).toBe(true)
    expect(tela.emitted('publicada')?.[0]).toEqual([publicada])

    // A acao sai da tela: publicada, ela nao tem mais o que fazer.
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
  })

  it('desabilita o controle e impede a segunda publicacao', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    const botao = tela.find('[data-acao="publicar"]')
    await botao.trigger('click')
    await flushPromises()

    expect(tela.find('[data-acao="publicar"]').attributes('disabled')).toBeDefined()

    // O clique num botao desabilitado nao dispara, mas a guarda no manipulador e
    // o que cobre o acionamento por teclado sobre o controle focado.
    await tela.find('[data-acao="publicar"]').trigger('click')
    await flushPromises()

    expect(chamadas('/publish')).toHaveLength(1)
  })

  it('exibe o 409 como condicao nao satisfeita, com a mensagem publica da API', async () => {
    requisitar.mockRejectedValue(problema(
      409,
      'LESSON_VIDEO_NOT_READY',
      'O video desta aula ainda nao esta pronto para reproducao.',
      { video_state: 'processing' },
    ))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    const falha = tela.find('[data-estado="falha"]')

    // Conflito de regra, e nao erro generico: a diferenca entre "algo deu
    // errado" e "falta o video ficar pronto" e a diferenca entre desistir e
    // esperar (RF-UI-010).
    expect(falha.attributes('data-situacao')).toBe('conflito')
    expect(falha.attributes('data-codigo')).toBe('LESSON_VIDEO_NOT_READY')
    expect(falha.text()).toContain('O video desta aula ainda nao esta pronto para reproducao.')
    expect(tela.emitted('publicada')).toBeUndefined()
  })
})

describe('propagacao ate a pagina', () => {
  function comEstrutura(aoPublicar: () => Promise<unknown>, aoReler?: (vez: number) => Promise<unknown>) {
    let publicou = false
    let leituras = 0

    requisitar.mockImplementation((caminho: string, opcoes: Opcoes = {}) => {
      if (caminho.endsWith('/publish')) {
        publicou = true

        return aoPublicar()
      }

      if (caminho.endsWith('/video')) {
        return Promise.resolve({ data: null })
      }

      if (caminho.endsWith('/structure')) {
        leituras += 1

        return aoReler?.(leituras) ?? Promise.resolve(arvore([
          aula({
            video_state: 'ready',
            published_at: publicou ? '2026-09-06T10:00:00+00:00' : null,
          }),
        ]))
      }

      throw new Error(`caminho inesperado: ${caminho} ${String(opcoes.method)}`)
    })
  }

  it('relê a estrutura inteira depois de publicar', async () => {
    comEstrutura(() => Promise.resolve({
      data: aula({ published_at: '2026-09-06T10:00:00+00:00', video_state: 'ready' }),
    }))

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // A releitura nao busca `published_at`, que ja veio na resposta: ela busca o
    // estado do **curso**, que a primeira publicacao torna `available` na mesma
    // transacao.
    expect(chamadas('/structure')).toHaveLength(2)
    expect(tela.find('[data-aula-id="a1"] [data-publicacao="publicada"]').exists()).toBe(true)
  })

  it('preserva a confirmacao da publicacao quando so a releitura falha', async () => {
    comEstrutura(
      () => Promise.resolve({ data: aula({ published_at: '2026-09-06T10:00:00+00:00', video_state: 'ready' }) }),
      vez => (
        vez === 2
          ? Promise.reject(problema(503, 'SERVICE_UNAVAILABLE', 'Tente novamente em alguns instantes.'))
          : Promise.resolve(arvore([aula({ video_state: 'ready' })]))
      ),
    )

    const tela = await mountSuspended(Estrutura)
    await assentar()

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // A publicacao aconteceu: dizer o contrario levaria a repeti-la.
    const sucesso = tela.find('[data-estado="sucesso"]')
    expect(sucesso.exists()).toBe(true)
    expect(sucesso.text()).toContain('publicada')
    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    // A nova tentativa repetiu **somente** a leitura da estrutura.
    expect(chamadas('/publish')).toHaveLength(1)
    expect(chamadas('/structure')).toHaveLength(3)
  })
})

describe('estados do video na aula', () => {
  it('mostra a falha do processamento com o texto da API e o caminho para novo envio', async () => {
    requisitar.mockResolvedValue({
      data: tentativa('failed', {
        failure_code: 'VIDEO_PROCESSING_FAILED',
        failure_message: 'Nao foi possivel processar o video. Envie o arquivo novamente.',
      }),
    })

    const tela = await mountSuspended(PainelDoVideo, {
      props: { aulaId: 'a1', estadoInicial: 'failed' },
    })
    await assentar()

    expect(tela.attributes('data-situacao-do-video')).toBe('falhou')
    expect(tela.text()).toContain('Nao foi possivel processar o video. Envie o arquivo novamente.')

    // `failed` e o unico estado a partir do qual o backend aceita um envio novo.
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(true)
  })

  it.each<EstadoDoVideo>(['pending', 'uploaded', 'processing', 'ready'])(
    'nao oferece novo envio com o video em %s',
    async (estado) => {
      requisitar.mockResolvedValue({ data: tentativa(estado) })

      const tela = await mountSuspended(PainelDoVideo, {
        props: { aulaId: 'a1', estadoInicial: estado },
      })
      await assentar()

      // Substituir video fora de `failed` voltaria `409` com
      // `VIDEO_ATTEMPT_ACTIVE`: oferecer a acao convidaria ao erro.
      expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(false)
    },
  )

  it('oferece o seletor na aula sem video, sem consultar a API', async () => {
    const tela = await mountSuspended(PainelDoVideo, {
      props: { aulaId: 'a1', estadoInicial: null },
    })
    await assentar()

    expect(tela.attributes('data-situacao-do-video')).toBe('semVideo')
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(true)

    // Sem tentativa, nao ha o que perguntar: a arvore ja disse tudo.
    expect(requisitar).not.toHaveBeenCalled()
  })

  it('distingue envio que ficou pelo caminho de transferencia em curso', async () => {
    requisitar.mockResolvedValue({ data: tentativa('uploading') })

    const tela = await mountSuspended(PainelDoVideo, {
      props: { aulaId: 'a1', estadoInicial: 'uploading' },
    })
    await assentar()

    // `uploading` sem transferencia nesta tela e um envio abandonado. Chamar
    // isso de "transferindo" prometeria um progresso que nao existe
    // (AC-VID-012).
    expect(tela.attributes('data-situacao-do-video')).toBe('envioIncompleto')
    expect(tela.find('[data-progresso]').exists()).toBe(false)
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(false)
  })
})

describe('recusa terminal da conclusao do envio', () => {
  /** Um arquivo de uma parte, o bastante para chegar ate a conclusao. */
  function arquivoFalso() {
    return {
      name: 'aula.mp4',
      type: 'video/mp4',
      size: 100,
      slice: () => new Blob(['bytes']),
    } as unknown as File
  }

  function comConclusaoRecusada(codigo: string, detail: string) {
    requisitar.mockImplementation((caminho: string) => {
      if (caminho.endsWith('/video/uploads')) {
        return Promise.resolve({
          data: {
            attempt_id: 't1',
            storage_key: 'videos/t1/original.mp4',
            upload_id: 'u1',
            part_size: 100,
            part_count: 1,
          },
        })
      }

      if (caminho.endsWith('/url')) {
        return Promise.resolve({ data: { url: 'https://armazenamento.test/parte-1', expires_at: 'x' } })
      }

      if (caminho.endsWith('/complete')) {
        return Promise.reject(problema(409, codigo, detail))
      }

      return Promise.resolve({ data: null })
    })

    enviarBytes.mockResolvedValue({
      ok: true,
      status: 200,
      headers: { get: () => '"c1"' },
    } as unknown as Response)
  }

  it.each([
    ['VIDEO_OBJECT_MISSING', 'O arquivo enviado nao foi encontrado no armazenamento. Envie o video novamente.'],
    ['VIDEO_OBJECT_MISMATCH', 'O arquivo enviado nao confere com o que foi declarado. Envie o video novamente.'],
  ])('mostra %s como falha do video, com caminho para novo envio', async (codigo, detail) => {
    comConclusaoRecusada(codigo, detail)

    const tela = await mountSuspended(PainelDoVideo, {
      props: { aulaId: 'a1', estadoInicial: null },
    })

    tela.findComponent(SeletorDeArquivo).vm.$emit('escolhido', arquivoFalso())
    await assentar()

    // A tentativa ficou em `failed` no backend antes do `409` sair de la: e o
    // unico estado a partir do qual um envio novo e aceito.
    expect(tela.attributes('data-situacao-do-video')).toBe('falhou')
    expect(tela.text()).toContain(detail)
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(true)

    // Repetir a conclusao devolveria sempre o mesmo `409`.
    expect(tela.find('[data-acao="retomar-envio"]').exists()).toBe(false)

    // O estado sai do painel para quem monta a aula — etiqueta e publicacao leem
    // o mesmo valor.
    const emitidos = tela.emitted('estado') as Array<[string | null]>
    expect(emitidos[emitidos.length - 1]).toEqual(['failed'])

    // Nenhum `PUT` repetido depois da recusa.
    expect(enviarBytes).toHaveBeenCalledTimes(1)
    expect(chamadas('/complete')).toHaveLength(1)
  })

  it('mostra a recusa que nao registrou falha sem prometer novo envio', async () => {
    comConclusaoRecusada('VIDEO_UPLOAD_NOT_ACTIVE', 'Este envio ja foi encerrado e nao aceita mais operacoes.')

    const tela = await mountSuspended(PainelDoVideo, {
      props: { aulaId: 'a1', estadoInicial: null },
    })

    tela.findComponent(SeletorDeArquivo).vm.$emit('escolhido', arquivoFalso())
    await assentar()

    // Terminal como as outras, mas afirmar `failed` inventaria um estado que o
    // backend nao gravou — e o seletor prometeria um envio que voltaria `409`.
    expect(tela.attributes('data-situacao-do-video')).toBe('conclusaoRecusada')
    expect(tela.text()).toContain('Este envio ja foi encerrado e nao aceita mais operacoes.')
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(false)
    expect(tela.find('[data-acao="retomar-envio"]').exists()).toBe(false)
  })
})

describe('o 409 da publicacao ensina sobre o video', () => {
  function comPublicacaoRecusada(codigo: string, detail: string, videoState?: string) {
    requisitar.mockImplementation((caminho: string) => {
      if (caminho.endsWith('/publish')) {
        return Promise.reject(problema(409, codigo, detail, videoState === undefined ? {} : { video_state: videoState }))
      }

      if (caminho.endsWith('/video')) {
        return Promise.resolve({ data: videoState === undefined ? null : tentativa(videoState as EstadoDoVideo) })
      }

      throw new Error(`caminho inesperado: ${caminho}`)
    })
  }

  async function comAulaPronta() {
    return mountSuspended(ItemDeAula, {
      props: { aula: aula({ video_state: 'ready' }) },
    })
  }

  it.each([
    ['LESSON_WITHOUT_VIDEO', 'Esta aula ainda nao tem video'],
    ['LESSON_VIDEO_NOT_READY', 'O video ainda nao esta pronto'],
    ['LESSON_PLAYBACK_REFERENCE_MISSING', 'O video nao tem referencia de reproducao'],
  ])('deixa o codigo %s nomear a condicao que faltou', async (codigo, titulo) => {
    requisitar.mockRejectedValue(problema(409, codigo, 'Texto publico da API.'))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // O nome da condicao vem do codigo, que e estavel; a explicacao vem do
    // `detail`, que a API escreveu e pode reescrever.
    expect(tela.text()).toContain(titulo)
    expect(tela.text()).toContain('Texto publico da API.')
    expect(tela.find('[data-estado="falha"]').attributes('data-codigo')).toBe(codigo)
  })

  it('nao emite sucesso e retira a acao depois de qualquer 409', async () => {
    requisitar.mockRejectedValue(problema(409, 'LESSON_VIDEO_NOT_READY', 'Ainda nao esta pronto.', { video_state: 'processing' }))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    expect(tela.emitted('publicada')).toBeUndefined()
    expect(tela.emitted('conflito')?.[0]).toEqual(['processing'])

    // Um botao que repetiria imediatamente a mesma chamada so ensinaria a
    // insistir no que nao muda.
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
  })

  it('reconcilia o estado do video e devolve a acao quando ele fica pronto', async () => {
    requisitar.mockRejectedValue(problema(409, 'LESSON_VIDEO_NOT_READY', 'Ainda nao esta pronto.', { video_state: 'processing' }))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)

    // Nada muda por reafirmar o mesmo estado.
    await tela.setProps({ aula: aula(), estadoDoVideo: 'ready' })
    await assentar()
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)

    // A reconciliacao inicial adota o estado que o proprio `409` informou. A
    // recusa passa a descrever a realidade, e apaga-la aqui tiraria a explicacao
    // no instante em que ela ficou verdadeira.
    await tela.setProps({ aula: aula(), estadoDoVideo: 'processing' })
    await assentar()
    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)
    expect(tela.text()).toContain('Ainda nao esta pronto.')

    // O acompanhamento alcancou `ready`: a recusa ficou obsoleta e cai inteira.
    await tela.setProps({ aula: aula(), estadoDoVideo: 'ready' })
    await assentar()

    // Um botao "Publicar aula" logo abaixo de um aviso dizendo que o video ainda
    // nao esta pronto se contradiria na mesma tela.
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.text()).not.toContain('Ainda nao esta pronto.')
    expect(tela.text()).not.toContain('O video ainda nao esta pronto')
    expect(tela.findAll('[data-acao="publicar"]')).toHaveLength(1)
  })

  it('nao aceita chave herdada como condicao de publicacao', async () => {
    // `constructor` satisfaria um teste com `in` e o titulo da tela viria do
    // prototipo de `Object` — uma funcao, onde deveria haver o nome de uma
    // condicao.
    requisitar.mockRejectedValue(problema(409, 'constructor', 'Texto publico da API.'))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    expect(tela.text()).toContain('Nao foi possivel publicar')
    expect(tela.text()).toContain('Texto publico da API.')
    expect(tela.text()).not.toContain('function')
    expect(tela.text()).not.toContain('Object')
  })

  it('nao aceita chave herdada como estado do video informado no problema', async () => {
    requisitar.mockRejectedValue(problema(
      409,
      'LESSON_VIDEO_NOT_READY',
      'Ainda nao esta pronto.',
      { video_state: '__proto__' },
    ))

    const tela = await mountSuspended(AcaoDePublicar, {
      props: { aula: aula(), estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // Um valor que nao e estado do contrato nao reconcilia coisa alguma: emitir
    // `__proto__` como estado o espalharia pela etiqueta e pelo painel.
    expect(tela.emitted('conflito')).toBeUndefined()

    // A recusa continua de pe pelo estado observado, e o botao segue fora.
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
  })

  it('nao oferece repeticao inutil quando falta a referencia de reproducao', async () => {
    comPublicacaoRecusada(
      'LESSON_PLAYBACK_REFERENCE_MISSING',
      'O video desta aula nao tem referencia de reproducao registrada.',
      'ready',
    )

    const tela = await comAulaPronta()
    await assentar()

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // O video esta pronto e continua pronto: nao ha o que esperar, e repetir
    // devolveria o mesmo `409`.
    expect(tela.find('[data-estado-do-video="ready"]').exists()).toBe(true)
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
    expect(tela.text()).toContain('O video desta aula nao tem referencia de reproducao registrada.')
  })

  it('reconcilia a aula inteira quando o 409 diz que o video ainda processa', async () => {
    comPublicacaoRecusada('LESSON_VIDEO_NOT_READY', 'O video ainda nao esta pronto.', 'processing')

    const tela = await comAulaPronta()
    await assentar()

    // Estado `ready` vindo da arvore: nenhuma consulta foi necessaria ainda.
    expect(chamadas('/video')).toHaveLength(0)

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // Etiqueta, painel e disponibilidade da publicacao passam a contar a mesma
    // historia — a que o backend acabou de contar.
    expect(tela.find('[data-estado-do-video="processing"]').exists()).toBe(true)
    expect(tela.find('[data-situacao-do-video]').attributes('data-situacao-do-video')).toBe('processando')
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)

    // E o acompanhamento volta a existir: o estado ainda pode mudar sozinho.
    expect(chamadas('/video').length).toBeGreaterThanOrEqual(1)
  })

  it('volta a consultar de tres em tres segundos depois da reconciliacao', async () => {
    comPublicacaoRecusada('LESSON_VIDEO_NOT_READY', 'O video ainda nao esta pronto.', 'processing')

    const tela = await comAulaPronta()
    await assentar()

    vi.useFakeTimers()

    await tela.find('[data-acao="publicar"]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)

    const consultasIniciais = chamadas('/video').length
    expect(consultasIniciais).toBeGreaterThanOrEqual(1)

    await vi.advanceTimersByTimeAsync(3000)
    expect(chamadas('/video').length).toBe(consultasIniciais + 1)

    await vi.advanceTimersByTimeAsync(3000)
    expect(chamadas('/video').length).toBe(consultasIniciais + 2)
  })

  it('afirma a ausencia de video quando o codigo diz que nao ha tentativa', async () => {
    comPublicacaoRecusada('LESSON_WITHOUT_VIDEO', 'Esta aula ainda nao tem video. Envie um video antes de publicar.')

    const tela = await comAulaPronta()
    await assentar()

    await tela.find('[data-acao="publicar"]').trigger('click')
    await assentar()

    // O codigo carrega a informacao sem precisar de campo: o retrato que a tela
    // tinha estava velho, e o estado certo e nenhum.
    expect(tela.find('[data-estado-do-video="sem-video"]').exists()).toBe(true)
    expect(tela.find('[data-situacao-do-video]').attributes('data-situacao-do-video')).toBe('semVideo')
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(false)
    expect(tela.find('[data-acao="escolher-video"]').exists()).toBe(true)
  })
})
