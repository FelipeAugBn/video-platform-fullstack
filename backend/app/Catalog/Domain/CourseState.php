<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Os dois estados possiveis de um curso (RN-CUR-001).
 *
 * Nao ha `published` nem `archived`, e a ausencia e deliberada: nao existe
 * operacao de publicar curso nesta solucao. O curso passa a `available` como
 * **consequencia** de a primeira aula dele ser publicada, e nao por alguem pedir
 * a mudanca. Declarar um terceiro valor sugeriria um comando que ninguem pode
 * emitir.
 *
 * A transicao para `available` nao existe aqui ainda: ela pertence a publicacao
 * de aula, e chega junto do caso de uso que a provoca.
 */
enum CourseState: string
{
    case DRAFT = 'draft';
    case AVAILABLE = 'available';
}
