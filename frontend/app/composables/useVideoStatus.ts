import type { EstadoDoVideo, TentativaDeVideo, TentativaOuNuloEnvelope } from '~/types/video'
import { ErroDeApi } from '~/utils/erroDeApi'

const INTERVALO = 3000

/**
 * Os estados do video e o que cada um significa para o acompanhamento.
 *
 * `ready` e `failed` sao terminais: consultar depois deles gastaria requisicao
 * para receber sempre a mesma resposta.
 *
 * E um `Record` completo de proposito. Uma lista solta compilaria com um estado
 * faltando e o esquecido cairia silenciosamente em "terminal" — o video pararia
 * de ser acompanhado sem ninguem perceber. Aqui, o contrato ganhar um estado
 * novo quebra a compilacao.
 */
const ESTADOS_DO_VIDEO = {
  pending: 'transitorio',
  uploading: 'transitorio',
  uploaded: 'transitorio',
  processing: 'transitorio',
  ready: 'terminal',
  failed: 'terminal',
} as const satisfies Record<EstadoDoVideo, 'transitorio' | 'terminal'>

/**
 * O estado ainda pode mudar sozinho?
 *
 * Exportada porque quem reconcilia o estado por outro caminho — a recusa de uma
 * publicacao, por exemplo — precisa da mesma resposta, e duas listas separadas
 * divergiriam.
 */
export function estadoTransitorio(estado: EstadoDoVideo | null): boolean {
  return estado !== null && ESTADOS_DO_VIDEO[estado] === 'transitorio'
}

/**
 * Um `video_state` que veio como texto solto, reduzido ao estado do contrato.
 *
 * Membro de extensao de um corpo de problema chega como `string`: aceita-lo sem
 * conferir colocaria na tela um estado que nao existe, e o mapa acima
 * responderia `undefined` para ele.
 */
export function comoEstadoDoVideo(valor: string | null): EstadoDoVideo | null {
  // `Object.hasOwn`, e nao `in`: `in` tambem enxerga o que vem do prototipo, e
  // um corpo de problema com `video_state: "constructor"` — ou `"toString"`, ou
  // `"__proto__"` — passaria por estado valido e viraria uma chave de leitura no
  // mapa acima. A pergunta certa e se **este** objeto declara a chave.
  return valor !== null && Object.hasOwn(ESTADOS_DO_VIDEO, valor) ? valor as EstadoDoVideo : null
}

/**
 * O acompanhamento do processamento, por consulta em intervalos (plan §15.2).
 *
 * ## Por que consulta, e nao conexao aberta
 *
 * O dado muda pouquissimas vezes por video — normalmente duas. Um WebSocket ou
 * SSE exigiria infraestrutura persistente e um segundo canal para manter, e o
 * ganho seria de segundos numa espera que ja e de minutos.
 *
 * ## `setTimeout` encadeado, nunca `setInterval`
 *
 * O proximo ciclo so e agendado **depois** que a resposta chega. Um intervalo
 * fixo dispararia a consulta seguinte enquanto a anterior ainda estivesse em
 * voo, e numa rede lenta as consultas se empilhariam ate a tela receber
 * respostas fora de ordem — a mais antiga sobrescrevendo a mais nova.
 *
 * ## Tres coisas que nao podem escrever aqui
 *
 * Uma resposta posterior ao desmonte, uma resposta de uma tentativa que ja foi
 * substituida por um envio novo, e uma falha que ficou para tras. As tres sao
 * barradas pela mesma geracao monotonica: quem saiu antes da virada nao escreve
 * depois dela.
 */
export function useVideoStatus(aulaId: string) {
  const { requisitar } = useApi()

  const tentativa = ref<TentativaDeVideo | null>(null)
  const erro = ref<ErroDeApi | null>(null)
  const consultando = ref(false)

  let temporizador: ReturnType<typeof setTimeout> | null = null
  let geracao = 0
  let ativo = true

  const estado = computed<EstadoDoVideo | null>(() => tentativa.value?.state ?? null)
  const transitorio = computed(() => estadoTransitorio(estado.value))

  /** A mensagem publica que a API escreveu — nunca um texto calculado aqui. */
  const mensagemDaFalha = computed(() => tentativa.value?.failure_message ?? null)

  function parar(): void {
    if (temporizador !== null) {
      clearTimeout(temporizador)
      temporizador = null
    }
  }

  function agendar(): void {
    parar()

    if (!ativo) {
      return
    }

    temporizador = setTimeout(() => {
      void consultar()
    }, INTERVALO)
  }

  async function consultar(): Promise<void> {
    if (!ativo) {
      return
    }

    // Duas consultas sobrepostas nao acontecem pelo encadeamento normal, mas
    // podem se a tela pedir uma enquanto outra esta em voo. Reagendar em vez de
    // simplesmente desistir e o que impede o ciclo de morrer nesse encontro.
    if (consultando.value) {
      agendar()

      return
    }

    const minha = ++geracao

    consultando.value = true
    erro.value = null

    try {
      const resposta = await requisitar<TentativaOuNuloEnvelope>(`/api/lessons/${aulaId}/video`)

      if (!ativo || minha !== geracao) {
        return
      }

      // `data: null` e o estado normal de aula sem video — nao e erro, e nao ha
      // o que acompanhar. A tentativa completa vem da API inteira, inclusive a
      // mensagem de falha: nada aqui e recomposto.
      tentativa.value = resposta.data

      if (transitorio.value) {
        agendar()
      }
    }
    catch (causa) {
      if (!ativo || minha !== geracao) {
        return
      }

      // O ciclo para. Insistir de tres em tres segundos contra uma API fora do ar
      // multiplicaria a falha e deixaria a tela sem explicacao; parada, ela
      // mostra a indisponibilidade e oferece tentar de novo.
      erro.value = ErroDeApi.de(causa)
      parar()
    }
    finally {
      consultando.value = false
    }
  }

  /**
   * Adota uma tentativa que veio por outro caminho — a conclusao de um envio — e
   * recomeca o ciclo a partir dela.
   *
   * A virada de geracao e o ponto: uma consulta disparada antes do envio novo
   * pode responder depois dele, trazendo o estado da tentativa **anterior**. Sem
   * invalidar, a tela voltaria de `uploaded` para o `failed` de um envio que
   * acabou de ser substituido.
   */
  function definir(nova: TentativaDeVideo | null): void {
    geracao += 1
    parar()

    tentativa.value = nova
    erro.value = null

    if (transitorio.value) {
      agendar()
    }
  }

  /**
   * Descarta o que estiver em voo e zera o acompanhamento.
   *
   * Chamado quando um envio novo comeca: a partir dali a tentativa antiga e a
   * mensagem de falha dela nao descrevem mais nada.
   */
  function invalidar(): void {
    geracao += 1
    parar()

    tentativa.value = null
    erro.value = null
  }

  if (getCurrentScope() !== undefined) {
    onScopeDispose(() => {
      // Sem isto, um temporizador pendente dispararia uma consulta para uma tela
      // que nao existe mais, e a resposta escreveria em refs orfaos.
      ativo = false
      parar()
    })
  }

  return {
    tentativa,
    estado,
    erro,
    consultando,
    transitorio,
    mensagemDaFalha,
    consultar,
    definir,
    invalidar,
    parar,
  }
}
