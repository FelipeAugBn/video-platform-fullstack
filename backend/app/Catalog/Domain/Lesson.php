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
 * **A publicacao vive aqui, e a elegibilidade nao.** `publish()` marca o
 * instante; quem verifica se o video esta pronto e tem referencia de reproducao
 * e o caso de uso, que coordena os tres agregados sob trava (plan §6.1). A
 * divisao e consequencia direta de `VideoAttempt` ser outro agregado: a aula nao
 * guarda o estado do video, so o identificador da tentativa — e um metodo aqui
 * que decidisse sobre um estado que a aula nao tem teria de recebe-lo por
 * parametro, o que e a mesma verificacao escrita num lugar pior.
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
     * A aula passa a apontar para esta tentativa de video.
     *
     * Substituir e apontar para outra linha, e nao sobrescrever a anterior: cada
     * envio cria uma tentativa nova, e o historico fica preservado sem custo
     * (RF-UPL-005, RN-VID-002). Uma aula tem no maximo **uma** tentativa atual
     * (RF-AUL-005), e a assinatura de um unico identificador e o que torna isso
     * verdade por construcao.
     */
    public function attachVideoAttempt(string $videoAttemptId): self
    {
        return new self(
            id: $this->id,
            moduleId: $this->moduleId,
            title: $this->title,
            position: $this->position,
            publishedAt: $this->publishedAt,
            currentVideoAttemptId: $videoAttemptId,
        );
    }

    /**
     * A aula deixa de ser rascunho.
     *
     * **Publicar de novo nao produz efeito** (RN-PUB-005, RN-IDM-004): uma aula
     * ja publicada devolve a si mesma, com o instante original preservado. A
     * idempotencia mora aqui, e nao no caso de uso, para que ela valha por
     * qualquer caminho que venha a publicar — e para que o instante da primeira
     * publicacao nunca seja reescrito por uma segunda chamada.
     */
    public function publish(DateTimeImmutable $publishedAt): self
    {
        if (! $this->isDraft()) {
            return $this;
        }

        return new self(
            id: $this->id,
            moduleId: $this->moduleId,
            title: $this->title,
            position: $this->position,
            publishedAt: $publishedAt,
            currentVideoAttemptId: $this->currentVideoAttemptId,
        );
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
