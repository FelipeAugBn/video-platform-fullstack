<script setup lang="ts">
/**
 * Lista vazia (RF-UI-002).
 *
 * **Visual e semanticamente distinto de falha**, que e o ponto do requisito:
 * tom neutro em vez de erro, `role="status"` em vez de `alert`, e um convite a
 * proxima acao quando ela existe. Uma tela que mostra o mesmo aviso vermelho
 * para "voce ainda nao criou nada" e para "nao consegui carregar" ensina a
 * desconfiar das duas.
 */
withDefaults(defineProps<{
  titulo?: string
  descricao?: string
}>(), {
  titulo: 'Nada por aqui ainda',
  descricao: undefined,
})
</script>

<template>
  <UiPainelDeEstado
    tom="neutro"
    icone="i-lucide-inbox"
    :titulo="titulo"
    :descricao="descricao"
    data-estado="vazio"
  >
    <template
      v-if="$slots.acoes"
      #acoes
    >
      <slot name="acoes" />
    </template>
  </UiPainelDeEstado>
</template>
