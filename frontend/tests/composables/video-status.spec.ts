import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import type { EstadoDoVideo, TentativaDeVideo } from '~/types/video'
import { comoEstadoDoVideo, estadoTransitorio, useVideoStatus } from '~/composables/useVideoStatus'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * O acompanhamento do processamento (plan §15.2; RF-UI-005 a 007, AC-VID-007).
 *
 * O relogio e falso porque o que se afirma sao **intervalos e encerramentos**, e
 * esperar tres segundos de verdade por asserção tornaria a suite inutilizavel.
 *
 * O escopo de efeito faz as vezes de montagem: `escopo.stop()` dispara o mesmo
 * `onScopeDispose` que o desmonte de um componente dispararia, e e assim que se
 * prova que nenhum temporizador sobrevive a tela.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))

const AULA = 'a1'
const INTERVALO = 3000

function tentativa(state: EstadoDoVideo, extras: Partial<TentativaDeVideo> = {}): TentativaDeVideo {
  return {
    id: 't1',
    lesson_id: AULA,
    state,
    filename: 'aula.mp4',
    failure_code: null,
    failure_message: null,
    ...extras,
  }
}

function problema(status: number, code: string): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem publica.', code },
  })
}

function adiada<T>() {
  let resolver!: (valor: T) => void

  const promessa = new Promise<T>((paraResolver) => {
    resolver = paraResolver
  })

  return { promessa, resolver }
}

/** Drena as microtarefas sem deixar o relogio falso andar. */
async function assentar(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
}

let escopo: ReturnType<typeof effectScope>

function montar() {
  escopo = effectScope()

  const acompanhamento = escopo.run(() => useVideoStatus(AULA))

  if (acompanhamento === undefined) {
    throw new Error('o escopo nao produziu o acompanhamento')
  }

  return acompanhamento
}

beforeEach(() => {
  vi.useFakeTimers()
  requisitar.mockReset()
})

afterEach(() => {
  escopo?.stop()
  vi.useRealTimers()
})

describe('ciclo de consulta', () => {
  it('consulta o estado do video da aula, e nada mais', async () => {
    requisitar.mockResolvedValue({ data: tentativa('processing') })

    const acompanhamento = montar()
    await acompanhamento.consultar()

    expect(requisitar).toHaveBeenCalledWith(`/api/lessons/${AULA}/video`)
    expect(acompanhamento.estado.value).toBe('processing')
  })

  it('consulta de novo a cada tres segundos enquanto o estado for transitorio', async () => {
    requisitar.mockResolvedValue({ data: tentativa('processing') })

    const acompanhamento = montar()
    await acompanhamento.consultar()

    expect(requisitar).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(INTERVALO)
    expect(requisitar).toHaveBeenCalledTimes(2)

    await vi.advanceTimersByTimeAsync(INTERVALO)
    expect(requisitar).toHaveBeenCalledTimes(3)
  })

  it.each<EstadoDoVideo>(['pending', 'uploading', 'uploaded', 'processing'])(
    'mantem o ciclo em %s',
    async (estado) => {
      requisitar.mockResolvedValue({ data: tentativa(estado) })

      const acompanhamento = montar()
      await acompanhamento.consultar()
      await vi.advanceTimersByTimeAsync(INTERVALO)

      expect(requisitar).toHaveBeenCalledTimes(2)
      expect(acompanhamento.transitorio.value).toBe(true)
    },
  )

  it('para em ready', async () => {
    requisitar.mockResolvedValue({ data: tentativa('ready') })

    const acompanhamento = montar()
    await acompanhamento.consultar()
    await vi.advanceTimersByTimeAsync(INTERVALO * 5)

    // Estado terminal: continuar perguntando so gastaria requisicao para receber
    // sempre a mesma resposta.
    expect(requisitar).toHaveBeenCalledTimes(1)
    expect(acompanhamento.transitorio.value).toBe(false)
  })

  it('para em failed e expoe a mensagem publica da API', async () => {
    requisitar.mockResolvedValue({
      data: tentativa('failed', {
        failure_code: 'VIDEO_PROCESSING_FAILED',
        failure_message: 'Nao foi possivel processar o video. Envie o arquivo novamente.',
      }),
    })

    const acompanhamento = montar()
    await acompanhamento.consultar()
    await vi.advanceTimersByTimeAsync(INTERVALO * 5)

    expect(requisitar).toHaveBeenCalledTimes(1)

    // O texto e o que a API escreveu. Um catalogo paralelo aqui divergiria do
    // dela na primeira correcao de portugues do backend.
    expect(acompanhamento.mensagemDaFalha.value)
      .toBe('Nao foi possivel processar o video. Envie o arquivo novamente.')
  })

  it('trata aula sem video como estado normal, sem erro e sem ciclo', async () => {
    requisitar.mockResolvedValue({ data: null })

    const acompanhamento = montar()
    await acompanhamento.consultar()
    await vi.advanceTimersByTimeAsync(INTERVALO * 3)

    // `data: null` e o estado inicial de toda aula recem-criada, e nao um recurso
    // ausente: `404` seria outra historia.
    expect(acompanhamento.tentativa.value).toBeNull()
    expect(acompanhamento.estado.value).toBeNull()
    expect(acompanhamento.erro.value).toBeNull()
    expect(requisitar).toHaveBeenCalledTimes(1)
  })
})

describe('sobreposicao', () => {
  it('nao dispara uma segunda consulta enquanto a primeira nao respondeu', async () => {
    const emVoo = adiada<{ data: TentativaDeVideo }>()
    requisitar.mockReturnValue(emVoo.promessa)

    const acompanhamento = montar()
    const primeira = acompanhamento.consultar()

    // Cinco segundos com a consulta pendente: um `setInterval` teria disparado
    // outra aos tres, e as respostas voltariam fora de ordem.
    await vi.advanceTimersByTimeAsync(INTERVALO + 2000)
    expect(requisitar).toHaveBeenCalledTimes(1)

    emVoo.resolver({ data: tentativa('processing') })
    await primeira

    await vi.advanceTimersByTimeAsync(INTERVALO)
    expect(requisitar).toHaveBeenCalledTimes(2)
  })
})

describe('falha da consulta', () => {
  it('encerra o ciclo, mostra indisponibilidade e repete somente a leitura', async () => {
    requisitar.mockRejectedValueOnce(problema(503, 'SERVICE_UNAVAILABLE'))

    const acompanhamento = montar()
    await acompanhamento.consultar()

    expect(acompanhamento.erro.value?.status).toBe(503)

    // Insistir de tres em tres segundos contra uma API fora do ar multiplicaria
    // a falha e deixaria a tela sem explicacao.
    await vi.advanceTimersByTimeAsync(INTERVALO * 3)
    expect(requisitar).toHaveBeenCalledTimes(1)

    requisitar.mockResolvedValue({ data: tentativa('ready') })
    await acompanhamento.consultar()

    expect(requisitar).toHaveBeenCalledTimes(2)
    expect(requisitar).toHaveBeenLastCalledWith(`/api/lessons/${AULA}/video`)
    expect(acompanhamento.erro.value).toBeNull()
    expect(acompanhamento.estado.value).toBe('ready')
  })
})

describe('respostas que nao podem escrever', () => {
  it('remove o temporizador ao desmontar', async () => {
    requisitar.mockResolvedValue({ data: tentativa('processing') })

    const acompanhamento = montar()
    await acompanhamento.consultar()

    escopo.stop()

    await vi.advanceTimersByTimeAsync(INTERVALO * 5)

    // Um temporizador sobrevivente consultaria para uma tela que nao existe.
    expect(requisitar).toHaveBeenCalledTimes(1)
  })

  it('descarta a resposta que chega depois do desmonte', async () => {
    const emVoo = adiada<{ data: TentativaDeVideo }>()
    requisitar.mockReturnValue(emVoo.promessa)

    const acompanhamento = montar()
    const consulta = acompanhamento.consultar()

    escopo.stop()

    emVoo.resolver({ data: tentativa('ready') })
    await consulta

    expect(acompanhamento.tentativa.value).toBeNull()
  })

  it('descarta a resposta da tentativa antiga depois de um envio novo', async () => {
    const antiga = adiada<{ data: TentativaDeVideo }>()
    requisitar.mockReturnValue(antiga.promessa)

    const acompanhamento = montar()
    const consulta = acompanhamento.consultar()

    // O envio novo terminou e trouxe a tentativa que passa a valer.
    const nova = tentativa('uploaded', { id: 't2' })
    acompanhamento.definir(nova)

    antiga.resolver({ data: tentativa('failed', { id: 't1', failure_message: 'Falhou antes.' }) })
    await consulta
    await assentar()

    // Sem a virada de geracao, a tela voltaria de `uploaded` para o `failed` de
    // um envio que acabou de ser substituido.
    expect(acompanhamento.tentativa.value).toEqual(nova)
    expect(acompanhamento.mensagemDaFalha.value).toBeNull()
  })

  it('descarta a resposta em voo quando o acompanhamento e invalidado', async () => {
    const antiga = adiada<{ data: TentativaDeVideo }>()
    requisitar.mockReturnValue(antiga.promessa)

    const acompanhamento = montar()
    const consulta = acompanhamento.consultar()

    acompanhamento.invalidar()

    antiga.resolver({ data: tentativa('failed', { failure_message: 'Falhou antes.' }) })
    await consulta

    expect(acompanhamento.tentativa.value).toBeNull()
    expect(acompanhamento.mensagemDaFalha.value).toBeNull()
  })

  it('retoma o ciclo a partir da tentativa adotada quando ela e transitoria', async () => {
    requisitar.mockResolvedValue({ data: tentativa('processing') })

    const acompanhamento = montar()
    acompanhamento.definir(tentativa('uploaded'))

    expect(acompanhamento.estado.value).toBe('uploaded')
    expect(requisitar).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(INTERVALO)

    expect(requisitar).toHaveBeenCalledTimes(1)
    expect(acompanhamento.estado.value).toBe('processing')
  })
})

describe('leitura de um estado vindo como texto solto', () => {
  it.each(['pending', 'uploading', 'uploaded', 'processing', 'ready', 'failed'])(
    'aceita %s, que e estado do contrato',
    (valor) => {
      expect(comoEstadoDoVideo(valor)).toBe(valor)
    },
  )

  it.each(['constructor', 'toString', '__proto__', 'hasOwnProperty', 'valueOf'])(
    'recusa a chave herdada %s',
    (valor) => {
      // `valor in mapa` responderia `true` para todas elas, e o texto viraria um
      // estado do video — que a tela leria, e o acompanhamento classificaria.
      expect(comoEstadoDoVideo(valor)).toBeNull()
    },
  )

  it.each(['', 'READY', 'pronto', 'processando'])('recusa o valor invalido %s', (valor) => {
    expect(comoEstadoDoVideo(valor)).toBeNull()
  })

  it('recusa a ausencia', () => {
    expect(comoEstadoDoVideo(null)).toBeNull()
  })

  it('nao classifica como transitorio nada que nao seja estado do contrato', () => {
    expect(estadoTransitorio(null)).toBe(false)
    expect(estadoTransitorio(comoEstadoDoVideo('constructor'))).toBe(false)
    expect(estadoTransitorio('processing')).toBe(true)
    expect(estadoTransitorio('ready')).toBe(false)
  })
})
