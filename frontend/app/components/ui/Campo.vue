<script setup lang="ts">
/**
 * Um campo de formulario: rotulo, controle e a recusa que veio da API.
 *
 * O ritmo entre as tres partes e decidido aqui, e nao repetido em cada
 * formulario — e o que faz o login, a criacao de curso, a de modulo e a de aula
 * parecerem o mesmo formulario em contextos diferentes.
 *
 * O componente **nao** liga `aria-describedby` sozinho: quem sabe se ha erro a
 * descrever e a tela que passa as mensagens, e e la que o controle recebe
 * `aria-invalid` junto. Escrever a ligacao aqui exigiria que este componente
 * alcancasse o controle que veio pelo slot.
 */
defineProps<{
  campo: string
  rotulo: string
  erroId: string
  mensagens: string[]
}>()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label
      :for="campo"
      class="text-sm font-medium text-highlighted"
    >
      {{ rotulo }}
    </label>

    <slot />

    <UiErroDeCampo
      :id="erroId"
      :mensagens="mensagens"
    />
  </div>
</template>
