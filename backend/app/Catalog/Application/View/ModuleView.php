<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

/**
 * Um modulo dentro da estrutura, com as aulas dele em ordem.
 *
 * O aninhamento existe apenas na leitura. `Module` e `Lesson` continuam sendo
 * raizes de agregado separadas, cada uma com seu repositorio (plan §6.1): nada
 * aqui pode ser salvo, e montar esta arvore nao cria um agregado gigante — cria
 * uma representacao para consulta.
 *
 * A ordem dos itens de `lessons` **e** o contrato: a lista chega ordenada por
 * posicao pela consulta, e nada aqui reordena (RF-EST-002).
 */
final class ModuleView
{
    /**
     * @param  list<LessonView>  $lessons
     */
    public function __construct(
        public readonly string $id,
        public readonly string $courseId,
        public readonly string $title,
        public readonly int $position,
        public readonly array $lessons,
    ) {}
}
