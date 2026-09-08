import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Usuario } from '~/composables/useSessionState'
import { ErroDeApi } from '~/utils/erroDeApi'
import Entrada from '~/pages/index.vue'

const navegar = vi.hoisted(() => vi.fn())
const recuperarSessao = vi.hoisted(() => vi.fn())

mockNuxtImport('navigateTo', () => navegar)

mockNuxtImport('useAuth', () => () => {
  const { usuario, estado } = useSessionState()

  return {
    usuario,
    estado,
    autenticado: computed(() => estado.value === 'autenticada' && usuario.value !== null),
    carregando: computed(() => estado.value === 'verificando'),
    expirada: computed(() => estado.value === 'expirada'),
    verificada: computed(() => estado.value !== 'naoVerificada'),
    recuperarSessao,
    entrar: vi.fn(),
    sair: vi.fn(),
    destinoDoPerfil: (perfil: Usuario['role']) => (
      perfil === 'producer' ? '/producer/courses' : '/catalog'
    ),
  }
})

/**
 * A porta de entrada (`/`) — RF-UI-018, AC-UI-005.
 *
 * A rota nao tem conteudo: quase tudo o que ela faz e decidir um destino. Os
 * testes abaixo separam os quatro desfechos, e a afirmacao que vale mais e a do
 * quarto: **indisponibilidade da API nao e ausencia de sessao**.
 *
 * Uma pagina escrita pela negacao — "se nao esta autenticado, va para o login" —
 * passa nos tres primeiros testes e reprova naquele, porque manda para a
 * reautenticacao quem apenas ficou sem conexao. O `401` e a unica resposta que
 * prova alguma coisa sobre a sessao; rede, `5xx` e resposta fora do contrato nao
 * provam nada, e por isso a tela permanece aqui com o estado de
 * indisponibilidade e um caminho de volta (RF-UI-011, RF-UI-012, RF-UI-014).
 *
 * `useAuth` e substituido de proposito. O destino por perfil **nao** e
 * recalculado por esta tela: ele vem de `destinoDoPerfil`, o mesmo que o login
 * usa. Um mapa proprio escrito na pagina ignoraria o dublê e falharia aqui.
 */

function problema(status: number, code: string): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem publica.', code },
  })
}

function autenticar(perfil: Usuario['role']): void {
  const { usuario, estado } = useSessionState()

  usuario.value = { id: 'u1', name: 'Pessoa', email: 'p@video.test', role: perfil }
  estado.value = 'autenticada'
}

type Tela = Awaited<ReturnType<typeof mountSuspended>>

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

async function abrir(): Promise<Tela> {
  const tela = await mountSuspended(Entrada, { attachTo: document.body })
  await assentar()

  return tela
}

beforeEach(() => {
  navegar.mockClear()
  recuperarSessao.mockReset()

  const { usuario, estado } = useSessionState()
  usuario.value = null
  estado.value = 'naoVerificada'
})

describe('sessao ja verificada', () => {
  it('encaminha o produtor para a area dele sem perguntar de novo', async () => {
    autenticar('producer')

    await abrir()

    // Verificada uma vez, nao se pergunta outra: entrar pela raiz nao acrescenta
    // requisicao a jornada de quem ja esta autenticado.
    expect(recuperarSessao).not.toHaveBeenCalled()
    expect(navegar).toHaveBeenCalledWith('/producer/courses', { replace: true })
  })

  it('encaminha o consumidor para o catalogo', async () => {
    autenticar('consumer')

    await abrir()

    expect(navegar).toHaveBeenCalledWith('/catalog', { replace: true })
  })

  it('leva ao login quem nunca entrou', async () => {
    useSessionState().estado.value = 'anonima'

    await abrir()

    // Sem `?redirect=/`: a raiz nao e um destino ao qual valha a pena voltar, e
    // o login ja sabe para onde mandar cada perfil.
    expect(navegar).toHaveBeenCalledWith('/login', { replace: true })
  })

  it('leva ao login quem teve a sessao expirada', async () => {
    useSessionState().estado.value = 'expirada'

    await abrir()

    expect(navegar).toHaveBeenCalledWith('/login', { replace: true })
  })
})

describe('sessao ainda nao verificada', () => {
  it('pergunta uma vez e encaminha conforme a resposta', async () => {
    recuperarSessao.mockImplementation(async () => {
      autenticar('consumer')
    })

    await abrir()

    expect(recuperarSessao).toHaveBeenCalledTimes(1)
    expect(navegar).toHaveBeenCalledWith('/catalog', { replace: true })
  })

  it('leva ao login quando a API responde que nao ha sessao', async () => {
    recuperarSessao.mockImplementation(async () => {
      useSessionState().estado.value = 'anonima'

      throw ErroDeApi.de({
        response: { status: 401 },
        status: 401,
        data: {
          type: 'https://x/y',
          title: 'Falha',
          status: 401,
          detail: 'Mensagem publica.',
          code: 'UNAUTHENTICATED',
        },
      })
    })

    await abrir()

    expect(navegar).toHaveBeenCalledWith('/login', { replace: true })
  })

})

describe('indisponibilidade nao e ausencia de sessao', () => {
  const INDISPONIVEIS: Array<[string, () => unknown]> = [
    ['rede', () => ErroDeApi.de(new Error('Failed to fetch'))],
    ['indisponivel', () => problema(503, 'SERVICE_UNAVAILABLE')],
    ['inesperado', () => problema(418, 'ALGO_QUE_A_TELA_NAO_CONHECE')],
  ]

  it.each(INDISPONIVEIS)('nao navega quando a falha e %s', async (_situacao, causa) => {
    recuperarSessao.mockRejectedValue(causa())

    await abrir()

    // A afirmacao central: sem prova de ausencia de sessao, ninguem e mandado
    // para a reautenticacao. Um `/login` aqui anunciaria uma perda que nao houve.
    expect(navegar).not.toHaveBeenCalled()
  })

  it.each(INDISPONIVEIS)('apresenta a indisponibilidade como %s, e nao carregamento', async (situacao, causa) => {
    recuperarSessao.mockRejectedValue(causa())

    const tela = await abrir()
    const falha = tela.find('[data-estado="falha"]')

    expect(falha.exists()).toBe(true)
    expect(falha.attributes('data-situacao')).toBe(situacao)

    // A tela nao pode permanecer em carregamento indefinido (RF-UI-012).
    expect(tela.find('[data-estado="carregando"]').exists()).toBe(false)
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(true)
  })

  it('a nova tentativa repete a verificacao de sessao', async () => {
    recuperarSessao.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await abrir()
    expect(recuperarSessao).toHaveBeenCalledTimes(1)

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    // Repetir precisa **perguntar de novo**: uma tela que so redesenha o mesmo
    // erro daria a impressao de ter tentado sem ter tentado.
    expect(recuperarSessao).toHaveBeenCalledTimes(2)
  })

  it('a nova tentativa bem-sucedida encaminha ao destino do perfil', async () => {
    recuperarSessao.mockRejectedValueOnce(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await abrir()
    expect(navegar).not.toHaveBeenCalled()

    recuperarSessao.mockImplementation(async () => {
      autenticar('producer')
    })

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    expect(navegar).toHaveBeenCalledWith('/producer/courses', { replace: true })
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
  })

  it('a segunda tentativa em voo nao decide sozinha', async () => {
    // `recuperarSessao` se recusa a comecar enquanto a anterior nao terminou.
    // Sem guarda, a segunda passagem chegaria a decisao com a sessao ainda em
    // verificacao e mandaria para o login quem pode ter sessao valida.
    let liberar: (valor: unknown) => void = () => {}

    recuperarSessao.mockRejectedValueOnce(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await abrir()

    recuperarSessao.mockImplementation(() => new Promise((resolver) => {
      liberar = resolver
    }))

    // Os dois cliques no **mesmo** elemento, sem esperar entre eles: e assim que
    // acontece num navegador, e e o unico jeito de a segunda passagem encontrar a
    // primeira ainda em voo. Esperando, o painel ja teria saido da tela e o
    // segundo clique nao teria onde cair.
    const botao = tela.find('[data-acao="nova-tentativa"]').element as HTMLElement

    botao.click()
    botao.click()
    await flushPromises()

    expect(recuperarSessao).toHaveBeenCalledTimes(2)
    expect(navegar).not.toHaveBeenCalled()

    liberar(undefined)
  })
})

describe('estado incoerente', () => {
  it('nao encaminha para area nenhuma sem usuario em maos', async () => {
    const { usuario, estado } = useSessionState()

    // `autenticada` sem usuario nao deveria acontecer, e por isso mesmo nao pode
    // escolher area: `destinoDoPerfil` precisa de um perfil, e inventar um
    // mandaria para o lugar errado.
    usuario.value = null
    estado.value = 'autenticada'

    await abrir()

    expect(navegar).toHaveBeenCalledWith('/login', { replace: true })
  })
})

describe('enquanto decide', () => {
  it('apresenta carregamento, e nao uma tela vazia', async () => {
    recuperarSessao.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(Entrada)

    // Sem isto, quem abre a plataforma numa conexao lenta ve uma pagina em
    // branco ate a verificacao de sessao responder (RF-UI-001).
    const carregando = tela.find('[data-estado="carregando"]')

    expect(carregando.exists()).toBe(true)
    expect(carregando.attributes('role')).toBe('status')
    expect(navegar).not.toHaveBeenCalled()
  })
})
