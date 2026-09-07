<script setup lang="ts">
import type { Aula, EstadoDoVideo } from '~/types/video'

/**
 * Uma aula na estrutura do produtor, com o ciclo do video inteiro (RF-EST-004).
 *
 * Ela compoe quatro coisas e nao decide nenhuma: o resumo da aula, o painel que
 * cuida do envio e do acompanhamento, a conferencia do video pronto e a acao de
 * publicar. A divisao segue o que cada parte precisa saber — transferir bytes,
 * perguntar o estado, assistir ao resultado e mudar a publicacao sao quatro
 * assuntos que so se encontram aqui.
 *
 * A ordem entre as tres ultimas e **visual**, e nao uma sequencia obrigatoria.
 * Conferencia e publicacao aparecem juntas, sob a mesma condicao — o video em
 * `ready` —, e a conferencia vem antes apenas porque assistir depois de publicar
 * chegaria tarde demais para ajudar a decidir. Publicar nao depende de ter
 * reproduzido o video.
 *
 * `published_at` nulo **e** o rascunho: nao existe um estado `draft` proprio na
 * aula, e a arvore do produtor inclui rascunhos de proposito — a do consumidor
 * nao os traz.
 *
 * O estado do video vive num `ref` proprio, alimentado pelo painel. A arvore da
 * o valor inicial, e o painel o corrige assim que observa algo mais recente: a
 * etiqueta e o botao de publicar precisam ler o **mesmo** estado, e um deles
 * lendo o retrato antigo ofereceria publicar um video que ja falhou.
 */
const props = defineProps<{ aula: Aula }>()

const emit = defineEmits<{ publicada: [Aula] }>()

const publicada = computed(() => props.aula.published_at !== null)

const estadoDoVideo = ref<EstadoDoVideo | null>(props.aula.video_state)

/**
 * O estado que a recusa de uma publicacao revelou, a caminho do painel.
 *
 * Ele nao e aplicado aqui: quem cuida do video e o painel, e e la que a leitura
 * nova substitui a anterior e reinicia o acompanhamento. Escrever direto em
 * `estadoDoVideo` deixaria a etiqueta certa e o painel errado — duas verdades
 * sobre o mesmo video, com o proximo ciclo de consulta desfazendo uma delas.
 *
 * `undefined` enquanto nada foi revelado; `null` e a afirmacao de que nao ha
 * tentativa.
 */
const estadoRevelado = ref<EstadoDoVideo | null | undefined>(undefined)
</script>

<template>
  <li
    data-aula
    :data-aula-id="aula.id"
    class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0"
  >
    <div class="flex gap-3">
      <!--
        A posicao exibida e a que veio na resposta, e nao o indice do laco: um
        contador local coincidiria hoje e mentiria no dia em que a consulta
        devolvesse um recorte.
      -->
      <span
        data-aula-posicao
        class="w-8 shrink-0 pt-0.5 text-xs text-muted tabular-nums"
      >
        {{ aula.position }}.
      </span>

      <!--
        Titulo e estados dividem a mesma coluna a direita da numeracao. Em telas
        estreitas eles empilham **dentro** dela, e nao de volta na margem: as
        etiquetas continuam alinhadas com o titulo da aula a que pertencem.
      -->
      <div class="flex min-w-0 grow flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
        <h4 class="min-w-0 text-sm font-medium text-highlighted">
          {{ aula.title }}
        </h4>

        <!--
          Os dois estados ficam juntos e nesta ordem: publicacao primeiro, porque
          e o desfecho; video depois, porque e a condicao dele.
        -->
        <div class="flex flex-wrap items-center gap-1.5">
          <UBadge
            :color="publicada ? 'success' : 'neutral'"
            variant="subtle"
            size="sm"
            :data-publicacao="publicada ? 'publicada' : 'rascunho'"
          >
            {{ publicada ? 'Publicada' : 'Rascunho' }}
          </UBadge>

          <LessonEtiquetaDeVideo :estado="estadoDoVideo" />
        </div>
      </div>
    </div>

    <!--
      Envio, conferencia e publicacao ficam recuados sob a aula e ligados por uma
      regua: o recuo diz de qual aula eles sao, e a ordem em que aparecem e so
      visual — as duas ultimas surgem juntas com o video em `ready`, e publicar
      nao exige ter passado pela conferencia. Cada bloco continua decidindo
      sozinho se aparece.
    -->
    <div class="ms-3 flex flex-col gap-3 border-s-2 border-default ps-3 sm:ms-8 sm:ps-4">
      <VideoPainelDoVideo
        :aula-id="aula.id"
        :estado-inicial="aula.video_state"
        :estado-informado="estadoRevelado"
        @estado="estadoDoVideo = $event"
      />

      <VideoConferenciaDoVideo
        :aula-id="aula.id"
        :estado-do-video="estadoDoVideo"
      />

      <LessonAcaoDePublicar
        :aula="aula"
        :estado-do-video="estadoDoVideo"
        @publicada="emit('publicada', $event)"
        @conflito="estadoRevelado = $event"
      />
    </div>
  </li>
</template>
