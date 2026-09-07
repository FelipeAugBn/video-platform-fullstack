<script setup lang="ts">
import type { Curso, CursosPaginados, PaginacaoMeta } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Os cursos do produtor: listagem paginada e criacao (RF-CUR-001, RF-CUR-002).
 *
 * ## A ordem e do backend
 *
 * A pagina guarda o array como veio e o entrega ao componente de lista. Nao ha
 * ordenacao, filtro ou recalculo em nenhum ponto: o recorte por proprietario e a
 * ordenacao acontecem na consulta, e `meta` conta o conjunto autorizado. Uma
 * ordenacao local produziria uma sequencia que a segunda pagina contradiria.
 *
 * ## Criacao e releitura sao dois desfechos
 *
 * O formulario faz o `POST` e avisa. A pagina faz o `GET` e mostra o resultado.
 * Quando o `201` chega e a releitura falha, a confirmacao permanece — ela fica
 * **fora** da regiao que alterna entre carregando, falha, vazio e conteudo — e o
 * que se oferece repetir e a leitura, nunca a criacao. Um unico estado para as
 * duas coisas anunciaria um fracasso que nao houve e convidaria a criar o mesmo
 * curso outra vez.
 */
definePageMeta({ middleware: 'auth' })

const { requisitar } = useApi()

const cursos = ref<Curso[]>([])
const paginacao = ref<PaginacaoMeta | null>(null)

/*
| Comeca em `true` porque a primeira busca ja esta a caminho. Em `false`, o
| primeiro quadro renderizaria a lista vazia — "voce ainda nao tem cursos" — para
| quem tem cursos, que e exatamente o que RF-UI-001 proibe.
*/
const carregando = ref(true)
const erro = ref<ErroDeApi | null>(null)
const confirmacao = ref<{ titulo: string, descricao: string } | null>(null)
const pagina = ref(1)

/*
| A geracao da leitura mais recente.
|
| Duas leituras podem estar em voo ao mesmo tempo — a inicial ainda pendente
| quando a criacao dispara a releitura, ou dois cliques seguidos na paginacao —,
| e nada garante que voltem na ordem em que sairam. Sem este contador, a resposta
| antiga chegaria por ultimo e sobrescreveria a lista nova com o conteudo de
| antes: a tela mostraria um curso a menos logo depois de confirmar que ele foi
| criado.
|
| Um numero simples, e nao um `AbortController`: cancelar a requisicao encerraria
| o pedido, mas nao resolve o problema aqui, que e **quem pode escrever no estado**
| quando a resposta enfim chega. E um `let` comum, e nao um `ref`, porque nada na
| tela depende dele.
*/
let leituraMaisRecente = 0

async function carregar(): Promise<void> {
  const leitura = ++leituraMaisRecente

  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<CursosPaginados>('/api/courses', {
      query: {
        page: pagina.value,
        /*
        | O tamanho **efetivo**, o que a API confirmou depois de aplicar o teto
        | de 50 — e nao o que a tela pediu. Pedindo o proprio numero de volta, a
        | navegacao mantem o mesmo recorte; sem isso, a segunda pagina voltaria
        | ao padrao e itens seriam pulados na virada.
        |
        | Ausente na primeira chamada: nao ha valor confirmado ainda, e inventar
        | um sobreporia o padrao do backend.
        */
        per_page: paginacao.value?.per_page,
      },
    })

    // Uma leitura ultrapassada nao escreve nada. Ela nao esta errada — era
    // verdade quando saiu —, apenas deixou de ser a resposta que a tela espera.
    if (leitura !== leituraMaisRecente) {
      return
    }

    cursos.value = resposta.data
    paginacao.value = resposta.meta

    // A pagina corrente vem da resposta: se o conjunto encolheu e a pedida nao
    // existe mais, quem sabe onde a navegacao parou e o backend.
    pagina.value = resposta.meta.current_page
  }
  catch (causa) {
    // A falha antiga cala junto com o sucesso antigo. Anunciada, ela trocaria
    // uma lista recem-carregada por um estado de erro que nao descreve mais
    // nada — e ofereceria repetir uma leitura que ja foi superada.
    if (leitura !== leituraMaisRecente) {
      return
    }

    erro.value = ErroDeApi.de(causa)
  }
  finally {
    // O `finally` corre inclusive no `return` acima. Sem esta guarda, a leitura
    // antiga apagaria o carregamento da leitura nova, e a tela ficaria vazia
    // enquanto a resposta que importa ainda estivesse a caminho.
    if (leitura === leituraMaisRecente) {
      carregando.value = false
    }
  }
}

function irPara(destino: number): void {
  // A confirmacao some ao trocar de pagina: ela fala de uma criacao que aconteceu
  // no contexto anterior, e permanecer aqui a colaria numa lista que nao e a dela.
  confirmacao.value = null
  pagina.value = destino

  void carregar()
}

async function aoCriar(curso: Curso): Promise<void> {
  confirmacao.value = {
    titulo: 'Curso criado',
    descricao: `O curso "${curso.title}" foi criado.`,
  }

  // Volta a primeira pagina e relê: o curso novo entra na sequencia decidida
  // pela consulta, e nao numa posicao que a tela tenha escolhido para ele.
  pagina.value = 1

  await carregar()
}

onMounted(() => {
  void carregar()
})
</script>

<template>
  <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
    <header>
      <h1 class="text-2xl font-semibold">
        Meus cursos
      </h1>
      <p class="text-sm text-muted">
        Crie um curso e abra o detalhe para montar modulos e aulas.
      </p>
    </header>

    <UiEstadoDeSucesso
      v-if="confirmacao"
      :titulo="confirmacao.titulo"
      :descricao="confirmacao.descricao"
    />

    <CourseFormularioDeCurso @criado="aoCriar" />

    <section
      aria-labelledby="titulo-da-lista"
      class="flex flex-col gap-4"
    >
      <h2
        id="titulo-da-lista"
        class="text-lg font-semibold"
      >
        Cursos
      </h2>

      <UiEstadoCarregando
        v-if="carregando"
        rotulo="Carregando seus cursos..."
      />

      <!--
        A nova tentativa repete **somente** a leitura: `carregar` nao cria nada, e
        a pagina pedida continua sendo a que estava em `pagina`.
      -->
      <UiEstadoDeFalha
        v-else-if="erro"
        :erro="erro"
        titulo="Nao foi possivel carregar seus cursos"
        @nova-tentativa="carregar"
      />

      <div
        v-else-if="cursos.length === 0"
        data-vazio="cursos"
      >
        <UiEstadoVazio
          titulo="Voce ainda nao tem cursos"
          descricao="Crie o primeiro curso pelo formulario acima para comecar a montar a estrutura."
        />
      </div>

      <template v-else>
        <CourseListaDeCursos :cursos="cursos" />

        <UiNavegacaoDePaginas
          v-if="paginacao && paginacao.last_page > 1"
          :atual="paginacao.current_page"
          :ultima="paginacao.last_page"
          @ir="irPara"
        />
      </template>
    </section>
  </main>
</template>
