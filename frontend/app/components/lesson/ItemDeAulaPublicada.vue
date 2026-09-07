<script setup lang="ts">
import type { AulaDoConsumidor } from '~/types/catalogo'

/**
 * Uma aula na arvore do consumidor (RF-CONS-003, AC-CONS-004).
 *
 * Nao e o `LessonItemDeAula` do produtor com menos coisas: e outro item. Aquele
 * compoe o envio do video, o acompanhamento do processamento e a publicacao, e
 * le `video_state` — um campo que **nao existe** em `AulaDoConsumidor`. O tipo e
 * o que garante isso: nao ha como esta tela ler o estado do video, porque a
 * resposta nunca o traz.
 *
 * Nao ha etiqueta de rascunho tampouco. Toda aula que chega aqui esta publicada
 * — `published_at` nao e nula no contrato do consumidor —, e um rotulo
 * "Publicada" repetido em todas as linhas nao distinguiria nada.
 *
 * O titulo **e** o link para a reproducao, pelo mesmo motivo do cartao de curso:
 * um link cujo texto nomeia o destino ja se anuncia sozinho.
 */
defineProps<{ aula: AulaDoConsumidor }>()
</script>

<template>
  <li
    data-aula
    :data-aula-id="aula.id"
    class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3"
  >
    <!--
      A posicao exibida e a que veio na resposta, e nao o indice do laco: a
      arvore do consumidor omite rascunhos, entao as posicoes podem ter buracos —
      um contador local renumeraria as aulas e contradiria o que o produtor ve.
    -->
    <span
      data-aula-posicao
      class="w-8 shrink-0 text-xs text-muted tabular-nums"
    >
      {{ aula.position }}.
    </span>

    <span class="grow text-sm font-medium">
      <NuxtLink
        :to="`/catalog/lessons/${aula.id}`"
        data-acao="abrir-aula"
        class="underline-offset-4 hover:underline focus-visible:underline"
      >
        {{ aula.title }}
      </NuxtLink>
    </span>

    <p class="text-xs text-muted">
      Publicada em
      <time :datetime="aula.published_at">{{ formatarDataHora(aula.published_at) }}</time>
    </p>
  </li>
</template>
