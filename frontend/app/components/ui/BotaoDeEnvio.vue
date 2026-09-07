<script setup lang="ts">
/**
 * Acao em andamento (RF-UI-003).
 *
 * Enquanto a submissao esta em voo o controle fica **desabilitado**, e nao
 * apenas com um indicador girando: um botao que continua clicavel envia a mesma
 * criacao duas vezes, e o segundo envio chega ao servidor como um pedido
 * legitimo.
 *
 * A protecao e do proprio elemento, e nao de uma trava em JavaScript: um
 * `<button disabled>` nao dispara `click` nem por teclado, nem por clique
 * repetido, nem por submissao do formulario.
 */
withDefaults(defineProps<{
  pendente?: boolean
  rotulo?: string
  rotuloPendente?: string
  desabilitado?: boolean
}>(), {
  pendente: false,
  rotulo: 'Enviar',
  rotuloPendente: 'Enviando...',
  desabilitado: false,
})
</script>

<template>
  <UButton
    type="submit"
    :loading="pendente"
    :disabled="pendente || desabilitado"
    :aria-busy="pendente"
    data-estado="acao"
    :data-pendente="pendente ? 'sim' : 'nao'"
    block
  >
    {{ pendente ? rotuloPendente : rotulo }}
  </UButton>
</template>
