<script setup lang="ts">
/**
 * Anterior e proxima, a partir de `meta` (plan §10.4).
 *
 * A posicao vem de `current_page` e `last_page` — os dois numeros que a API
 * calculou sobre o conjunto **autorizado**. O componente nao conta itens, nao
 * deduz quantas paginas existem e nao guarda historico: ele pede uma pagina, e a
 * resposta seguinte diz onde a navegacao parou.
 *
 * Nas bordas o controle fica **desabilitado**, e nao escondido: um botao que
 * some muda o alcance do teclado a cada pagina, e quem navega por tabulacao
 * perde a referencia de onde estava.
 */
const props = defineProps<{
  atual: number
  ultima: number
}>()

const emit = defineEmits<{ ir: [pagina: number] }>()

const temAnterior = computed(() => props.atual > 1)
const temProxima = computed(() => props.atual < props.ultima)
</script>

<template>
  <nav
    aria-label="Paginacao"
    data-paginacao
    class="flex items-center justify-between gap-3 border-t border-default pt-4"
  >
    <UButton
      color="neutral"
      variant="outline"
      icon="i-lucide-chevron-left"
      :disabled="!temAnterior"
      data-acao="pagina-anterior"
      @click="emit('ir', atual - 1)"
    >
      Anterior
    </UButton>

    <!--
      `aria-live` porque a mudanca de pagina nao move o foco: sem o anuncio, quem
      usa leitor de tela clica em "Proxima" e nada indica que a posicao mudou.
    -->
    <p
      aria-live="polite"
      data-paginacao-posicao
      class="text-sm text-muted tabular-nums"
    >
      Pagina {{ atual }} de {{ ultima }}
    </p>

    <UButton
      color="neutral"
      variant="outline"
      icon="i-lucide-chevron-right"
      trailing
      :disabled="!temProxima"
      data-acao="pagina-proxima"
      @click="emit('ir', atual + 1)"
    >
      Proxima
    </UButton>
  </nav>
</template>
