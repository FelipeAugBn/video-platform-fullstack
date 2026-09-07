import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import type { RouteLocationNormalized } from 'vue-router'
import { ErroDeApi } from '~/utils/erroDeApi'
import middleware from '~/middleware/auth'

const navegar = vi.hoisted(() => vi.fn())
const recuperarSessao = vi.hoisted(() => vi.fn())

mockNuxtImport('navigateTo', () => navegar)

mockNuxtImport('useAuth', () => () => {
  const { usuario, estado } = useSessionState()

  return {
    usuario,
    estado,
    autenticado: computed(() => estado.value === 'autenticada'),
    carregando: computed(() => estado.value === 'verificando'),
    expirada: computed(() => estado.value === 'expirada'),
    verificada: computed(() => estado.value !== 'naoVerificada'),
    recuperarSessao,
    entrar: vi.fn(),
    sair: vi.fn(),
    destinoDoPerfil: () => '/producer/courses',
  }
})

/**
 * O middleware de rota.
 *
 * A afirmacao que vale mais aqui e a do terceiro caso: **falha de rede nao e
 * ausencia de sessao**. Um middleware escrito pela negacao — "se nao esta
 * autenticado, va para o login" — passa nos dois primeiros testes e reprova
 * neste, porque manda para a tela de login quem apenas ficou sem conexao por um
 * instante.
 */

const DESTINO = { fullPath: '/producer/courses/abc' } as RouteLocationNormalized

const DE_ONDE_VEIO = { fullPath: '/' } as RouteLocationNormalized

function executar() {
  return middleware(DESTINO, DE_ONDE_VEIO)
}

function estadoAtual() {
  return useSessionState().estado
}

beforeEach(() => {
  navegar.mockClear()
  recuperarSessao.mockReset()

  const { usuario, estado } = useSessionState()
  usuario.value = null
  estado.value = 'naoVerificada'
})

describe('sessao ja verificada', () => {
  it('deixa passar quem esta autenticado, sem reconsultar', async () => {
    estadoAtual().value = 'autenticada'

    await executar()

    expect(recuperarSessao).not.toHaveBeenCalled()
    expect(navegar).not.toHaveBeenCalled()
  })

  it('conduz ao login quem ja foi marcado como expirado', async () => {
    estadoAtual().value = 'expirada'

    await executar()

    expect(recuperarSessao).not.toHaveBeenCalled()
    expect(navegar).toHaveBeenCalledWith({
      path: '/login',
      query: { redirect: '/producer/courses/abc' },
    })
  })
})

describe('primeira verificacao', () => {
  it('consulta a sessao uma vez e deixa passar quando ha sessao', async () => {
    recuperarSessao.mockImplementation(async () => {
      estadoAtual().value = 'autenticada'
    })

    await executar()

    expect(recuperarSessao).toHaveBeenCalledTimes(1)
    expect(navegar).not.toHaveBeenCalled()
  })

  it('401 sem sessao conduz ao login preservando o caminho pretendido', async () => {
    // O desfecho de quem abre uma tela protegida sem ter entrado: a API
    // respondeu, e a resposta prova que nao ha sessao.
    recuperarSessao.mockImplementation(async () => {
      estadoAtual().value = 'anonima'

      throw ErroDeApi.de({
        response: { status: 401 },
        status: 401,
        data: {
          type: 'https://x/unauthenticated',
          title: 'Nao autenticado',
          status: 401,
          detail: 'Entre na aplicacao para continuar.',
          code: 'UNAUTHENTICATED',
        },
      })
    })

    await executar()

    expect(navegar).toHaveBeenCalledWith({
      path: '/login',
      query: { redirect: '/producer/courses/abc' },
    })
  })
})

describe('quando nao foi possivel saber', () => {
  it('falha de rede nao redireciona para o login', async () => {
    // `recuperarSessao` devolve o estado a `naoVerificada` porque nao houve
    // resposta que provasse coisa alguma.
    recuperarSessao.mockImplementation(async () => {
      estadoAtual().value = 'naoVerificada'

      throw ErroDeApi.de(new Error('Failed to fetch'))
    })

    await executar()

    // A afirmacao central: rede caida nao pode ser lida como ausencia de sessao.
    expect(navegar).not.toHaveBeenCalled()
    expect(estadoAtual().value).toBe('naoVerificada')
  })

  it('5xx nao redireciona para o login', async () => {
    recuperarSessao.mockImplementation(async () => {
      estadoAtual().value = 'naoVerificada'

      throw ErroDeApi.de({
        response: { status: 503 },
        status: 503,
        data: {
          type: 'https://x/service-unavailable',
          title: 'Servico indisponivel',
          status: 503,
          detail: 'Tente de novo em alguns instantes.',
          code: 'SERVICE_UNAVAILABLE',
        },
      })
    })

    await executar()

    expect(navegar).not.toHaveBeenCalled()
    expect(estadoAtual().value).toBe('naoVerificada')
  })

  it('a navegacao segue, e a tela de destino lida com a indisponibilidade', async () => {
    recuperarSessao.mockImplementation(async () => {
      throw ErroDeApi.de(new Error('Failed to fetch'))
    })

    // `undefined` e o que o Nuxt entende como "siga em frente".
    await expect(executar()).resolves.toBeUndefined()
  })

  it('nao redireciona enquanto uma verificacao esta em voo', async () => {
    // Duas navegacoes simultaneas: a segunda encontra a primeira a meio caminho.
    // Sem sessao decidida, desviar seria decidir por adivinhacao.
    estadoAtual().value = 'verificando'
    recuperarSessao.mockResolvedValue(undefined)

    await executar()

    expect(navegar).not.toHaveBeenCalled()
  })
})
