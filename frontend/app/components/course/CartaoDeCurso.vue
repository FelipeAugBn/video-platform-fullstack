<script setup lang="ts">
import type { Curso } from '~/types/catalogo'

/**
 * Um curso na listagem do produtor (RF-CUR-002).
 *
 * Os cinco campos que o contrato promete estao aqui — titulo, descricao, estado
 * e data de criacao, mais o identificador que vira destino do link. O
 * proprietario nao aparece: a listagem so devolve cursos proprios, e repetir
 * "seu" em cada linha nao informa nada.
 *
 * O titulo **e** o link. Um link cujo texto e o nome do curso ja se anuncia
 * sozinho em leitor de tela; "ver detalhes" repetido dez vezes numa lista nao
 * distingue um item do outro.
 */
defineProps<{ curso: Curso }>()
</script>

<template>
  <li
    data-curso
    :data-curso-id="curso.id"
    class="flex flex-col gap-2 rounded-lg border border-default p-4"
  >
    <div class="flex flex-wrap items-center gap-2">
      <h3 class="text-base font-semibold">
        <NuxtLink
          :to="`/producer/courses/${curso.id}`"
          data-acao="abrir-curso"
          class="underline-offset-4 hover:underline focus-visible:underline"
        >
          {{ curso.title }}
        </NuxtLink>
      </h3>

      <CourseEtiquetaDeEstado :estado="curso.state" />
    </div>

    <p class="text-sm text-muted">
      {{ curso.description }}
    </p>

    <!--
      O instante legivel por maquina fica em `datetime`, e o texto formatado no
      corpo: o primeiro nao depende do fuso de quem le, o segundo depende.
    -->
    <p class="text-xs text-muted">
      Criado em
      <time :datetime="curso.created_at">{{ formatarDataHora(curso.created_at) }}</time>
    </p>
  </li>
</template>
