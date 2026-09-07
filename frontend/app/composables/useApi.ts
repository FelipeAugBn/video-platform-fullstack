export type MetodoHttp = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

/**
 * O que pode ser enviado como corpo.
 *
 * Deliberadamente estreito: objetos serializaveis como JSON, ou os tipos que o
 * proprio navegador sabe transmitir. `unknown` aqui obrigaria uma conversao
 * cega na hora do envio, e e justamente essa conversao que deixaria passar um
 * valor que o cliente HTTP nao sabe serializar.
 */
export type CorpoDaRequisicao = Record<string, unknown> | BodyInit | null

export interface OpcoesDaRequisicao {
  method?: MetodoHttp
  body?: CorpoDaRequisicao
  query?: Record<string, string | number | boolean | undefined>
  headers?: Record<string, string>
  /**
   * Trata `401` como **ausencia** de sessao, e nao como expiracao.
   *
   * Existe para um unico chamador: a recuperacao inicial de `useAuth`, que
   * pergunta `/api/auth/me` justamente para descobrir se ha sessao. Ali o `401`
   * e a resposta esperada de quem abriu a aplicacao sem ter entrado, e anunciar
   * "sua sessao expirou" seria informar uma perda que nao houve.
   *
   * Nao e um jeito de silenciar o `401` em geral: nas demais chamadas ele
   * continua significando que uma sessao que existia deixou de valer.
   */
  sessaoAusenteEhEsperada?: boolean
}

const METODOS_MUTANTES: ReadonlySet<MetodoHttp> = new Set<MetodoHttp>([
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
])

const COOKIE_CSRF = 'XSRF-TOKEN'

const CABECALHO_CSRF = 'X-XSRF-TOKEN'

/**
 * A obtencao do cookie de protecao em voo, compartilhada.
 *
 * Duas mutacoes disparadas juntas na primeira interacao pediriam o cookie duas
 * vezes, e a segunda resposta sobrescreveria a sessao que a primeira acabou de
 * receber. Guardando a promessa, as duas esperam a mesma chamada.
 *
 * E uma variavel de modulo, e nao uma chave de estado: `useState` guarda apenas
 * sessao e usuario (plan §15.2), e isto nao e estado da aplicacao — e o registro
 * de que uma chamada ja esta acontecendo. Volta a `null` sempre que falha ou
 * quando um `419` prova que o cookie nao vale mais.
 */
let cookieCsrfEmVoo: Promise<void> | null = null

/**
 * O unico ponto da aplicacao que fala HTTP (plan §15.3).
 *
 * Paginas, componentes e `useAuth` passam por aqui. A concentracao nao e
 * estetica: credenciais, protecao contra requisicao forjada e traducao de erro
 * sao tres coisas faceis de esquecer, e esquecidas em **uma** tela produzem uma
 * falha que so aparece naquela tela.
 *
 * ## O que ele garante em toda requisicao
 *
 *   - `credentials: include`, para o cookie de sessao viajar. Sem isso o
 *     navegador simplesmente nao o envia entre origens diferentes, e toda
 *     chamada responderia `401`;
 *   - `Accept: application/json`, para a API nunca negociar HTML;
 *   - a resposta **inteira** do contrato, com `data`, `meta` e `links` como
 *     vieram. Desembrulhar aqui faria a paginacao se perder no caminho.
 *
 * ## O que ele deliberadamente nao faz
 *
 * Nao guarda token, nao le nem escreve `localStorage`, nao define `Authorization`
 * e nao define `Origin` — este ultimo e do navegador, e tentar defini-lo seria
 * pedir algo que ele recusa. Tambem nao ha proxy no servidor Nuxt: a SPA fala
 * direto com a API, que e o que permite ao cookie de sessao ser `HttpOnly` e
 * ainda assim funcionar (plan §9.1).
 */
export function useApi() {
  const config = useRuntimeConfig()
  const roteador = useRouter()
  const { usuario, estado } = useSessionState()

  const base = config.public.apiBase

  /**
   * Le o cookie de protecao **no instante do envio**.
   *
   * Nunca de um cache. O login regenera a sessao e, com ela, o token: um valor
   * guardado antes de autenticar estaria vencido exatamente na primeira operacao
   * depois do login, que e a mais provavel de acontecer.
   *
   * A comparacao e pelo nome exato. Uma busca por conteudo casaria com um cookie
   * cujo nome apenas termine igual, e o valor lido seria de outro.
   */
  function lerTokenCsrf(): string | null {
    if (typeof document === 'undefined') {
      return null
    }

    for (const parte of document.cookie.split(';')) {
      const bruto = parte.trim()
      const separador = bruto.indexOf('=')

      if (separador === -1 || bruto.slice(0, separador) !== COOKIE_CSRF) {
        continue
      }

      // O valor chega codificado como URL. Enviado sem decodificar, ele nao
      // confere com o que o servidor guardou, e a operacao responde `419`.
      return decodeURIComponent(bruto.slice(separador + 1))
    }

    return null
  }

  async function obterCookieCsrf(): Promise<void> {
    cookieCsrfEmVoo ??= $fetch<unknown>('/sanctum/csrf-cookie', {
      baseURL: base,
      credentials: 'include',
      headers: { Accept: 'application/json' },
    }).then(() => undefined)

    try {
      await cookieCsrfEmVoo
    }
    catch (causa) {
      // A falha nao pode ficar memorizada: a proxima mutacao precisa poder
      // tentar de novo, e nao herdar uma promessa ja rejeitada.
      cookieCsrfEmVoo = null
      throw ErroDeApi.de(causa)
    }
  }

  function enviar<T>(caminho: string, opcoes: OpcoesDaRequisicao, metodo: MetodoHttp, mutante: boolean): Promise<T> {
    const cabecalhos: Record<string, string> = {
      Accept: 'application/json',
      ...opcoes.headers,
    }

    if (mutante) {
      const token = lerTokenCsrf()

      if (token !== null) {
        cabecalhos[CABECALHO_CSRF] = token
      }
    }

    return $fetch<T>(caminho, {
      baseURL: base,
      method: metodo,
      body: opcoes.body,
      query: opcoes.query,
      headers: cabecalhos,
      credentials: 'include',
    })
  }

  /**
   * O caminho interno que o usuario tentava alcancar, para voltar a ele depois
   * de reautenticar. A propria tela de login nao e destino de retorno.
   */
  function destinoPretendido(): string | undefined {
    const atual = roteador.currentRoute.value.fullPath

    return atual.startsWith('/login') ? undefined : atual
  }

  /**
   * O que fazer com um `401`, e o que **nao** confundir com ele.
   *
   * `401` diz que nao ha sessao. `403` diz que ha sessao e o perfil nao serve, e
   * `404` que o recurso nao esta disponivel para quem pediu — nenhum dos dois
   * passa por aqui, e nenhum conduz de volta ao login. Tratar os tres igual
   * mandaria reautenticar quem ja esta autenticado.
   */
  function tratarSessao(erro: ErroDeApi, opcoes: OpcoesDaRequisicao): ErroDeApi {
    if (erro.status !== 401) {
      return erro
    }

    usuario.value = null

    if (opcoes.sessaoAusenteEhEsperada) {
      estado.value = 'anonima'

      return erro
    }

    estado.value = 'expirada'
    void navigateTo({ path: '/login', query: { redirect: destinoPretendido() } })

    return erro
  }

  /**
   * Uma requisicao a API, com renovacao de token e **uma unica** repeticao.
   *
   * O limite de uma repeticao e obrigatorio, e nao uma otimizacao. Um `419` que
   * persiste — sessao encerrada do outro lado, dominio mal configurado — faria
   * uma renovacao recursiva girar para sempre, e a tela ficaria carregando em
   * vez de explicar o que houve. Repetindo uma vez, ou o token estava velho e o
   * problema se resolve sozinho, ou ha algo que so a interface pode comunicar.
   *
   * A repeticao reenvia a **mesma** chamada: metodo, corpo, query e cabecalhos
   * do pedido original, com o token relido. Reconstruir a requisicao aqui
   * arriscaria repetir algo diferente do que o usuario pediu.
   */
  async function requisitar<T>(caminho: string, opcoes: OpcoesDaRequisicao = {}): Promise<T> {
    const metodo = opcoes.method ?? 'GET'
    const mutante = METODOS_MUTANTES.has(metodo)

    if (mutante) {
      await obterCookieCsrf()
    }

    try {
      return await enviar<T>(caminho, opcoes, metodo, mutante)
    }
    catch (causa) {
      const erro = ErroDeApi.de(causa)

      if (!mutante || erro.status !== 419 || erro.code !== 'CSRF_TOKEN_MISMATCH') {
        throw tratarSessao(erro, opcoes)
      }

      // O cookie em maos nao vale mais: descartar a promessa memorizada e o que
      // faz a proxima chamada buscar um token novo em vez de reaproveitar o
      // vencido.
      cookieCsrfEmVoo = null

      try {
        await obterCookieCsrf()

        return await enviar<T>(caminho, opcoes, metodo, mutante)
      }
      catch (causaDaRepeticao) {
        // Segundo `419`, falha de rede na renovacao ou qualquer outro desfecho:
        // acaba aqui. Nao ha terceira tentativa, e nenhuma promessa fica
        // pendente — quem chamou recebe o erro e a tela sai do carregamento.
        throw tratarSessao(ErroDeApi.de(causaDaRepeticao), opcoes)
      }
    }
  }

  return { requisitar }
}
