<script setup lang="ts">
/**
 * Sucesso (RF-UI-008).
 *
 * Confirmacao explicita das acoes que importam — criar, concluir um envio,
 * publicar. Sem ela, a unica evidencia de que a acao funcionou seria a tela ter
 * mudado, e quem usa leitor de tela nao teria nem isso.
 *
 * `status`, e nao `alert`: a confirmacao e anunciada na proxima pausa, sem
 * interromper.
 */
defineProps<{
  titulo: string
  descricao?: string
}>()
</script>

<template>
  <UiPainelDeEstado
    tom="sucesso"
    :titulo="titulo"
    :descricao="descricao"
    data-estado="sucesso"
  />
</template>
