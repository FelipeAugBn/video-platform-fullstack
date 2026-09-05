<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidPosition;
use DateTimeImmutable;

/**
 * Uma aula, o lugar dela dentro do modulo, e o que ainda nao aconteceu com ela.
 *
 * Dois campos anulaveis carregam o estado inicial da aula, e a ausencia e o
 * significado nos dois casos:
 *
 *   `publishedAt`              nulo e **rascunho**. Nao existe um estado
 *                              `draft` proprio: a spec representa o rascunho
 *                              pela ausencia do instante de publicacao
 *                              (plan §7.2, RF-AUL-004), e criar um enum ao lado
 *                              da coluna daria duas fontes para a mesma verdade,
 *                              livres para divergir.
 *   `currentVideoAttemptId`    nulo e **sem video**. Uma aula tem no maximo uma
 *                              tentativa atual (RF-AUL-005), referenciada por
 *                              identificador porque a tentativa e outro agregado,
 *                              com ciclo de vida proprio (plan §6.1).
 *
 * **A publicacao nao e implementada aqui.** Nao ha `publish()` nesta etapa: ela
 * exige o video pronto e a referencia de reproducao presentes, e o agregado de
 * video ainda nao existe. Declarar o metodo agora obrigaria a decidir sem os
 * dados, e um metodo que ninguem chama nao tem teste que o corrija.
 *
 * A posicao segue a mesma regra de `Module`: calculada pelo caso de uso sob lock
 * da linha do modulo, verificada aqui.
 *
 * PHP puro, como todo o `Domain` (plan §§5.1, 6.2).
 */
final class Lesson
{
    private function __construct(
        private readonly string $id,
        private readonly string $moduleId,
        private readonly string $title,
        private readonly int $position,
        private readonly ?DateTimeImmutable $publishedAt,
        private readonly ?string $currentVideoAttemptId,
    ) {}

    /**
     * Uma aula nova: rascunho, sem video.
     *
     * Nem `publishedAt` nem `currentVideoAttemptId` sao parametros. Quem cria
     * informa o que sabe — identidade, modulo, titulo e posicao —, e os dois
     * estados iniciais sao resultado da regra, e nao escolha de quem chama
     * (RF-AUL-004, RF-AUL-005). E a mesma separacao que impede um curso de nascer
     * `available`.
     *
     * @throws InvalidPosition quando a posicao calculada nao e uma posicao valida
     */
    public static function create(string $id, string $moduleId, string $title, int $position): self
    {
        self::garantirPosicao($position);

        return new self(
            id: $id,
            moduleId: $moduleId,
            title: $title,
            position: $position,
            publishedAt: null,
            currentVideoAttemptId: null,
        );
    }

    /**
     * Uma aula que ja existia.
     *
     * Aqui os dois campos vem de fora, porque a essa altura eles ja foram
     * decididos — pela publicacao e pela abertura de um envio. Reconstituir nao e
     * criar.
     *
     * @throws InvalidPosition quando a linha lida traz uma posicao invalida
     */
    public static function reconstitute(
        string $id,
        string $moduleId,
        string $title,
        int $position,
        ?DateTimeImmutable $publishedAt,
        ?string $currentVideoAttemptId,
    ): self {
        self::garantirPosicao($position);

        return new self(
            id: $id,
            moduleId: $moduleId,
            title: $title,
            position: $position,
            publishedAt: $publishedAt,
            currentVideoAttemptId: $currentVideoAttemptId,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function currentVideoAttemptId(): ?string
    {
        return $this->currentVideoAttemptId;
    }

    /**
     * Rascunho e a ausencia de publicacao — dita uma vez, aqui.
     *
     * Existe para que nenhum caso de uso precise escrever `publishedAt === null`
     * por conta propria. Espalhada, essa comparacao seria o lugar por onde uma
     * futura mudanca na representacao do rascunho passaria despercebida.
     */
    public function isDraft(): bool
    {
        return $this->publishedAt === null;
    }

    /**
     * Responde se esta aula pertence ao modulo informado.
     *
     * Como em `Module`, o agregado nao conhece produtor: a propriedade e herdada
     * pela cadeia aula, modulo, curso, dono (RN-PROP-004), e quem a resolve e a
     * consulta.
     */
    public function belongsToModule(string $moduleId): bool
    {
        return $this->moduleId === $moduleId;
    }

    /**
     * @throws InvalidPosition
     */
    private static function garantirPosicao(int $position): void
    {
        if ($position < Module::PRIMEIRA_POSICAO) {
            throw InvalidPosition::below($position);
        }
    }
}
