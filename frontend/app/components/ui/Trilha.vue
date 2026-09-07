<script setup lang="ts">
/**
 * O caminho de volta, e onde a pessoa esta dentro dele.
 *
 * Duas coisas num controle so: o link para a area de origem — que continua
 * existindo quando a leitura da tela falha, porque ele fica fora da regiao que
 * alterna de estado — e o nome do lugar atual, que nao e link porque ja se esta
 * nele.
 *
 * O identificador da acao vem de fora, e nao e detalhe de estilo: ele e o
 * contrato de leitura automatizada de cada tela, e nomeia o destino, nao o
 * desenho.
 *
 * A barra entre os dois e decorativa e some para quem le por audio: a relacao
 * entre os itens ja esta na lista ordenada e em `aria-current`.
 */
defineProps<{
  rotulo: string
  destino: string
  acao: string
  atual?: string
}>()
</script>

<template>
  <nav
    aria-label="Trilha"
    class="min-w-0"
  >
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
      <li class="flex min-w-0 items-center">
        <NuxtLink
          :to="destino"
          :data-acao="acao"
          class="inline-flex items-center gap-1 font-medium text-muted underline-offset-4 hover:text-default hover:underline focus-visible:text-default"
        >
          <UIcon
            name="i-lucide-chevron-left"
            class="size-4 shrink-0"
          />
          {{ rotulo }}
        </NuxtLink>
      </li>

      <template v-if="atual">
        <li
          aria-hidden="true"
          class="text-muted"
        >
          /
        </li>

        <li
          aria-current="page"
          class="min-w-0 truncate text-default"
        >
          {{ atual }}
        </li>
      </template>
    </ol>
  </nav>
</template>
