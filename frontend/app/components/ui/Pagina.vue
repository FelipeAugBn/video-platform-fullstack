<script setup lang="ts">
/**
 * A coluna de conteudo de uma tela.
 *
 * Existe para que largura, respiro e ritmo vertical sejam decididos uma vez, e
 * nao repetidos em seis paginas com valores que divergem na primeira
 * manutencao. Ela traz o `main` — o marco de regiao do conteudo —, entao cada
 * pagina continua com exatamente um.
 *
 * Sao duas medidas, e nao mais: a entrada e um formulario curto e nao ganha
 * nada em ficar larga; todo o resto compartilha a mesma coluna, que e a mesma
 * da barra de sessao — a marca no topo e o titulo da tela ficam alinhados na
 * mesma vertical em vez de dancarem de uma pagina para a outra.
 */
withDefaults(defineProps<{
  largura?: 'estreita' | 'padrao'
  centralizada?: boolean
}>(), {
  largura: 'padrao',
  centralizada: false,
})

const LARGURAS = {
  estreita: 'max-w-md',
  padrao: 'max-w-5xl',
} as const
</script>

<template>
  <main
    class="mx-auto flex w-full flex-col gap-8 px-4 py-8 sm:px-6 sm:py-10"
    :class="[LARGURAS[largura], centralizada ? 'min-h-dvh justify-center' : '']"
  >
    <slot />
  </main>
</template>
