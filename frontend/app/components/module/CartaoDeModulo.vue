<script setup lang="ts">
import type { Aula, ModuloComAulas } from '~/types/catalogo'

/**
 * Um modulo com suas aulas (RF-EST-001, RF-EST-002).
 *
 * As aulas saem na ordem exata de `lessons`. Sem `sort`, sem indice local, sem
 * insercao otimista do que acabou de ser criado: a sequencia e a que a consulta
 * devolveu, ordenada por `position` no banco.
 *
 * O modulo sem aula tem estado proprio. Uma lista simplesmente vazia deixaria em
 * aberto se o modulo nao tem aulas ou se elas nao chegaram.
 */
defineProps<{ modulo: ModuloComAulas }>()

const emit = defineEmits<{ criada: [Aula] }>()
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
        titulo="Nenhuma aula neste modulo"
        descricao="Crie a primeira aula pelo campo abaixo."
      />
    </div>

    <ol
      v-else
      data-lista="aulas"
      class="divide-y divide-default"
    >
      <LessonItemDeAula
        v-for="aula in modulo.lessons"
        :key="aula.id"
        :aula="aula"
      />
    </ol>

    <LessonFormularioDeAula
      :modulo-id="modulo.id"
      @criada="emit('criada', $event)"
    />
  </li>
</template>
