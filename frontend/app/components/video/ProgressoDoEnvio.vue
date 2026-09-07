<script setup lang="ts">
/**
 * Progresso **real**, por partes confirmadas (RF-UI-004).
 *
 * O numerador sao as partes com comprovante em maos, e nao as iniciadas: uma
 * barra que avanca ao pedir a URL ou ao comecar o `PUT` andaria a frente dos
 * bytes e chegaria a 100% com o arquivo incompleto.
 *
 * `<progress>` nativo em vez de uma barra desenhada: ele ja tem papel de
 * `progressbar`, valor e maximo lidos por tecnologia assistiva, sem nenhum
 * `aria-*` escrito a mao para manter em dia.
 */
defineProps<{
  enviadas: number
  total: number
  percentual: number
}>()
</script>

<template>
  <div
    data-progresso
    :data-partes="`${enviadas}/${total}`"
    class="flex flex-col gap-1.5"
  >
    <progress
      :value="enviadas"
      :max="total"
      aria-label="Progresso do envio do video"
      class="h-1.5 w-full"
    />

    <!-- O texto repete a informacao da barra porque nem todo mundo consegue ler
         a barra, e porque "12 de 16" e mais util que uma proporcao sozinha. -->
    <p class="text-xs text-muted tabular-nums">
      {{ enviadas }} de {{ total }} partes enviadas ({{ percentual }}%)
    </p>
  </div>
</template>
