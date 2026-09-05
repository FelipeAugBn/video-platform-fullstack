<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\InvalidPosition;

/**
 * Um modulo, e o lugar dele dentro do curso.
 *
 * O agregado guarda duas coisas que precisam andar juntas: a que curso ele
 * pertence e qual e a sua posicao la dentro. A posicao nao e enfeite — e ela que
 * ordena a leitura da estrutura, e a spec exige que essa ordem seja preservada
 * (RN-ORD-001).
 *
 * **O modulo nao escolhe a propria posicao, e nao sabe calcula-la.** Quem calcula
 * e o caso de uso, sob lock da linha do curso, porque a resposta depende dos
 * outros modulos do mesmo curso — informacao que este objeto nao tem e nao
 * deveria ter (plan §§6.1, 8.1). O que o agregado garante e mais restrito e mais
 * util: a posicao que chegar precisa ser um inteiro valido.
 *
 * Duas formas de nascer, com o mesmo significado que em `Course`:
 *
 *   `create`       um modulo novo, com a posicao que o caso de uso calculou.
 *   `reconstitute` um modulo que ja existia, relido do armazenamento.
 *
 * As duas recebem posicao, e por isso a diferenca aqui e menor do que em
 * `Course` — nao ha campo derivado de regra na criacao. Elas continuam separadas
 * porque a distincao entre criar e reconstituir vale para o vocabulario da
 * camada, e apagar essa distincao neste agregado obrigaria a reintroduzi-la
 * quando o modulo ganhasse qualquer campo com valor inicial proprio.
 *
 * PHP puro, como todo o `Domain` (plan §§5.1, 6.2): sem Eloquent, sem facade,
 * sem Request, sem status HTTP.
 */
final class Module
{
    /**
     * A menor posicao valida.
     *
     * Um espelho do `CHECK (position >= 1)` da tabela, e nao uma duplicata
     * inutil: o banco recusa a linha, mas quem recebe a recusa e o adapter, tarde
     * demais para dizer o que aconteceu. Aqui a violacao falha no lugar em que a
     * regra e conhecida.
     */
    public const PRIMEIRA_POSICAO = 1;

    private function __construct(
        private readonly string $id,
        private readonly string $courseId,
        private readonly string $title,
        private readonly int $position,
    ) {}

    /**
     * Um modulo novo, no fim do curso.
     *
     * @throws InvalidPosition quando a posicao calculada nao e uma posicao valida
     */
    public static function create(string $id, string $courseId, string $title, int $position): self
    {
        self::garantirPosicao($position);

        return new self($id, $courseId, $title, $position);
    }

    /**
     * Um modulo que ja existia.
     *
     * A posicao tambem e verificada aqui. Poderia parecer redundante — ela ja
     * passou pela regra quando foi criada —, mas reconstituir le uma linha que
     * pode ter sido escrita por qualquer caminho, inclusive um seed ou uma
     * correcao manual no banco. Uma posicao invalida vinda de la deve falhar na
     * leitura, e nao virar uma ordem silenciosamente errada na tela.
     *
     * @throws InvalidPosition quando a linha lida traz uma posicao invalida
     */
    public static function reconstitute(string $id, string $courseId, string $title, int $position): self
    {
        self::garantirPosicao($position);

        return new self($id, $courseId, $title, $position);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function courseId(): string
    {
        return $this->courseId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Responde se este modulo pertence ao curso informado.
     *
     * A propriedade de um modulo e herdada do curso (RN-PROP-004), entao o
     * agregado nao conhece produtor nenhum: ele sabe apenas de que curso e. Quem
     * resolve a cadeia ate o dono e a consulta, dentro do SQL.
     */
    public function belongsToCourse(string $courseId): bool
    {
        return $this->courseId === $courseId;
    }

    /**
     * @throws InvalidPosition
     */
    private static function garantirPosicao(int $position): void
    {
        if ($position < self::PRIMEIRA_POSICAO) {
            throw InvalidPosition::below($position);
        }
    }
}
