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
 * A ordem entre as tres ultimas e a ordem da decisao: o painel diz que ficou
 * pronto, a conferencia deixa ver o que ficou pronto, e a publicacao vem depois
 * de as duas terem acontecido.
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
    class="flex flex-col gap-2 py-3"
  >
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
      <!--
        A posicao exibida e a que veio na resposta, e nao o indice do laco: um
        contador local coincidiria hoje e mentiria no dia em que a consulta
        devolvesse um recorte.
      -->
      <span
        data-aula-posicao
        class="w-8 shrink-0 text-xs text-muted tabular-nums"
      >
        {{ aula.position }}.
      </span>

      <span class="grow text-sm font-medium">
        {{ aula.title }}
      </span>

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
  </li>
</template>
