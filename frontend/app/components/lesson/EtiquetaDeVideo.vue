<script setup lang="ts">
import type { EstadoDoVideo } from '~/types/catalogo'

/**
 * O estado do video de uma aula (RF-UI-004 a 007).
 *
 * Nesta tela o estado e **informativo**. Enviar, acompanhar e publicar sao
 * acoes da jornada seguinte; aqui o produtor so precisa enxergar em que ponto
 * cada aula esta antes de decidir o que fazer.
 *
 * `null` tem rotulo proprio, e nao um espaco em branco: "sem video" e uma
 * informacao — a aula existe e ainda nao teve nenhuma tentativa de envio —, e
 * omiti-la faria a linha parecer incompleta em vez de vazia por decisao.
 */
const props = defineProps<{ estado: EstadoDoVideo | null }>()

const ROTULOS = {
  pending: 'Envio pendente',
  uploading: 'Enviando',
  uploaded: 'Enviado',
  processing: 'Processando',
  ready: 'Video pronto',
  // Neutro de proposito: `failed` tambem resulta da verificacao do envio
  // (`uploading -> failed`), entao a estrutura nao tem como afirmar em que etapa
  // a falha aconteceu. Nomear o processamento acusaria a etapa errada metade das
  // vezes, e mandaria o produtor procurar o problema onde ele nao esta.
  failed: 'Falha no video',
} as const satisfies Record<EstadoDoVideo, string>

const CORES = {
  pending: 'neutral',
  uploading: 'info',
  uploaded: 'info',
  processing: 'info',
  ready: 'success',
  failed: 'error',
} as const satisfies Record<EstadoDoVideo, string>

// O valor do atributo tambem serve a leitura automatizada: `sem-video` e um
// estado afirmado, e nao a ausencia do atributo.
const chave = computed(() => props.estado ?? 'sem-video')
const rotulo = computed(() => (props.estado === null ? 'Sem video' : ROTULOS[props.estado]))
const cor = computed(() => (props.estado === null ? 'neutral' : CORES[props.estado]))
</script>

<template>
  <UBadge
    :color="cor"
    variant="subtle"
    size="sm"
    :data-estado-do-video="chave"
  >
    {{ rotulo }}
  </UBadge>
</template>
