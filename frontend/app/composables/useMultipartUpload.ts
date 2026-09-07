import type {
  AberturaDeEnvio,
  ArquivoEnviavel,
  ConclusaoDeEnvio,
  ParteConcluida,
  PlanoDeEnvio,
  PlanoEnvelope,
  TentativaDeVideo,
  TentativaEnvelope,
  UrlDeParteEnvelope,
} from '~/types/video'
import type { CodigoDeFalha } from '~/types/problema'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Em que ponto do envio a transferencia esta.
 *
 * Sete fases, e cada uma existe porque leva a uma **acao diferente**:
 *
 *   `ocioso`            nada em andamento. Se houver erro, ele e da abertura, e
 *                       a saida e escolher um arquivo de novo.
 *   `abrindo`           o plano foi pedido e ainda nao chegou.
 *   `transferindo`      partes a caminho, uma de cada vez.
 *   `interrompido`      uma parte falhou. A saida e **retomar**, nunca recomecar:
 *                       as partes ja confirmadas continuam valendo.
 *   `concluindo`        a conclusao esta em voo.
 *   `conclusaoPendente`  todas as partes tem comprovante e a conclusao falhou por
 *                        algo que **pode** passar. A saida e repetir so a
 *                        conclusao — reenviar bytes aqui refaria gigabytes por
 *                        causa de segundos de indisponibilidade.
 *   `conclusaoRecusada`  a API recusou a conclusao por regra. Repetir devolveria
 *                        exatamente a mesma resposta, entao nao ha saida por
 *                        aqui: ou a tentativa ficou em `failed` e um envio novo
 *                        e aceito, ou nao ha o que fazer nesta tela.
 *   `concluido`          a conclusao foi aceita. Daqui em diante quem fala e o
 *                        backend, pelo acompanhamento do processamento.
 */
export type FaseDoEnvio =
  | 'ocioso'
  | 'abrindo'
  | 'transferindo'
  | 'interrompido'
  | 'concluindo'
  | 'conclusaoPendente'
  | 'conclusaoRecusada'
  | 'concluido'

/**
 * Por que a transferencia parou.
 *
 * Nenhum destes carrega texto do armazenamento. `url` e a unica que traz um
 * `ErroDeApi`, porque so ela veio da **nossa** API e tem mensagem publica
 * escrita para ser lida.
 */
export type MotivoDaInterrupcao = 'url' | 'rede' | 'recusado' | 'semComprovante'

export interface Interrupcao {
  parte: number
  motivo: MotivoDaInterrupcao
  erro: ErroDeApi | null
}

type Entrega =
  | { situacao: 'aceita', etag: string }
  | { situacao: 'semComprovante' }
  | { situacao: 'expirada' }
  | { situacao: 'recusada' }
  | { situacao: 'rede' }

type ResultadoDaParte =
  | { ok: true, parte: ParteConcluida }
  | { ok: false, interrupcao: Interrupcao }

const CABECALHO_COMPROVANTE = 'ETag'

/**
 * As recusas da conclusao que **provam** que a tentativa ficou em `failed`.
 *
 * O backend grava a recusa antes de responder: nos dois casos o objeto foi
 * observado e nao serve — ausente, ou incompativel com o que a abertura
 * declarou. `failed` e o unico estado a partir do qual um envio novo e aceito,
 * entao saber disso e o que permite a tela oferecer um arquivo novo em vez de
 * um botao que repetiria o mesmo `409`.
 *
 * `VIDEO_UPLOAD_NOT_ACTIVE` fica de fora de proposito: ele tambem e terminal,
 * mas **nao** toca no armazenamento e nao registra falha — afirmar `failed` ali
 * seria inventar um estado que o backend nao gravou.
 */
const RECUSAS_COM_FALHA_REGISTRADA: ReadonlySet<CodigoDeFalha> = new Set<CodigoDeFalha>([
  'VIDEO_OBJECT_MISSING',
  'VIDEO_OBJECT_MISMATCH',
])

/**
 * Repetir a conclusao pode mudar alguma coisa?
 *
 * Sim quando nao houve resposta, quando houve `5xx` — o `503` da verificacao
 * sem evidencia deixa a tentativa em `uploading` justamente para a conclusao
 * poder ser repetida — e quando a resposta nao foi nada que o contrato descreva:
 * nos tres casos o desfecho e desconhecido, e insistir e legitimo.
 *
 * Nao quando a API **declarou** uma recusa. Um problema do contrato e uma
 * decisao tomada sobre os mesmos comprovantes que seriam reenviados: a segunda
 * chamada recebe a mesma resposta, e o botao so ensinaria a insistir no que nao
 * muda.
 *
 * A decisao e por categoria e por codigo, nunca pelo texto de `detail`.
 */
function conclusaoPodeSerRepetida(falha: ErroDeApi): boolean {
  return falha.categoria !== 'problema'
}

/**
 * O envio do video em partes, direto ao armazenamento (plan §11).
 *
 * ## Onde cada byte passa
 *
 * As tres chamadas de controle — abrir, pedir URL de parte, concluir — vao para
 * a API pelo `useApi`, com cookie de sessao e token de protecao. **O `PUT` de
 * cada parte nao**: ele vai direto a URL assinada do armazenamento, com `fetch`
 * nativo e `credentials: 'omit'`.
 *
 * A excecao e a razao de ser do desenho. Varios gigabytes atravessando o PHP ou
 * o servidor Nuxt significariam memoria, tempo limite e uma API que vira gargalo
 * — e o desafio pede explicitamente que o arquivo nao dependa disso
 * (AC-VID-001). Mandar cookie junto seria pior que inutil: a credencial da
 * sessao viajaria para um host que nao precisa dela e nao deveria ve-la.
 *
 * ## Uma parte por vez
 *
 * Nunca ha duas em voo. Com um unico ponto de falha, **a parte que falhou e
 * sempre a proxima da fila**, e retomar e continuar de onde parou — sem mapa de
 * lacunas, sem reenviar o que ja tem comprovante. O custo aceito e banda ociosa
 * em conexoes rapidas; paralelizar e evolucao natural e nao muda o contrato.
 *
 * ## O que ele nao decide
 *
 * Tipo aceito, tamanho maximo, tamanho de parte e numero de partes sao **do
 * backend**. Nao ha aqui uma segunda constante de 64 MiB nem um teto de 10 GiB
 * repetido: uma copia dessas regras no cliente divergiria da original no dia em
 * que ela mudasse, e a tela recusaria um arquivo que a API aceitaria.
 *
 * Chave de armazenamento, identificador multipart e URL de parte tambem nao sao
 * montados aqui. Todos chegam prontos, e remonta-los seria reconstruir no
 * cliente o vinculo que o servidor verifica na conclusao.
 */
export function useMultipartUpload(aulaId: string) {
  const { requisitar } = useApi()

  const fase = ref<FaseDoEnvio>('ocioso')
  const plano = ref<PlanoDeEnvio | null>(null)
  const partes = ref<ParteConcluida[]>([])
  const tentativa = ref<TentativaDeVideo | null>(null)
  const interrupcao = ref<Interrupcao | null>(null)

  /** Falha da abertura ou da conclusao — as duas que sao da nossa API. */
  const erro = ref<ErroDeApi | null>(null)

  // `shallowRef` porque um arquivo nao e estado observavel: torna-lo reativo em
  // profundidade faria o Vue percorrer um objeto do navegador sem necessidade.
  const arquivo = shallowRef<ArquivoEnviavel | null>(null)

  const ocupado = computed(() => (
    fase.value === 'abrindo' || fase.value === 'transferindo' || fase.value === 'concluindo'
  ))

  const totalDePartes = computed(() => plano.value?.part_count ?? 0)
  const partesEnviadas = computed(() => partes.value.length)

  const percentual = computed(() => (
    totalDePartes.value === 0
      ? 0
      : Math.round((partesEnviadas.value / totalDePartes.value) * 100)
  ))

  const nomeDoArquivo = computed(() => tentativa.value?.filename ?? arquivo.value?.name ?? null)

  /**
   * A conclusao foi recusada por um motivo que deixou a tentativa em `failed`.
   *
   * Nao ha `TentativaDeVideo` sintetizada para representar isso: inventar um
   * objeto que o backend nao devolveu colocaria na tela um `id` e um `filename`
   * que nao existem. O que se afirma e menor e verdadeiro — **o estado observado
   * do video e `failed`** —, e quem monta a tela deriva o resto disso.
   */
  const falhaRegistrada = computed(() => (
    fase.value === 'conclusaoRecusada'
    && erro.value?.code !== null
    && erro.value?.code !== undefined
    && RECUSAS_COM_FALHA_REGISTRADA.has(erro.value.code)
  ))

  async function pedirUrl(tentativaId: string, numero: number): Promise<string> {
    const resposta = await requisitar<UrlDeParteEnvelope>(
      `/api/video-uploads/${tentativaId}/parts/${numero}/url`,
      { method: 'POST' },
    )

    return resposta.data.url
  }

  /**
   * O `PUT` da parte, direto ao armazenamento.
   *
   * O corpo da resposta **nunca** e lido. Uma mensagem de erro do armazenamento
   * e escrita para quem opera o armazenamento: repassa-la a tela exporia
   * detalhe de infraestrutura a quem so quer saber que a parte 7 nao subiu.
   */
  async function entregar(url: string, pedaco: Blob): Promise<Entrega> {
    let resposta: Response

    try {
      // A URL vai exatamente como veio, sem `baseURL` e sem cabecalho nosso: ela
      // e assinada, e qualquer acrescimo pode invalidar a assinatura.
      resposta = await fetch(url, {
        method: 'PUT',
        body: pedaco,
        credentials: 'omit',
      })
    }
    catch {
      return { situacao: 'rede' }
    }

    if (resposta.status === 401 || resposta.status === 403) {
      return { situacao: 'expirada' }
    }

    if (!resposta.ok) {
      return { situacao: 'recusada' }
    }

    const etag = resposta.headers.get(CABECALHO_COMPROVANTE)

    // Sem comprovante a parte **nao** esta concluida. Segui-la em frente faria a
    // conclusao ser pedida com uma lista incompleta, e o armazenamento recusaria
    // a montagem depois de todo o resto ja ter subido.
    return etag === null || etag.length === 0
      ? { situacao: 'semComprovante' }
      : { situacao: 'aceita', etag }
  }

  async function enviarParte(
    planoAtual: PlanoDeEnvio,
    escolhido: ArquivoEnviavel,
    numero: number,
  ): Promise<ResultadoDaParte> {
    const inicio = (numero - 1) * planoAtual.part_size

    // A ultima parte e menor: o recorte para no tamanho do arquivo, e nao no
    // multiplo seguinte de `part_size`.
    const fim = Math.min(inicio + planoAtual.part_size, escolhido.size)
    const pedaco = escolhido.slice(inicio, fim)

    let url: string

    try {
      url = await pedirUrl(planoAtual.attempt_id, numero)
    }
    catch (causa) {
      return { ok: false, interrupcao: { parte: numero, motivo: 'url', erro: ErroDeApi.de(causa) } }
    }

    let entrega = await entregar(url, pedaco)

    if (entrega.situacao === 'expirada') {
      // A URL vale 15 minutos, e uma parte grande pode atravessar esse prazo.
      // Renovar e pedir outra pela mesma rota — nao muda nada no dominio.
      //
      // **Uma** renovacao por tentativa de parte. Sem o limite, uma recusa
      // permanente de autorizacao viraria um laco de renovar e reenviar que so
      // para quando alguem fecha a aba.
      try {
        url = await pedirUrl(planoAtual.attempt_id, numero)
      }
      catch (causa) {
        return { ok: false, interrupcao: { parte: numero, motivo: 'url', erro: ErroDeApi.de(causa) } }
      }

      entrega = await entregar(url, pedaco)
    }

    if (entrega.situacao === 'aceita') {
      // O comprovante e opaco e vai adiante **exatamente** como veio, aspas
      // inclusive: remove-las quebra a conclusao.
      return { ok: true, parte: { part_number: numero, etag: entrega.etag } }
    }

    const motivo: MotivoDaInterrupcao = entrega.situacao === 'rede'
      ? 'rede'
      : entrega.situacao === 'semComprovante' ? 'semComprovante' : 'recusado'

    return { ok: false, interrupcao: { parte: numero, motivo, erro: null } }
  }

  async function concluir(): Promise<void> {
    const planoAtual = plano.value

    if (planoAtual === null) {
      return
    }

    fase.value = 'concluindo'
    erro.value = null

    try {
      const resposta = await requisitar<TentativaEnvelope>(
        `/api/video-uploads/${planoAtual.attempt_id}/complete`,
        {
          method: 'POST',
          body: { parts: [...partes.value] } satisfies ConclusaoDeEnvio,
        },
      )

      // `200` e `202` chegam no mesmo formato: o que muda entre eles e se o
      // processamento foi enfileirado agora ou ja tinha sido, e nenhum dos dois
      // significa video pronto.
      tentativa.value = resposta.data
      fase.value = 'concluido'
    }
    catch (causa) {
      const falha = ErroDeApi.de(causa)

      // Nada aqui apresenta o video como enviado, em nenhum dos dois caminhos:
      // quem decide isso e o backend, e ele acabou de dizer que nao.
      erro.value = falha
      fase.value = conclusaoPodeSerRepetida(falha) ? 'conclusaoPendente' : 'conclusaoRecusada'
    }
  }

  async function transferir(): Promise<void> {
    const planoAtual = plano.value
    const escolhido = arquivo.value

    if (planoAtual === null || escolhido === null) {
      return
    }

    fase.value = 'transferindo'
    interrupcao.value = null

    // A proxima e sempre `concluidas + 1`. Com uma parte em voo por vez, a que
    // falhou e sempre a primeira ainda sem comprovante — e retomar e continuar
    // daqui, sem reenviar nenhuma das anteriores.
    for (let numero = partes.value.length + 1; numero <= planoAtual.part_count; numero++) {
      const resultado = await enviarParte(planoAtual, escolhido, numero)

      if (!resultado.ok) {
        interrupcao.value = resultado.interrupcao
        fase.value = 'interrompido'

        // Sem conclusao pedida, o backend mantem a tentativa em `uploading`. A
        // tela tambem nao avanca: transferencia interrompida nunca vira enviado,
        // processando ou pronto (AC-VID-012).
        return
      }

      // O progresso so anda com o comprovante em maos. Contar a URL emitida ou o
      // `PUT` iniciado mostraria uma barra a frente dos bytes que subiram.
      partes.value = [...partes.value, resultado.parte]
    }

    await concluir()
  }

  /**
   * Abre um envio para o arquivo escolhido e transfere ate o fim.
   *
   * A guarda contra repeticao vive aqui, e nao so no botao: um duplo clique ou
   * um `Enter` repetido abriria **duas** tentativas, e a segunda seria recusada
   * com `409` depois de a primeira ja ter comecado a subir bytes.
   */
  async function enviar(escolhido: ArquivoEnviavel): Promise<void> {
    if (ocupado.value) {
      return
    }

    arquivo.value = escolhido
    plano.value = null
    partes.value = []
    tentativa.value = null
    interrupcao.value = null
    erro.value = null
    fase.value = 'abrindo'

    try {
      const resposta = await requisitar<PlanoEnvelope>(`/api/lessons/${aulaId}/video/uploads`, {
        method: 'POST',
        /*
        | Nome, tipo e tamanho **declarados**. Nenhum byte entra aqui: esta
        | chamada pede uma estrategia de transferencia, nao transfere nada.
        |
        | O `satisfies` confere os nomes dos campos contra o contrato — um campo
        | a mais nao compila. O tipo nao e estreitado para o literal `video/mp4`
        | de proposito: o que se manda e o que o navegador leu do arquivo, e
        | substituir isso pelo valor aceito esconderia do backend o tipo real e
        | transformaria uma recusa `422` num envio aceito por engano.
        */
        body: {
          filename: escolhido.name,
          content_type: escolhido.type,
          size: escolhido.size,
        } satisfies Record<keyof AberturaDeEnvio, unknown>,
      })

      plano.value = resposta.data
    }
    catch (causa) {
      // Sem plano nao ha tentativa aberta, e nao ha o que retomar: a saida e
      // escolher um arquivo de novo.
      erro.value = ErroDeApi.de(causa)
      fase.value = 'ocioso'

      return
    }

    await transferir()
  }

  /**
   * Continua de onde parou, sem reabrir nada.
   *
   * Duas retomadas diferentes, decididas pela fase: com partes faltando, pede
   * uma URL nova **so** para a parte que falhou e segue; com todas as partes
   * confirmadas, repete apenas a conclusao. Nenhuma das duas abre uma segunda
   * tentativa nem reenvia byte ja comprovado.
   *
   * Em `conclusaoRecusada` ela nao faz nada, e a inercia e a resposta certa: a
   * API ja decidiu sobre estes comprovantes, e mandar os mesmos de novo so
   * gastaria uma requisicao para receber o mesmo `409`. A tela nem oferece a
   * acao; a guarda existe para o caso de ela ser chamada por outro caminho.
   */
  async function retomar(): Promise<void> {
    if (ocupado.value) {
      return
    }

    if (fase.value === 'conclusaoPendente') {
      await concluir()

      return
    }

    if (fase.value === 'interrompido') {
      await transferir()
    }
  }

  return {
    fase,
    plano,
    partes,
    tentativa,
    interrupcao,
    erro,
    arquivo,
    ocupado,
    totalDePartes,
    partesEnviadas,
    percentual,
    nomeDoArquivo,
    falhaRegistrada,
    enviar,
    retomar,
  }
}
