<script setup lang="ts">
import type { EstadoDoVideo } from '~/types/video'
import type { FaseDoEnvio } from '~/composables/useMultipartUpload'
import { estadoTransitorio } from '~/composables/useVideoStatus'

/**
 * O video de uma aula: enviar, acompanhar e entender o que aconteceu.
 *
 * Reune os dois composables — a transferencia e o acompanhamento — porque eles
 * se cruzam num ponto so: a conclusao do envio devolve a tentativa que o
 * acompanhamento passa a seguir. Separados em telas diferentes, a passagem
 * exigiria estado global; juntos aqui, e uma chamada.
 *
 * ## Duas fontes para um estado so
 *
 * A arvore do curso ja traz `video_state`, e e o que a tela mostra antes de
 * perguntar qualquer coisa — sem isso, cada aula abriria com um vazio ate a
 * primeira consulta responder. A partir da primeira observacao propria, quem
 * manda e a tentativa consultada: ela e mais recente que o retrato que a arvore
 * tirou.
 *
 * A consulta so acontece quando ha o que descobrir. `null` e `ready` nao pedem
 * nada — o primeiro nao tem tentativa, o segundo e terminal. Os demais ou vao
 * mudar sozinhos, ou tem uma mensagem de falha que so o endpoint do video
 * carrega.
 */
const props = withDefaults(defineProps<{
  aulaId: string
  estadoInicial: EstadoDoVideo | null
  /**
   * Um estado que **outra** parte da aula descobriu — hoje, o `video_state` que
   * acompanha a recusa de uma publicacao.
   *
   * `undefined` significa "ninguem informou nada"; `null` e uma afirmacao de que
   * nao ha tentativa. A diferenca importa: as duas coisas levariam a telas
   * diferentes se fossem confundidas.
   */
  estadoInformado?: EstadoDoVideo | null
}>(), {
  estadoInformado: undefined,
})

const emit = defineEmits<{ estado: [EstadoDoVideo | null] }>()

const {
  fase,
  interrupcao,
  erro: erroDoEnvio,
  ocupado,
  percentual,
  partesEnviadas,
  totalDePartes,
  nomeDoArquivo,
  falhaRegistrada,
  tentativa: tentativaDoEnvio,
  enviar,
  retomar,
} = useMultipartUpload(props.aulaId)

const {
  estado: estadoConsultado,
  erro: erroDaConsulta,
  mensagemDaFalha,
  consultar,
  definir,
  invalidar,
} = useVideoStatus(props.aulaId)

const envioConcluido = ref(false)
const estadoDaArvore = ref<EstadoDoVideo | null>(props.estadoInicial)

const estadoObservado = computed<EstadoDoVideo | null>(() => {
  // A recusa foi gravada **antes** de virar `409`: a tentativa esta em `failed`,
  // e essa e a leitura mais recente que existe — mais nova que a consulta
  // anterior e que o retrato da arvore.
  if (falhaRegistrada.value) {
    return 'failed'
  }

  return estadoConsultado.value ?? estadoDaArvore.value
})

/*
| A arvore pode ser relida por outro motivo — uma aula criada em outro modulo, uma
| publicacao — e trazer um retrato mais velho do que o que esta tela ja observou.
| So se aceita o valor novo quando nao ha observacao propria para contradizer.
*/
watch(() => props.estadoInicial, (novo) => {
  if (estadoConsultado.value === null && fase.value === 'ocioso') {
    estadoDaArvore.value = novo
  }
})

/**
 * Adota um estado que veio de fora e recomeca o ciclo se ele ainda puder mudar.
 *
 * A consulta anterior e descartada antes: ela pode ter parado num terminal — um
 * `failed` de minutos atras — e continuaria vencendo o valor novo, que e o que o
 * backend acabou de ler.
 */
function reconciliar(informado: EstadoDoVideo | null): void {
  invalidar()

  estadoDaArvore.value = informado

  if (estadoTransitorio(informado)) {
    void consultar()
  }
}

watch(() => props.estadoInformado, (informado) => {
  if (informado !== undefined) {
    reconciliar(informado)
  }
})

type Situacao =
  | 'semVideo'
  | 'abrindo'
  | 'transferindo'
  | 'interrompido'
  | 'concluindo'
  | 'conclusaoPendente'
  | 'conclusaoRecusada'
  | 'pendente'
  | 'envioIncompleto'
  | 'enviado'
  | 'processando'
  | 'pronto'
  | 'falhou'

/*
| As duas fases sem situacao propria sao as que devolvem a palavra ao backend:
| `ocioso` porque nada esta acontecendo aqui, e `concluido` porque a partir dali
| quem descreve o video e o acompanhamento.
*/
const SITUACAO_DA_FASE = {
  ocioso: null,
  abrindo: 'abrindo',
  transferindo: 'transferindo',
  interrompido: 'interrompido',
  concluindo: 'concluindo',
  conclusaoPendente: 'conclusaoPendente',
  conclusaoRecusada: 'conclusaoRecusada',
  concluido: null,
} as const satisfies Record<FaseDoEnvio, Situacao | null>

const SITUACAO_DO_ESTADO = {
  pending: 'pendente',
  // `uploading` sem transferencia em curso aqui e um envio que ficou pelo
  // caminho — a aba fechou, a rede caiu. A tentativa fica parada ate falhar, e
  // dizer "transferindo" prometeria um progresso que nao existe (AC-VID-012).
  uploading: 'envioIncompleto',
  uploaded: 'enviado',
  processing: 'processando',
  ready: 'pronto',
  failed: 'falhou',
} as const satisfies Record<EstadoDoVideo, Situacao>

/**
 * O que a tela mostra agora.
 *
 * A fase local vence enquanto existe, e a razao e temporal: durante a
 * transferencia o backend enxerga `uploading` e nada mais, enquanto aqui se sabe
 * exatamente em que parte o envio esta. Terminada a fase, a palavra volta a ser
 * do backend.
 */
const situacao = computed<Situacao>(() => {
  // A recusa que registrou falha e contada como falha do video, e nao como uma
  // fase do envio: o que importa dali em diante e que a tentativa acabou em
  // `failed` — e que, por isso, um arquivo novo e aceito.
  if (falhaRegistrada.value) {
    return 'falhou'
  }

  const daFase = SITUACAO_DA_FASE[fase.value]

  if (daFase !== null) {
    return daFase
  }

  const estado = estadoObservado.value

  return estado === null ? 'semVideo' : SITUACAO_DO_ESTADO[estado]
})

const TITULOS: Record<Situacao, string> = {
  semVideo: 'Sem video',
  abrindo: 'Preparando o envio',
  transferindo: 'Transferindo o video',
  interrompido: 'Transferencia interrompida',
  concluindo: 'Concluindo o envio',
  conclusaoPendente: 'Envio transferido, conclusao pendente',
  conclusaoRecusada: 'Conclusao recusada',
  pendente: 'Envio pendente',
  envioIncompleto: 'Envio incompleto',
  enviado: 'Enviado, aguardando processamento',
  processando: 'Processando o video',
  pronto: 'Video pronto',
  falhou: 'Falha no video',
}

const TONS = {
  semVideo: 'neutro',
  abrindo: 'informativo',
  transferindo: 'informativo',
  interrompido: 'atencao',
  concluindo: 'informativo',
  conclusaoPendente: 'atencao',
  conclusaoRecusada: 'atencao',
  pendente: 'informativo',
  envioIncompleto: 'atencao',
  enviado: 'informativo',
  processando: 'informativo',
  pronto: 'sucesso',
  falhou: 'erro',
} as const satisfies Record<Situacao, string>

/**
 * Por que a transferencia parou, sem uma palavra do armazenamento.
 *
 * O corpo de erro do armazenamento nao e lido em lugar nenhum: ele descreve
 * infraestrutura para quem opera infraestrutura. O que a tela precisa dizer e
 * qual parte parou e se vale retomar — e as duas coisas sao sabidas aqui.
 */
const MOTIVOS = {
  rede: 'A conexao caiu durante a transferencia.',
  recusado: 'O armazenamento nao aceitou a parte.',
  semComprovante: 'A parte subiu, mas o armazenamento nao devolveu o comprovante dela.',
  url: 'Nao foi possivel obter a autorizacao para enviar esta parte.',
} as const

const descricao = computed<string | undefined>(() => {
  switch (situacao.value) {
    case 'semVideo':
      return 'Esta aula ainda nao tem video. Escolha um arquivo para enviar.'

    case 'abrindo':
      return 'Pedindo ao servidor a estrategia de transferencia.'

    case 'transferindo':
      return nomeDoArquivo.value === null
        ? undefined
        : `Enviando ${nomeDoArquivo.value} em partes, uma de cada vez.`

    case 'interrompido': {
      const parada = interrupcao.value

      if (parada === null) {
        return undefined
      }

      const causa = parada.motivo === 'url' && parada.erro !== null
        ? parada.erro.mensagem
        : MOTIVOS[parada.motivo]

      return `Parou na parte ${parada.parte}. ${causa} As partes ja confirmadas continuam valendo.`
    }

    case 'concluindo':
      return 'Enviando os comprovantes das partes para o servidor verificar o arquivo.'

    case 'conclusaoPendente':
      return erroDoEnvio.value === null
        ? undefined
        : `${erroDoEnvio.value.mensagem} Nenhum byte precisa ser reenviado.`

    case 'conclusaoRecusada':
      // Terminal, e sem falha registrada: nao ha novo envio a oferecer nem
      // conclusao a repetir. O texto e o que a API escreveu.
      return erroDoEnvio.value?.mensagem

    case 'pendente':
      return 'O envio foi aberto e a transferencia ainda nao comecou.'

    case 'envioIncompleto':
      return 'A transferencia deste video nao chegou ao fim. Um novo envio so e aceito depois que a tentativa atual terminar em falha.'

    case 'enviado':
      return 'O arquivo foi verificado e esta na fila de processamento.'

    case 'processando':
      return 'O processamento esta em andamento. Esta tela se atualiza sozinha.'

    case 'pronto':
      return 'O video esta pronto para reproducao.'

    case 'falhou':
      // O texto e o que a API escreveu, venha ele da recusa da conclusao ou da
      // tentativa consultada. Nao ha aqui um catalogo paralelo de mensagens: a
      // interface exibe a decisao de quem decidiu (RF-UI-017).
      return mensagemDoVideoFalho.value ?? 'Envie o arquivo novamente.'
  }

  return undefined
})

/*
| Duas origens para a mesma frase: a recusa sincrona da conclusao, e a tentativa
| que o acompanhamento consultou. As duas sao texto publico escrito pela API.
*/
const mensagemDoVideoFalho = computed(() => (
  falhaRegistrada.value && erroDoEnvio.value !== null
    ? erroDoEnvio.value.mensagem
    : mensagemDaFalha.value
))

// Substituir video so e possivel a partir de `failed` — e a unica situacao em
// que o backend aceita um envio novo. Oferecer o seletor nas demais convidaria a
// uma acao que voltaria `409`.
const podeEnviar = computed(() => situacao.value === 'semVideo' || situacao.value === 'falhou')

const mostrarProgresso = computed(() => (
  situacao.value === 'transferindo'
  || situacao.value === 'interrompido'
  || situacao.value === 'conclusaoPendente'
))

const rotuloDaRetomada = computed(() => (
  situacao.value === 'conclusaoPendente' ? 'Concluir envio' : 'Retomar envio'
))

function adotarConclusao(): void {
  if (fase.value !== 'concluido' || tentativaDoEnvio.value === null) {
    return
  }

  envioConcluido.value = true

  // A tentativa devolvida pela conclusao vira o estado inicial do
  // acompanhamento, e a virada invalida qualquer consulta que ainda esteja em
  // voo desde antes deste envio.
  definir(tentativaDoEnvio.value)
}

async function aoEscolher(arquivo: File): Promise<void> {
  envioConcluido.value = false

  // A partir daqui a tentativa anterior — e a mensagem de falha dela — nao
  // descreve mais nada, e o retrato da arvore ficou velho.
  estadoDaArvore.value = null
  invalidar()

  await enviar(arquivo)
  adotarConclusao()
}

async function aoRetomar(): Promise<void> {
  await retomar()
  adotarConclusao()
}

watch(estadoObservado, (novo) => {
  emit('estado', novo)
}, { immediate: true })

onMounted(() => {
  // `null` nao tem tentativa e `ready` e terminal: nos dois casos a consulta
  // devolveria exatamente o que a arvore ja disse.
  if (props.estadoInicial !== null && props.estadoInicial !== 'ready') {
    void consultar()
  }
})
</script>

<template>
  <section
    data-painel-do-video
    :data-situacao-do-video="situacao"
    class="flex flex-col gap-3 border-t border-default pt-3"
  >
    <UiEstadoDeSucesso
      v-if="envioConcluido"
      titulo="Envio concluido"
      descricao="O arquivo foi verificado pelo servidor. O processamento comeca em seguida."
    />

    <!--
      A falha do acompanhamento e separada da falha do video: uma diz que nao foi
      possivel **perguntar**, a outra que o video nao deu certo. Fundidas, um
      problema de rede pareceria um video perdido.
    -->
    <UiEstadoDeFalha
      v-if="erroDaConsulta"
      :erro="erroDaConsulta"
      titulo="Nao foi possivel consultar o estado do video"
      @nova-tentativa="consultar"
    />

    <UiPainelDeEstado
      :tom="TONS[situacao]"
      :titulo="TITULOS[situacao]"
      :descricao="descricao"
    >
      <template
        v-if="situacao === 'interrompido' || situacao === 'conclusaoPendente'"
        #acoes
      >
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-rotate-ccw"
          :loading="ocupado"
          :disabled="ocupado"
          data-acao="retomar-envio"
          @click="aoRetomar"
        >
          {{ rotuloDaRetomada }}
        </UButton>
      </template>
    </UiPainelDeEstado>

    <VideoProgressoDoEnvio
      v-if="mostrarProgresso"
      :enviadas="partesEnviadas"
      :total="totalDePartes"
      :percentual="percentual"
    />

    <!--
      A abertura falhou: nao ha tentativa aberta e nao ha o que retomar. A saida
      e escolher um arquivo de novo, e o seletor logo abaixo ja e essa saida.
    -->
    <UiEstadoDeFalha
      v-if="erroDoEnvio && fase === 'ocioso'"
      :erro="erroDoEnvio"
      titulo="Nao foi possivel abrir o envio"
      :permitir-nova-tentativa="false"
    />

    <VideoSeletorDeArquivo
      v-if="podeEnviar"
      :id="`campo-video-${aulaId}`"
      :desabilitado="ocupado"
      @escolhido="aoEscolher"
    />
  </section>
</template>
