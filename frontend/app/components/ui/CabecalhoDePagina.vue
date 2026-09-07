<script setup lang="ts">
/**
 * O cabecalho de uma tela: onde a pessoa esta, sobre o que e a tela e em que
 * estado esta o que ela veio ver.
 *
 * A ordem e sempre a mesma — caminho, titulo, estado, descricao, dados de
 * apoio — porque e ela que faz as seis telas parecerem o mesmo produto. O
 * titulo e o unico `h1` da pagina.
 *
 * Os atributos que chegam de fora caem no `header`, que e a raiz: e assim que a
 * estrutura do curso marca este bloco como o cabecalho do curso, sem que este
 * componente precise conhecer curso nenhum.
 */
defineProps<{
  titulo: string
  descricao?: string
}>()
</script>

<template>
  <header class="flex flex-col gap-3">
    <div class="flex flex-col gap-3">
      <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="titulo-de-pagina">
          {{ titulo }}
        </h1>

        <slot name="etiquetas" />
      </div>

      <p
        v-if="descricao"
        class="medida-de-leitura text-[0.9375rem] leading-relaxed text-toned"
      >
        {{ descricao }}
      </p>

      <slot name="apoio" />
    </div>
  </header>
</template>
