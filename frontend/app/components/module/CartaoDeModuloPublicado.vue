<script setup lang="ts">
import type { ModuloComAulasPublicadas } from '~/types/catalogo'

/**
 * Um modulo na arvore do consumidor (RF-CONS-003, RF-EST-002, AC-CONS-004).
 *
 * As aulas saem na ordem exata de `lessons`, sem `sort` e sem filtro. O recorte
 * por publicacao ja aconteceu na consulta: repeti-lo aqui seria duplicar regra
 * de negocio no frontend (RF-UI-017) e, pior, criar uma segunda definicao de
 * "publicada" que poderia discordar da do backend.
 *
 * ## O modulo sem aula continua visivel
 *
 * `lessons: []` significa que o modulo nao tem **aula publicada** — e nao que
 * ele nao existe ou esta vazio. Esconde-lo apagaria a organizacao do curso, que
 * o consumidor tem direito de ver; e afirmar "nenhuma aula" diria algo sobre o
 * conteudo em rascunho que a interface nao pode afirmar. Por isso o texto fala
 * de disponibilidade, nao de existencia.
 */
defineProps<{ modulo: ModuloComAulasPublicadas }>()
</script>

<template>
  <li
    data-modulo
    :data-modulo-id="modulo.id"
    class="flex flex-col gap-3 rounded-lg border border-default p-4"
  >
    <div class="flex items-baseline gap-3">
      <span
        data-modulo-posicao
        class="text-xs text-muted tabular-nums"
      >
        {{ modulo.position }}.
      </span>

      <h3 class="text-base font-semibold">
        {{ modulo.title }}
      </h3>
    </div>

    <div
      v-if="modulo.lessons.length === 0"
      data-vazio="aulas"
    >
      <UiEstadoVazio
        titulo="Nenhuma aula disponivel neste modulo"
        descricao="As aulas aparecem aqui assim que forem liberadas."
      />
    </div>

    <ol
      v-else
      data-lista="aulas"
      class="divide-y divide-default"
    >
      <LessonItemDeAulaPublicada
        v-for="aula in modulo.lessons"
        :key="aula.id"
        :aula="aula"
      />
    </ol>
  </li>
</template>
