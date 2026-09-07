import type { EstadoDaSessao } from '~/composables/useSessionState'

/**
 * Os estados que **provam** ausencia de sessao.
 *
 * A lista e de afirmacoes positivas, e nao a negacao de "autenticada". A
 * diferenca decide o caso mais delicado: quando a rede cai ou a API responde
 * `5xx`, nao se sabe se ha sessao — e "nao sei" nao pode ser tratado como "nao
 * tem". Pela negacao, qualquer estado diferente de autenticado mandaria a pessoa
 * para o login, inclusive uma oscilacao de rede de um segundo.
 */
const SEM_SESSAO: ReadonlySet<EstadoDaSessao> = new Set<EstadoDaSessao>(['anonima', 'expirada'])

/**
 * Exige sessao para alcancar a pagina.
 *
 * **Nao e protecao.** Ele evita que alguem sem sessao aterrisse numa tela que so
 * mostraria erros, e nada alem disso: propriedade, concessao e perfil sao
 * decididos pelo backend a cada requisicao, e continuariam valendo mesmo que
 * este arquivo fosse apagado (RN-AUT-002, RF-UI-017).
 *
 * ## Tres desfechos, e nao dois
 *
 *   **autenticada** — segue.
 *   **anonima ou expirada** — a API respondeu `401`, e isso e evidencia de que
 *   nao ha sessao. Vai para `/login`, guardando o caminho pretendido.
 *   **nao foi possivel saber** — rede caida, `5xx`, ou uma verificacao ainda em
 *   voo. A navegacao **segue**, e a tela de destino lida com a falha pelo
 *   estado de indisponibilidade que ela ja sabe mostrar (RF-UI-011, RF-UI-012).
 *
 * O terceiro caso e o que este middleware existe para nao errar. Mandar para o
 * login quem apenas ficou sem conexao anunciaria uma perda de sessao que nao
 * houve, e ainda esconderia a causa real: a pessoa tentaria entrar de novo, o
 * login falharia pela mesma indisponibilidade, e nada explicaria o porque.
 *
 * Seguir tambem nao abre brecha: sem sessao valida, cada requisicao da tela
 * responde `401`, e ai sim o cliente marca expiracao e conduz ao login. A
 * decisao continua sendo do backend.
 *
 * Nao ha laco de navegacao possivel: `/login` nao usa este middleware, e o
 * desvio so acontece a partir de outra rota.
 */
export default defineNuxtRouteMiddleware(async (para) => {
  const { estado, verificada, recuperarSessao } = useAuth()

  if (!verificada.value) {
    try {
      await recuperarSessao()
    }
    catch {
      // O desfecho ja esta no estado: `anonima` quando a API respondeu `401`,
      // ou de volta a `naoVerificada` quando nao houve resposta que provasse
      // coisa alguma. Decidir pelo estado, e nao pela excecao, e o que mantem
      // as duas situacoes separadas.
    }
  }

  if (SEM_SESSAO.has(estado.value)) {
    return navigateTo({
      path: '/login',
      query: { redirect: para.fullPath },
    })
  }
})
