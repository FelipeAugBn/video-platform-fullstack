<script setup lang="ts">
import type { ErroDeApi } from '~/utils/erroDeApi'

/**
 * Todas as negativas, num componente so — porque o que muda entre elas e a
 * mensagem e a saida, nao o desenho.
 *
 * O mapeamento segue plan §15.4 e usa **`categoria` e `status`**, nunca o texto
 * de `detail`:
 *
 *   rede           a requisicao nao chegou (RF-UI-012)
 *   indisponivel   `5xx` — para quem usa, e o mesmo que estar fora (RF-UI-011)
 *   sessaoExpirada `401` — reconduz ao login, e nao e acesso negado (RF-UI-014)
 *   acessoNegado   `403` e `404` — sem revelar se o recurso existe (RF-UI-013)
 *   conflito       `409` — condicao de regra nao satisfeita. Serve tanto a
 *                  "nao da para publicar ainda" (RF-UI-010) quanto a "esta aula
 *                  ainda nao esta disponivel" (RF-UI-015): sao a mesma forma de
 *                  negativa, e o que as diferencia e o texto que a API mandou.
 *                  A tela pode nomear o contexto por `titulo`.
 *   inesperado     resposta fora do contrato
 *
 * **Nova tentativa so aparece onde repetir faz sentido.** Oferecer "tentar de
 * novo" para um `403` convidaria a insistir no que nunca vai mudar.
 */
const props = withDefaults(defineProps<{
  erro: ErroDeApi
  titulo?: string
  permitirNovaTentativa?: boolean
}>(), {
  titulo: undefined,
  permitirNovaTentativa: undefined,
})

defineEmits<{ novaTentativa: [] }>()

type Situacao =
  | 'rede'
  | 'indisponivel'
  | 'sessaoExpirada'
  | 'acessoNegado'
  | 'conflito'
  | 'inesperado'

const situacao = computed<Situacao>(() => {
  if (props.erro.categoria === 'rede') {
    return 'rede'
  }

  if (props.erro.categoria === 'indisponivel') {
    return 'indisponivel'
  }

  if (props.erro.status === 401) {
    return 'sessaoExpirada'
  }

  if (props.erro.status === 403 || props.erro.status === 404) {
    return 'acessoNegado'
  }

  return props.erro.status === 409 ? 'conflito' : 'inesperado'
})

const TITULOS: Record<Situacao, string> = {
  rede: 'Sem conexao com o servidor',
  indisponivel: 'Servico indisponivel',
  sessaoExpirada: 'Sua sessao expirou',
  acessoNegado: 'Acesso negado',
  conflito: 'Nao foi possivel concluir',
  inesperado: 'Resposta inesperada do servidor',
}

// Repetir so resolve o que e transitorio. Nas negativas por autorizacao e por
// regra, a mesma requisicao daria a mesma resposta.
const RECUPERAVEIS: ReadonlySet<Situacao> = new Set<Situacao>(['rede', 'indisponivel', 'inesperado'])

const tom = computed(() => (situacao.value === 'conflito' ? 'atencao' : 'erro'))
const titulo = computed(() => props.titulo ?? TITULOS[situacao.value])
const mostrarNovaTentativa = computed(() => props.permitirNovaTentativa ?? RECUPERAVEIS.has(situacao.value))
</script>

<template>
  <UiPainelDeEstado
    :tom="tom"
    :titulo="titulo"
    :descricao="erro.mensagem"
    data-estado="falha"
    :data-situacao="situacao"
    :data-codigo="erro.code ?? undefined"
  >
    <template
      v-if="mostrarNovaTentativa"
      #acoes
    >
      <UButton
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-rotate-ccw"
        data-acao="nova-tentativa"
        @click="$emit('novaTentativa')"
      >
        Tentar de novo
      </UButton>
    </template>
  </UiPainelDeEstado>
</template>
