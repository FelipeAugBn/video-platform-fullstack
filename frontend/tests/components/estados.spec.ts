import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { ErroDeApi } from '~/utils/erroDeApi'
import EstadoCarregando from '~/components/ui/EstadoCarregando.vue'
import EstadoVazio from '~/components/ui/EstadoVazio.vue'
import EstadoDeFalha from '~/components/ui/EstadoDeFalha.vue'
import ErroDeCampo from '~/components/ui/ErroDeCampo.vue'
import BotaoDeEnvio from '~/components/ui/BotaoDeEnvio.vue'

/**
 * O vocabulario de estados que todas as telas reutilizam (plan §15.4).
 *
 * A afirmacao mais importante nao e que cada componente renderiza: e que
 * **vazio e falha sao distinguiveis**, por marcacao e por semantica. Duas telas
 * que mostram o mesmo aviso para "voce ainda nao criou nada" e para "nao
 * consegui carregar" ensinam a desconfiar das duas.
 */

function erro(status: number | null, code: string | null, categoria: 'rede' | 'http' = 'http'): ErroDeApi {
  if (categoria === 'rede') {
    return ErroDeApi.de(new Error('Failed to fetch'))
  }

  return ErroDeApi.de({
    response: { status },
    status,
    data: code === null
      ? '<html/>'
      : { type: 'https://x/y', title: 'Falha', status, detail: 'Mensagem publica.', code },
  })
}

describe('carregando', () => {
  it('anuncia a espera de forma acessivel', async () => {
    const tela = await mountSuspended(EstadoCarregando)

    // Sem `aria-busy`, quem usa leitor de tela nao tem como saber que a tela
    // ainda esta buscando dados — e o vazio momentaneo parece resultado.
    expect(tela.find('[data-estado="carregando"]').attributes('aria-busy')).toBe('true')
    expect(tela.find('[data-estado="carregando"]').attributes('role')).toBe('status')
  })
})

describe('vazio e falha sao distintos', () => {
  it('a lista vazia usa tom neutro e papel de status', async () => {
    const tela = await mountSuspended(EstadoVazio, {
      props: { titulo: 'Nenhum curso ainda', descricao: 'Crie o primeiro.' },
    })

    const painel = tela.find('[data-tom]')
    expect(painel.attributes('data-tom')).toBe('neutro')
    expect(painel.attributes('role')).toBe('status')
    expect(tela.text()).toContain('Nenhum curso ainda')
  })

  it('a falha usa tom de erro e papel de alerta', async () => {
    const tela = await mountSuspended(EstadoDeFalha, {
      props: { erro: erro(null, null, 'rede') },
    })

    const painel = tela.find('[data-tom]')
    expect(painel.attributes('data-tom')).toBe('erro')
    // `alert` interrompe para anunciar; `status` espera a pausa. A diferenca e
    // o que separa "algo falhou agora" de "aqui nao ha nada".
    expect(painel.attributes('role')).toBe('alert')
  })
})

describe('a falha escolhe a situacao por status e categoria', () => {
  it.each([
    ['rede sem resposta', erro(null, null, 'rede'), 'rede', true],
    ['5xx e indisponibilidade', erro(503, 'SERVICE_UNAVAILABLE'), 'indisponivel', true],
    ['401 e sessao expirada', erro(401, 'UNAUTHENTICATED'), 'sessaoExpirada', false],
    ['403 e acesso negado', erro(403, 'FORBIDDEN'), 'acessoNegado', false],
    ['404 e acesso negado', erro(404, 'NOT_FOUND'), 'acessoNegado', false],
    ['409 e conflito de regra', erro(409, 'LESSON_VIDEO_NOT_READY'), 'conflito', false],
    ['corpo fora do contrato', erro(400, null), 'inesperado', true],
  ])('%s', async (_caso, falha, situacao, recuperavel) => {
    const tela = await mountSuspended(EstadoDeFalha, { props: { erro: falha } })

    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe(situacao)

    // Oferecer "tentar de novo" num `403` convidaria a insistir no que nunca vai
    // mudar; nao oferecer numa falha de rede deixaria a tela sem saida.
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(recuperavel)
  })

  it('emite o pedido de nova tentativa', async () => {
    const tela = await mountSuspended(EstadoDeFalha, {
      props: { erro: erro(null, null, 'rede') },
    })

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')

    expect(tela.emitted('novaTentativa')).toHaveLength(1)
  })

  it('o conflito distingue-se da negativa por autorizacao', async () => {
    const conflito = await mountSuspended(EstadoDeFalha, {
      props: { erro: erro(409, 'LESSON_NOT_PUBLISHED') },
    })
    const negado = await mountSuspended(EstadoDeFalha, {
      props: { erro: erro(404, 'NOT_FOUND') },
    })

    // RF-UI-015: conteudo indisponivel comunicado de forma distinta da negativa
    // por autorizacao.
    expect(conflito.find('[data-tom]').attributes('data-tom')).toBe('atencao')
    expect(negado.find('[data-tom]').attributes('data-tom')).toBe('erro')
    expect(conflito.find('[data-estado="falha"]').attributes('data-codigo')).toBe('LESSON_NOT_PUBLISHED')
  })
})

describe('erro por campo', () => {
  it('expoe o id para o campo referenciar e alerta a mudanca', async () => {
    const tela = await mountSuspended(ErroDeCampo, {
      props: { id: 'erro-email', mensagens: ['O campo e-mail e obrigatorio.'] },
    })

    const lista = tela.find('#erro-email')
    expect(lista.attributes('role')).toBe('alert')
    expect(lista.text()).toContain('O campo e-mail e obrigatorio.')
  })

  it('nao renderiza nada sem mensagens', async () => {
    const tela = await mountSuspended(ErroDeCampo, {
      props: { id: 'erro-email', mensagens: [] },
    })

    expect(tela.find('#erro-email').exists()).toBe(false)
  })
})

describe('acao em andamento', () => {
  it('desabilita o controle e impede envio repetido', async () => {
    const tela = await mountSuspended(BotaoDeEnvio, {
      props: { pendente: true, rotulo: 'Entrar', rotuloPendente: 'Entrando...' },
    })

    const botao = tela.find('button')

    // A protecao e do proprio elemento: um `<button disabled>` nao dispara
    // `click` nem por teclado, nem por clique repetido.
    expect(botao.attributes('disabled')).toBeDefined()
    expect(botao.attributes('aria-busy')).toBe('true')
    expect(tela.text()).toContain('Entrando...')
  })

  it('fica disponivel quando nao ha acao em voo', async () => {
    const tela = await mountSuspended(BotaoDeEnvio, {
      props: { pendente: false, rotulo: 'Entrar' },
    })

    expect(tela.find('button').attributes('disabled')).toBeUndefined()
    expect(tela.text()).toContain('Entrar')
  })
})
