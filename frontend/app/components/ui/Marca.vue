<script setup lang="ts">
/**
 * A identidade da plataforma.
 *
 * Um simbolo desenhado em SVG no proprio arquivo, e nao uma imagem: nenhum
 * arquivo remoto, nada a baixar e nenhuma dependencia nova para exibir tres
 * retangulos. O desenho e o indice de um curso — o corte vertical do sumario e
 * as linhas dos titulos —, que e a estrutura que a plataforma organiza.
 *
 * O nome e descritivo de proposito. Nao ha marca comercial nesta entrega, e
 * inventar uma faria a interface afirmar algo que o produto nao e.
 *
 * O simbolo e decorativo: quem le por audio ja recebe o nome ao lado, e anuncia-lo
 * duas vezes so acrescenta ruido.
 */
withDefaults(defineProps<{ tamanho?: 'padrao' | 'grande' }>(), {
  tamanho: 'padrao',
})
</script>

<template>
  <span
    class="inline-flex items-center gap-2.5 font-[family-name:var(--font-display)] font-semibold tracking-tight text-highlighted"
    :class="tamanho === 'grande' ? 'text-lg' : 'text-[0.9375rem]'"
  >
    <svg
      viewBox="0 0 20 20"
      aria-hidden="true"
      focusable="false"
      class="shrink-0 text-primary"
      :class="tamanho === 'grande' ? 'size-6' : 'size-5'"
    >
      <rect
        x="0.5"
        y="1"
        width="3"
        height="18"
        rx="1.5"
        fill="currentColor"
        opacity="0.35"
      />
      <rect
        x="6.5"
        y="2.5"
        width="13"
        height="3"
        rx="1.5"
        fill="currentColor"
      />
      <rect
        x="6.5"
        y="8.5"
        width="9"
        height="3"
        rx="1.5"
        fill="currentColor"
        opacity="0.55"
      />
      <rect
        x="6.5"
        y="14.5"
        width="11"
        height="3"
        rx="1.5"
        fill="currentColor"
        opacity="0.55"
      />
    </svg>

    Cursos em video
  </span>
</template>
