<script setup lang="ts">
import type { EstruturaDoConsumidor, EstruturaDoConsumidorEnvelope } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * A arvore de um curso concedido (RF-CONS-003, RN-AUT-004, AC-CONS-004).
 *
 * ## Uma leitura, e nada de filtro
 *
 * `EstruturaDoConsumidor` e `Curso` mais `modules`, entao titulo, descricao,
 * estado e data saem da mesma resposta que traz a arvore. Modulos e aulas sao
 * renderizados na sequencia exata dos arrays recebidos.
 *
 * A ausencia de rascunhos e responsabilidade da **API**: ela ja devolve apenas
 * aulas publicadas, e o tipo prova isso — `AulaDoConsumidor` nao tem
 * `video_state`, e `published_at` nunca e nula. Uma verificacao de publicacao
 * escrita aqui seria uma segunda definicao da mesma regra, livre para discordar
 * da primeira (RF-UI-017).
 *
 * ## O curso pode estar em rascunho
 *
 * A listagem so traz cursos em `available`, mas um curso concedido ainda sem
 * nenhuma aula publicada continua alcancavel pelo endereco direto — e responde
 * `200` com modulos vazios. Por isso o estado do curso aparece no cabecalho: ele
 * explica uma arvore sem aula sem que a tela precise adivinhar o motivo.
 *
 * ## O caminho de volta sobrevive a falha
 *
 * O link para o catalogo fica **fora** da regiao que alterna entre carregando,
 * negativa e conteudo. Dentro dela, um `404` deixaria a pessoa numa tela sem
 * saida, dependendo do botao do navegador para escapar.
 */
definePageMeta({ middleware: 'auth' })

const rota = useRoute()
const { requisitar } = useApi()

const cursoId = computed(() => String(rota.params.id))

const estrutura = ref<EstruturaDoConsumidor | null>(null)
const carregando = ref(true)
const erro = ref<ErroDeApi | null>(null)

async function carregar(): Promise<void> {
  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<EstruturaDoConsumidorEnvelope>(
      `/api/catalog/courses/${cursoId.value}`,
    )

    estrutura.value = resposta.data
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    carregando.value = false
  }
}

/*
| O destino do foco depois de uma nova tentativa que falhou.
|
| Repetir a leitura troca a negativa pelo carregamento e depois de volta: o
| elemento que recebeu o clique deixa de existir no meio do caminho, e o foco cai
| no corpo do documento. Quem navega por teclado ficaria sem posicao e sem sinal
| de que a tentativa terminou.
*/
const regiaoDaNegativa = ref<HTMLElement | null>(null)

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
      :atual="estrutura?.title"
    />

    <UiEstadoCarregando
      v-if="carregando"
      rotulo="Carregando o curso..."
    />

    <!--
      `tabindex="-1"` para a regiao poder receber foco por codigo sem entrar na
      ordem de tabulacao: ela e destino depois de uma tentativa que falhou.
    -->
    <div
      v-else-if="erro"
      ref="regiaoDaNegativa"
      tabindex="-1"
      data-regiao="negativa"
    >
      <UiNegativaPublica
        :erro="erro"
        titulo-da-falha="Nao foi possivel carregar o curso"
        @nova-tentativa="tentarDeNovo"
      />
    </div>

    <template v-else-if="estrutura">
      <UiCabecalhoDePagina
        data-curso
        :data-curso-id="estrutura.id"
        :titulo="estrutura.title"
        :descricao="estrutura.description"
      >
        <template #etiquetas>
          <CourseEtiquetaDeEstado :estado="estrutura.state" />
        </template>
      </UiCabecalhoDePagina>

      <UiSecaoDaTela
        id="titulo-dos-modulos"
        titulo="Conteudo do curso"
        descricao="Os modulos aparecem na ordem definida pelo curso. Abra uma aula para assistir."
      >
        <div
          v-if="estrutura.modules.length === 0"
          data-vazio="modulos"
        >
          <UiEstadoVazio
            titulo="Nenhum modulo disponivel neste curso"
            descricao="Os modulos aparecem aqui assim que tiverem aulas liberadas."
          />
        </div>

        <ol
          v-else
          data-lista="modulos"
          class="flex flex-col gap-5"
        >
          <ModuleCartaoDeModuloPublicado
            v-for="modulo in estrutura.modules"
            :key="modulo.id"
            :modulo="modulo"
          />
        </ol>
      </UiSecaoDaTela>
    </template>
  </UiPagina>
</template>
