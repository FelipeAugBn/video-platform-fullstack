<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListCourses;

/**
 * Qual pagina, de quem.
 *
 * `ownerId` e obrigatorio e vem da sessao. Nao existe listagem sem dono nesta
 * porta — uma consulta "todos os cursos" nao teria a quem responder nesta etapa,
 * e existir sem uso seria o convite para alguem chama-la sem filtro.
 *
 * Os dois numeros ja chegam saneados: quem valida formato e aplica o teto de
 * tamanho e a fronteira HTTP, onde a recusa vira `422` com mensagem por campo.
 */
final class ListCoursesQuery
{
    public function __construct(
        public readonly string $ownerId,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
