<script setup lang="ts">
import type { Aula, AulaEnvelope, EstadoDoVideo } from '~/types/video'
import type { CodigoDeFalha } from '~/types/problema'
import { comoEstadoDoVideo } from '~/composables/useVideoStatus'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Publicar a aula (RF-AUL-005, AC-PROD-004, AC-PROD-005).
 *
 * ## O botao some, e nao apenas desabilita
 *
 * Fora de `ready`, a acao **nao existe** na tela. Um botao desabilitado ainda
 * anuncia que aquilo e possivel agora e convida a descobrir por que nao esta;
 * ausente, ele conta a mesma verdade que o backend contaria — nao ha o que
 * publicar ainda.
 *
 * Isso e conveniencia visual, e nada mais. A autorizacao e a regra continuam no
 * backend, que reavalia video pronto e referencia de reproducao a cada chamada e
 * responde `409` se a condicao nao valer. Apagar este arquivo nao abriria brecha
 * nenhuma (RN-AUT-002, RF-UI-017).
 *
 * ## A recusa vem por `code`, nunca por texto
 *
 * O `409` chega com o codigo que diz **qual** condicao faltou, e o texto publico
 * que a API escreveu. A tela repassa o texto e decide pelo codigo; comparar
 * mensagens quebraria na primeira correcao de portugues do backend. A existencia
 * de referencia de reproducao tambem nao e calculada aqui — ela nem atravessa a
 * fronteira HTTP.
 *
 * ## O que a recusa ensina sobre o video
 *
 * Um `409` nao e so uma negativa: ele carrega a leitura que o backend acabou de
 * fazer, e essa leitura e mais nova que qualquer coisa nesta tela. `video_state`
 * vem como membro de extensao justamente para a interface nao precisar de uma
 * segunda requisicao — e `LESSON_WITHOUT_VIDEO` diz, sem precisar de campo
 * nenhum, que nao ha tentativa alguma.
 *
 * O que se faz com isso e devolver o valor para quem cuida do estado do video,
 * pelo evento `conflito`. O painel adota a leitura e, se ela ainda puder mudar,
 * volta a acompanhar de tres em tres segundos. Guardar uma copia aqui criaria
 * uma segunda verdade sobre o mesmo video.
 *
 * ## E some o botao
 *
 * Depois da recusa, repetir devolveria exatamente o mesmo `409` enquanto o
 * estado nao mudar. O botao volta sozinho quando ele muda — nao por um
 * temporizador, e sim porque a condicao que o esconde deixou de valer.
 */
const props = defineProps<{
  aula: Aula
  estadoDoVideo: EstadoDoVideo | null
}>()

const { requisitar } = useApi()

const emit = defineEmits<{ publicada: [Aula], conflito: [EstadoDoVideo | null] }>()

const enviando = ref(false)
const erro = ref<ErroDeApi | null>(null)
const publicadaAgora = ref<Aula | null>(null)

/**
 * O estado do video que a recusa em vigor descreve.
 *
 * `undefined` significa que nao ha recusa de pe. Qualquer outro valor — inclusive
 * `null`, que afirma a ausencia de tentativa — e a leitura que o backend fez ao
 * recusar.
 *
 * Guardar o **estado**, e nao apenas um sinal de "houve recusa", e o que permite
 * distinguir as duas coisas que acontecem em seguida:
 *
 *   a reconciliacao inicial, quando a tela adota o valor que veio no proprio
 *   `409`. A recusa continua descrevendo a realidade, e apaga-la ali tiraria da
 *   tela a explicacao no exato instante em que ela passou a ser verdadeira;
 *
 *   o avanco real, quando o acompanhamento traz um estado **diferente** do que a
 *   recusa descrevia. Ai ela ficou obsoleta, e some junto com a mensagem.
 */
const estadoDaRecusa = ref<EstadoDoVideo | null | undefined>(undefined)

watch(() => props.estadoDoVideo, (observado) => {
  if (estadoDaRecusa.value === undefined || observado === estadoDaRecusa.value) {
    return
  }

  /*
  | A recusa e a mensagem caem juntas, e e obrigatorio que caiam: `erro` guarda o
  | problema que estabeleceu esta recusa — os dois sao escritos no mesmo `catch` e
  | nao ha como um sobreviver ao outro. Deixando so a mensagem, a tela ofereceria
  | "Publicar aula" logo abaixo de um aviso dizendo que o video ainda nao esta
  | pronto.
  */
  estadoDaRecusa.value = undefined
  erro.value = null
})

/**
 * As tres condicoes de publicacao que o contrato distingue, cada uma com o nome
 * da condicao que faltou.
 *
 * O titulo vem do **codigo**; a explicacao vem do `detail` que a API escreveu.
 * A divisao e proposital: o codigo e estavel e pode governar a tela, o texto e
 * escrito para pessoas e pode ser reescrito sem quebrar contrato.
 *
 *   `LESSON_WITHOUT_VIDEO`               nao existe tentativa. O retrato desta
 *                                        tela estava velho, e o estado certo e
 *                                        nenhum.
 *   `LESSON_VIDEO_NOT_READY`             existe, e ainda vai mudar. O proprio
 *                                        problema traz em que ponto esta.
 *   `LESSON_PLAYBACK_REFERENCE_MISSING`  o video esta pronto e mesmo assim nao
 *                                        da para publicar. Nao ha o que esperar,
 *                                        e repetir seria inutil.
 */
const CONDICOES_DE_PUBLICACAO = {
  LESSON_WITHOUT_VIDEO: 'Esta aula ainda nao tem video',
  LESSON_VIDEO_NOT_READY: 'O video ainda nao esta pronto',
  LESSON_PLAYBACK_REFERENCE_MISSING: 'O video nao tem referencia de reproducao',
} as const satisfies Partial<Record<CodigoDeFalha, string>>

type CondicaoDePublicacao = keyof typeof CONDICOES_DE_PUBLICACAO

function ehCondicaoDePublicacao(codigo: CodigoDeFalha | null): codigo is CondicaoDePublicacao {
  // Propriedade propria, e nao `in`: um `code` como `constructor` ou `toString`
  // satisfaria `in` e faria o titulo da tela ser lido do prototipo de `Object` —
  // uma funcao, onde deveria haver o nome de uma condicao.
  return codigo !== null && Object.hasOwn(CONDICOES_DE_PUBLICACAO, codigo)
}

const descricaoDoSucesso = computed(() => (
  publicadaAgora.value === null
    ? undefined
    : `A aula "${publicadaAgora.value.title}" esta publicada e visivel para quem tem acesso ao curso.`
))

/**
 * O nome da condicao que faltou, escolhido pelo codigo.
 *
 * Fora das tres condicoes conhecidas fica o titulo generico: inventar um nome
 * para um codigo que a interface nao conhece afirmaria mais do que se sabe.
 */
const tituloDaRecusa = computed(() => {
  const codigo = erro.value?.code ?? null

  return ehCondicaoDePublicacao(codigo) ? CONDICOES_DE_PUBLICACAO[codigo] : 'Nao foi possivel publicar'
})

const podePublicar = computed(() => (
  props.aula.published_at === null
  && publicadaAgora.value === null
  && props.estadoDoVideo === 'ready'
  && estadoDaRecusa.value === undefined
))

async function publicar(): Promise<void> {
  // A guarda vive aqui alem do botao desabilitado: `Enter` sobre um controle
  // focado nao passa pelo estado visual, e a segunda chamada chegaria ao
  // servidor como um pedido legitimo.
  if (enviando.value) {
    return
  }

  enviando.value = true
  erro.value = null

  try {
    // Sem corpo. Nao ha nada a informar: a aula esta na rota, e todo o resto —
    // estado do video, referencia de reproducao, propriedade — o backend ja sabe
    // e reavalia sozinho.
    const resposta = await requisitar<AulaEnvelope>(`/api/lessons/${props.aula.id}/publish`, {
      method: 'POST',
    })

    publicadaAgora.value = resposta.data
    emit('publicada', resposta.data)
  }
  catch (causa) {
    const falha = ErroDeApi.de(causa)

    erro.value = falha
    absorverRecusa(falha)
  }
  finally {
    enviando.value = false
  }
}

/**
 * Lê o que a recusa ensinou e ajusta a tela a isso.
 *
 * Vale para qualquer negativa por regra, e nao so para as tres nomeadas: um
 * `409` que a interface nao reconheca continua sendo uma decisao tomada sobre as
 * mesmas condicoes, e repetir sem que nada mude daria o mesmo resultado.
 */
function absorverRecusa(falha: ErroDeApi): void {
  if (falha.status !== 409) {
    return
  }

  const codigo = falha.code
  const informado = comoEstadoDoVideo(falha.videoState)

  // A afirmacao de que nao ha tentativa nao precisa de campo: esta no codigo.
  const reconciliado: EstadoDoVideo | null | undefined = codigo === 'LESSON_WITHOUT_VIDEO'
    ? null
    : informado ?? undefined

  if (reconciliado !== undefined) {
    emit('conflito', reconciliado)
  }

  /*
  | A recusa passa a valer, descrevendo o estado que o backend leu — ou, quando o
  | problema nao informou nenhum, o que a tela observava ao pedir a publicacao.
  |
  | `LESSON_PLAYBACK_REFERENCE_MISSING` e o caso em que os dois coincidem: o video
  | esta `ready`, continua `ready`, e ainda assim nao da para publicar. A recusa
  | nunca fica obsoleta sozinha, o aviso permanece e o botao nao volta — que e o
  | desfecho certo, porque nao ha o que esperar. A referencia de reproducao nem
  | atravessa a fronteira HTTP para ser conferida aqui.
  */
  estadoDaRecusa.value = reconciliado === undefined ? props.estadoDoVideo : reconciliado
}
</script>

<template>
  <div
    v-if="podePublicar || publicadaAgora || erro"
    data-publicacao
    class="flex flex-col gap-2"
  >
    <UiEstadoDeSucesso
      v-if="publicadaAgora"
      titulo="Aula publicada"
      :descricao="descricaoDoSucesso"
    />

    <!--
      `409` cai em conflito de regra, e nao em erro generico: a diferenca entre
      "algo deu errado" e "falta o video ficar pronto" e a diferenca entre
      desistir e esperar (RF-UI-010).
    -->
    <UiEstadoDeFalha
      v-else-if="erro"
      :erro="erro"
      :titulo="tituloDaRecusa"
      @nova-tentativa="publicar"
    />

    <!--
      Botao proprio, e nao o de formulario: aqui nao ha formulario para submeter,
      e um `type="submit"` solto se comportaria de forma diferente dentro de um.
      O desabilitado durante o envio e a metade visual da protecao; a outra
      metade e a guarda no manipulador.
    -->
    <UButton
      v-if="podePublicar"
      type="button"
      :loading="enviando"
      :disabled="enviando"
      :aria-busy="enviando"
      size="sm"
      icon="i-lucide-badge-check"
      data-acao="publicar"
      @click="publicar"
    >
      {{ enviando ? 'Publicando...' : 'Publicar aula' }}
    </UButton>
  </div>
</template>
