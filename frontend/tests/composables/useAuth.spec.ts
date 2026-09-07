import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { ErroDeApi } from '~/utils/erroDeApi'

const navegar = vi.hoisted(() => vi.fn())
const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('navigateTo', () => navegar)
mockNuxtImport('useApi', () => () => ({ requisitar }))

/**
 * `useAuth` — sessao, login, logout e expiracao.
 *
 * O dublê e `useApi`, e nao o cliente HTTP: e assim que se afirma o que este
 * arquivo existe para garantir — que **nenhuma** chamada sai por fora do ponto
 * unico de HTTP. Se `useAuth` chamasse `$fetch` diretamente, o dublê nao seria
 * usado e as afirmacoes de chamada falhariam.
 */

const PRODUTOR = { id: 'u1', name: 'Produtora', email: 'p@video.test', role: 'producer' } as const
const CONSUMIDOR = { id: 'u2', name: 'Consumidor', email: 'c@video.test', role: 'consumer' } as const

function erroDeStatus(status: number, code: string): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem.', code },
  })
}

beforeEach(() => {
  navegar.mockClear()
  requisitar.mockReset()

  const { usuario, estado } = useSessionState()
  usuario.value = null
  estado.value = 'naoVerificada'
})

describe('recuperacao de sessao', () => {
  it('traz o usuario autenticado', async () => {
    requisitar.mockResolvedValue({ data: PRODUTOR })

    const { recuperarSessao, usuario, autenticado, verificada } = useAuth()
    await recuperarSessao()

    expect(usuario.value).toEqual(PRODUTOR)
    expect(autenticado.value).toBe(true)
    expect(verificada.value).toBe(true)
  })

  it('pede a /api/auth/me tratando o 401 como ausencia esperada', async () => {
    requisitar.mockResolvedValue({ data: PRODUTOR })

    const { recuperarSessao } = useAuth()
    await recuperarSessao()

    // Sem esta opcao, abrir a aplicacao sem ter entrado anunciaria "sua sessao
    // expirou" — uma perda que nunca aconteceu.
    expect(requisitar).toHaveBeenCalledWith('/api/auth/me', { sessaoAusenteEhEsperada: true })
  })

  it('ausencia de sessao na primeira verificacao nao vira expiracao', async () => {
    const { estado } = useSessionState()

    // O cliente ja traduziu o `401` esperado para `anonima`; aqui se afirma que
    // `useAuth` nao sobrescreve isso.
    requisitar.mockImplementation(() => {
      estado.value = 'anonima'

      return Promise.reject(erroDeStatus(401, 'UNAUTHENTICATED'))
    })

    const { recuperarSessao, expirada, usuario } = useAuth()
    await recuperarSessao().catch(() => undefined)

    expect(estado.value).toBe('anonima')
    expect(expirada.value).toBe(false)
    expect(usuario.value).toBeNull()
  })

  it('falha de rede nao afirma ausencia de sessao', async () => {
    // Rede caida nao prova que ninguem esta autenticado. Marcar "anonima" aqui
    // deslogaria visualmente quem so ficou sem conexao por um instante.
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const { recuperarSessao } = useAuth()
    const { estado } = useSessionState()
    await recuperarSessao().catch(() => undefined)

    expect(estado.value).toBe('naoVerificada')
  })

  it('expiracao depois de sessao autenticada e um estado proprio', async () => {
    requisitar.mockResolvedValueOnce({ data: PRODUTOR })

    const { recuperarSessao, expirada } = useAuth()
    await recuperarSessao()

    // Uma chamada seguinte recebe `401`; o cliente marca expirada.
    const { usuario, estado } = useSessionState()
    usuario.value = null
    estado.value = 'expirada'

    expect(expirada.value).toBe(true)
  })
})

describe('login', () => {
  it('autentica o produtor e encaminha para a area dele', async () => {
    requisitar.mockResolvedValue({ data: PRODUTOR })

    const { entrar, usuario, autenticado } = useAuth()
    await entrar({ email: 'p@video.test', password: 'segredo' })

    expect(requisitar).toHaveBeenCalledWith('/api/auth/login', {
      method: 'POST',
      body: { email: 'p@video.test', password: 'segredo' },
    })
    expect(usuario.value).toEqual(PRODUTOR)
    expect(autenticado.value).toBe(true)
    expect(navegar).toHaveBeenCalledWith('/producer/courses')
  })

  it('autentica o consumidor e encaminha para o catalogo', async () => {
    requisitar.mockResolvedValue({ data: CONSUMIDOR })

    const { entrar } = useAuth()
    await entrar({ email: 'c@video.test', password: 'segredo' })

    expect(navegar).toHaveBeenCalledWith('/catalog')
  })

  it('usa o retorno pedido quando ele e um caminho interno', async () => {
    requisitar.mockResolvedValue({ data: PRODUTOR })

    const { entrar } = useAuth()
    await entrar({ email: 'p@video.test', password: 'x' }, '/producer/courses/abc')

    expect(navegar).toHaveBeenCalledWith('/producer/courses/abc')
  })

  it.each([
    ['URL absoluta', 'https://outro-site.test/roubo'],
    ['caminho de protocolo relativo', '//outro-site.test/roubo'],
    ['valor sem barra inicial', 'producer/courses'],
    ['area do outro perfil', '/catalog'],
  ])('recusa retorno inseguro: %s', async (_caso, destino) => {
    requisitar.mockResolvedValue({ data: PRODUTOR })

    const { entrar } = useAuth()
    await entrar({ email: 'p@video.test', password: 'x' }, destino)

    // Sem esta recusa, um link com `?redirect=` levaria quem acabou de
    // autenticar para fora da aplicacao, com a credibilidade de ter vindo de uma
    // tela de login legitima.
    expect(navegar).toHaveBeenCalledWith('/producer/courses')
  })

  it('nao autentica nem encaminha quando a API recusa', async () => {
    requisitar.mockRejectedValue(erroDeStatus(422, 'VALIDATION_FAILED'))

    const { entrar, usuario, autenticado } = useAuth()
    await expect(entrar({ email: 'p@video.test', password: 'errada' })).rejects.toBeInstanceOf(ErroDeApi)

    expect(usuario.value).toBeNull()
    expect(autenticado.value).toBe(false)
    expect(navegar).not.toHaveBeenCalled()
  })
})

describe('logout', () => {
  it('limpa o estado somente depois da confirmacao do servidor', async () => {
    requisitar.mockResolvedValue(undefined)

    const { usuario, estado } = useSessionState()
    usuario.value = { ...PRODUTOR }
    estado.value = 'autenticada'

    const { sair } = useAuth()
    await sair()

    expect(requisitar).toHaveBeenCalledWith('/api/auth/logout', { method: 'POST' })
    expect(usuario.value).toBeNull()
    expect(estado.value).toBe('anonima')
  })

  it('preserva a sessao quando a chamada falha', async () => {
    // Limpar antes da confirmacao deixaria a interface deslogada com a sessao
    // viva do outro lado.
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const { usuario, estado } = useSessionState()
    usuario.value = { ...PRODUTOR }
    estado.value = 'autenticada'

    const { sair } = useAuth()
    await sair().catch(() => undefined)

    expect(usuario.value).toEqual(PRODUTOR)
    expect(estado.value).toBe('autenticada')
  })
})
