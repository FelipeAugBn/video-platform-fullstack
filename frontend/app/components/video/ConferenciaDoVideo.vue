<script setup lang="ts">
import type { EstadoDoVideo } from '~/types/video'
import type { Reproducao, ReproducaoEnvelope } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * O produtor confere o proprio video antes de decidir publicar (RF-PLB-009,
 * AC-PROD-008).
 *
 * ## Por que fica entre o painel e a publicacao
 *
 * E posicao na tela, e nao etapa de um fluxo. O painel acompanha o video; esta
 * secao e a acao de publicar ficam disponiveis **juntas**, sob a mesma condicao
 * — o video em `ready` —, e a conferencia vem antes porque assistir depois de
 * publicar chegaria tarde demais para ajudar a decidir. Publicar nao exige ter
 * passado por aqui: o botao de publicar esta logo abaixo, disponivel desde o
 * mesmo instante.
 *
 * ## Sob demanda, e nunca antes
 *
 * Nenhuma requisicao acontece ao montar. A URL so e pedida no clique, e a razao
 * e o que ela e: uma credencial temporaria de cinco minutos. Pedi-la para toda
 * aula pronta de um curso emitiria uma leva de URLs assinadas que ninguem pediu,
 * a maioria expirando sem ter sido usada.
 *
 * Pela mesma razao a acao **some** fora de `ready`, em vez de aparecer
 * desabilitada: um botao desabilitado anuncia que aquilo e possivel agora e
 * convida a descobrir por que nao esta. Isso e conveniencia visual e nada mais —
 * o backend reavalia propriedade e disponibilidade a cada chamada, e apagar este
 * arquivo nao abriria brecha nenhuma (RN-AUT-002, RF-UI-017).
 *
 * ## O que muda quando o estado muda
 *
 * Sair de `ready` descarta a reproducao e o erro anteriores. Os dois descrevem
 * um video que deixou de ser o atual, e manter um `<video>` tocando sob um
 * estado que ja mudou mostraria duas verdades ao mesmo tempo.
 *
 * **Descartar o que esta na tela nao basta.** Uma solicitacao ja em voo continua
 * viva depois da mudanca, e resolveria — ou falharia — escrevendo por cima do
 * estado recem-limpo. O sintoma seria um player de um video que nao e mais o
 * atual, ou uma negativa reaparecendo sozinha; e o `finally` deixaria
 * `carregando` desligando um carregamento que ja nao existe, ou desligando o de
 * uma solicitacao mais nova.
 *
 * Por isso cada solicitacao carrega uma **geracao**. Invalidar incrementa o
 * contador, e a resposta que chegar de uma geracao vencida e simplesmente
 * descartada: nao escreve reproducao, nao escreve erro e nao mexe em
 * `carregando`. Um contador local resolve o caso inteiro — nao ha por que
 * ampliar `useApi` nem introduzir cancelamento global para isto.
 *
 * `aulaId` entra na invalidacao pelo mesmo motivo. Se a prop for trocada por
 * outra aula, o que estava em voo pertence a aula anterior, e exibi-lo aqui
 * mostraria o video errado.
 *
 * ## Publicacao continua separada
 *
 * Assistir nao publica, e publicar nao depende de ter assistido. A conferencia e
 * uma leitura; a publicacao e uma acao explicita, com regra propria no backend
 * (RF-AUL-006).
 */
const props = defineProps<{
  aulaId: string
  estadoDoVideo: EstadoDoVideo | null
}>()

const { requisitar } = useApi()

const reproducao = ref<Reproducao | null>(null)
const erro = ref<ErroDeApi | null>(null)
const carregando = ref(false)

const pronto = computed(() => props.estadoDoVideo === 'ready')

/**
 * A geracao da solicitacao vigente.
 *
 * Deliberadamente **fora** da reatividade: nenhum template a le, e transformá-la
 * em `ref` faria cada invalidacao disparar uma renderizacao para dizer algo que
 * a tela nao mostra. E uma variavel por instancia — o corpo de `<script setup>`
 * roda uma vez para cada componente montado.
 */
let geracao = 0

/**
 * Descarta o que esta na tela **e** o que ainda esta a caminho.
 *
 * Incrementar a geracao e o que aposenta a solicitacao em voo: ela ainda vai
 * resolver ou falhar, mas ja nao pertence a geracao vigente e nao escreve nada.
 * `carregando` volta a `false` aqui porque o carregamento que ela representava
 * deixou de interessar — sem isso, o botao voltaria a aparecer travado quando o
 * video voltasse a `ready`.
 */
function invalidar(): void {
  geracao += 1
  reproducao.value = null
  erro.value = null
  carregando.value = false
}

watch(() => props.estadoDoVideo, (estado) => {
  if (estado !== 'ready') {
    invalidar()
  }
})

// Trocar de aula invalida em qualquer estado, e nao so fora de `ready`: o que
// esta em voo pertence a aula anterior.
watch(() => props.aulaId, () => {
  invalidar()
})

/**
 * Pede uma URL nova a cada chamada, inclusive na repeticao depois de um erro.
 *
 * Nao ha o que reaproveitar: uma URL que falhou nao existe, e uma que funcionou
 * ja esta em uso. A permissao e curta de proposito, e renova-la e pedir outra.
 *
 * Cada chamada abre uma geracao, o que tambem aposenta qualquer solicitacao
 * anterior ainda em voo: uma resposta velha nunca sobrescreve uma solicitacao
 * mais nova.
 */
async function visualizar(): Promise<void> {
  // Duas guardas, e nao uma. A de `carregando` vive aqui alem do botao
  // desabilitado, porque `Enter` sobre um controle focado nao passa pelo estado
  // visual. A de `pronto` cobre o caso em que a acao e disparada por codigo com
  // o video fora de `ready` — a tela nem oferece o botao ali, e pedir assim
  // mesmo emitiria uma URL para um video que ja nao e o atual.
  if (carregando.value || !pronto.value) {
    return
  }

  geracao += 1
  const desta = geracao

  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<ReproducaoEnvelope>(`/api/lessons/${props.aulaId}/video/playback`)

    if (desta !== geracao) {
      return
    }

    reproducao.value = resposta.data
  }
  catch (causa) {
    if (desta !== geracao) {
      return
    }

    // A reproducao anterior cai junto: um player com uma URL antiga sob uma
    // mensagem de erro mostraria as duas coisas ao mesmo tempo, e uma delas
    // estaria mentindo.
    reproducao.value = null
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    // So a geracao vigente desliga o carregamento. Uma vencida o desligaria em
    // nome de outra solicitacao, que continuaria em voo com o botao ja liberado.
    if (desta === geracao) {
      carregando.value = false
    }
  }
}
</script>

<template>
  <div
    v-if="pronto"
    data-conferencia
    class="flex flex-col gap-2"
  >
    <!--
      O player nasce aqui dentro, e nao numa tela separada: a aula ja esta na
      frente de quem produz, e mandá-la para outro endereco para assistir a
      trinta segundos de video devolveria a pessoa a uma arvore que ela teria de
      reabrir para publicar.
    -->
    <VideoReprodutor
      v-if="reproducao"
      :reproducao="reproducao"
      class="max-w-xl"
    />

    <!--
      `permitir-nova-tentativa` e explicito porque repetir aqui **faz** sentido
      mesmo nas negativas que normalmente nao se repete: a acao so aparece sobre
      a propria aula com video pronto, entao o que resta e falha de rede, storage
      indisponivel ou uma URL que ficou pelo caminho — os tres passam com uma
      segunda tentativa.
    -->
    <UiEstadoDeFalha
      v-else-if="erro"
      :erro="erro"
      titulo="Nao foi possivel preparar a reproducao"
      :permitir-nova-tentativa="true"
      @nova-tentativa="visualizar"
    />

    <UButton
      v-else
      type="button"
      color="neutral"
      variant="outline"
      icon="i-lucide-play"
      :loading="carregando"
      :disabled="carregando"
      :aria-busy="carregando"
      data-acao="visualizar-video"
      class="self-start"
      @click="visualizar"
    >
      {{ carregando ? 'Preparando a reproducao...' : 'Visualizar video' }}
    </UButton>
  </div>
</template>
