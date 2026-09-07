import type { components } from '~/types/api'
import type { Usuario } from './useSessionState'

type Credenciais = components['schemas']['Credenciais']

type RespostaDeUsuario = components['schemas']['UsuarioEnvelope']

/**
 * Para onde cada perfil vai depois de entrar.
 *
 * As duas telas chegam em tarefas seguintes; o encaminhamento existe desde
 * agora porque ele e parte do login, e nao das telas de destino.
 */
const DESTINO_POR_PERFIL: Record<Usuario['role'], string> = {
  producer: '/producer/courses',
  consumer: '/catalog',
}

/**
 * Um retorno so e aceito se for **caminho interno desta aplicacao**.
 *
 * A verificacao existe contra redirecionamento aberto: sem ela, um link com
 * `?redirect=https://outro-site` faria a propria aplicacao levar quem acabou de
 * autenticar para fora, com a credibilidade de ter vindo de uma tela de login
 * legitima.
 *
 * Recusado, portanto: URL absoluta, valor comecando por `//` — que o navegador
 * resolve como outro host —, qualquer coisa que nao comece por `/`, e o destino
 * da area do **outro** perfil, que renderia um `403` logo na chegada.
 */
function retornoSeguro(destino: unknown, perfil: Usuario['role']): string | null {
  if (typeof destino !== 'string' || destino.length === 0) {
    return null
  }

  if (!destino.startsWith('/') || destino.startsWith('//')) {
    return null
  }

  const areaDoOutroPerfil = perfil === 'producer'
    ? DESTINO_POR_PERFIL.consumer
    : DESTINO_POR_PERFIL.producer

  return destino.startsWith(areaDoOutroPerfil) ? null : destino
}

/**
 * Sessao, login, logout e expiracao (plan §15.2).
 *
 * Nao fala HTTP: toda chamada passa por `useApi`. E o que mantem credenciais,
 * protecao contra requisicao forjada e traducao de erro em um lugar so — um
 * segundo cliente aqui teria de repetir os tres, e esqueceria um.
 *
 * O estado global sao duas chaves, e vem de `useSessionState`. Os indicadores
 * abaixo sao **derivados** delas, e nao um terceiro dado a manter em sincronia.
 */
export function useAuth() {
  const { requisitar } = useApi()
  const { usuario, estado } = useSessionState()

  const autenticado = computed(() => estado.value === 'autenticada' && usuario.value !== null)
  const carregando = computed(() => estado.value === 'verificando')
  const expirada = computed(() => estado.value === 'expirada')
  const verificada = computed(() => estado.value !== 'naoVerificada')

  /**
   * Descobre se ha sessao, uma vez.
   *
   * O `401` daqui e **ausencia**, e nao expiracao: quem abre a aplicacao sem ter
   * entrado receberia "sua sessao expirou" se os dois casos fossem o mesmo. A
   * distincao vem de uma opcao do proprio cliente, e nao de uma segunda chamada
   * HTTP escrita fora dele.
   */
  async function recuperarSessao(): Promise<void> {
    if (estado.value === 'verificando') {
      return
    }

    estado.value = 'verificando'

    try {
      const resposta = await requisitar<RespostaDeUsuario>('/api/auth/me', {
        sessaoAusenteEhEsperada: true,
      })

      usuario.value = resposta.data
      estado.value = 'autenticada'
    }
    catch (causa) {
      const erro = ErroDeApi.de(causa)

      usuario.value = null

      // `401` ja foi traduzido para `anonima` pelo cliente. Qualquer outra falha
      // — rede, `5xx` — nao prova ausencia de sessao: dizer "anonima" ali
      // deslogaria visualmente quem apenas ficou sem rede por um instante.
      if (erro.status !== 401) {
        estado.value = 'naoVerificada'
      }

      throw erro
    }
  }

  /**
   * Autentica e encaminha conforme o perfil.
   *
   * O destino padrao vem do perfil; um `redirect` na URL so e usado quando
   * sobrevive a {@link retornoSeguro}.
   */
  async function entrar(credenciais: Credenciais, retorno?: unknown): Promise<Usuario> {
    const resposta = await requisitar<RespostaDeUsuario>('/api/auth/login', {
      method: 'POST',
      body: credenciais,
    })

    usuario.value = resposta.data
    estado.value = 'autenticada'

    const perfil = resposta.data.role
    const destino = retornoSeguro(retorno, perfil) ?? DESTINO_POR_PERFIL[perfil]

    await navigateTo(destino)

    return resposta.data
  }

  /**
   * Encerra a sessao e limpa o estado.
   *
   * A limpeza acontece **depois** da confirmacao do servidor. Limpar antes
   * deixaria a interface deslogada com a sessao viva do outro lado, caso a
   * chamada falhasse.
   */
  async function sair(): Promise<void> {
    // `unknown`, e nao `void`: a rota responde `204` sem corpo, e o que se
    // afirma e que nada e lido — nao que a promessa nao devolve nada.
    await requisitar<unknown>('/api/auth/logout', { method: 'POST' })

    usuario.value = null
    estado.value = 'anonima'
  }

  return {
    usuario,
    estado,
    autenticado,
    carregando,
    expirada,
    verificada,
    recuperarSessao,
    entrar,
    sair,
    destinoDoPerfil: (perfil: Usuario['role']) => DESTINO_POR_PERFIL[perfil],
  }
}
