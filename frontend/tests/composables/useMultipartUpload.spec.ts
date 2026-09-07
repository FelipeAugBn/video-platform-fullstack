import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { ArquivoEnviavel, PlanoDeEnvio, TentativaDeVideo } from '~/types/video'
import { useMultipartUpload } from '~/composables/useMultipartUpload'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * O envio do video em partes (plan §11; AC-VID-001, AC-VID-012).
 *
 * As afirmacoes que mais importam aqui nao sao sobre o caminho feliz. Sao tres
 * negativas:
 *
 *   - **nenhum byte atravessa a nossa API** — o corpo da abertura leva nome,
 *     tipo e tamanho, e os bytes vao por `fetch` nativo direto ao armazenamento,
 *     sem cookie;
 *   - **transferencia interrompida nunca vira video pronto** — sem conclusao
 *     pedida, nada avanca;
 *   - **retomar nao reenvia o que ja tem comprovante** — nem reabre a tentativa.
 *
 * O arquivo e um dublê com `size` e `slice` controlados. Alocar varios gigabytes
 * para provar o recorte de partes testaria a capacidade do ambiente de fatiar
 * bytes, que nao e nossa; o que e nosso sao os **intervalos pedidos**, e e isso
 * que se afirma.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))

const AULA = 'a1'
const TENTATIVA = 't1'

const PRONTA: TentativaDeVideo = {
  id: TENTATIVA,
  lesson_id: AULA,
  state: 'uploaded',
  filename: 'aula.mp4',
  failure_code: null,
  failure_message: null,
}

/** A ordem em que a API e o armazenamento foram acionados, na sequencia real. */
let registro: string[] = []

/** Quantos `PUT` estiveram em voo ao mesmo tempo, no pico. */
let picoEmVoo = 0

let emVoo = 0

const enviarBytes = vi.fn()

interface Recorte {
  inicio: number
  fim: number
}

function arquivoFalso(tamanho: number, nome = 'aula.mp4', tipo = 'video/mp4') {
  const recortes: Recorte[] = []

  const arquivo: ArquivoEnviavel = {
    name: nome,
    type: tipo,
    size: tamanho,
    slice(inicio = 0, fim = tamanho) {
      recortes.push({ inicio, fim })

      return new Blob([`bytes ${inicio}-${fim}`])
    },
  }

  return { arquivo, recortes }
}

function respostaDoArmazenamento(status: number, etag?: string): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: {
      get: (nome: string) => (nome === 'ETag' && etag !== undefined ? etag : null),
    },
  } as unknown as Response
}

interface Cenario {
  plano?: Partial<PlanoDeEnvio>
  aoAbrir?: () => Promise<unknown>
  aoPedirUrl?: (numero: number, vez: number) => Promise<unknown>
  aoConcluir?: (vez: number) => Promise<unknown>
}

function montarApi(cenario: Cenario = {}): PlanoDeEnvio {
  const plano: PlanoDeEnvio = {
    attempt_id: TENTATIVA,
    storage_key: 'videos/t1/original.mp4',
    upload_id: 'u1',
    part_size: 40,
    part_count: 3,
    ...cenario.plano,
  }

  const vezesPorParte = new Map<number, number>()
  let vezesDaConclusao = 0

  requisitar.mockImplementation((caminho: string) => {
    if (caminho.endsWith('/video/uploads')) {
      registro.push('abrir')

      return cenario.aoAbrir?.() ?? Promise.resolve({ data: plano })
    }

    const parte = /\/parts\/(\d+)\/url$/.exec(caminho)

    if (parte !== null) {
      const numero = Number(parte[1])
      const vez = (vezesPorParte.get(numero) ?? 0) + 1
      vezesPorParte.set(numero, vez)
      registro.push(`url:${numero}`)

      return cenario.aoPedirUrl?.(numero, vez)
        ?? Promise.resolve({ data: { url: `https://armazenamento.test/parte-${numero}`, expires_at: '2026-09-06T12:15:00+00:00' } })
    }

    if (caminho.endsWith('/complete')) {
      vezesDaConclusao += 1
      registro.push('concluir')

      return cenario.aoConcluir?.(vezesDaConclusao) ?? Promise.resolve({ data: PRONTA })
    }

    throw new Error(`caminho inesperado: ${caminho}`)
  })

  return plano
}

/** Cada `PUT`, na ordem, com a contagem de simultaneidade. */
function montarArmazenamento(resposta: (url: string, vez: number) => Promise<Response> | Response) {
  const vezes = new Map<string, number>()

  enviarBytes.mockImplementation(async (url: string) => {
    const vez = (vezes.get(url) ?? 0) + 1
    vezes.set(url, vez)

    registro.push(`put:${url.slice(url.lastIndexOf('-') + 1)}`)

    emVoo += 1
    picoEmVoo = Math.max(picoEmVoo, emVoo)

    try {
      return await resposta(url, vez)
    }
    finally {
      emVoo -= 1
    }
  })
}

function chamadasDe(sufixo: string): unknown[][] {
  return requisitar.mock.calls.filter(([caminho]) => String(caminho).endsWith(sufixo))
}

function adiada<T>() {
  let resolver!: (valor: T) => void
  let rejeitar!: (causa: unknown) => void

  const promessa = new Promise<T>((paraResolver, paraRejeitar) => {
    resolver = paraResolver
    rejeitar = paraRejeitar
  })

  return { promessa, resolver, rejeitar }
}

beforeEach(() => {
  requisitar.mockReset()
  enviarBytes.mockReset()
  registro = []
  picoEmVoo = 0
  emVoo = 0

  vi.stubGlobal('fetch', enviarBytes)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('abertura', () => {
  it('declara nome, tipo e tamanho — e nenhum byte', async () => {
    montarApi()
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    expect(requisitar).toHaveBeenNthCalledWith(1, `/api/lessons/${AULA}/video/uploads`, {
      method: 'POST',
      body: { filename: 'aula.mp4', content_type: 'video/mp4', size: 100 },
    })

    const [, opcoes] = requisitar.mock.calls[0] as [string, { body: Record<string, unknown> }]

    // Exatamente os tres campos do contrato — e todos escalares. Um `Blob`, um
    // `ArrayBuffer` ou um `File` aqui significaria o arquivo atravessando a API.
    expect(Object.keys(opcoes.body).sort()).toEqual(['content_type', 'filename', 'size'])
    expect(Object.values(opcoes.body).every(valor => typeof valor !== 'object')).toBe(true)
  })

  it('nao transfere nada quando a abertura falha', async () => {
    montarApi({ aoAbrir: () => Promise.reject(problema(422, 'VALIDATION_FAILED')) })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    expect(enviarBytes).not.toHaveBeenCalled()
    expect(envio.fase.value).toBe('ocioso')
    expect(envio.erro.value?.status).toBe(422)
  })

  it('ignora um segundo pedido enquanto o primeiro esta em voo', async () => {
    const abertura = adiada<unknown>()
    montarApi({ aoAbrir: () => abertura.promessa })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    const primeiro = envio.enviar(arquivo)
    await envio.enviar(arquivo)

    // Duas aberturas criariam duas tentativas, e a segunda voltaria `409` depois
    // de a primeira ja ter comecado a subir bytes.
    expect(chamadasDe('/video/uploads')).toHaveLength(1)

    abertura.resolver({ data: montarApi() })
    await primeiro
  })
})

describe('particionamento', () => {
  it('recorta pelo plano da API, com a ultima parte menor', async () => {
    // 100 bytes em partes de 40: 40 + 40 + 20. O tamanho de parte e do backend —
    // nao ha aqui uma segunda constante de 64 MiB.
    montarApi({ plano: { part_size: 40, part_count: 3 } })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c"'))

    const { arquivo, recortes } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    expect(recortes).toEqual([
      { inicio: 0, fim: 40 },
      { inicio: 40, fim: 80 },
      { inicio: 80, fim: 100 },
    ])
    expect(envio.totalDePartes.value).toBe(3)
  })

  it('mantem uma unica parte em voo, na ordem crescente', async () => {
    montarApi({ plano: { part_size: 40, part_count: 3 } })
    montarArmazenamento(async () => {
      // Uma volta no laco de eventos: se houvesse concorrencia, dois `PUT`
      // estariam aqui dentro ao mesmo tempo.
      await Promise.resolve()

      return respostaDoArmazenamento(200, '"c"')
    })

    const { arquivo } = arquivoFalso(100)

    await useMultipartUpload(AULA).enviar(arquivo)

    expect(picoEmVoo).toBe(1)

    // A URL da parte seguinte so e pedida depois do comprovante da anterior.
    expect(registro).toEqual([
      'abrir',
      'url:1', 'put:1',
      'url:2', 'put:2',
      'url:3', 'put:3',
      'concluir',
    ])
  })
})

describe('transferencia direta ao armazenamento', () => {
  it('envia o PUT a URL assinada, sem credenciais e sem baseURL', async () => {
    montarApi({ plano: { part_size: 100, part_count: 1 } })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)

    await useMultipartUpload(AULA).enviar(arquivo)

    const [url, opcoes] = enviarBytes.mock.calls[0] as [string, RequestInit & { baseURL?: unknown }]

    // A URL vai exatamente como veio: qualquer acrescimo pode invalidar a
    // assinatura.
    expect(url).toBe('https://armazenamento.test/parte-1')
    expect(opcoes.method).toBe('PUT')
    expect(opcoes.body).toBeInstanceOf(Blob)

    // A credencial da sessao nao viaja para um host que nao precisa dela.
    expect(opcoes.credentials).toBe('omit')
    expect(opcoes.baseURL).toBeUndefined()
    expect(opcoes.headers).toBeUndefined()
  })

  it('preserva o comprovante exatamente como veio, aspas inclusive', async () => {
    montarApi({ plano: { part_size: 50, part_count: 2 } })
    montarArmazenamento((url) => respostaDoArmazenamento(200, url.endsWith('1') ? '"aspas-1"' : '"aspas-2"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    const [, opcoes] = chamadasDe('/complete')[0] as [string, { body: { parts: unknown[] } }]

    // Remover as aspas quebra a montagem no armazenamento.
    expect(opcoes.body.parts).toEqual([
      { part_number: 1, etag: '"aspas-1"' },
      { part_number: 2, etag: '"aspas-2"' },
    ])
  })

  it('so avanca o progresso com o comprovante em maos', async () => {
    const primeiroPut = adiada<Response>()

    montarApi({ plano: { part_size: 50, part_count: 2 } })
    montarArmazenamento((url) => (
      url.endsWith('1') ? primeiroPut.promessa : respostaDoArmazenamento(200, '"c2"')
    ))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    const emCurso = envio.enviar(arquivo)

    // Drena as microtarefas ate o `PUT` da primeira parte estar em voo; a
    // promessa adiada segura o envio exatamente nesse ponto.
    await flushPromises()

    // A URL foi emitida e o `PUT` esta em voo: nada disso e progresso.
    expect(enviarBytes).toHaveBeenCalledTimes(1)
    expect(envio.partesEnviadas.value).toBe(0)
    expect(envio.percentual.value).toBe(0)

    primeiroPut.resolver(respostaDoArmazenamento(200, '"c1"'))
    await emCurso

    expect(envio.partesEnviadas.value).toBe(2)
    expect(envio.percentual.value).toBe(100)
  })
})

describe('falha de transferencia', () => {
  it('para na parte que falhou, sem concluir e sem dar o video como pronto', async () => {
    montarApi({ plano: { part_size: 40, part_count: 3 } })
    montarArmazenamento((url) => (
      url.endsWith('2') ? Promise.reject(new Error('conexao perdida')) : respostaDoArmazenamento(200, '"c"')
    ))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // Sem conclusao pedida, o backend mantem a tentativa em `uploading`. A tela
    // tambem nao avanca (AC-VID-012).
    expect(chamadasDe('/complete')).toHaveLength(0)
    expect(envio.fase.value).toBe('interrompido')
    expect(envio.tentativa.value).toBeNull()
    expect(envio.interrupcao.value).toEqual({ parte: 2, motivo: 'rede', erro: null })

    // A parte 3 nunca foi tentada: a transferencia para, nao pula.
    expect(envio.partesEnviadas.value).toBe(1)
    expect(chamadasDe('/parts/3/url')).toHaveLength(0)
  })

  it('trata resposta sem comprovante como falha da parte', async () => {
    montarApi({ plano: { part_size: 50, part_count: 2 } })
    montarArmazenamento((url) => (
      url.endsWith('1') ? respostaDoArmazenamento(200, undefined) : respostaDoArmazenamento(200, '"c2"')
    ))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // `200` sem `ETag` parece sucesso e nao e: a conclusao seria pedida com uma
    // lista incompleta, depois de todo o resto ja ter subido.
    expect(envio.interrupcao.value?.motivo).toBe('semComprovante')
    expect(chamadasDe('/complete')).toHaveLength(0)
  })

  it('para na parte atual quando a autorizacao da parte falha, sem reabrir o envio', async () => {
    montarApi({
      plano: { part_size: 50, part_count: 2 },
      aoPedirUrl: numero => (
        numero === 2
          ? Promise.reject(problema(503, 'SERVICE_UNAVAILABLE'))
          : Promise.resolve({ data: { url: 'https://armazenamento.test/parte-1', expires_at: 'x' } })
      ),
    })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    expect(envio.fase.value).toBe('interrompido')
    expect(envio.interrupcao.value?.parte).toBe(2)
    expect(envio.interrupcao.value?.motivo).toBe('url')

    // A falha e da nossa API, entao ela tem mensagem publica para exibir.
    expect(envio.interrupcao.value?.erro?.status).toBe(503)

    // Reabrir criaria uma segunda tentativa e descartaria a parte ja confirmada.
    expect(chamadasDe('/video/uploads')).toHaveLength(1)
  })
})

describe('retomada', () => {
  it('reenvia somente a parte que falhou e segue dali', async () => {
    let falhar = true

    montarApi({ plano: { part_size: 40, part_count: 3 } })
    montarArmazenamento((url) => {
      if (url.endsWith('2') && falhar) {
        falhar = false

        return Promise.reject(new Error('conexao perdida'))
      }

      return respostaDoArmazenamento(200, `"c${url.slice(-1)}"`)
    })

    const { arquivo, recortes } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)
    expect(envio.fase.value).toBe('interrompido')

    recortes.length = 0
    await envio.retomar()

    // So as partes 2 e 3 foram recortadas de novo. A 1 nao voltou a subir: ela
    // ja tem comprovante, e reenvia-la seria refazer bytes por nada.
    expect(recortes).toEqual([
      { inicio: 40, fim: 80 },
      { inicio: 80, fim: 100 },
    ])
    expect(chamadasDe('/parts/1/url')).toHaveLength(1)
    expect(chamadasDe('/video/uploads')).toHaveLength(1)

    expect(envio.fase.value).toBe('concluido')
    const [, opcoes] = chamadasDe('/complete')[0] as [string, { body: { parts: Array<{ part_number: number }> } }]
    expect(opcoes.body.parts.map(parte => parte.part_number)).toEqual([1, 2, 3])
  })

  it('renova a URL uma vez diante de recusa de autorizacao do armazenamento', async () => {
    montarApi({ plano: { part_size: 50, part_count: 2 } })
    montarArmazenamento((url, vez) => (
      url.endsWith('1') && vez === 1
        ? respostaDoArmazenamento(403)
        : respostaDoArmazenamento(200, '"c"')
    ))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // A URL vale 15 minutos e uma parte grande pode atravessar o prazo: renovar
    // e repetir **aquela** parte, sem tocar nas outras.
    expect(chamadasDe('/parts/1/url')).toHaveLength(2)
    expect(chamadasDe('/parts/2/url')).toHaveLength(1)
    expect(envio.fase.value).toBe('concluido')
  })

  it('nao renova em laco quando a recusa persiste', async () => {
    montarApi({ plano: { part_size: 100, part_count: 1 } })
    montarArmazenamento(() => respostaDoArmazenamento(401))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // Exatamente uma renovacao automatica. Sem o limite, uma recusa permanente
    // giraria ate alguem fechar a aba.
    expect(chamadasDe('/parts/1/url')).toHaveLength(2)
    expect(enviarBytes).toHaveBeenCalledTimes(2)
    expect(envio.fase.value).toBe('interrompido')
    expect(envio.interrupcao.value?.motivo).toBe('recusado')
  })

  it('ignora retomadas repetidas enquanto uma esta em voo', async () => {
    const segundoPut = adiada<Response>()
    let falhar = true

    montarApi({ plano: { part_size: 50, part_count: 2 } })
    montarArmazenamento((url) => {
      if (url.endsWith('2')) {
        if (falhar) {
          falhar = false

          return Promise.reject(new Error('conexao perdida'))
        }

        return segundoPut.promessa
      }

      return respostaDoArmazenamento(200, '"c1"')
    })

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    const retomada = envio.retomar()
    await Promise.resolve()
    await envio.retomar()

    expect(chamadasDe('/parts/2/url')).toHaveLength(2)

    segundoPut.resolver(respostaDoArmazenamento(200, '"c2"'))
    await retomada
  })
})

describe('conclusao', () => {
  it('repete somente a conclusao quando ela falha, sem reenviar bytes', async () => {
    montarApi({
      plano: { part_size: 50, part_count: 2 },
      aoConcluir: vez => (
        vez === 1
          ? Promise.reject(problema(503, 'SERVICE_UNAVAILABLE'))
          : Promise.resolve({ data: PRONTA })
      ),
    })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // Indisponibilidade nao e decisao: o `503` da verificacao sem evidencia deixa
    // a tentativa em `uploading` justamente para a conclusao poder ser repetida.
    expect(envio.fase.value).toBe('conclusaoPendente')
    expect(envio.falhaRegistrada.value).toBe(false)
    expect(envio.tentativa.value).toBeNull()

    const putsAntes = enviarBytes.mock.calls.length
    const urlsAntes = chamadasDe('/parts/1/url').length + chamadasDe('/parts/2/url').length

    await envio.retomar()

    // Os comprovantes ja estao em maos: reenviar gigabytes por causa de segundos
    // de indisponibilidade seria refazer todo o trabalho.
    expect(enviarBytes.mock.calls).toHaveLength(putsAntes)
    expect(chamadasDe('/parts/1/url').length + chamadasDe('/parts/2/url').length).toBe(urlsAntes)
    expect(chamadasDe('/complete')).toHaveLength(2)
    expect(envio.fase.value).toBe('concluido')
    expect(envio.tentativa.value).toEqual(PRONTA)
  })

  it.each(['VIDEO_OBJECT_MISSING', 'VIDEO_OBJECT_MISMATCH'])(
    'trata %s como recusa terminal, com a falha ja registrada no backend',
    async (codigo) => {
      montarApi({
        plano: { part_size: 50, part_count: 2 },
        aoConcluir: () => Promise.reject(problema(409, codigo, 'O arquivo enviado nao foi encontrado no armazenamento.')),
      })
      montarArmazenamento(() => respostaDoArmazenamento(200, '"c"'))

      const { arquivo } = arquivoFalso(100)
      const envio = useMultipartUpload(AULA)

      await envio.enviar(arquivo)

      // O backend grava a recusa **antes** de responder: a mesma conclusao, com
      // os mesmos comprovantes, devolveria sempre o mesmo `409`.
      expect(envio.fase.value).toBe('conclusaoRecusada')
      expect(envio.falhaRegistrada.value).toBe(true)

      // Nada de sucesso sintetizado, e nenhuma tentativa inventada para
      // representar o `failed`: o que se afirma e o estado, nao um objeto que a
      // API nao devolveu.
      expect(envio.tentativa.value).toBeNull()
      expect(envio.erro.value?.code).toBe(codigo)
      expect(envio.erro.value?.mensagem).toBe('O arquivo enviado nao foi encontrado no armazenamento.')

      const conclusoesAntes = chamadasDe('/complete').length
      const putsAntes = enviarBytes.mock.calls.length

      await envio.retomar()

      // Um clique posterior nao repete nada — nem a conclusao, nem os bytes.
      expect(chamadasDe('/complete')).toHaveLength(conclusoesAntes)
      expect(enviarBytes.mock.calls).toHaveLength(putsAntes)
      expect(envio.fase.value).toBe('conclusaoRecusada')
    },
  )

  it('nao classifica como falha registrada a recusa que nao tocou no armazenamento', async () => {
    montarApi({
      plano: { part_size: 100, part_count: 1 },
      aoConcluir: () => Promise.reject(problema(409, 'VIDEO_UPLOAD_NOT_ACTIVE', 'Este envio ja foi encerrado.')),
    })
    montarArmazenamento(() => respostaDoArmazenamento(200, '"c1"'))

    const { arquivo } = arquivoFalso(100)
    const envio = useMultipartUpload(AULA)

    await envio.enviar(arquivo)

    // Terminal, como as outras recusas — mas afirmar `failed` aqui inventaria um
    // estado que o backend nao gravou.
    expect(envio.fase.value).toBe('conclusaoRecusada')
    expect(envio.falhaRegistrada.value).toBe(false)

    await envio.retomar()
    expect(chamadasDe('/complete')).toHaveLength(1)
  })
})

function problema(status: number, code: string, detail = 'Mensagem publica.'): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail, code },
  })
}
