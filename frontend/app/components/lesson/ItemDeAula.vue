<script setup lang="ts">
import type { Aula } from '~/types/catalogo'

/**
 * Uma aula na estrutura do produtor (RF-EST-004).
 *
 * Quatro informacoes, e as tres ultimas sao o motivo da tela existir: a posicao
 * que o backend atribuiu, se a aula esta publicada e em que ponto o video esta.
 *
 * `published_at` nulo **e** o rascunho: nao existe um estado `draft` proprio na
 * aula, e a arvore do produtor inclui rascunhos de proposito — a do consumidor
 * nao os traz.
 */
const props = defineProps<{ aula: Aula }>()

const publicada = computed(() => props.aula.published_at !== null)
</script>

<template>
  <li
    data-aula
    :data-aula-id="aula.id"
    class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
  >
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

    <LessonEtiquetaDeVideo :estado="aula.video_state" />
  </li>
</template>
