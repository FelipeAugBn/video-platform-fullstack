<script setup lang="ts">
/**
 * O painel comum dos estados de tela (plan §15.4).
 *
 * Existe para que "vazio", "falhou" e "deu certo" nao sejam decididos de novo em
 * cada tela. A variante define cor, icone e, principalmente, **o papel de
 * acessibilidade**: um erro precisa ser anunciado assim que aparece; uma lista
 * vazia, nao — anunciar tudo com a mesma urgencia treina quem usa leitor de tela
 * a ignorar o anuncio.
 */

type Tom = 'neutro' | 'informativo' | 'atencao' | 'erro' | 'sucesso'

const props = withDefaults(defineProps<{
  tom?: Tom
  titulo: string
  descricao?: string
  icone?: string
}>(), {
  tom: 'neutro',
  descricao: undefined,
  icone: undefined,
})

const CORES = {
  neutro: 'neutral',
  informativo: 'info',
  atencao: 'warning',
  erro: 'error',
  sucesso: 'success',
} as const satisfies Record<Tom, string>

const ICONES = {
  neutro: 'i-lucide-inbox',
  informativo: 'i-lucide-info',
  atencao: 'i-lucide-triangle-alert',
  erro: 'i-lucide-circle-x',
  sucesso: 'i-lucide-circle-check',
} as const satisfies Record<Tom, string>

const cor = computed(() => CORES[props.tom])
const icone = computed(() => props.icone ?? ICONES[props.tom])

// `alert` interrompe a leitura para anunciar; `status` espera a pausa seguinte.
// A diferenca e o que separa "algo falhou agora" de "aqui nao ha nada".
const papel = computed(() => (props.tom === 'erro' || props.tom === 'atencao' ? 'alert' : 'status'))
</script>

<template>
  <!--
    Todos os estados da aplicacao passam por aqui, e por isso a geometria e
    definida num lugar so: mesmo raio, mesma coluna interna e o mesmo peso de
    titulo. O que muda entre eles e a cor, o icone e o papel — que sao o que os
    torna distintos, e nao o desenho.
  -->
  <UAlert
    :color="cor"
    :icon="icone"
    :title="titulo"
    :description="descricao"
    variant="subtle"
    :role="papel"
    :data-tom="tom"
    :ui="{
      root: 'items-start gap-3 p-4',
      wrapper: 'gap-1',
      title: 'text-sm font-semibold',
      description: 'text-sm leading-relaxed opacity-90',
      actions: 'mt-2',
    }"
  >
    <template
      v-if="$slots.acoes"
      #actions
    >
      <slot name="acoes" />
    </template>
  </UAlert>
</template>
