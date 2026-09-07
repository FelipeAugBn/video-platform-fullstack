<script setup lang="ts">
import type { EstadoDoCurso } from '~/types/catalogo'

/**
 * O estado do curso, em palavra e em cor.
 *
 * O curso nasce em `draft` e passa a `available` sozinho, na primeira publicacao
 * de aula (RN-CUR-002). A interface **le** esse estado; ela nao o calcula e nao
 * o altera — quem decide e o backend.
 */
const props = defineProps<{ estado: EstadoDoCurso }>()

const ROTULOS = {
  draft: 'Rascunho',
  available: 'Disponivel',
} as const satisfies Record<EstadoDoCurso, string>

const CORES = {
  draft: 'neutral',
  available: 'success',
} as const satisfies Record<EstadoDoCurso, string>

const rotulo = computed(() => ROTULOS[props.estado])
const cor = computed(() => CORES[props.estado])
</script>

<template>
  <UBadge
    :color="cor"
    variant="subtle"
    size="sm"
    :data-estado-do-curso="estado"
  >
    {{ rotulo }}
  </UBadge>
</template>
