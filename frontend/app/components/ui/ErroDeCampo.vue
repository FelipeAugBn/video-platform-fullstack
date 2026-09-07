<script setup lang="ts">
/**
 * As mensagens de validacao de um campo, vindas da API (RF-UI-009, AC-UI-001).
 *
 * O texto e o que o backend escreveu — a interface nao reescreve regra de
 * validacao, ela mostra a decisao de quem validou (RF-UI-017).
 *
 * O `id` e obrigatorio de proposito: e ele que o campo referencia em
 * `aria-describedby`. Sem essa ligacao, quem navega por teclado ouve o rotulo do
 * campo e nunca o motivo da recusa.
 */
defineProps<{
  id: string
  mensagens: string[]
}>()
</script>

<template>
  <ul
    v-if="mensagens.length > 0"
    :id="id"
    role="alert"
    data-estado="erro-de-campo"
    class="space-y-1 text-sm font-medium text-error"
  >
    <li
      v-for="mensagem in mensagens"
      :key="mensagem"
    >
      {{ mensagem }}
    </li>
  </ul>
</template>
