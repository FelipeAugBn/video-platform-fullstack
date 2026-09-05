<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use DateTimeImmutable;

/**
 * Um curso, e o dono dele.
 *
 * A propriedade nao e um campo qualquer: ela e a razao pela qual este agregado
 * existe separado (RN-PROP-001). Todo curso pertence a exatamente um produtor, e
 * o identificador do dono entra na construcao — nao ha estado intermediario em
 * que um curso exista sem dono.
 *
 * Duas formas de nascer, com significados diferentes:
 *
 *   `create`       um curso novo, agora. Sempre `draft`, sem excecao.
 *   `reconstitute` um curso que ja existia, relido do armazenamento com o
 *                  estado que ele tinha.
 *
 * Separa-las e o que impede o armazenamento de criar cursos e o caso de uso de
 * ressuscitar um estado arbitrario. Um unico construtor publico permitiria a
 * qualquer chamador escolher o estado inicial, e a garantia de RN-CUR-001
 * passaria a depender de disciplina de quem escreve o proximo caso de uso.
 *
 * **O que este arquivo deliberadamente nao faz:** validar tamanho de titulo ou
 * descricao. Esses limites vem da coluna e da entrada do usuario, nao da regra de
 * negocio, e sao recusados na fronteira HTTP com `422` e mensagem por campo. Um
 * agregado que os repetisse produziria uma segunda rejeicao, com outro formato,
 * para o mesmo problema.
 *
 * PHP puro, como todo o `Domain` (plan §§5.1, 6.2): sem Eloquent, sem facade,
 * sem Request, sem status HTTP.
 */
final class Course
{
    private function __construct(
        private readonly string $id,
        private readonly string $ownerId,
        private readonly string $title,
        private readonly string $description,
        private readonly CourseState $state,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * Um curso novo.
     *
     * O estado nao e parametro. Quem chama informa o que sabe — identidade, dono,
     * conteudo e instante —, e o estado inicial e resultado da regra, nao escolha
     * de quem cria (RN-CUR-001).
     */
    public static function create(
        string $id,
        string $ownerId,
        string $title,
        string $description,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            ownerId: $ownerId,
            title: $title,
            description: $description,
            state: CourseState::DRAFT,
            createdAt: $createdAt,
        );
    }

    /**
     * Um curso que ja existia.
     *
     * Usado apenas pelo adapter de persistencia, ao traduzir uma linha de volta
     * para o dominio. Aqui o estado vem de fora porque ele ja foi decidido
     * quando o curso foi criado ou publicado — reconstituir nao e criar.
     */
    public static function reconstitute(
        string $id,
        string $ownerId,
        string $title,
        string $description,
        CourseState $state,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            ownerId: $ownerId,
            title: $title,
            description: $description,
            state: $state,
            createdAt: $createdAt,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function state(): CourseState
    {
        return $this->state;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Responde se este curso pertence ao produtor informado.
     *
     * A comparacao vive no agregado, e nao espalhada em cada caso de uso, para
     * que a definicao de "e meu" tenha um lugar so. Ela nao substitui o filtro na
     * consulta: o SQL continua sendo a barreira que impede o curso alheio de
     * sequer ser carregado (RN-PROP-002).
     */
    public function isOwnedBy(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }
}
