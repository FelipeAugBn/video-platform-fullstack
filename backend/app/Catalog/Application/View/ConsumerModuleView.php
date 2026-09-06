<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

/**
 * Um modulo da arvore do consumidor, com as aulas publicadas dele em ordem.
 *
 * O aninhamento existe apenas na leitura, como em {@see ModuleView}: `Module` e
 * `Lesson` continuam sendo raizes de agregado separadas (plan §6.1), e nada aqui
 * pode ser salvo.
 *
 * A ordem dos itens de `lessons` **e** o contrato: a lista chega ordenada por
 * posicao pela consulta, e nada aqui reordena (RF-EST-002, RF-CONS-003).
 *
 * Um modulo cujas aulas estejam todas em rascunho aparece com a lista vazia, e
 * nao desaparece: a estrutura do curso e a mesma para todos, e o que o
 * consumidor deixa de ver e o conteudo nao publicado, nao a organizacao.
 */
final class ConsumerModuleView
{
    /**
     * @param  list<ConsumerLessonView>  $lessons
     */
    public function __construct(
        public readonly string $id,
        public readonly string $courseId,
        public readonly string $title,
        public readonly int $position,
        public readonly array $lessons,
    ) {}
}
