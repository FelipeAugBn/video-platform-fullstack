<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Lesson;

/**
 * O que os casos de uso precisam de um armazenamento de aulas (plan §5.3).
 *
 * Mesma disciplina das outras duas portas do catalogo: nenhuma assinatura
 * alcanca uma aula sem informar o dono. A cadeia aqui e mais longa —
 * `lesson -> module -> course -> owner_id` —, e ela e percorrida **dentro do
 * SQL**. Carregar a aula para so entao comparar o dono em PHP seria ler recurso
 * alheio antes de recusa-lo, e a diferenca aparece na paginacao e na contagem de
 * qualquer listagem futura.
 *
 * A regra de dependencia vale integralmente (plan §5.1): sem Eloquent, sem
 * Query Builder, sem Request. A porta fala em `Lesson` e em tipos da linguagem.
 *
 * **O estado do video nao esta aqui**, e nao por esquecimento: a tentativa e
 * outro agregado, e a aula guarda apenas o identificador da atual (RF-AUL-005).
 * Quem precisa do estado junto — a consulta de aula e a estrutura — usa a porta
 * de leitura `CatalogReadModel`, que resolve as duas coisas numa consulta so.
 */
interface LessonRepository
{
    public function nextIdentity(): string;

    public function save(Lesson $lesson): void;

    /**
     * A aula, se ela pertencer a um curso **deste** dono.
     *
     * Devolve `null` para aula inexistente e para aula alheia, sem distincao
     * (RN-PROP-005, RN-AUT-006).
     *
     * E a leitura do agregado, para quem vai **mudar** a aula: abrir um envio de
     * video e publicar precisam do objeto de dominio, e nao de uma projecao. A
     * consulta de aula da API usa a porta de leitura, porque a resposta inclui o
     * estado do video.
     */
    public function findOwned(string $lessonId, string $ownerId): ?Lesson;

    /**
     * O mesmo que {@see findOwned()}, com a linha travada ate o fim da transacao.
     *
     * Duas operacoes precisam dela, e pelo mesmo motivo: abrir um envio e
     * publicar decidem sobre o estado da aula e o alteram na sequencia
     * (plan §8.5). Ler sem travar deixaria duas requisicoes simultaneas
     * decidirem sobre a mesma leitura e gravarem por cima uma da outra.
     */
    public function lockOwned(string $lessonId, string $ownerId): ?Lesson;

    /**
     * A proxima posicao livre dentro do modulo: o maior valor existente mais um,
     * ou 1 quando ainda nao houver aula (RN-ORD-002).
     *
     * Vale sob a trava da linha do modulo, pelo mesmo motivo descrito em
     * `ModuleRepository::nextPosition`. A UNIQUE `(module_id, position)` continua
     * sendo a ultima linha de defesa.
     */
    public function nextPosition(string $moduleId): int;

    /**
     * As aulas de um modulo **deste** dono, em ordem de posicao.
     *
     * @return list<Lesson>
     */
    public function listOfOwnedModule(string $moduleId, string $ownerId): array;
}
