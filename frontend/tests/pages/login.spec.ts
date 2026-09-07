import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { ErroDeApi } from '~/utils/erroDeApi'
import Login from '~/pages/login.vue'

const entrar = vi.hoisted(() => vi.fn())
const retorno = vi.hoisted(() => ({ valor: undefined as unknown }))

mockNuxtImport('useRoute', () => () => ({ query: { redirect: retorno.valor } }))

/**
 * A tela de login — o primeiro formulario completo (RF-UI-008, 009, 014;
 * AC-UI-001, AC-UI-003).
 *
 * `useAuth` e substituido de proposito: o que se testa aqui e o comportamento do
 * formulario diante de cada desfecho, e nao a autenticacao em si — essa ja tem
 * teste proprio. A substituicao tambem prova que a pagina nao fala HTTP
 * diretamente: se falasse, o dublê nao seria exercitado.
 */

const PRODUTOR = { id: 'u1', name: 'Produtora', email: 'p@video.test', role: 'producer' } as const

function erroDeApi(status: number, code: string, extras: Record<string, unknown> = {}): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem publica.', code, ...extras },
  })
}

function comAuth(estadoInicial: 'anonima' | 'expirada' = 'anonima') {
  const { usuario, estado } = useSessionState()
  usuario.value = null
  estado.value = estadoInicial
}

mockNuxtImport('useAuth', () => () => {
  const { usuario, estado } = useSessionState()

  return {
    entrar,
    usuario,
    estado,
    autenticado: computed(() => estado.value === 'autenticada'),
    carregando: computed(() => estado.value === 'verificando'),
    expirada: computed(() => estado.value === 'expirada'),
    verificada: computed(() => estado.value !== 'naoVerificada'),
    recuperarSessao: vi.fn(),
    sair: vi.fn(),
    destinoDoPerfil: () => '/producer/courses',
  }
})

async function preencher(tela: Awaited<ReturnType<typeof mountSuspended>>, email: string, senha: string) {
  await tela.find('#campo-email').setValue(email)
  await tela.find('#campo-senha').setValue(senha)
}

beforeEach(() => {
  entrar.mockReset()
  retorno.valor = undefined
  comAuth()
})

describe('acessibilidade do formulario', () => {
  it('associa rotulos aos campos e declara o preenchimento automatico', async () => {
    const tela = await mountSuspended(Login)

    // `for` e `id` casando e o que faz o leitor de tela anunciar o rotulo ao
    // chegar no campo, e o que faz o clique no texto focar o controle.
    expect(tela.find('label[for="campo-email"]').exists()).toBe(true)
    expect(tela.find('label[for="campo-senha"]').exists()).toBe(true)
    expect(tela.find('#campo-email').attributes('autocomplete')).toBe('email')
    expect(tela.find('#campo-senha').attributes('autocomplete')).toBe('current-password')
    expect(tela.find('#campo-senha').attributes('type')).toBe('password')
  })

  it('liga a mensagem de erro ao campo por aria-describedby', async () => {
    entrar.mockRejectedValue(erroDeApi(422, 'VALIDATION_FAILED', {
      errors: { email: ['O campo e-mail e obrigatorio.'] },
    }))

    const tela = await mountSuspended(Login)
    await tela.find('form').trigger('submit')
    await flushPromises()

    const campo = tela.find('#campo-email')
    expect(campo.attributes('aria-invalid')).toBe('true')
    expect(campo.attributes('aria-describedby')).toBe('erro-email')
    expect(tela.find('#erro-email').text()).toContain('O campo e-mail e obrigatorio.')
  })
})

describe('sucesso', () => {
  it('autentica o produtor e confirma antes do encaminhamento', async () => {
    entrar.mockImplementation(async () => {
      const { estado } = useSessionState()
      estado.value = 'autenticada'

      return PRODUTOR
    })

    const tela = await mountSuspended(Login)
    await preencher(tela, 'p@video.test', 'segredo')
    await tela.find('form').trigger('submit')
    await flushPromises()

    expect(entrar).toHaveBeenCalledWith({ email: 'p@video.test', password: 'segredo' }, undefined)
    expect(tela.find('[data-estado="sucesso"]').exists()).toBe(true)
  })

  it('repassa o retorno pedido na URL', async () => {
    retorno.valor = '/producer/courses/abc'
    entrar.mockResolvedValue(PRODUTOR)

    const tela = await mountSuspended(Login)
    await preencher(tela, 'p@video.test', 'segredo')
    await tela.find('form').trigger('submit')

    // A pagina apenas repassa; quem decide se o destino e seguro e `useAuth`.
    expect(entrar).toHaveBeenCalledWith(expect.anything(), '/producer/courses/abc')
  })
})

describe('validacao e credencial recusada', () => {
  it('exibe a mensagem da API junto do campo e preserva o digitado', async () => {
    entrar.mockRejectedValue(erroDeApi(422, 'VALIDATION_FAILED', {
      errors: { email: ['As credenciais informadas nao conferem.'] },
    }))

    const tela = await mountSuspended(Login)
    await preencher(tela, 'p@video.test', 'senha-errada')
    await tela.find('form').trigger('submit')
    await flushPromises()

    expect(tela.find('#erro-email').text()).toContain('As credenciais informadas nao conferem.')

    // Apagar o que foi digitado obrigaria a redigitar tudo por causa de um erro
    // num campo so (AC-UI-001).
    expect((tela.find('#campo-email').element as HTMLInputElement).value).toBe('p@video.test')
    expect((tela.find('#campo-senha').element as HTMLInputElement).value).toBe('senha-errada')
  })

  it('nao revela se o e-mail existe', async () => {
    entrar.mockRejectedValue(erroDeApi(422, 'VALIDATION_FAILED', {
      errors: { email: ['As credenciais informadas nao conferem.'] },
    }))

    const tela = await mountSuspended(Login)
    await preencher(tela, 'inexistente@video.test', 'x')
    await tela.find('form').trigger('submit')
    await flushPromises()

    const texto = tela.text().toLowerCase()

    // A mesma resposta para usuario inexistente e senha errada e o que impede a
    // tela de virar um verificador de cadastro (RF-AUT-008).
    for (const vazamento of ['nao encontrado', 'nao existe', 'nao cadastrado', 'senha incorreta']) {
      expect(texto).not.toContain(vazamento)
    }
  })

  it('leva o foco para o primeiro campo recusado', async () => {
    entrar.mockRejectedValue(erroDeApi(422, 'VALIDATION_FAILED', {
      errors: { password: ['O campo senha e obrigatorio.'] },
    }))

    const tela = await mountSuspended(Login, { attachTo: document.body })
    await tela.find('form').trigger('submit')
    await flushPromises()
    await nextTick()

    expect(document.activeElement?.id).toBe('campo-senha')
  })
})

describe('falhas sem campo', () => {
  it('mostra a indisponibilidade num resumo com nova tentativa', async () => {
    entrar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await mountSuspended(Login)
    await preencher(tela, 'p@video.test', 'segredo')
    await tela.find('form').trigger('submit')
    await flushPromises()

    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('rede')
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(true)

    // A tela nao pode ficar carregando para sempre depois de uma falha de rede.
    expect(tela.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('sessao expirada aparece distinta de acesso negado', async () => {
    comAuth('expirada')

    const tela = await mountSuspended(Login)

    // RF-UI-014 e AC-UI-003: quem foi desviado para ca precisa saber que
    // **tinha** acesso, e nao que ele foi negado.
    expect(tela.find('[data-estado="sessao-expirada"]').exists()).toBe(true)
    expect(tela.text()).toContain('Sua sessao expirou')
  })
})

describe('acao em andamento', () => {
  it('desabilita o botao e impede a segunda submissao', async () => {
    let liberar: (valor: unknown) => void = () => {}
    entrar.mockImplementation(() => new Promise((resolver) => {
      liberar = resolver
    }))

    const tela = await mountSuspended(Login)
    await preencher(tela, 'p@video.test', 'segredo')

    await tela.find('form').trigger('submit')
    await flushPromises()

    expect(tela.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    // Submissao por `Enter` nao passa pelo estado do botao; a guarda no
    // manipulador e o que impede o envio duplicado por essa via.
    await tela.find('form').trigger('submit')
    expect(entrar).toHaveBeenCalledTimes(1)

    liberar(PRODUTOR)
  })
})
