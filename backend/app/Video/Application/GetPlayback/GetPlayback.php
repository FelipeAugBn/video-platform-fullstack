<?php

declare(strict_types=1);

namespace App\Video\Application\GetPlayback;

use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Catalog\Domain\Lesson;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\IssuePlayback\IssuePlayback;
use App\Video\Application\IssuePlayback\Playback;

/**
 * Autoriza a reproducao de uma aula **para o consumidor** e emite a URL curta
 * que a entrega (RF-PLB-001 a 008, plan §14.2).
 *
 * ## Quatro verificacoes, nesta ordem
 *
 *   1. **concessao** — a aula so e alcancada dentro de um curso concedido;
 *   2. **publicacao** — a aula precisa estar publicada;
 *   3. **estado do video** — a tentativa atual precisa estar em `ready`;
 *   4. **referencia** — precisa haver uma referencia de reproducao gravada.
 *
 * As duas primeiras sao desta classe; as duas ultimas, e a assinatura da URL,
 * ficam em {@see IssuePlayback}, que o produtor tambem usa.
 *
 * A ordem nao e estilistica. A primeira e a unica que responde `404`, e ela vem
 * antes de tudo justamente para que nada do conteudo — nem o titulo, nem o
 * estado do video, nem a existencia da aula — seja observavel por quem nao tem
 * acesso (RN-PROP-005, RF-PLB-008). As tres seguintes respondem `409`, porque
 * descrevem indisponibilidade de algo que o consumidor **pode** ver, e a
 * interface precisa separar "ainda nao esta pronto" de "nao e para voce"
 * (RF-PLB-007, RF-UI-015).
 *
 * ## Publicacao e regra **daqui**, e nao da emissao
 *
 * A aula em rascunho e invisivel para o consumidor e visivel para quem a
 * produziu: o produtor proprietario reproduz o proprio video antes de publicar,
 * justamente para decidir se publica (RF-PLB-009). Por isso a exigencia de
 * publicacao fica neste caso de uso, e nao na emissao compartilhada — ali ela
 * viraria uma condicao com excecao por perfil.
 *
 * ## Leitura pura
 *
 * Sem transacao e sem trava: nada e decidido sobre o estado, nada e gravado.
 */
final class GetPlayback
{
    public function __construct(
        private readonly ConsumerCatalogReadModel $catalog,
        private readonly IssuePlayback $emitirReproducao,
    ) {}

    /**
     * @throws DomainException
     */
    public function __invoke(GetPlaybackQuery $consulta): Playback
    {
        $aula = $this->autorizar($consulta);

        $this->exigirPublicada($aula);

        return ($this->emitirReproducao)($aula);
    }

    /**
     * A aula, se o curso dela estiver concedido a este consumidor.
     *
     * Aula inexistente e aula de curso nao concedido produzem a mesma recusa, e
     * produzem-na pelo mesmo caminho: a consulta resolve a concessao dentro do
     * SQL e devolve `null` nos dois casos, sem informacao que permita
     * diferencia-los (AC-CONS-002, AC-CONS-005).
     *
     * @throws DomainException
     */
    private function autorizar(GetPlaybackQuery $consulta): Lesson
    {
        $aula = $this->catalog->lessonOfConsumer($consulta->lessonId, $consulta->consumerId);

        if ($aula === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $aula;
    }

    /**
     * Aula em rascunho nao e reproduzivel por quem consome (RF-PLB-003).
     *
     * `409`, e nao `404`: a aula esta dentro de um curso concedido, e a
     * distincao entre "ainda nao esta pronto" e "nao e para voce" e o que a
     * interface precisa para escolher entre esperar e desistir.
     *
     * @throws DomainException
     */
    private function exigirPublicada(Lesson $aula): void
    {
        if ($aula->isDraft()) {
            throw new DomainException(Failure::LESSON_NOT_PUBLISHED);
        }
    }
}
