<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Module;

/**
 * O que os casos de uso precisam de um armazenamento de modulos (plan §5.3).
 *
 * Mesma disciplina de `CourseRepository`: nao e CRUD, e **nao existe assinatura
 * aqui capaz de alcancar um modulo sem informar o dono**. Um `findById($id)`
 * seria o caminho por onde o isolamento de RN-PROP-002 vazaria na proxima
 * tarefa — e vazaria em silencio, porque quem chamasse nao veria nada de errado.
 *
 * A propriedade de um modulo e herdada do curso (RN-PROP-004), entao o filtro
 * atravessa a cadeia `module -> course -> owner_id` **dentro do SQL**. Carregar
 * o modulo para comparar o dono em PHP seria carregar recurso alheio antes de
 * recusa-lo.
 *
 * A regra de dependencia vale aqui integralmente (plan §5.1): esta interface nao
 * conhece Eloquent, Query Builder, Request nem qualquer classe de
 * Infrastructure. Ela fala em `Module` e em tipos da linguagem.
 */
interface ModuleRepository
{
    /**
     * O proximo identificador, obtido antes de existir linha no banco.
     *
     * Mesmo motivo de `CourseRepository::nextIdentity`: a identidade nasce com o
     * agregado, e nao no `INSERT`.
     */
    public function nextIdentity(): string;

    public function save(Module $module): void;

    /**
     * O modulo, se ele for de um curso **deste** dono, com a linha travada ate o
     * fim da transacao.
     *
     * Devolve `null` tanto para modulo inexistente quanto para modulo de outro
     * produtor — a indistincao comeca aqui, e quem chama nao recebe informacao
     * suficiente para responder os dois casos de forma diferente por engano
     * (RN-PROP-005, RN-AUT-006).
     *
     * A trava e o que serializa duas criacoes de aula simultaneas no mesmo
     * modulo: sem ela, as duas leriam a mesma maior posicao e disputariam o mesmo
     * valor (plan §8.1).
     */
    public function lockOwned(string $moduleId, string $ownerId): ?Module;

    /**
     * A proxima posicao livre dentro do curso: o maior valor existente mais um,
     * ou 1 quando ainda nao houver modulo (RN-ORD-002).
     *
     * **O resultado so vale sob a trava da linha do curso.** Chamada fora da
     * transacao que travou o pai, ela devolve um numero que outra requisicao pode
     * ja ter consumido. Nao ha como a porta impedir isso; o que ha e a UNIQUE
     * `(course_id, position)`, que recusa a segunda gravacao (plan §8.1).
     *
     * O curso ja foi resolvido pelo dono antes desta chamada, e por isso ela nao
     * repete o filtro: ela conta modulos de um curso cuja propriedade a operacao
     * acabou de travar.
     */
    public function nextPosition(string $courseId): int;

    /**
     * Os modulos de um curso **deste** dono, em ordem de posicao.
     *
     * Recebe o dono mesmo com o curso ja resolvido pelo caso de uso. E redundancia
     * deliberada: a consulta que lista conteudo continua sendo, por si so,
     * incapaz de devolver material alheio, independentemente de quem a chame no
     * futuro.
     *
     * @return list<Module>
     */
    public function listOfOwnedCourse(string $courseId, string $ownerId): array;
}
