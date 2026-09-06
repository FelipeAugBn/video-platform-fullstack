<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListGrantedCourses;

/**
 * Qual pagina, para qual consumidor.
 *
 * `consumerId` e obrigatorio e vem da sessao. Nao existe listagem sem consumidor
 * nesta porta — uma consulta "todos os cursos disponiveis" nao teria a quem
 * responder, e existir sem uso seria o convite para alguem chama-la sem recorte.
 *
 * Os dois numeros ja chegam saneados: quem valida formato e aplica o teto de
 * tamanho e a fronteira HTTP, onde a recusa vira `422` com mensagem por campo.
 */
final class ListGrantedCoursesQuery
{
    public function __construct(
        public readonly string $consumerId,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
