<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

use DateTimeImmutable;

/**
 * Uma aula como o **consumidor** a ve.
 *
 * Existe separada de {@see LessonView} por causa do que ela nao tem. A projecao
 * do produtor carrega `videoState`, porque a jornada dele e acompanhar o
 * processamento (RF-EST-004); a do consumidor nao carrega — nem esse campo, nem
 * chave de armazenamento, nem motivo de falha. A separacao e por **tipo**, e nao
 * por disciplina de quem monta a resposta: nao existe caminho pelo qual o estado
 * interno do video chegue a um consumidor, porque ele nao esta neste objeto.
 *
 * `publishedAt` nao e anulavel, e isso tambem e afirmacao. A arvore do consumidor
 * so contem aulas publicadas (RN-AUT-004), e um rascunho simplesmente nao tem
 * como ser representado aqui.
 *
 * Imutavel e sem comportamento: e resultado de leitura, nao objeto de dominio.
 */
final class ConsumerLessonView
{
    public function __construct(
        public readonly string $id,
        public readonly string $moduleId,
        public readonly string $title,
        public readonly int $position,
        public readonly DateTimeImmutable $publishedAt,
    ) {}
}
