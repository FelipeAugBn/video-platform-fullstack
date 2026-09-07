import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

/**
 * O `401` da reproducao: sessao expirada e retorno a rota pretendida
 * (RF-AUT-005, RF-UI-014).
 *
 * ## Por que este desfecho tem arquivo proprio
 *
 * Os outros testes de negativa substituem `useApi` e afirmam **o que a tela
 * mostra**. Mas o desvio para o login nao acontece na tela: ele nasce dentro do
 * cliente, que ao receber `401` limpa o usuario, marca a sessao como expirada e
 * guarda o caminho pretendido para depois da reautenticacao. Com o cliente
 * substituido, esse trecho nao roda — e a assercao viraria uma verificacao do
 * proprio dublê.
 *
 * Aqui quem e substituido e `$fetch`, e o cliente e o real. As duas metades da
 * corrente ficam provadas: que a pagina pede exatamente
 * `/api/lessons/{id}/playback` esta no arquivo vizinho, e que um `401` nesse
 * pedido conduz ao login carregando a rota, esta aqui.
 *
 * O roteador e substituido porque nenhuma pagina e montada neste arquivo: nao ha
 * link para resolver, e posicionar a rota de verdade exigiria uma navegacao que
 * dispararia o middleware e uma segunda requisicao — ruido sem nada a acrescentar.
 */

const navegar = vi.hoisted(() => vi.fn())

const ROTA = '/catalog/lessons/l1'

mockNuxtImport('navigateTo', () => navegar)
/*
| O roteador substituido precisa manter a superficie que o ambiente de teste e os
| plugins do Nuxt usam ao subir — os registradores de guarda entre elas. Um objeto
| so com `currentRoute` derrubaria a configuracao antes do primeiro teste, e a
| falha nao teria nada a ver com o que se quer afirmar aqui.
*/
mockNuxtImport('useRouter', () => () => ({
  currentRoute: { value: { fullPath: ROTA } },
  beforeEach: () => () => {},
  beforeResolve: () => () => {},
  afterEach: () => () => {},
  onError: () => () => {},
  isReady: () => Promise.resolve(),
  resolve: (destino: unknown) => ({ href: String(destino) }),
  push: () => Promise.resolve(),
  replace: () => Promise.resolve(),
}))

let caminhosPedidos: string[] = []

/**
 * O cliente real, reimportado depois do dublê de rede.
 *
 * A reimportacao tambem descarta a promessa de cookie memorizada no modulo, que
 * herdada de outro teste faria este pular a chamada que aquele ja tinha feito.
 */
async function comApi() {
  vi.resetModules()
  const { useApi } = await import('~/composables/useApi')

  return useApi()
}

beforeEach(() => {
  caminhosPedidos = []
  navegar.mockReset()

  vi.stubGlobal('$fetch', vi.fn((caminho: string) => {
    caminhosPedidos.push(caminho)

    // A forma que o cliente HTTP lanca: a excecao carrega a resposta e o corpo.
    return Promise.reject(Object.assign(new Error('HTTP 401'), {
      response: { status: 401 },
      status: 401,
      data: {
        type: 'https://api.example.test/problems/unauthenticated',
        title: 'Nao autenticado',
        status: 401,
        detail: 'Esta operacao exige autenticacao.',
        code: 'UNAUTHENTICATED',
      },
    }))
  }))
})

describe('sessao expirada ao pedir a reproducao', () => {
  it('conduz ao login guardando a rota pretendida', async () => {
    const { requisitar } = await comApi()
    const { usuario, estado } = useSessionState()

    usuario.value = { id: 'u1', name: 'Consumidora', email: 'c@video.test', role: 'consumer' }
    estado.value = 'autenticada'

    await requisitar('/api/lessons/l1/playback').catch(() => undefined)

    expect(caminhosPedidos).toEqual(['/api/lessons/l1/playback'])

    // O retorno acompanha o desvio: sem ele, reautenticar levaria a pessoa para
    // a area do perfil em vez da aula que ela estava tentando abrir.
    expect(navegar).toHaveBeenCalledWith({ path: '/login', query: { redirect: ROTA } })
  })

  it('marca expiracao, e nao ausencia de sessao', async () => {
    const { requisitar } = await comApi()
    const { usuario, estado } = useSessionState()

    usuario.value = { id: 'u1', name: 'Consumidora', email: 'c@video.test', role: 'consumer' }
    estado.value = 'autenticada'

    await requisitar('/api/lessons/l1/playback').catch(() => undefined)

    // Houve perda: a tela de login precisa dizer isso, e nao tratar quem foi
    // desviado como quem nunca entrou (RF-UI-014).
    expect(estado.value).toBe('expirada')
    expect(usuario.value).toBeNull()
  })

  it('nao repete o pedido negado', async () => {
    const { requisitar } = await comApi()

    await requisitar('/api/lessons/l1/playback').catch(() => undefined)

    // Repetir um `401` daria outro `401`, e cada repeticao empurraria uma nova
    // navegacao para o login.
    expect(caminhosPedidos).toHaveLength(1)
  })
})
