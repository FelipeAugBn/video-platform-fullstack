import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { Aula, EstadoDoVideo } from '~/types/video'
import type { ReproducaoEnvelope } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'
import ConferenciaDoVideo from '~/components/video/ConferenciaDoVideo.vue'
import ItemDeAula from '~/components/lesson/ItemDeAula.vue'

/**
 * A conferencia do proprio video pelo produtor (RF-PLB-009, AC-PROD-008).
 *
 * Tres afirmacoes organizam o arquivo, e as tres sao sobre **quando**:
 *
 *   a acao so existe com o video em `ready`, e nao depende de publicacao — a
 *   aula em rascunho e o caso que motiva tudo isto;
 *
 *   nenhuma URL e pedida antes do clique. A URL assinada e uma credencial de
 *   cinco minutos, e emitir uma por aula pronta ao abrir a arvore gastaria uma
 *   leva delas que ninguem pediu;
 *
 *   sair de `ready` descarta o que havia. Player e erro descrevem um video que
 *   deixou de ser o atual.
 *
 * A autorizacao continua no backend, que reavalia propriedade e disponibilidade
 * a cada chamada. O que se prova aqui e a conveniencia visual, e nada alem
 * disso.
 */

const requisitar = vi.hoisted(() => vi.fn())

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: { id: 'c1' } }))

const ENDERECO = '/api/lessons/a1/video/playback'

const REPRODUCAO = {
  data: {
    playback_url: 'https://armazenamento.test/videos/gravado.mp4?assinatura=REDIGIDO',
    expires_at: '2026-09-06T12:05:00+00:00',
    content_type: 'video/mp4',
  },
}

const REPRODUCAO_NOVA = {
  data: {
    playback_url: 'https://armazenamento.test/videos/gravado.mp4?assinatura=OUTRA-REDIGIDA',
    expires_at: '2026-09-06T12:20:00+00:00',
    content_type: 'video/mp4',
  },
}

/**
 * Uma promessa cuja conclusao o teste decide, e nao o relogio.
 *
 * E o que permite encenar a corrida real: a solicitacao sai, o estado do video
 * muda, e **so entao** a resposta antiga chega. Com `mockResolvedValue` a
 * resposta chegaria antes de o teste conseguir mudar o estado, e a corrida que
 * importa nunca aconteceria.
 */
function promessaControlada<T>() {
  let resolver!: (valor: T) => void
  let rejeitar!: (causa: unknown) => void

  const promessa = new Promise<T>((res, rej) => {
    resolver = res
    rejeitar = rej
  })

  return { promessa, resolver, rejeitar }
}

function aula(extras: Partial<Aula> = {}): Aula {
  return {
    id: 'a1',
    module_id: 'm1',
    title: 'Primeiros passos',
    position: 1,
    published_at: null,
    video_state: 'ready',
    ...extras,
  }
}

function problema(status: number, code: string, detail: string): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: 'https://x/y', title: 'Falha', status, detail, code },
  })
}

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

beforeEach(() => {
  requisitar.mockReset()
})

describe('quando a acao aparece', () => {
  const OCULTOS: Array<EstadoDoVideo | null> = [null, 'pending', 'uploading', 'uploaded', 'processing', 'failed']

  it.each(OCULTOS)('nao oferece visualizar com o video em %s', async (estado) => {
    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: estado },
    })

    // Ausente, e nao desabilitada: nao ha video pronto para assistir.
    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(false)
    expect(tela.find('[data-conferencia]').exists()).toBe(false)
  })

  it('oferece visualizar com o video pronto numa aula em rascunho', async () => {
    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    // A aula em rascunho e o caso que motiva a operacao: conferir o conteudo
    // faz parte de decidir se ele deve ser publicado.
    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(true)
  })

  it('continua oferecendo visualizar depois de a aula ser publicada', async () => {
    const tela = await mountSuspended(ItemDeAula, {
      props: { aula: aula({ published_at: '2026-09-06T10:00:00+00:00' }) },
    })
    await assentar()

    // A publicacao nao participa da decisao — nem para liberar, nem para
    // bloquear.
    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(true)
  })

  it('nao pede URL nenhuma antes do clique', async () => {
    const tela = await mountSuspended(ItemDeAula, { props: { aula: aula() } })
    await assentar()

    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(true)

    // Nem a conferencia, nem o painel: com o video em `ready` nao ha o que
    // perguntar, e a URL assinada so nasce quando alguem a pede.
    expect(requisitar).not.toHaveBeenCalled()
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)
  })
})

describe('a solicitacao', () => {
  it('pede a reproducao do produtor e mostra o player no mesmo item da aula', async () => {
    requisitar.mockResolvedValue(REPRODUCAO)

    const tela = await mountSuspended(ItemDeAula, { props: { aula: aula() } })
    await assentar()

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await assentar()

    // O endereco do produtor, e nao o do consumidor: as duas rotas existem
    // separadas justamente porque as regras sao outras.
    expect(requisitar).toHaveBeenCalledTimes(1)
    expect(requisitar).toHaveBeenCalledWith(ENDERECO)

    const item = tela.find('[data-aula-id="a1"]')
    expect(item.find('[data-reprodutor]').exists()).toBe(true)
    expect(item.find('[data-fonte]').attributes('src')).toBe(REPRODUCAO.data.playback_url)
    expect(item.find('[data-fonte]').attributes('type')).toBe('video/mp4')

    // A URL fica no atributo porque o video precisa dela para tocar; oferece-la
    // como texto ou link seria transformar a limitacao assumida em convite.
    expect(item.text()).not.toContain(REPRODUCAO.data.playback_url)
  })

  it('desabilita o controle e informa andamento enquanto pede', async () => {
    requisitar.mockReturnValue(new Promise(() => {}))

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await flushPromises()

    const botao = tela.find('[data-acao="visualizar-video"]')
    expect(botao.attributes('disabled')).toBeDefined()
    expect(botao.attributes('aria-busy')).toBe('true')
    expect(botao.text()).toContain('Preparando')

    // O clique num botao desabilitado nao dispara, mas a guarda no manipulador e
    // o que cobre o acionamento por teclado sobre o controle focado.
    await botao.trigger('click')
    await flushPromises()

    expect(requisitar).toHaveBeenCalledTimes(1)
  })
})

describe('quando a solicitacao falha', () => {
  it('mostra a negativa da API e repete pedindo uma URL nova', async () => {
    requisitar
      .mockRejectedValueOnce(problema(503, 'SERVICE_UNAVAILABLE', 'Tente novamente em alguns instantes.'))
      .mockResolvedValueOnce(REPRODUCAO)

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await assentar()

    const falha = tela.find('[data-estado="falha"]')
    expect(falha.exists()).toBe(true)
    expect(falha.attributes('data-codigo')).toBe('SERVICE_UNAVAILABLE')
    expect(falha.text()).toContain('Tente novamente em alguns instantes.')
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    // Uma segunda chamada ao mesmo endereco: nao ha URL a reaproveitar — a que
    // falhou nao existe, e a permissao e curta de proposito.
    expect(requisitar).toHaveBeenCalledTimes(2)
    expect(requisitar.mock.calls.every(([caminho]) => caminho === ENDERECO)).toBe(true)

    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-reprodutor]').exists()).toBe(true)
  })
})

describe('quando o estado do video deixa de ser ready', () => {
  it('descarta o player', async () => {
    requisitar.mockResolvedValue(REPRODUCAO)

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await assentar()
    expect(tela.find('[data-reprodutor]').exists()).toBe(true)

    await tela.setProps({ estadoDoVideo: 'failed' })
    await assentar()

    // Um `<video>` tocando sob um estado que ja mudou mostraria duas verdades ao
    // mesmo tempo.
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)
    expect(tela.find('[data-conferencia]').exists()).toBe(false)
  })

  it('descarta a negativa anterior', async () => {
    requisitar.mockRejectedValue(problema(409, 'LESSON_VIDEO_NOT_READY', 'O video desta aula ainda nao esta pronto para reproducao.'))

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await assentar()
    expect(tela.find('[data-estado="falha"]').exists()).toBe(true)

    await tela.setProps({ estadoDoVideo: 'processing' })
    await assentar()
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)

    // E ao voltar a `ready` a secao recomeca do zero, sem herdar a negativa de
    // um video que ja nao e o mesmo.
    await tela.setProps({ estadoDoVideo: 'ready' })
    await assentar()

    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(true)
  })
})

describe('respostas que chegam depois de o estado mudar', () => {
  /*
  | O bloco anterior prova o descarte do que **ja estava assentado** na tela. Este
  | prova o caso mais dificil: a solicitacao que ainda estava a caminho quando o
  | estado mudou. Ela nao some sozinha — vai resolver ou falhar de qualquer
  | forma —, e sem invalidacao escreveria por cima de um estado que ja tinha sido
  | limpo.
  */

  it('descarta o sucesso que chega depois de o video sair de ready', async () => {
    const antiga = promessaControlada<ReproducaoEnvelope>()
    const nova = promessaControlada<ReproducaoEnvelope>()

    requisitar
      .mockReturnValueOnce(antiga.promessa)
      .mockReturnValueOnce(nova.promessa)

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await flushPromises()

    // O video muda **antes** de a resposta chegar.
    await tela.setProps({ estadoDoVideo: 'processing' })
    await assentar()

    antiga.resolver(REPRODUCAO as ReproducaoEnvelope)
    await assentar()

    await tela.setProps({ estadoDoVideo: 'ready' })
    await assentar()

    // Nada da solicitacao vencida sobreviveu.
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)

    // E o botao voltou utilizavel: sem a invalidacao, `carregando` teria ficado
    // preso e ele apareceria travado em "Preparando a reproducao...".
    const botao = tela.find('[data-acao="visualizar-video"]')
    expect(botao.exists()).toBe(true)
    expect(botao.attributes('disabled')).toBeUndefined()
    expect(botao.attributes('aria-busy')).not.toBe('true')
    expect(botao.text()).toContain('Visualizar video')

    // Uma solicitacao nova comeca, e e a dela que aparece.
    await botao.trigger('click')
    await flushPromises()
    expect(requisitar).toHaveBeenCalledTimes(2)

    nova.resolver(REPRODUCAO_NOVA as ReproducaoEnvelope)
    await assentar()

    expect(tela.find('[data-reprodutor]').exists()).toBe(true)
    expect(tela.find('[data-fonte]').attributes('src')).toBe(REPRODUCAO_NOVA.data.playback_url)
  })

  it('descarta a falha que chega depois de o video sair de ready', async () => {
    const antiga = promessaControlada<ReproducaoEnvelope>()
    const nova = promessaControlada<ReproducaoEnvelope>()

    requisitar
      .mockReturnValueOnce(antiga.promessa)
      .mockReturnValueOnce(nova.promessa)

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await flushPromises()

    await tela.setProps({ estadoDoVideo: 'processing' })
    await assentar()

    antiga.rejeitar(problema(503, 'SERVICE_UNAVAILABLE', 'Tente novamente em alguns instantes.'))
    await assentar()

    await tela.setProps({ estadoDoVideo: 'ready' })
    await assentar()

    // A negativa vencida nao reaparece: ela descrevia uma tentativa de assistir a
    // um video que ja nao era o atual.
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)

    const botao = tela.find('[data-acao="visualizar-video"]')
    expect(botao.exists()).toBe(true)
    expect(botao.attributes('disabled')).toBeUndefined()
    expect(botao.text()).toContain('Visualizar video')

    await botao.trigger('click')
    await flushPromises()
    expect(requisitar).toHaveBeenCalledTimes(2)

    nova.resolver(REPRODUCAO_NOVA as ReproducaoEnvelope)
    await assentar()

    expect(tela.find('[data-reprodutor]').exists()).toBe(true)
    expect(tela.find('[data-estado="falha"]').exists()).toBe(false)
  })

  it('descarta o que estava em voo quando a aula muda', async () => {
    const daPrimeira = promessaControlada<ReproducaoEnvelope>()

    requisitar.mockReturnValueOnce(daPrimeira.promessa)

    const tela = await mountSuspended(ConferenciaDoVideo, {
      props: { aulaId: 'a1', estadoDoVideo: 'ready' },
    })

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await flushPromises()

    // O componente passa a descrever outra aula, sem sair de `ready`.
    await tela.setProps({ aulaId: 'a2' })
    await assentar()

    daPrimeira.resolver(REPRODUCAO as ReproducaoEnvelope)
    await assentar()

    // Exibir aqui o video da aula anterior seria mostrar o conteudo errado sob o
    // titulo certo.
    expect(tela.find('[data-reprodutor]').exists()).toBe(false)

    const botao = tela.find('[data-acao="visualizar-video"]')
    expect(botao.exists()).toBe(true)
    expect(botao.attributes('disabled')).toBeUndefined()
  })
})

describe('convivencia com a publicacao', () => {
  it('oferece as duas acoes, e assistir nao consome a publicacao', async () => {
    requisitar.mockResolvedValue(REPRODUCAO)

    const tela = await mountSuspended(ItemDeAula, { props: { aula: aula() } })
    await assentar()

    expect(tela.find('[data-acao="visualizar-video"]').exists()).toBe(true)
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(true)

    await tela.find('[data-acao="visualizar-video"]').trigger('click')
    await assentar()

    // Assistir e uma leitura: nao publica, nao muda o estado do video e nao tira
    // da tela a acao que publica (RF-AUL-006).
    expect(tela.find('[data-reprodutor]').exists()).toBe(true)
    expect(tela.find('[data-acao="publicar"]').exists()).toBe(true)
    expect(tela.find('[data-publicacao="rascunho"]').exists()).toBe(true)
    expect(requisitar.mock.calls.every(([caminho]) => caminho === ENDERECO)).toBe(true)
  })
})
