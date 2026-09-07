import type { components } from '~/types/api'

export type Usuario = components['schemas']['Usuario']

/**
 * Em que ponto da verificacao de sessao a aplicacao esta.
 *
 * Cinco estados, e a distincao entre os dois ultimos e a razao de existirem
 * cinco em vez de tres:
 *
 *   `naoVerificada` a aplicacao acabou de abrir e ainda nao perguntou nada.
 *   `verificando`   a pergunta esta em voo.
 *   `autenticada`   ha sessao, e o usuario esta em maos.
 *   `anonima`       a **primeira** verificacao encontrou ausencia de sessao.
 *                   Ninguem perdeu nada: e quem abriu a aplicacao sem ter
 *                   entrado.
 *   `expirada`      uma sessao que **existia** deixou de valer. Aqui houve
 *                   perda, e a interface diz isso (RF-UI-014, RF-AUT-005).
 *
 * Sem a separacao entre as duas ultimas, abrir a tela de login sem nunca ter
 * entrado exibiria "sua sessao expirou" — uma mensagem falsa sobre algo que
 * nunca aconteceu.
 */
export type EstadoDaSessao =
  | 'naoVerificada'
  | 'verificando'
  | 'autenticada'
  | 'anonima'
  | 'expirada'

/**
 * As duas unicas chaves de estado global da aplicacao (plan §15.2).
 *
 * Existe separado de `useAuth` por uma razao mecanica: `useApi` precisa marcar a
 * sessao como expirada ao receber `401`, e `useAuth` precisa de `useApi` para
 * falar com a API. Um importando o outro fecharia um ciclo. Este composable
 * carrega o que os dois compartilham — duas chaves e nada mais.
 *
 * **Nao e uma store.** Nao tem acao, nao tem regra e nao tem estado derivado: os
 * indicadores compostos vivem em `useAuth`, e estado de tela vive na tela. Sem
 * Pinia, porque o estado global cabe em duas chaves.
 *
 * Nada aqui e persistido no navegador. A sessao mora num cookie `HttpOnly` que o
 * JavaScript nao le, e guardar uma copia dela em `localStorage` criaria uma
 * segunda verdade — que sobreviveria ao logout (RF-AUT-006).
 */
export function useSessionState() {
  const usuario = useState<Usuario | null>('sessao:usuario', () => null)
  const estado = useState<EstadoDaSessao>('sessao:estado', () => 'naoVerificada')

  return { usuario, estado }
}
