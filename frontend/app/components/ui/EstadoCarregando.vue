<script setup lang="ts">
/**
 * Carregando (RF-UI-001).
 *
 * A tela nunca mostra vazio enquanto os dados vem a caminho: sem este estado,
 * "ainda nao chegou" e "nao existe nada" ficam identicos, e a pessoa conclui a
 * segunda coisa.
 *
 * `role="status"` com `aria-busy` anuncia a espera sem interromper; o texto
 * visivel serve a quem enxerga, e o esqueleto da a dimensao do que vem.
 */
withDefaults(defineProps<{
  rotulo?: string
  linhas?: number
}>(), {
  rotulo: 'Carregando...',
  linhas: 3,
})
</script>

<template>
  <div
    role="status"
    aria-busy="true"
    aria-live="polite"
    data-estado="carregando"
    class="flex flex-col gap-3"
  >
    <span class="text-sm text-muted">{{ rotulo }}</span>

    <USkeleton
      v-for="linha in linhas"
      :key="linha"
      class="h-6 w-full"
    />
  </div>
</template>
