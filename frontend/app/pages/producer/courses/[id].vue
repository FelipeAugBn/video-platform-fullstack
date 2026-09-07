<script setup lang="ts">
import type { Aula, EstruturaDoCurso, EstruturaEnvelope, Modulo } from '~/types/catalogo'
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * A estrutura de um curso proprio (RF-EST-001, RF-EST-002, RF-EST-004).
 *
 * ## Uma leitura, nao duas
 *
 * A arvore ja traz o curso — `EstruturaDoCurso` e `Curso` mais `modules` —, entao
 * titulo, descricao, estado e data saem daqui. Uma segunda chamada a
 * `GET /api/courses/{course}` devolveria os mesmos campos e abriria a chance de
 * as duas respostas discordarem entre si, com a tela mostrando um estado do
 * curso que a arvore desmente.
 *
 * ## Nada e reordenado
 *
 * Modulos e aulas sao renderizados na sequencia exata dos arrays recebidos,
 * ordenados por `position` na consulta. Depois de cada criacao a estrutura
 * inteira e relida: o item novo aparece no fim porque a resposta o traz la
 * (RN-ORD-002). Nada e inserido localmente, e nenhuma posicao e calculada aqui —
 * a tela nao tem como saber qual a proxima livre sem perguntar.
 *
 * ## A criacao e a releitura sao dois desfechos
 *
 * Como na listagem: o formulario sabe que o `201` chegou, a pagina sabe que o
 * `GET` falhou, e a confirmacao vive fora da regiao que alterna entre
 * carregando, falha e conteudo. Repetir a nova tentativa repete a leitura, nunca
 * a criacao.
 */
definePageMeta({ middleware: 'auth' })

const rota = useRoute()
const { requisitar } = useApi()

const cursoId = computed(() => String(rota.params.id))

const estrutura = ref<EstruturaDoCurso | null>(null)
const carregando = ref(true)
const erro = ref<ErroDeApi | null>(null)
const confirmacao = ref<{ titulo: string, descricao: string } | null>(null)

async function carregar(): Promise<void> {
  carregando.value = true
  erro.value = null

  try {
    const resposta = await requisitar<EstruturaEnvelope>(`/api/courses/${cursoId.value}/structure`)

    estrutura.value = resposta.data
  }
  catch (causa) {
    erro.value = ErroDeApi.de(causa)
  }
  finally {
    carregando.value = false
  }
}

async function aoCriarModulo(modulo: Modulo): Promise<void> {
  confirmacao.value = {
    titulo: 'Modulo criado',
    descricao: `O modulo "${modulo.title}" foi criado.`,
  }

  await carregar()
}

/**
 * A aula foi publicada, e a arvore inteira e relida.
 *
 * A releitura nao existe para descobrir `published_at` — ele veio na resposta da
 * publicacao. Ela existe porque a **primeira** publicacao do curso o torna
 * `available` na mesma transacao, e esse estado esta no cabecalho da pagina, nao
 * na aula. Sem relê-la, o curso continuaria escrito como rascunho depois de
 * deixar de ser um.
 */
async function aoPublicar(aula: Aula): Promise<void> {
  confirmacao.value = {
    titulo: 'Aula publicada',
    descricao: `A aula "${aula.title}" foi publicada.`,
  }

  await carregar()
}

async function aoCriarAula(aula: Aula): Promise<void> {
  confirmacao.value = {
    titulo: 'Aula criada',
    descricao: `A aula "${aula.title}" foi criada como rascunho, ainda sem video.`,
  }

  await carregar()
}

onMounted(() => {
  void carregar()
})
</script>

<template>
  <UiPagina>
    <!--
      Fora da regiao que alterna de estado: quando a leitura falha, o caminho de
      volta continua disponivel.
    -->
    <UiTrilha
      rotulo="Meus cursos"
      destino="/producer/courses"
      acao="voltar-para-cursos"
      :atual="estrutura?.title"
    />

    <UiEstadoDeSucesso
      v-if="confirmacao"
      :titulo="confirmacao.titulo"
      :descricao="confirmacao.descricao"
    />

    <UiEstadoCarregando
      v-if="carregando"
      rotulo="Carregando a estrutura do curso..."
    />

    <UiEstadoDeFalha
      v-else-if="erro"
      :erro="erro"
      titulo="Nao foi possivel carregar o curso"
      @nova-tentativa="carregar"
    />

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

        <template #apoio>
          <p class="text-xs text-muted">
            Criado em
            <time :datetime="estrutura.created_at">{{ formatarDataHora(estrutura.created_at) }}</time>
          </p>
        </template>
      </UiCabecalhoDePagina>

      <!--
        A descricao da secao e o unico lugar em que a jornada do video e contada
        por extenso. Ela e texto fixo: nao consulta estado nenhum e nao promete
        prazo, so nomeia a ordem das etapas que a tela mostra logo abaixo.
      -->
      <UiSecaoDaTela
        id="titulo-dos-modulos"
        titulo="Modulos e aulas"
        descricao="Os modulos organizam as aulas do curso. Depois do processamento, voce pode conferir o video e publicar a aula; a conferencia e opcional."
      >
        <div
          v-if="estrutura.modules.length === 0"
          data-vazio="modulos"
        >
          <UiEstadoVazio
            titulo="Este curso ainda nao tem modulos"
            descricao="Crie o primeiro modulo pelo formulario desta tela para comecar a organizar as aulas."
          />
        </div>

        <ol
          v-else
          data-lista="modulos"
          class="flex flex-col gap-5"
        >
          <ModuleCartaoDeModulo
            v-for="modulo in estrutura.modules"
            :key="modulo.id"
            :modulo="modulo"
            @criada="aoCriarAula"
            @publicada="aoPublicar"
          />
        </ol>

        <ModuleFormularioDeModulo
          :curso-id="cursoId"
          @criado="aoCriarModulo"
        />
      </UiSecaoDaTela>
    </template>
  </UiPagina>
</template>
