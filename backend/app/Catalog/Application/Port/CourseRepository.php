<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Course;
use App\Shared\Application\Page;

/**
 * O que os casos de uso de catalogo precisam de um armazenamento de cursos —
 * nada alem disso (plan §5.3).
 *
 * Nao e um CRUD. Nao ha `all`, `find`, `update` nem `delete`, e a ausencia e a
 * parte importante: **nao existe assinatura nesta porta capaz de alcancar um
 * curso sem informar o dono**. Um `findById($id)` inocente seria o caminho por
 * onde o isolamento de RN-PROP-002 vazaria na proxima tarefa, e ele vazaria em
 * silencio, porque quem chamasse nao veria nada de errado.
 *
 * O dono, portanto, nao e um filtro opcional aplicado depois: e parametro
 * obrigatorio das duas leituras de curso individual.
 *
 * A regra de dependencia vale aqui integralmente (plan §5.1): esta interface nao
 * conhece Eloquent, Query Builder, `Illuminate\Pagination`, Request, Response nem
 * qualquer classe de Infrastructure. Ela fala em `Course` e em `Page`.
 */
interface CourseRepository
{
    /**
     * O proximo identificador, obtido antes de existir linha no banco.
     *
     * A identidade nasce com o agregado, e nao no `INSERT`. E o que permite ao
     * caso de uso montar o `Course` completo — e ao dominio exigir identificador
     * no construtor — sem que o banco precise ser consultado no meio da regra.
     */
    public function nextIdentity(): string;

    public function save(Course $course): void;

    /**
     * O curso, se ele for **deste** dono.
     *
     * Devolve `null` tanto para curso inexistente quanto para curso de outro
     * produtor. A indistincao e proposital e comeca aqui: quem chama nao recebe
     * informacao suficiente para diferenciar os dois casos, entao nao tem como
     * responde-los de forma diferente por engano (RN-PROP-005, RN-AUT-006).
     */
    public function findOwned(string $courseId, string $ownerId): ?Course;

    /**
     * Uma pagina dos cursos deste dono, do mais recente para o mais antigo.
     *
     * O recorte por dono pertence a consulta, e nao ao resultado: `total` e
     * numero de paginas precisam contar somente o que e dele.
     */
    public function pageOfOwner(string $ownerId, int $page, int $perPage): Page;

    /**
     * O curso, deste dono, com a linha travada ate o fim da transacao.
     *
     * Existe para as operacoes que alteram a arvore do curso a partir da proxima
     * tarefa — criar modulo e aula exige que o pai nao mude por baixo enquanto a
     * ordem e calculada. Esta declarada agora porque a decisao de travar pertence
     * a esta porta, e nao ao caso de uso que vier depois.
     *
     * Filtra dono pelo mesmo motivo de `findOwned`: uma leitura travada sem esse
     * filtro seria uma segunda porta de entrada para curso alheio, e ainda por
     * cima uma que segura recurso do banco.
     */
    public function lockOwned(string $courseId, string $ownerId): ?Course;
}
