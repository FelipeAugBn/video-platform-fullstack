<?php

declare(strict_types=1);

namespace App\Video\Application\GetPlayback;

use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Catalog\Domain\Lesson;
use App\Shared\Application\Port\Clock;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;

/**
 * Autoriza a reproducao de uma aula e emite a URL curta que a entrega
 * (RF-PLB-001 a 008, plan §14.2).
 *
 * ## Quatro verificacoes, nesta ordem
 *
 *   1. **concessao** — a aula so e alcancada dentro de um curso concedido;
 *   2. **publicacao** — a aula precisa estar publicada;
 *   3. **estado do video** — a tentativa atual precisa estar em `ready`;
 *   4. **referencia** — precisa haver uma referencia de reproducao gravada.
 *
 * A ordem nao e estilistica. A primeira e a unica que responde `404`, e ela vem
 * antes de tudo justamente para que nada do conteudo — nem o titulo, nem o
 * estado do video, nem a existencia da aula — seja observavel por quem nao tem
 * acesso (RN-PROP-005, RF-PLB-008). As tres seguintes respondem `409`, porque
 * descrevem indisponibilidade de algo que o consumidor **pode** ver, e a
 * interface precisa separar "ainda nao esta pronto" de "nao e para voce"
 * (RF-PLB-007, RF-UI-015).
 *
 * ## O storage e a ultima coisa a ser chamada
 *
 * `presignRead` acontece depois das quatro verificacoes, sem excecao. Uma URL
 * assinada nao comprova que quem a recebeu tinha direito — comprova apenas que a
 * aplicacao permitiu; emiti-la antes de decidir seria assinar primeiro e pensar
 * depois. Nenhum caminho negativo deste arquivo passa pela linha que a emite.
 *
 * ## A chave vem do dominio, nunca do cliente
 *
 * A URL e assinada sobre a `playback_reference` **gravada pela conclusao do
 * processamento**, e nao sobre uma chave remontada a partir de identificadores
 * recebidos na requisicao. Remontar transformaria um parametro de rota em
 * caminho de armazenamento, que e exatamente o que a derivacao da chave em
 * `VideoAttempt` existe para impedir (plan §11.3).
 *
 * ## Leitura pura
 *
 * Sem transacao e sem trava: nada e decidido sobre o estado, nada e gravado.
 * Travar linhas para responder a um `GET` faria consultas simultaneas esperarem
 * umas pelas outras sem disputar coisa alguma.
 */
final class GetPlayback
{
    public function __construct(
        private readonly ConsumerCatalogReadModel $catalog,
        private readonly VideoAttemptRepository $attempts,
        private readonly ObjectStorage $storage,
        private readonly Clock $clock,
        private readonly int $urlTtlSeconds,
    ) {}

    /**
     * @throws DomainException
     */
    public function __invoke(GetPlaybackQuery $consulta): Playback
    {
        $aula = $this->autorizar($consulta);
        $tentativa = $this->exigirVideoReproduzivel($aula);

        $expiraEm = $this->clock->now()->modify('+'.$this->urlTtlSeconds.' seconds');

        try {
            $url = $this->storage->presignRead((string) $tentativa->playbackReference(), $expiraEm);
        } catch (StorageFailure) {
            // Nada da excecao original atravessa: nem mensagem, nem provedor,
            // nem chave. Quem pediu recebe a mesma indisponibilidade temporaria
            // que qualquer outra falha de infraestrutura produz (RN-AUT-005).
            throw new DomainException(Failure::SERVICE_UNAVAILABLE);
        }

        return new Playback(
            url: $url,
            expiresAt: $expiraEm,
            // O tipo observado no objeto durante a conclusao do envio. O
            // declarado e a reserva para uma tentativa antiga que tenha chegado
            // a `ready` sem passar por essa verificacao — os dois valem
            // `video/mp4`, porque a abertura ja recusa qualquer outro
            // (RF-UPL-006).
            contentType: $tentativa->verifiedContentType() ?? $tentativa->declaredContentType(),
        );
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
     * A tentativa atual da aula, se ela puder ser reproduzida.
     *
     * As tres condicoes sao verificadas **depois** da autorizacao, e as tres
     * respondem `409`. Sao caminhos defensivos: publicar exige video em `ready`
     * com referencia (RN-PUB-002, RN-PUB-003), e um video pronto nao pode ser
     * substituido (RF-UPL-012), entao uma aula publicada sem video reproduzivel
     * nao deveria existir. A verificacao continua aqui exatamente porque "nao
     * deveria" nao e garantia — e o ponto de recusa correto e antes de assinar
     * uma URL, e nao depois.
     *
     * @throws DomainException
     */
    private function exigirVideoReproduzivel(Lesson $aula): VideoAttempt
    {
        if ($aula->isDraft()) {
            throw new DomainException(Failure::LESSON_NOT_PUBLISHED);
        }

        $tentativa = $aula->currentVideoAttemptId() === null
            ? null
            : $this->attempts->findCurrentOfLesson($aula->id());

        // `isPlayable()` responde as duas ultimas condicoes de uma vez, e a
        // pergunta pertence ao agregado: repetir aqui `state === READY &&
        // referencia !== null` daria uma segunda definicao de "reproduzivel",
        // livre para divergir da que a publicacao ja consulta.
        if ($tentativa === null || ! $tentativa->isPlayable()) {
            throw new DomainException(Failure::LESSON_VIDEO_NOT_READY);
        }

        return $tentativa;
    }
}
