<script setup lang="ts">
/**
 * Uma secao nomeada dentro de uma tela.
 *
 * O titulo e a regua abaixo dele sao o que separa dois blocos de conteudo sem
 * precisar de mais uma caixa em volta — cartao dentro de cartao e o que torna
 * uma estrutura de curso ilegivel.
 *
 * O `id` e obrigatorio porque e ele que nomeia a secao para quem navega por
 * regioes: sem a ligacao com o proprio titulo, a secao chega anonima.
 */
defineProps<{
  id: string
  titulo: string
  descricao?: string
}>()
</script>

<template>
  <section
    :aria-labelledby="id"
    class="flex flex-col gap-5"
  >
    <div class="flex flex-col gap-1.5 border-b border-default pb-3">
      <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2
          :id="id"
          class="text-lg font-semibold text-highlighted"
        >
          {{ titulo }}
        </h2>

        <slot name="acoes" />
      </div>

      <p
        v-if="descricao"
        class="medida-de-leitura text-sm leading-relaxed text-muted"
      >
        {{ descricao }}
      </p>
    </div>

    <slot />
  </section>
</template>
