import type { components } from './api'

/**
 * Corpo de problema do contrato, no formato da RFC 9457 (plan §10.2).
 *
 * Vem do OpenAPI, e nao de uma interface escrita a mao: uma segunda descricao do
 * mesmo formato divergiria na primeira vez que o contrato mudasse, e ninguem
 * perceberia — os dois compilariam.
 */
export type Problema = components['schemas']['Problema']

/**
 * O codigo funcional. E ele que a interface usa para decidir o que mostrar,
 * nunca o texto de `detail` (RF-UI-017).
 */
export type CodigoDeFalha = components['schemas']['CodigoDeFalha']

/**
 * Erros por campo, presentes apenas no `422`.
 */
export type ErrosDeValidacao = Record<string, string[]>

/**
 * Por que a requisicao falhou — a pergunta que a interface precisa responder
 * antes de escolher o que mostrar.
 *
 * As quatro categorias existem porque levam a telas diferentes (plan §15.4):
 *
 *   `problema`    a API respondeu, com um corpo que o contrato descreve. E o
 *                 unico caso em que `status` e `code` sao confiaveis.
 *   `rede`        nao houve resposta. A requisicao nao chegou, ou a conexao caiu
 *                 no meio (RF-UI-012).
 *   `indisponivel` a API respondeu, mas com defeito dela — `5xx`. Do ponto de
 *                 vista de quem usa, e o mesmo que estar fora do ar
 *                 (RF-UI-011).
 *   `inesperado`  houve resposta e ela nao e nada do que o contrato promete.
 *                 Tratar isso como problema valido faria a interface ler
 *                 `status` e `code` de um objeto que nao os tem.
 */
export type CategoriaDeFalha = 'problema' | 'rede' | 'indisponivel' | 'inesperado'
