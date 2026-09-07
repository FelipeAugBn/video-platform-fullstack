import type { components } from './api'

/*
| Os formatos do catalogo do produtor, apontando para o contrato.
|
| Sao **apelidos**, e nao definicoes: cada um resolve para
| `components['schemas'][...]`, o tipo gerado a partir de `docs/openapi.yaml`.
| Uma interface escrita a mao com os mesmos campos compilaria igual e divergiria
| em silencio na primeira mudanca do contrato — os dois lados continuariam
| validos, e so a tela quebraria, em producao.
|
| O que se ganha com os apelidos e leitura: `EstruturaDoCurso` diz o que a tela
| manipula; `components['schemas']['EstruturaDoCurso']` diz de onde veio. Aqui
| ficam as duas informacoes, uma vez.
*/

export type EstadoDoCurso = components['schemas']['EstadoDoCurso']

export type EstadoDoVideo = components['schemas']['EstadoDoVideo']

export type Curso = components['schemas']['Curso']

export type CursoEnvelope = components['schemas']['CursoEnvelope']

export type CursosPaginados = components['schemas']['CursosPaginados']

export type PaginacaoMeta = components['schemas']['PaginacaoMeta']

export type EstruturaDoCurso = components['schemas']['EstruturaDoCurso']

export type EstruturaEnvelope = components['schemas']['EstruturaEnvelope']

export type ModuloComAulas = components['schemas']['ModuloComAulas']

export type Modulo = components['schemas']['Modulo']

export type ModuloEnvelope = components['schemas']['ModuloEnvelope']

export type Aula = components['schemas']['Aula']

export type AulaEnvelope = components['schemas']['AulaEnvelope']

/**
 * Corpo aceito na criacao de curso. **Nao tem** `owner_id`, `state` nem datas: o
 * proprietario vem da sessao e o estado inicial e do backend.
 */
export type NovoCurso = components['schemas']['NovoCurso']

/**
 * Corpo aceito na criacao de modulo. **Nao tem** `position`: ela e a proxima
 * livre no curso, calculada sob trava (RN-ORD-002).
 */
export type NovoModulo = components['schemas']['NovoModulo']

/**
 * Corpo aceito na criacao de aula. **Nao tem** `position`, `published_at` nem
 * estado de video: a aula nasce em rascunho e sem video.
 */
export type NovaAula = components['schemas']['NovaAula']

/*
| A visao do consumidor sobre o mesmo catalogo.
|
| Sao tipos **diferentes**, e nao um recorte opcional dos de cima: a aula do
| consumidor tem cinco campos, `video_state` nao esta entre eles e `published_at`
| nunca e nula. Reaproveitar `Aula` aqui deixaria a tela ler um campo que a
| resposta nunca traz, e o compilador nao teria como avisar.
*/

/**
 * A aula como o consumidor a recebe. **Nao tem** `video_state`: o
 * acompanhamento do processamento e assunto de quem produz, e a arvore do
 * consumidor so contem aula ja publicada.
 */
export type AulaDoConsumidor = components['schemas']['AulaDoConsumidor']

export type ModuloComAulasPublicadas = components['schemas']['ModuloComAulasPublicadas']

export type EstruturaDoConsumidor = components['schemas']['EstruturaDoConsumidor']

export type EstruturaDoConsumidorEnvelope = components['schemas']['EstruturaDoConsumidorEnvelope']

/**
 * Dados de reproducao — URL pre-assinada, tipo do conteudo e o instante em que
 * a permissao expira. **Nunca o arquivo** (RF-PLB-005).
 */
export type Reproducao = components['schemas']['Reproducao']

export type ReproducaoEnvelope = components['schemas']['ReproducaoEnvelope']
