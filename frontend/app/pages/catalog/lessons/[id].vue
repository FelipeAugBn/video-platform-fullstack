<script setup lang="ts">
import type { Reproducao, ReproducaoEnvelope } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * A reproducao de uma aula (RF-CONS-004, RF-PLB-005 a 008; AC-CONS-001 a 003).
 *
 * ## Uma unica leitura, e ela decide tudo
 *
 * `GET /api/lessons/{lesson}/playback` faz as quatro verificacoes — autenticado,
 * concessao, aula publicada, video `ready` — e so entao assina a URL. A tela nao
 * refaz nenhuma delas: ela pede, e mostra o que voltou. Um `409` aqui e uma
 * afirmacao do backend sobre disponibilidade, nao uma conclusao da interface.
 *
 * ## A pagina se sustenta sozinha
 *
 * Nada aqui depende de ter passado pela arvore antes: o identificador vem do
 * endereco, e o cabecalho nao exibe titulo de aula — o contrato de reproducao
 * traz tres campos, e nenhum deles e o titulo. Isso e proposital, e nao uma
 * limitacao contornavel: carregar o titulo de outro lugar exigiria uma segunda
 * requisicao cujo desfecho negativo teria de ser reconciliado com o desta, e
 * duas negativas diferentes na mesma tela e exatamente o que RF-PLB-008 evita.
 *
 * Por consequencia, o cabecalho e **identico** em todos os desfechos — inclusive
 * entre `403` e `404`, onde qualquer texto variavel reabriria a distincao que a
 * negativa publica fecha.
 *
 * ## Sem renovacao automatica
 *
 * A URL vale cinco minutos e a tela diz ate quando. Renova-la sozinha exigiria
 * um segundo pedido no meio da reproducao e um ciclo de vida proprio para
 * mante-lo; recarregar a pagina resolve o mesmo caso, e a limitacao esta
 * assumida no plano (§14.2).
 */
definePageMeta({ middleware: 'auth' })

const rota = useRoute()
const { requisitar } = useApi()

const aulaId = computed(() => String(rota.params.id))

const reproducao = ref<Reproducao | null>(null)
const carregando = ref(true)
const erro = ref<ErroDeApi | null>(null)

/*
| O destino do foco depois de uma nova tentativa que falhou.
|
| Repetir a leitura troca a negativa pelo carregamento e depois de volta: o botao
| que recebeu o clique deixa de existir no meio do caminho, e o foco cai no corpo
| do documento. Quem navega por teclado ficaria sem posicao e sem qualquer sinal
| de que a tentativa terminou.
*/
const regiaoDaNegativa = ref<HTMLElement | null>(null)

async function carregar(): Promise<void> {
  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<ReproducaoEnvelope>(`/api/lessons/${aulaId.value}/playback`)

    reproducao.value = resposta.data
  }
  catch (causa) {
    // A reproducao anterior e descartada junto: manter um `<video>` tocando com
    // uma URL antiga sob uma mensagem de erro mostraria as duas coisas ao mesmo
    // tempo, e uma delas estaria mentindo.
    reproducao.value = null
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    carregando.value = false
  }
}

async function tentarDeNovo(): Promise<void> {
  await carregar()

  if (erro.value === null) {
    return
  }

  // Depois do quadro em que a negativa volta a existir, e nao antes: um elemento
  // que ainda nao foi renderizado nao aceita foco.
  await nextTick()
  regiaoDaNegativa.value?.focus()
}

onMounted(() => {
  void carregar()
})
</script>

<template>
  <UiPagina>
    <UiTrilha
      rotulo="Catalogo"
      destino="/catalog"
      acao="voltar-para-catalogo"
      atual="Aula"
    />

    <UiCabecalhoDePagina titulo="Aula" />

    <UiEstadoCarregando
      v-if="carregando"
      rotulo="Preparando a reproducao..."
      :linhas="2"
    />

    <!--
      `tabindex="-1"` para a regiao poder receber foco por codigo sem entrar na
      ordem de tabulacao: ela e destino depois de uma tentativa que falhou, e nao
      uma parada normal do teclado.
    -->
    <div
      v-else-if="erro"
      ref="regiaoDaNegativa"
      tabindex="-1"
      data-regiao="negativa"
    >
      <UiNegativaPublica
        :erro="erro"
        titulo-da-falha="Nao foi possivel preparar a reproducao"
        @nova-tentativa="tentarDeNovo"
      />
    </div>

    <VideoReprodutor
      v-else-if="reproducao"
      :reproducao="reproducao"
    />
  </UiPagina>
</template>
