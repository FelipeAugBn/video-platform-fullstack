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
  <!--
    Mesma geometria dos demais paineis de estado: caixa, raio e respiro iguais,
    para que a troca de "carregando" por "vazio" ou por "falhou" nao mova a
    pagina inteira quando a resposta chega.
  -->
  <div
    role="status"
    aria-busy="true"
    aria-live="polite"
    data-estado="carregando"
    class="flex flex-col gap-3 rounded-lg border border-default bg-default p-4"
  >
    <span class="text-sm font-medium text-muted">{{ rotulo }}</span>

    <div class="flex flex-col gap-2">
      <USkeleton
        v-for="linha in linhas"
        :key="linha"
        class="h-4"
        :class="linha === linhas ? 'w-2/3' : 'w-full'"
      />
    </div>
  </div>
</template>
