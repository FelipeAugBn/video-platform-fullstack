import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { ErroDeApi } from '~/utils/erroDeApi'
import Reproducao from '~/pages/catalog/lessons/[id].vue'
import CursoDoCatalogo from '~/pages/catalog/courses/[id].vue'

/**
 * As negativas publicas da jornada do consumidor (RF-PLB-006, RF-PLB-007,
 * RF-PLB-008; RN-PROP-005; RF-UI-013, RF-UI-015; AC-CONS-002, AC-CONS-003,
 * AC-CONS-005).
 *
 * ## A afirmacao central
 *
 * **`403` e `404` produzem a mesma tela.** Nao "telas parecidas": a mesma arvore
 * renderizada, atributo por atributo. O backend responde `404` de forma
 * indistinguivel para recurso inexistente e para recurso existente sem
 * concessao; se a interface dissesse "nao encontrado" num caso e "sem permissao"
 * no outro, ela desfaria no cliente a ocultacao que o servidor construiu na
 * consulta — e devolveria o oraculo de enumeracao que RN-PROP-005 fecha.
 *
 * A comparacao e do HTML **inteiro** da pagina, e nao do texto visivel apenas.
 * Um `data-codigo="NOT_FOUND"` ao lado de um `data-codigo="FORBIDDEN"` nao
 * apareceria num `text()`, e vazaria a distincao para qualquer pessoa com o
 * inspetor aberto.
 *
 * ## E o `409` continua distinto
 *
 * Ele nao e uma negativa de autorizacao: a aula esta dentro de um curso
 * concedido e simplesmente ainda nao ha o que reproduzir. Tratar os dois como um
 * so faria "ainda estou processando seu video" parecer "isto nao e para voce".
 * A escolha da mensagem e por `code`, nunca por `detail`.
 */

const requisitar = vi.hoisted(() => vi.fn())
const parametros = vi.hoisted(() => ({ id: 'l1' }))

mockNuxtImport('useApi', () => () => ({ requisitar }))
mockNuxtImport('useRoute', () => () => ({ params: parametros }))

type Tela = Awaited<ReturnType<typeof mountSuspended>>

const URL_ASSINADA = 'https://storage.exemplo.test/videos/exemplo/original.mp4?assinatura=x'

/**
 * Os corpos que a API realmente devolve nos dois status.
 *
 * Os `detail` sao **diferentes de proposito**, copiados do contrato: e
 * exatamente esse texto que vazaria a distincao se a tela o repassasse. O do
 * `404` chega a dizer, em portugues claro, que o recurso "nao existe".
 */
const CORPOS = {
  403: {
    type: 'https://api.example.test/problems/forbidden',
    title: 'Acesso negado',
    status: 403,
    detail: 'Esta conta nao tem permissao para esta operacao.',
    code: 'FORBIDDEN',
  },
  404: {
    type: 'https://api.example.test/problems/not-found',
    title: 'Recurso nao encontrado',
    status: 404,
    detail: 'O recurso solicitado nao existe ou nao esta disponivel.',
    code: 'NOT_FOUND',
  },
} as const

function problema(status: number, code: string, detail = 'Mensagem publica.'): ErroDeApi {
  return ErroDeApi.de({
    response: { status },
    status,
    data: { type: `https://x/${code}`, title: 'Falha', status, detail, code },
  })
}

function negativaDoContrato(status: 403 | 404): ErroDeApi {
  return ErroDeApi.de({ response: { status }, status, data: CORPOS[status] })
}

async function assentar(): Promise<void> {
  await flushPromises()
  await nextTick()
  await flushPromises()
}

async function telaCom(causa: unknown, pagina: unknown = Reproducao): Promise<Tela> {
  requisitar.mockRejectedValue(causa)

  const tela = await mountSuspended(pagina as never)
  await assentar()

  return tela
}

beforeEach(() => {
  requisitar.mockReset()
  parametros.id = 'l1'
})

describe('403 e 404 sao a mesma negativa', () => {
  it('produzem arvore renderizada identica na reproducao', async () => {
    const negado = await telaCom(negativaDoContrato(403))
    const html403 = negado.html()
    const texto403 = negado.text()

    requisitar.mockReset()

    const ausente = await telaCom(negativaDoContrato(404))

    // Igualdade real, e nao semelhanca. Qualquer ramo por status dentro da
    // negativa — um titulo, uma frase, um atributo — quebra esta assercao.
    expect(ausente.html()).toBe(html403)
    expect(ausente.text()).toBe(texto403)
  })

  it('produzem arvore renderizada identica na arvore do curso', async () => {
    parametros.id = 'c1'

    const negado = await telaCom(negativaDoContrato(403), CursoDoCatalogo)
    const html403 = negado.html()

    requisitar.mockReset()
    parametros.id = 'c1'

    const ausente = await telaCom(negativaDoContrato(404), CursoDoCatalogo)

    expect(ausente.html()).toBe(html403)
  })

  it('nenhum texto da negativa afirma existencia ou inexistencia', async () => {
    for (const status of [403, 404] as const) {
      requisitar.mockReset()

      const tela = await telaCom(negativaDoContrato(status))
      const texto = tela.text().toLowerCase()

      expect(tela.find('[data-estado="acesso-negado"]').exists()).toBe(true)

      // O `detail` da API contem varias destas palavras. Nenhuma chega a tela,
      // porque a descricao publica e escrita aqui e nao vem da resposta.
      for (const vazamento of [
        'existe',
        'inexistente',
        'encontrado',
        'removido',
        'excluido',
        'permissao',
        'nao esta disponivel',
      ]) {
        expect(texto).not.toContain(vazamento)
      }
    }
  })

  it('a negativa nao carrega nada do corpo da resposta', async () => {
    const tela = await telaCom(negativaDoContrato(404))
    const html = tela.html()

    // Nem o codigo, nem o titulo, nem o tipo do problema: um atributo com
    // `NOT_FOUND` seria observavel e a igualdade valeria so para quem enxerga.
    for (const vazamento of ['NOT_FOUND', 'FORBIDDEN', 'not-found', 'forbidden', 'data-codigo']) {
      expect(html).not.toContain(vazamento)
    }
  })

  it('nao oferecem repetir o que devolveria a mesma resposta', async () => {
    for (const status of [403, 404] as const) {
      requisitar.mockReset()

      const tela = await telaCom(negativaDoContrato(status))

      expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
      expect(requisitar).toHaveBeenCalledTimes(1)
    }
  })
})

describe('409 — conteudo conhecido, ainda indisponivel', () => {
  it('e distinto de acesso negado', async () => {
    const tela = await telaCom(problema(409, 'LESSON_NOT_PUBLISHED'))

    expect(tela.find('[data-estado="conteudo-indisponivel"]').exists()).toBe(true)
    expect(tela.find('[data-estado="acesso-negado"]').exists()).toBe(false)

    // Tom de atencao, e nao de erro: aqui nada foi negado a quem pediu.
    expect(tela.find('[data-estado="conteudo-indisponivel"]').attributes('data-tom')).toBe('atencao')
  })

  it('explica a aula ainda nao publicada pelo codigo, e nao pelo detail', async () => {
    // O `detail` e propositalmente inutil: se a tela o exibisse, a assercao
    // seguinte falharia.
    const tela = await telaCom(problema(409, 'LESSON_NOT_PUBLISHED', 'Texto que a API pode reescrever amanha.'))

    expect(tela.find('[data-estado="conteudo-indisponivel"]').attributes('data-codigo')).toBe('LESSON_NOT_PUBLISHED')
    expect(tela.text()).toContain('Esta aula ainda nao foi publicada.')
    expect(tela.text()).not.toContain('Texto que a API pode reescrever amanha.')
  })

  /**
   * `LESSON_VIDEO_NOT_READY` e um codigo **largo**.
   *
   * O backend o emite em tres situacoes que nao se parecem: aula publicada sem
   * nenhuma tentativa de video, tentativa fora de `ready` — `failed` incluida —,
   * e tentativa em `ready` sem referencia de reproducao registrada. A resposta
   * nao traz `video_state`, entao a tela nao tem como saber em qual delas esta.
   *
   * Por isso a mensagem so pode afirmar o que as tres tem em comum: nao ha o que
   * reproduzir agora. Prometer preparo em andamento seria falso em duas das
   * tres, e anunciaria uma espera que nunca termina para um video que falhou ou
   * que nunca foi enviado.
   */
  describe('LESSON_VIDEO_NOT_READY', () => {
    const PROMESSAS_DE_PROCESSAMENTO = [
      'preparad',
      'preparando',
      'process',
      'convert',
      'aguard',
      'em andamento',
      'em breve',
      'em instantes',
      'sera ',
      'ficara',
      'assim que',
      'volte',
      'fila',
      'enviando',
    ]

    async function painel(detail?: string) {
      const tela = await telaCom(problema(409, 'LESSON_VIDEO_NOT_READY', detail))

      return { tela, estado: tela.find('[data-estado="conteudo-indisponivel"]') }
    }

    it('comunica apenas que o video nao esta pronto para reproducao', async () => {
      const { estado } = await painel()

      expect(estado.attributes('data-codigo')).toBe('LESSON_VIDEO_NOT_READY')
      expect(estado.text()).toContain('O video desta aula ainda nao esta pronto para reproducao.')
    })

    it('nao afirma que ha processamento em andamento', async () => {
      const { estado } = await painel()
      const texto = estado.text().toLowerCase()

      // Nenhuma palavra que sugira preparo acontecendo ou desfecho garantido: a
      // tela nao sabe se ha algo em curso, e em dois dos tres casos nao ha.
      for (const promessa of PROMESSAS_DE_PROCESSAMENTO) {
        expect(texto).not.toContain(promessa)
      }
    })

    it('continua independente do detail, inclusive quando ele promete processamento', async () => {
      // O `detail` e texto para humanos e pode ser reescrito sem quebrar
      // contrato. Repassado, ele reintroduziria pela API a promessa que a tela
      // acabou de deixar de fazer.
      const { estado } = await painel('O video desta aula esta sendo processado agora, aguarde.')
      const texto = estado.text().toLowerCase()

      expect(estado.text()).toContain('O video desta aula ainda nao esta pronto para reproducao.')
      expect(texto).not.toContain('processado')
      expect(texto).not.toContain('aguarde')
    })

    it('nao oferece nova tentativa', async () => {
      const { tela } = await painel()

      // Repetir nao muda nada: o desfecho depende do produtor, e nao de
      // insistir. O botao convidaria a tentar de novo indefinidamente.
      expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
      expect(requisitar).toHaveBeenCalledTimes(1)
    })

    it('nao renderiza video nem URL', async () => {
      const { tela } = await painel()

      expect(tela.find('video').exists()).toBe(false)
      expect(tela.find('[data-fonte]').exists()).toBe(false)
      expect(tela.html()).not.toContain('https://storage')
    })
  })

  it('os dois codigos dizem coisas diferentes', async () => {
    const naoPublicada = await telaCom(problema(409, 'LESSON_NOT_PUBLISHED'))
    const textoNaoPublicada = naoPublicada.find('[data-estado="conteudo-indisponivel"]').text()

    requisitar.mockReset()

    const videoNaoPronto = await telaCom(problema(409, 'LESSON_VIDEO_NOT_READY'))

    // "Ainda nao publicaram" e "ainda esta processando" sao situacoes
    // diferentes, e quem espera precisa saber em qual das duas esta.
    expect(videoNaoPronto.find('[data-estado="conteudo-indisponivel"]').text()).not.toBe(textoNaoPublicada)
  })

  it('um codigo desconhecido continua sendo indisponibilidade, sem quebrar', async () => {
    const tela = await telaCom(problema(409, 'CONFLICT'))

    expect(tela.find('[data-estado="conteudo-indisponivel"]').exists()).toBe(true)
    expect(tela.text()).toContain('Este conteudo ainda nao esta disponivel para reproducao.')
  })

  it('um codigo herdado do prototipo nao vira mensagem', async () => {
    // `'toString' in mapa` e verdadeiro. Com `in` no lugar de propriedade
    // propria, a tela exibiria uma funcao herdada de `Object.prototype` como se
    // fosse a explicacao da indisponibilidade.
    const tela = await telaCom(problema(409, 'toString'))

    expect(tela.text()).toContain('Este conteudo ainda nao esta disponivel para reproducao.')
    expect(tela.text()).not.toContain('function')
    expect(tela.text()).not.toContain('native code')
  })

  it('nao oferece repetir de imediato', async () => {
    const tela = await telaCom(problema(409, 'LESSON_VIDEO_NOT_READY'))

    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
    expect(requisitar).toHaveBeenCalledTimes(1)
  })
})

describe('nenhuma negativa entrega conteudo', () => {
  const NEGATIVAS: Array<[string, () => unknown]> = [
    ['403', () => negativaDoContrato(403)],
    ['404', () => negativaDoContrato(404)],
    ['409 nao publicada', () => problema(409, 'LESSON_NOT_PUBLISHED')],
    ['409 video nao pronto', () => problema(409, 'LESSON_VIDEO_NOT_READY')],
    ['401', () => problema(401, 'UNAUTHENTICATED')],
    ['rede', () => ErroDeApi.de(new Error('Failed to fetch'))],
    ['500', () => ErroDeApi.de({ response: { status: 500 }, status: 500, data: null })],
  ]

  it.each(NEGATIVAS)('%s nao renderiza elemento reproduzivel nem URL', async (_nome, causa) => {
    const tela = await telaCom(causa())

    expect(tela.find('video').exists()).toBe(false)
    expect(tela.find('[data-fonte]').exists()).toBe(false)
    expect(tela.find('[data-reproducao]').exists()).toBe(false)

    const html = tela.html()

    expect(html).not.toContain(URL_ASSINADA)
    expect(html).not.toContain('https://storage')
  })

})

describe('401, rede e 5xx', () => {
  it('401 aparece como sessao expirada, e nao como acesso negado', async () => {
    const tela = await telaCom(problema(401, 'UNAUTHENTICATED'))

    // RF-UI-014: quem foi desviado precisa saber que **tinha** acesso. Chamar
    // isso de acesso negado anunciaria uma perda de permissao que nao houve.
    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('sessaoExpirada')
    expect(tela.find('[data-estado="acesso-negado"]').exists()).toBe(false)
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(false)
  })

  it('rede permite repetir, e repete apenas o GET', async () => {
    requisitar
      .mockRejectedValueOnce(ErroDeApi.de(new Error('Failed to fetch')))
      .mockResolvedValueOnce({
        data: { playback_url: URL_ASSINADA, expires_at: '2026-09-06T12:05:00+00:00', content_type: 'video/mp4' },
      })

    const tela = await mountSuspended(Reproducao)
    await assentar()

    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('rede')

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()

    expect(tela.find('video').exists()).toBe(true)
    expect(requisitar.mock.calls).toHaveLength(2)

    // Duas leituras do mesmo endereco, sem metodo e sem corpo: nada foi criado
    // nem alterado para tentar de novo.
    for (const [caminho, opcoes] of requisitar.mock.calls) {
      expect(caminho).toBe('/api/lessons/l1/playback')
      expect(opcoes).toBeUndefined()
    }
  })

  it('5xx e indisponibilidade repetivel, distinta de acesso negado', async () => {
    const tela = await telaCom(ErroDeApi.de({ response: { status: 503 }, status: 503, data: null }))

    expect(tela.find('[data-estado="falha"]').attributes('data-situacao')).toBe('indisponivel')
    expect(tela.find('[data-estado="acesso-negado"]').exists()).toBe(false)
    expect(tela.find('[data-acao="nova-tentativa"]').exists()).toBe(true)
  })

  it('leva o foco para a negativa quando a nova tentativa falha de novo', async () => {
    requisitar.mockRejectedValue(ErroDeApi.de(new Error('Failed to fetch')))

    const tela = await mountSuspended(Reproducao, { attachTo: document.body })
    await assentar()

    await tela.find('[data-acao="nova-tentativa"]').trigger('click')
    await assentar()
    await nextTick()

    // O botao clicado deixa de existir enquanto a leitura corre; sem tratar o
    // foco, quem navega por teclado cairia no corpo do documento sem qualquer
    // sinal de que a tentativa terminou.
    expect(document.activeElement).toBe(tela.find('[data-regiao="negativa"]').element)
  })
})
