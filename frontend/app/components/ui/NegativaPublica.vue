<script setup lang="ts">
import type { CodigoDeFalha } from '~/types/problema'
import type { ErroDeApi } from '~/utils/erroDeApi'

/**
 * A negativa como o **consumidor** a ve (RF-UI-013, RF-UI-015; RF-PLB-008;
 * RN-PROP-005).
 *
 * Existe por causa de uma exigencia que `UiEstadoDeFalha` sozinho nao consegue
 * cumprir: `403` e `404` precisam ser **indistinguiveis**. Aquele componente
 * usa `erro.mensagem` como descricao, e `mensagem` e o `detail` da API — que
 * difere entre os dois. Pior: o `detail` do `404` diz, em portugues claro, que o
 * recurso "nao existe ou nao esta disponivel". Repassado a tela, ele desfaria no
 * cliente a ocultacao que o backend construiu na consulta.
 *
 * Aqui o texto e **fixo e escrito uma vez**. Nao ha ramo por status dentro da
 * negativa de acesso, nao ha `data-codigo` para separar `FORBIDDEN` de
 * `NOT_FOUND`, e nada do corpo da resposta chega a arvore renderizada. A
 * igualdade nao e verificada depois: ela e consequencia de so existir um texto.
 *
 * ## Tres desfechos publicos, e nao quatro
 *
 *   `403` e `404`  acesso negado, sem afirmar se algo existe do outro lado.
 *   `409`          o conteudo e conhecido e ainda nao pode ser reproduzido.
 *                  Distinto de autorizacao de proposito: "ainda nao esta pronto"
 *                  e uma informacao legitima dentro de um curso concedido.
 *   o resto        `401`, rede, `5xx` e resposta fora do contrato seguem para
 *                  `UiEstadoDeFalha`, que ja os separa — inclusive conduzindo a
 *                  reautenticacao sem chamar isso de acesso negado (RF-UI-014).
 *
 * ## Repetir so onde repetir muda alguma coisa
 *
 * As duas negativas tratadas aqui **nao** oferecem nova tentativa: a mesma
 * requisicao devolveria a mesma resposta, e o botao convidaria a insistir. O que
 * permanece repetivel — rede, indisponibilidade, resposta inesperada — e
 * decidido por `UiEstadoDeFalha`, e o evento apenas atravessa.
 */
const props = withDefaults(defineProps<{
  erro: ErroDeApi
  /** Titulo das falhas transitorias. As negativas publicas tem titulo proprio. */
  tituloDaFalha?: string
}>(), {
  tituloDaFalha: undefined,
})

defineEmits<{ novaTentativa: [] }>()

type Publica = 'acessoNegado' | 'conteudoIndisponivel' | 'transitoria'

/*
| A decisao e por `status`, nunca por `detail`.
|
| `detail` e texto para humanos e pode ser reescrito sem quebrar contrato; alem
| disso, e justamente ele que difere entre `403` e `404`. Compara-lo aqui seria
| reintroduzir a distincao que este componente existe para apagar.
*/
const publica = computed<Publica>(() => {
  const status = props.erro.status

  if (status === 403 || status === 404) {
    return 'acessoNegado'
  }

  return status === 409 ? 'conteudoIndisponivel' : 'transitoria'
})

/**
 * O que cada `409` significa para quem tentou assistir.
 *
 * Pelo `code`, o identificador estavel — e nao pelo texto que veio junto. Os
 * dois codigos do contrato para esta rota estao cobertos; qualquer outro cai na
 * frase generica, que continua sendo uma indisponibilidade e nao uma negativa de
 * acesso.
 *
 * ## As frases dizem **so** o que o codigo garante
 *
 * `LESSON_VIDEO_NOT_READY` e um codigo largo: o backend o emite quando a aula
 * publicada nao tem tentativa nenhuma, quando a tentativa esta fora de `ready`
 * — inclusive em `failed` — e quando esta em `ready` sem referencia de
 * reproducao registrada. As tres viram a mesma recusa, e a resposta **nao**
 * traz `video_state`.
 *
 * Logo, esta tela nao sabe qual das tres aconteceu, e nao pode dizer que o
 * video "esta sendo preparado": em duas delas nao ha preparo algum acontecendo,
 * e a frase prometeria uma espera que nunca termina. O que o codigo garante e
 * apenas que nao ha o que reproduzir agora — e e so isso que a frase afirma.
 *
 * Descobrir qual e o caso exigiria um segundo pedido a um endereco que o
 * consumidor nao alcanca: o estado do video pertence a quem produz.
 */
const INDISPONIBILIDADES = {
  LESSON_NOT_PUBLISHED: 'Esta aula ainda nao foi publicada.',
  LESSON_VIDEO_NOT_READY: 'O video desta aula ainda nao esta pronto para reproducao.',
} as const satisfies Partial<Record<CodigoDeFalha, string>>

type CodigoIndisponivel = keyof typeof INDISPONIBILIDADES

/*
| Propriedade **propria**, e nao `in`.
|
| O codigo chega da rede, e `'toString' in INDISPONIBILIDADES` e verdadeiro: com
| `in`, um corpo com `code: "toString"` faria a tela exibir uma funcao herdada de
| `Object.prototype` como se fosse a explicacao da indisponibilidade.
*/
function ehIndisponibilidadeConhecida(codigo: CodigoDeFalha | null): codigo is CodigoIndisponivel {
  return codigo !== null && Object.hasOwn(INDISPONIBILIDADES, codigo)
}

const descricaoDaIndisponibilidade = computed(() => (
  ehIndisponibilidadeConhecida(props.erro.code)
    ? INDISPONIBILIDADES[props.erro.code]
    : 'Este conteudo ainda nao esta disponivel para reproducao.'
))
</script>

<template>
  <!--
    Titulo, descricao e atributos fixos: nada da resposta entra aqui. O motivo
    esta no bloco acima — e ele fica no script de proposito, porque comentario de
    template e servido junto da pagina e vazaria pelo DOM a distincao que este
    ramo existe para apagar.
  -->
  <UiPainelDeEstado
    v-if="publica === 'acessoNegado'"
    tom="erro"
    titulo="Acesso negado"
    descricao="Esta conta nao pode acessar este endereco."
    data-estado="acesso-negado"
  >
    <template
      v-if="$slots.acoes"
      #acoes
    >
      <slot name="acoes" />
    </template>
  </UiPainelDeEstado>

  <UiPainelDeEstado
    v-else-if="publica === 'conteudoIndisponivel'"
    tom="atencao"
    titulo="Conteudo indisponivel"
    :descricao="descricaoDaIndisponibilidade"
    data-estado="conteudo-indisponivel"
    :data-codigo="erro.code ?? undefined"
  >
    <template
      v-if="$slots.acoes"
      #acoes
    >
      <slot name="acoes" />
    </template>
  </UiPainelDeEstado>

  <UiEstadoDeFalha
    v-else
    :erro="erro"
    :titulo="tituloDaFalha"
    @nova-tentativa="$emit('novaTentativa')"
  />
</template>
