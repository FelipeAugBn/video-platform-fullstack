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
    class="overflow-hidden rounded-xl border border-default bg-default"
  >
    <div class="flex items-center gap-3 border-b border-default bg-muted px-4 py-3 sm:px-5">
      <span
        data-modulo-posicao
        class="grid size-7 shrink-0 place-items-center rounded-md bg-primary/10 text-xs font-semibold text-primary tabular-nums"
      >
        {{ modulo.position }}
      </span>

      <h3 class="min-w-0 text-base font-semibold text-highlighted">
        {{ modulo.title }}
      </h3>
    </div>

    <div class="px-4 py-3 sm:px-5">
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
        class="flex flex-col"
      >
        <LessonItemDeAulaPublicada
          v-for="aula in modulo.lessons"
          :key="aula.id"
          :aula="aula"
        />
      </ol>
    </div>
  </li>
</template>
