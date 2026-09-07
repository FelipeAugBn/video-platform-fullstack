import type {
  CategoriaDeFalha,
  CodigoDeFalha,
  ErrosDeValidacao,
  Problema,
} from '~/types/problema'

/**
 * Um valor vindo da rede, reduzido a objeto — ou `null` se nao for um.
 *
 * Toda leitura de resposta comeca aqui. O que chega da rede e `unknown` ate ser
 * verificado: tratar como objeto o que talvez seja `null`, texto ou HTML de uma
 * pagina de erro de proxy e como um `500` inesperado vira um `TypeError` no meio
 * da tela.
 */
function comoObjeto(valor: unknown): Record<string, unknown> | null {
  return typeof valor === 'object' && valor !== null && !Array.isArray(valor)
    ? (valor as Record<string, unknown>)
    : null
}

function comoTexto(valor: unknown): string | null {
  return typeof valor === 'string' ? valor : null
}

function comoNumero(valor: unknown): number | null {
  return typeof valor === 'number' && Number.isFinite(valor) ? valor : null
}

/**
 * O corpo tem a forma que o contrato promete para `application/problem+json`?
 *
 * Os cinco campos obrigatorios da RFC 9457, conferidos um a um. Sem esta
 * verificacao, uma resposta de um intermediario — um proxy devolvendo HTML, uma
 * pagina de manutencao — seria lida como problema, e a interface escolheria o
 * que mostrar a partir de um `status` que nao existe.
 */
function ehProblema(valor: unknown): valor is Problema {
  const corpo = comoObjeto(valor)

  return corpo !== null
    && typeof corpo.type === 'string'
    && typeof corpo.title === 'string'
    && typeof corpo.status === 'number'
    && typeof corpo.detail === 'string'
    && typeof corpo.code === 'string'
}

/**
 * Os erros por campo do `422`, com as mensagens que a API escreveu.
 *
 * Cada chave precisa mapear para uma lista de textos; qualquer campo fora desse
 * formato e descartado em vez de repassado, porque ele iria direto para a tela.
 */
function lerErros(corpo: Record<string, unknown>): ErrosDeValidacao | null {
  const bruto = comoObjeto(corpo.errors)

  if (bruto === null) {
    return null
  }

  const erros: ErrosDeValidacao = {}

  for (const [campo, mensagens] of Object.entries(bruto)) {
    if (Array.isArray(mensagens)) {
      const textos = mensagens.filter((m): m is string => typeof m === 'string')

      if (textos.length > 0) {
        erros[campo] = textos
      }
    }
  }

  return Object.keys(erros).length > 0 ? erros : null
}

/**
 * A falha de uma chamada a API, no vocabulario da interface.
 *
 * Existe para que nenhuma tela precise inspecionar a excecao bruta do cliente
 * HTTP. O que ela expoe e o que o contrato promete — `status`, `code`, `errors`
 * e os membros de extensao —, mais a categoria que diz **que tipo** de tela
 * mostrar.
 *
 * ## Decida por `status` e `code`, nunca por `detail`
 *
 * `detail` e texto escrito para humanos e pode ser reescrito a qualquer momento
 * sem quebrar o contrato. `code` e o identificador estavel. Uma interface que
 * comparasse mensagens quebraria na primeira correcao de portugues do backend.
 *
 * ## Membros de extensao sao preservados
 *
 * `errors` acompanha o `422` e alimenta as mensagens por campo; `video_state`
 * acompanha o `409` de publicacao e de novo envio, e evita uma segunda
 * requisicao so para descobrir o que ja veio junto do erro. Descarta-los aqui
 * obrigaria cada tela a reabrir a resposta bruta.
 */
export class ErroDeApi extends Error {
  readonly categoria: CategoriaDeFalha

  readonly status: number | null

  readonly code: CodigoDeFalha | null

  readonly problema: Problema | null

  readonly errors: ErrosDeValidacao | null

  readonly videoState: string | null

  private constructor(dados: {
    categoria: CategoriaDeFalha
    status: number | null
    problema: Problema | null
    errors: ErrosDeValidacao | null
    videoState: string | null
    causa: unknown
  }) {
    // A mensagem serve ao log e a depuracao, nunca a tela: o que a interface
    // exibe vem de `code`, ou de `detail` quando ela decide mostrar o texto da
    // API. Nada de rastro de execucao chega aqui.
    super(dados.problema?.title ?? `Falha de comunicacao com a API (${dados.categoria}).`, {
      cause: dados.causa,
    })

    this.name = 'ErroDeApi'
    this.categoria = dados.categoria
    this.status = dados.status
    this.problema = dados.problema
    this.code = (dados.problema?.code ?? null) as CodigoDeFalha | null
    this.errors = dados.errors
    this.videoState = dados.videoState
  }

  /**
   * Traduz qualquer coisa lancada pelo cliente HTTP.
   *
   * A ordem das perguntas e a propria classificacao:
   *
   *   1. **sem status** — nao houve resposta. A requisicao nao chegou ao
   *      servidor, ou a conexao caiu antes de ele responder (RF-UI-012).
   *   2. **`5xx`** — houve resposta, e ela e defeito de quem responde. Para quem
   *      usa, e o mesmo que a API estar fora (RF-UI-011). O corpo continua sendo
   *      lido quando valido: o `503` da conclusao de envio traz um `code` que a
   *      tela aproveita.
   *   3. **corpo valido** — problema declarado pelo contrato. E o unico caso em
   *      que `status` e `code` podem ser usados para decidir.
   *   4. **o resto** — houve resposta e ela nao e nada do que o contrato promete.
   */
  static de(causa: unknown): ErroDeApi {
    if (causa instanceof ErroDeApi) {
      return causa
    }

    const bruto = comoObjeto(causa)
    const resposta = bruto === null ? null : comoObjeto(bruto.response)

    const status = resposta === null
      ? (bruto === null ? null : comoNumero(bruto.status) ?? comoNumero(bruto.statusCode))
      : comoNumero(resposta.status)

    if (status === null) {
      return new ErroDeApi({
        categoria: 'rede',
        status: null,
        problema: null,
        errors: null,
        videoState: null,
        causa,
      })
    }

    const corpo = bruto === null ? undefined : bruto.data
    const problema = ehProblema(corpo) ? corpo : null
    const objeto = comoObjeto(corpo)

    return new ErroDeApi({
      categoria: status >= 500
        ? 'indisponivel'
        : problema !== null ? 'problema' : 'inesperado',
      status,
      problema,
      errors: objeto === null ? null : lerErros(objeto),
      videoState: objeto === null ? null : comoTexto(objeto.video_state),
      causa,
    })
  }

  /**
   * O texto que a interface pode exibir quando nao tem mensagem propria.
   *
   * Vem da API quando ela respondeu algo valido; nos demais casos e uma frase
   * fixa do cliente, porque nao ha o que citar — e citar a excecao original
   * colocaria rastro de execucao na tela.
   */
  get mensagem(): string {
    if (this.problema !== null) {
      return this.problema.detail
    }

    return this.categoria === 'rede'
      ? 'Nao foi possivel falar com o servidor. Verifique a conexao e tente de novo.'
      : 'O servico esta indisponivel no momento. Tente de novo em alguns instantes.'
  }
}
