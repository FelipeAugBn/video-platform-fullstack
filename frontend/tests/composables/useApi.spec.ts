import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import type { OpcoesDaRequisicao } from '~/composables/useApi'

const navegar = vi.hoisted(() => vi.fn())
mockNuxtImport('navigateTo', () => navegar)

/**
 * `useApi` — o unico ponto que fala HTTP.
 *
 * As afirmacoes conferem **as opcoes efetivamente passadas** ao cliente, e nao
 * apenas o valor devolvido. A diferenca importa: um teste que so olhasse o
 * retorno passaria com credenciais desligadas, sem token de protecao e sem
 * traducao de erro — exatamente as tres coisas que este composable existe para
 * garantir.
 *
 * O modulo e reimportado a cada teste. Ele guarda, entre chamadas, a promessa da
 * obtencao do cookie de protecao; herdada de um teste para o outro, ela faria o
 * segundo teste pular a chamada que o primeiro ja tinha feito.
 */

type Chamada = [string, Record<string, unknown>]

interface RespostaFalsa {
  status?: number
  corpo?: unknown
  rede?: boolean
}

let respostas: RespostaFalsa[] = []
let chamadas: Chamada[] = []

function erroDeResposta(status: number, corpo: unknown): Error {
  // A forma que o cliente HTTP do Nuxt lanca: a excecao carrega a resposta e o
  // corpo ja convertido.
  return Object.assign(new Error(`HTTP ${status}`), {
    response: { status },
    status,
    data: corpo,
  })
}

function problema(status: number, code: string, extras: Record<string, unknown> = {}) {
  return {
    type: `https://api.example.test/problems/${code.toLowerCase().replaceAll('_', '-')}`,
    title: 'Falha',
    status,
    detail: 'Mensagem publica.',
    code,
    ...extras,
  }
}

function responder(...sequencia: RespostaFalsa[]): void {
  respostas = [...sequencia]
}

async function comApi() {
  vi.resetModules()
  const { useApi } = await import('~/composables/useApi')

  return useApi()
}

beforeEach(() => {
  chamadas = []
  respostas = []
  navegar.mockClear()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'

  vi.stubGlobal('$fetch', vi.fn((caminho: string, opcoes: Record<string, unknown>) => {
    chamadas.push([caminho, opcoes])

    const proxima = respostas.shift() ?? { corpo: { data: null } }

    if (proxima.rede === true) {
      // Sem `response` e sem `status`: e assim que uma falha de conexao chega.
      return Promise.reject(new Error('Failed to fetch'))
    }

    if (proxima.status !== undefined && proxima.status >= 400) {
      return Promise.reject(erroDeResposta(proxima.status, proxima.corpo))
    }

    return Promise.resolve(proxima.corpo)
  }))
})

function mutacoes(): Chamada[] {
  return chamadas.filter(([caminho]) => caminho !== '/sanctum/csrf-cookie')
}

function pedidosDeCookie(): Chamada[] {
  return chamadas.filter(([caminho]) => caminho === '/sanctum/csrf-cookie')
}

describe('sucesso e envelope', () => {
  it('devolve a resposta inteira, com meta e links preservados', async () => {
    const pagina = {
      data: [{ id: 'c1' }],
      meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 },
      links: { first: '/a', prev: null, next: null, last: '/a' },
    }
    responder({ corpo: pagina })

    const { requisitar } = await comApi()

    // Desembrulhar `data` aqui perderia a paginacao no caminho, e a tela nao
    // teria como montar a navegacao entre paginas.
    await expect(requisitar('/api/courses')).resolves.toEqual(pagina)
  })

  it('envia credenciais e aceita apenas JSON', async () => {
    responder({ corpo: { data: null } })

    const { requisitar } = await comApi()
    await requisitar('/api/auth/me')

    const [, opcoes] = chamadas[0]!

    // Sem isto o navegador nao envia o cookie de sessao entre origens, e toda
    // chamada responderia `401`.
    expect(opcoes.credentials).toBe('include')
    expect(opcoes.headers).toMatchObject({ Accept: 'application/json' })
    expect(opcoes.method).toBe('GET')
  })

  it('nao pede o cookie de protecao numa leitura', async () => {
    responder({ corpo: { data: null } })

    const { requisitar } = await comApi()
    await requisitar('/api/courses')

    expect(pedidosDeCookie()).toHaveLength(0)
  })
})

describe('protecao contra requisicao forjada', () => {
  it('obtem o cookie antes da primeira mutacao', async () => {
    responder({ corpo: undefined }, { corpo: { data: { id: 'u1' } } })

    const { requisitar } = await comApi()
    await requisitar('/api/courses', { method: 'POST', body: { title: 'Curso' } })

    expect(chamadas[0]![0]).toBe('/sanctum/csrf-cookie')
    expect(chamadas[1]![0]).toBe('/api/courses')
  })

  it('envia o token decodificado no cabecalho', async () => {
    // O cookie chega codificado como URL. Enviado sem decodificar, ele nao
    // confere com o que o servidor guardou.
    document.cookie = 'XSRF-TOKEN=abc%3Ddef%2Bghi'
    responder({ corpo: undefined }, { corpo: { data: null } })

    const { requisitar } = await comApi()
    await requisitar('/api/courses', { method: 'POST', body: { title: 'Curso' } })

    const [, opcoes] = mutacoes()[0]!
    expect((opcoes.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=def+ghi')
  })

  it('nao confunde o cookie com outro de nome parecido', async () => {
    document.cookie = 'OUTRO-XSRF-TOKEN=errado'
    document.cookie = 'XSRF-TOKEN=certo'
    responder({ corpo: undefined }, { corpo: { data: null } })

    const { requisitar } = await comApi()
    await requisitar('/api/courses', { method: 'POST', body: {} })

    const [, opcoes] = mutacoes()[0]!
    expect((opcoes.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('certo')
  })

  it('rele o cookie a cada mutacao, sem reaproveitar o valor anterior', async () => {
    // E o cenario do login: ele regenera a sessao e o token. Um valor guardado
    // em cache estaria vencido exatamente na operacao seguinte.
    document.cookie = 'XSRF-TOKEN=antes-do-login'
    responder(
      { corpo: undefined },
      { corpo: { data: { role: 'producer' } } },
      { corpo: { data: null } },
    )

    const { requisitar } = await comApi()
    await requisitar('/api/auth/login', { method: 'POST', body: { email: 'a@b.c', password: 'x' } })

    document.cookie = 'XSRF-TOKEN=depois-do-login'
    await requisitar('/api/courses', { method: 'POST', body: { title: 'Curso' } })

    const enviadas = mutacoes()
    expect((enviadas[0]![1].headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('antes-do-login')
    expect((enviadas[1]![1].headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('depois-do-login')
  })

  it('compartilha uma unica obtencao entre mutacoes simultaneas', async () => {
    responder({ corpo: undefined }, { corpo: { data: null } }, { corpo: { data: null } })

    const { requisitar } = await comApi()
    await Promise.all([
      requisitar('/api/courses', { method: 'POST', body: {} }),
      requisitar('/api/courses', { method: 'POST', body: {} }),
    ])

    expect(pedidosDeCookie()).toHaveLength(1)
  })
})

describe('traducao das falhas', () => {
  it('preserva os erros por campo do 422', async () => {
    responder({
      status: 422,
      corpo: problema(422, 'VALIDATION_FAILED', {
        errors: { email: ['As credenciais informadas nao conferem.'] },
      }),
    })

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/auth/me').catch((e: unknown) => e)

    expect(erro).toMatchObject({
      categoria: 'problema',
      status: 422,
      code: 'VALIDATION_FAILED',
      errors: { email: ['As credenciais informadas nao conferem.'] },
    })
  })

  it('preserva o codigo funcional e a extensao do 409', async () => {
    responder({
      status: 409,
      corpo: problema(409, 'LESSON_VIDEO_NOT_READY', { video_state: 'processing' }),
    })

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/lessons/x/publish', { method: 'POST' }).catch((e: unknown) => e)

    // `video_state` viaja junto do problema justamente para a tela nao precisar
    // de uma segunda requisicao para descobrir o que ja veio no erro.
    expect(erro).toMatchObject({
      status: 409,
      code: 'LESSON_VIDEO_NOT_READY',
      videoState: 'processing',
    })
  })

  it('classifica falha de rede sem resposta', async () => {
    responder({ rede: true })

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/courses').catch((e: unknown) => e)

    expect(erro).toMatchObject({ categoria: 'rede', status: null, code: null })
  })

  it('classifica 5xx como indisponibilidade, preservando o codigo quando houver', async () => {
    responder({ status: 503, corpo: problema(503, 'SERVICE_UNAVAILABLE') })

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/courses').catch((e: unknown) => e)

    expect(erro).toMatchObject({ categoria: 'indisponivel', status: 503, code: 'SERVICE_UNAVAILABLE' })
  })

  it('classifica corpo fora do contrato como inesperado', async () => {
    // Uma pagina de erro de intermediario, por exemplo. Tratada como problema,
    // a tela leria `status` e `code` de um objeto que nao os tem.
    responder({ status: 400, corpo: '<html>erro</html>' })

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/courses').catch((e: unknown) => e)

    expect(erro).toMatchObject({ categoria: 'inesperado', status: 400, code: null })
  })
})

describe('sessao', () => {
  it('401 limpa o usuario, marca expirada e conduz ao login', async () => {
    responder({ status: 401, corpo: problema(401, 'UNAUTHENTICATED') })

    const { requisitar } = await comApi()
    const { usuario, estado } = useSessionState()
    usuario.value = { id: 'u1', name: 'Alguem', email: 'a@b.c', role: 'producer' }
    estado.value = 'autenticada'

    await requisitar('/api/courses').catch(() => undefined)

    expect(usuario.value).toBeNull()
    expect(estado.value).toBe('expirada')
    expect(navegar).toHaveBeenCalledWith(expect.objectContaining({ path: '/login' }))
  })

  it('401 esperado vira sessao anonima e nao redireciona', async () => {
    responder({ status: 401, corpo: problema(401, 'UNAUTHENTICATED') })

    const { requisitar } = await comApi()
    const { estado } = useSessionState()

    const opcoes: OpcoesDaRequisicao = { sessaoAusenteEhEsperada: true }
    await requisitar('/api/auth/me', opcoes).catch(() => undefined)

    // Quem apenas abriu a aplicacao sem entrar nao perdeu sessao nenhuma.
    expect(estado.value).toBe('anonima')
    expect(navegar).not.toHaveBeenCalled()
  })

  it('403 nao mexe na sessao nem redireciona', async () => {
    responder({ status: 403, corpo: problema(403, 'FORBIDDEN') })

    const { requisitar } = await comApi()
    const { estado } = useSessionState()
    estado.value = 'autenticada'

    await requisitar('/api/catalog/courses').catch(() => undefined)

    expect(estado.value).toBe('autenticada')
    expect(navegar).not.toHaveBeenCalled()
  })
})

describe('repeticao unica do 419', () => {
  it('renova o cookie e repete uma vez quando a repeticao resolve', async () => {
    document.cookie = 'XSRF-TOKEN=vencido'
    responder(
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CSRF_TOKEN_MISMATCH') },
      { corpo: undefined },
      { corpo: { data: { id: 'c1' } } },
    )

    const { requisitar } = await comApi()
    const resposta = await requisitar('/api/courses', { method: 'POST', body: { title: 'Curso' } })

    expect(resposta).toEqual({ data: { id: 'c1' } })
    expect(pedidosDeCookie()).toHaveLength(2)
    expect(mutacoes()).toHaveLength(2)
  })

  it('nao envia uma terceira vez quando a repeticao tambem falha', async () => {
    responder(
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CSRF_TOKEN_MISMATCH') },
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CSRF_TOKEN_MISMATCH') },
    )

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/courses', { method: 'POST', body: {} }).catch((e: unknown) => e)

    // O limite e o que impede o laco infinito de renovar e reenviar: sem ele, um
    // `419` persistente deixaria a tela carregando para sempre.
    expect(mutacoes()).toHaveLength(2)
    expect(erro).toMatchObject({ status: 419, code: 'CSRF_TOKEN_MISMATCH' })
  })

  it('repete a mesma requisicao: metodo, corpo, query e cabecalhos', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    responder(
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CSRF_TOKEN_MISMATCH') },
      { corpo: undefined },
      { corpo: { data: null } },
    )

    const { requisitar } = await comApi()
    await requisitar('/api/courses', {
      method: 'PATCH',
      body: { title: 'Curso' },
      query: { page: 2 },
      headers: { 'X-Proprio': 'valor' },
    })

    const [primeira, repeticao] = mutacoes()
    expect(repeticao![1].method).toBe(primeira![1].method)
    expect(repeticao![1].body).toEqual(primeira![1].body)
    expect(repeticao![1].query).toEqual(primeira![1].query)
    expect((repeticao![1].headers as Record<string, string>)['X-Proprio']).toBe('valor')
  })

  it('nao repete um 419 que nao e do token', async () => {
    responder(
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CONFLICT') },
    )

    const { requisitar } = await comApi()
    await requisitar('/api/courses', { method: 'POST', body: {} }).catch(() => undefined)

    expect(mutacoes()).toHaveLength(1)
  })

  it('encerra sem deixar promessa pendente quando a renovacao falha na rede', async () => {
    responder(
      { corpo: undefined },
      { status: 419, corpo: problema(419, 'CSRF_TOKEN_MISMATCH') },
      { rede: true },
    )

    const { requisitar } = await comApi()
    const erro = await requisitar('/api/courses', { method: 'POST', body: {} }).catch((e: unknown) => e)

    // A promessa **rejeita**; ela nao fica pendurada. E o que tira a tela do
    // estado de carregamento em vez de deixa-la girando (RF-UI-012).
    expect(erro).toMatchObject({ categoria: 'rede' })
    expect(mutacoes()).toHaveLength(1)
  })
})
