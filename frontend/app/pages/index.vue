<script setup lang="ts">
import { ErroDeApi } from '~/utils/erroDeApi'

/**
 * A porta de entrada da aplicacao (RF-UI-018, AC-UI-005).
 *
 * `/` nao tem tela propria. Ela existe para que quem digita o endereco da
 * plataforma chegue a algum lugar em vez de a um `404`, e a unica coisa que
 * decide e para onde esta sessao pertence.
 *
 * Essa decisao **nao e nova**: e o mesmo `destinoDoPerfil` que `useAuth` aplica
 * depois de autenticar. Um segundo mapa de perfil para area escrito aqui
 * divergiria do primeiro na primeira mudanca, e a raiz passaria a encaminhar
 * para um lugar diferente daquele que o login escolhe.
 *
 * ## Por que aqui, e nao num middleware de rota
 *
 * `middleware/auth` existe para **proteger** telas: ele evita que alguem sem
 * sessao aterrisse num conteudo que so mostraria erros. Aqui nao ha conteudo a
 * proteger — a rota inteira e o encaminhamento —, e o desfecho de
 * indisponibilidade precisa de uma tela: um middleware que nao encaminha deixa a
 * navegacao parada sem nada renderizado, que e exatamente o carregamento
 * indefinido que RF-UI-012 proibe.
 *
 * ## Quatro desfechos, e um deles nao e encaminhamento
 *
 *   **autenticada, produtor**   `/producer/courses`.
 *   **autenticada, consumidor** `/catalog`.
 *   **ausencia confirmada**     `401`, `anonima` ou `expirada` levam ao login. O
 *                               `401` e a **prova** de que nao ha sessao — o
 *                               proprio cliente ja o traduziu em `anonima`.
 *   **indisponibilidade**       rede, `5xx` ou resposta fora do contrato: a tela
 *                               **permanece** aqui, apresenta a falha e oferece
 *                               nova tentativa.
 *
 * O quarto desfecho e a razao de este arquivo nao ser trivial. Uma falha de rede
 * **nao prova ausencia de sessao**: mandar quem apenas ficou sem conexao para o
 * login anunciaria uma perda que nao houve, esconderia a causa real e ainda o
 * faria tentar entrar de novo — com a mesma indisponibilidade recusando o login
 * em seguida, agora sem explicacao nenhuma (RF-UI-011, RF-UI-012, RF-UI-014).
 *
 * A distincao vem do **status**, e nao da existencia da excecao: `recuperarSessao`
 * lanca nos dois casos, e so o `401` afirma alguma coisa sobre a sessao.
 *
 * `replace` porque a raiz nao e um lugar: mantida no historico, ela faria o
 * "voltar" a partir da area do perfil cair aqui e ser reencaminhado para a
 * frente de novo, prendendo quem tenta sair.
 *
 * **Nao e protecao, e nao acrescenta requisicao.** Propriedade, concessao e
 * perfil continuam decididos pelo backend a cada requisicao (RN-AUT-002,
 * RF-UI-017), e a verificacao de sessao aqui e a mesma que `middleware/auth`
 * faria na tela de destino — `useAuth` so pergunta enquanto nao ha resposta.
 */
const { usuario, estado, verificada, recuperarSessao, destinoDoPerfil } = useAuth()

const erro = ref<ErroDeApi | null>(null)

/*
| A guarda contra a segunda tentativa em voo.
|
| Sem ela, dois cliques seguidos em "Tentar de novo" produzem um desfecho errado,
| e nao apenas uma chamada a mais: `recuperarSessao` se recusa a comecar enquanto
| a anterior nao terminou, entao a segunda passagem chegaria a decisao com a
| sessao ainda em verificacao — nem autenticada, nem ausente — e mandaria para o
| login alguem que pode ter sessao valida.
*/
const consultando = ref(false)

/*
| O destino do foco depois de uma nova tentativa que falhou.
|
| Repetir troca a falha pelo carregamento e depois de volta: o botao que recebeu
| o clique deixa de existir no meio do caminho, e o foco cai no corpo do
| documento. Quem navega por teclado ficaria sem posicao e sem sinal de que a
| tentativa terminou.
*/
const regiaoDaFalha = ref<HTMLElement | null>(null)

async function encaminhar(): Promise<void> {
  if (consultando.value) {
    return
  }

  consultando.value = true
  erro.value = null

  try {
    if (!verificada.value) {
      try {
        await recuperarSessao()
      }
      catch (causa) {
        const falha = ErroDeApi.de(causa)

        // So o `401` prova ausencia de sessao, e o cliente ja o registrou
        // como `anonima`. Todo o resto e indisponibilidade, e fica nesta tela.
        if (falha.status !== 401) {
          erro.value = falha

          return
        }
      }
    }

    const pessoa = usuario.value

    // `autenticada` sem usuario em maos nao decide area nenhuma:
    // `destinoDoPerfil` precisa de um perfil, e escolher um mandaria metade das
    // sessoes para o lugar errado.
    const destino = estado.value === 'autenticada' && pessoa !== null
      ? destinoDoPerfil(pessoa.role)
      : '/login'

    await navigateTo(destino, { replace: true })
  }
  finally {
    consultando.value = false
  }
}

async function tentarDeNovo(): Promise<void> {
  await encaminhar()

  if (erro.value === null) {
    return
  }

  // Depois do quadro em que a falha volta a existir, e nao antes: um elemento
  // que ainda nao foi renderizado nao aceita foco.
  await nextTick()
  regiaoDaFalha.value?.focus()
}

onMounted(() => {
  void encaminhar()
})
</script>

<template>
  <UiPagina
    largura="estreita"
    centralizada
  >
    <!--
      `tabindex="-1"` para a regiao poder receber foco por codigo sem entrar na
      ordem de tabulacao: ela e destino depois de uma tentativa que falhou, e nao
      uma parada normal do teclado.
    -->
    <div
      v-if="erro"
      ref="regiaoDaFalha"
      tabindex="-1"
      data-regiao="falha"
    >
      <!--
        `permitir-nova-tentativa` e explicito porque esta tela nao pode ser um
        beco sem saida: qualquer negativa que chegasse aqui sem oferecer repeticao
        deixaria a pessoa parada na raiz, sem conteudo e sem caminho. As unicas
        respostas esperadas de `/api/auth/me` sao rede, `5xx` e resposta fora do
        contrato — as tres se resolvem repetindo.
      -->
      <UiEstadoDeFalha
        :erro="erro"
        titulo="Nao foi possivel abrir a plataforma"
        :permitir-nova-tentativa="true"
        @nova-tentativa="tentarDeNovo"
      />
    </div>

    <!--
      O quadro que aparece enquanto a verificacao de sessao esta em voo, e so
      isso. E um estado de carregamento, e nao um cabecalho: dar titulo a uma
      tela que apenas encaminha anunciaria uma pagina que nao existe.
    -->
    <UiEstadoCarregando
      v-else
      rotulo="Abrindo a plataforma..."
      :linhas="2"
    />
  </UiPagina>
</template>
