import type { components } from './api'

/*
| Os formatos do envio, do processamento e da publicacao, apontando para o
| contrato gerado de `docs/openapi.yaml`.
|
| Mesma regra de `catalogo.ts`: apelidos, nunca definicoes proprias. Reescrever
| `TentativaDeVideo` a mao compilaria hoje e divergiria no dia em que o contrato
| ganhasse um campo — e o unico sinal seria a tela deixar de mostrar algo que a
| API passou a mandar.
*/

export type AberturaDeEnvio = components['schemas']['AberturaDeEnvio']

export type PlanoDeEnvio = components['schemas']['PlanoDeEnvio']

export type PlanoEnvelope = components['schemas']['PlanoEnvelope']

export type UrlDeParte = components['schemas']['UrlDeParte']

export type UrlDeParteEnvelope = components['schemas']['UrlDeParteEnvelope']

export type ParteConcluida = components['schemas']['ParteConcluida']

export type ConclusaoDeEnvio = components['schemas']['ConclusaoDeEnvio']

export type TentativaDeVideo = components['schemas']['TentativaDeVideo']

export type TentativaEnvelope = components['schemas']['TentativaEnvelope']

/**
 * Aula **sem** video responde `200` com `data: null`, e nao `404`: "ainda nao
 * enviei nada" e o estado inicial de toda aula recem-criada, e nao um recurso
 * ausente.
 */
export type TentativaOuNuloEnvelope = components['schemas']['TentativaOuNuloEnvelope']

/*
| Publicar devolve a mesma `Aula` que a estrutura ja carrega, entao o apelido e
| reaproveitado em vez de redefinido: dois nomes para o mesmo esquema
| conviveriam ate alguem atualizar so um deles.
*/
export type { Aula, AulaEnvelope, EstadoDoVideo } from './catalogo'

/**
 * O minimo que o envio precisa saber sobre um arquivo.
 *
 * `File` satisfaz esta forma, e e o que chega do seletor em producao. O tipo
 * existe para o **teste**: afirmar o recorte de partes de um arquivo de varios
 * gigabytes exigiria alocar varios gigabytes, e o que importa provar sao os
 * intervalos pedidos a `slice` — nao a capacidade do navegador de fatiar bytes,
 * que e dele e nao nossa.
 *
 * A assinatura de `slice` acompanha a de `Blob`, com os tres parametros
 * opcionais, porque uma funcao nao e atribuivel a um tipo que declare **menos**
 * parametros do que ela recebe.
 */
export interface ArquivoEnviavel {
  name: string
  type: string
  size: number
  slice: (inicio?: number, fim?: number, tipoDeConteudo?: string) => Blob
}
