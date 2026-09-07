<?php

declare(strict_types=1);

namespace App\Video\Application\IssuePlayback;

use App\Catalog\Domain\Lesson;
use App\Shared\Application\Port\Clock;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;

/**
 * A emissao da URL de reproducao — a parte que os dois perfis compartilham
 * (plan §14.2).
 *
 * ## O que ela faz, e o que ela deliberadamente nao faz
 *
 * **Faz:** localiza a tentativa atual da aula, exige que ela seja reproduzivel,
 * assina uma URL de leitura de curta duracao sobre a referencia gravada e
 * devolve os tres campos do contrato.
 *
 * **Nao faz:** autorizacao. Quem chega aqui ja decidiu que quem pediu pode
 * pedir. Sao dois caminhos com politicas diferentes — o consumidor precisa de
 * concessao e de aula publicada; o produtor, de propriedade, e a publicacao nao
 * o alcanca (RF-PLB-009) — e nenhuma das duas cabe nesta classe. Trazer as duas
 * para ca obrigaria a escolher a regra pelo perfil de quem chamou, que e
 * exatamente o acoplamento que a separacao dos casos de uso evita.
 *
 * ## Por que extrair
 *
 * O que os dois perfis realmente compartilham e curto: o prazo da URL, a porta
 * de armazenamento, a traducao da falha de assinatura e o formato da resposta.
 * Copiado, esse trecho divergiria no primeiro ajuste de prazo — um perfil
 * assinando por cinco minutos e o outro por outro numero, sem que nada avisasse.
 *
 * ## O storage e a ultima coisa a ser chamada
 *
 * `presignRead` acontece depois da verificacao de disponibilidade, sem excecao.
 * Uma URL assinada nao comprova que quem a recebeu tinha direito — comprova
 * apenas que a aplicacao permitiu; emiti-la antes de decidir seria assinar
 * primeiro e pensar depois.
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
final class IssuePlayback
{
    public function __construct(
        private readonly VideoAttemptRepository $attempts,
        private readonly ObjectStorage $storage,
        private readonly Clock $clock,
        private readonly int $urlTtlSeconds,
    ) {}

    /**
     * @throws DomainException
     */
    public function __invoke(Lesson $aula): Playback
    {
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
     * A tentativa atual da aula, se ela puder ser reproduzida.
     *
     * Tres condicoes — existir tentativa atual, estar em `ready` e ter
     * referencia gravada — e uma resposta so, `409` com
     * `LESSON_VIDEO_NOT_READY`. Distingui-las daria ao consumidor tres textos
     * para a mesma situacao ("ainda nao da para assistir") e ao produtor uma
     * informacao que o painel do video ja mostra em detalhe.
     *
     * Sao caminhos defensivos nos dois perfis. Do lado do consumidor, publicar
     * exige video em `ready` com referencia (RN-PUB-002, RN-PUB-003), entao uma
     * aula publicada sem video reproduzivel nao deveria existir; do lado do
     * produtor, a interface so oferece a acao em `ready`. A verificacao continua
     * aqui exatamente porque "nao deveria" nao e garantia — e o ponto de recusa
     * correto e antes de assinar uma URL, e nao depois.
     *
     * @throws DomainException
     */
    private function exigirVideoReproduzivel(Lesson $aula): VideoAttempt
    {
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
