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

const emit = defineEmits<{ criada: [Aula], publicada: [Aula] }>()
</script>

<template>
  <!--
    O modulo e uma faixa com cabecalho proprio, e nao mais um cartao dentro do
    cartao da pagina: as aulas ficam em linhas separadas por regua, e o unico
    contorno da regiao e o do proprio modulo. Empilhar bordas aqui e o que torna
    a estrutura de um curso ilegivel.
  -->
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

    <div class="px-4 py-4 sm:px-5">
      <div
        v-if="modulo.lessons.length === 0"
        data-vazio="aulas"
      >
        <UiEstadoVazio
          titulo="Nenhuma aula neste modulo"
          descricao="Crie a primeira aula no campo abaixo para comecar a enviar o video."
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
          @publicada="emit('publicada', $event)"
        />
      </ol>
    </div>

    <div class="border-t border-default bg-muted px-4 py-4 sm:px-5">
      <LessonFormularioDeAula
        :modulo-id="modulo.id"
        @criada="emit('criada', $event)"
      />
    </div>
  </li>
</template>
