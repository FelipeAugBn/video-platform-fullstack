<script setup lang="ts">
import type { Curso } from '~/types/catalogo'

/**
 * A lista de cursos, **na ordem em que a API a devolveu**.
 *
 * O `v-for` percorre o array recebido, direto. Nao ha `sort`, nao ha filtro e
 * nao ha computed reordenando: a ordenacao e criacao decrescente com desempate
 * por identificador, decidida na consulta, e reordenar aqui produziria uma
 * sequencia que nao corresponde a nenhuma pagina do backend — a segunda pagina
 * viria de um recorte que a primeira contradisse (RN-ORD-002).
 */
/** `base` apenas atravessa: quem decide o destino e a pagina que lista. */
defineProps<{ cursos: Curso[], base: string }>()
</script>

<template>
  <ul
    data-lista="cursos"
    class="grid gap-4 sm:grid-cols-2"
  >
    <CourseCartaoDeCurso
      v-for="curso in cursos"
      :key="curso.id"
      :curso="curso"
      :base="base"
    />
  </ul>
</template>
