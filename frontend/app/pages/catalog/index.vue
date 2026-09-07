<script setup lang="ts">
import type { Curso, CursosPaginados, PaginacaoMeta } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * O catalogo do consumidor (RF-CONS-001, RF-CONS-002, RF-CONS-005).
 *
 * ## A tela nao decide o que aparece
 *
 * As duas condicoes — ter concessao e estar em `available` — vivem **dentro da
 * consulta**, e por isso `meta.total` conta apenas o conjunto autorizado.
 * Refiltrar aqui nao acrescentaria protecao nenhuma (o backend ja decidiu) e
 * estragaria a contagem: a paginacao passaria a prometer paginas que a tela
 * esvaziaria depois de carregar. Nao ha `sort`, `filter` nem recalculo de
 * disponibilidade em ponto algum deste arquivo (RF-UI-017).
 *
 * ## Lista vazia nao e uma afirmacao sobre o mundo
 *
 * Nenhum curso concedido e um estado legitimo, e o backend responde `200` com
 * `data: []` — nao um erro (RF-UI-002). O texto fala do **acesso**, e nao do
 * acervo: dizer "ainda nao ha cursos na plataforma" seria contar a quem nao tem
 * concessao o que existe fora dela, que e a mesma revelacao que RN-PROP-005
 * fecha na API.
 */
definePageMeta({ middleware: 'auth' })

const { requisitar } = useApi()

const cursos = ref<Curso[]>([])
const paginacao = ref<PaginacaoMeta | null>(null)

// Comeca em `true` porque a primeira leitura ja esta a caminho: em `false`, o
// primeiro quadro anunciaria "nenhum curso disponivel" a quem tem cursos.
const carregando = ref(true)
const erro = ref<ErroDeApi | null>(null)
const pagina = ref(1)

/*
| A geracao da leitura mais recente, como na listagem do produtor.
|
| Dois cliques seguidos na paginacao deixam duas leituras em voo, e nada garante
| que voltem na ordem em que sairam. Sem o contador, a resposta antiga chegaria
| por ultimo e recolocaria a pagina anterior na tela — com a numeracao dizendo
| outra coisa.
*/
let leituraMaisRecente = 0

async function carregar(): Promise<void> {
  const leitura = ++leituraMaisRecente

  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<CursosPaginados>('/api/catalog/courses', {
      query: {
        page: pagina.value,
        // O tamanho **efetivo** confirmado pela API, e nao o que a tela pediu.
        // Ausente na primeira chamada, para nao sobrepor o padrao do backend.
        per_page: paginacao.value?.per_page,
      },
    })

    if (leitura !== leituraMaisRecente) {
      return
    }

    cursos.value = resposta.data
    paginacao.value = resposta.meta

    // Quem sabe onde a navegacao parou e a resposta, e nao o clique.
    pagina.value = resposta.meta.current_page
  }
  catch (causa) {
    if (leitura !== leituraMaisRecente) {
      return
    }

    erro.value = ErroDeApi.de(causa)
  }
  finally {
    if (leitura === leituraMaisRecente) {
      carregando.value = false
    }
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

function irPara(destino: number): void {
  pagina.value = destino

  void carregar()
}

onMounted(() => {
  void carregar()
})
</script>

<template>
  <UiPagina>
    <UiCabecalhoDePagina
      titulo="Catalogo"
      descricao="Estes sao os cursos liberados para a sua conta. Abra um curso para ver os modulos e comecar a assistir."
    />

    <UiSecaoDaTela
      id="titulo-do-catalogo"
      titulo="Cursos disponiveis"
    >
      <UiEstadoCarregando
        v-if="carregando"
        rotulo="Carregando o catalogo..."
      />

      <!--
        A nova tentativa repete **somente** a leitura, e so aparece onde repetir
        muda alguma coisa: quem decide isso e a negativa publica.
      -->
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
          titulo-da-falha="Nao foi possivel carregar o catalogo"
          @nova-tentativa="tentarDeNovo"
        />
      </div>

      <div
        v-else-if="cursos.length === 0"
        data-vazio="catalogo"
      >
        <UiEstadoVazio
          titulo="Nenhum curso disponivel para este acesso"
          descricao="Quando um curso for liberado para a sua conta, ele aparece aqui."
        />
      </div>

      <template v-else>
        <CourseListaDeCursos
          :cursos="cursos"
          base="/catalog/courses"
        />

        <UiNavegacaoDePaginas
          v-if="paginacao && paginacao.last_page > 1"
          :atual="paginacao.current_page"
          :ultima="paginacao.last_page"
          @ir="irPara"
        />
      </template>
    </UiSecaoDaTela>
  </UiPagina>
</template>
