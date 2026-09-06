<?php

declare(strict_types=1);

namespace App\Video\Application\IssuePartUrl;

use App\Shared\Application\Port\Clock;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Application\Service\ResolveOwnedAttempt;
use App\Video\Domain\UploadPolicy;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;

/**
 * Autoriza o envio de uma parte, e move a tentativa na primeira vez
 * (RF-UPL-007, plan §11.3).
 *
 * ## Por que a transicao acontece aqui e nao na abertura
 *
 * O **primeiro** pedido de URL de parte e o primeiro sinal observavel pelo
 * backend de que o cliente comecou a executar a estrategia de transferencia que
 * a API forneceu. Transicionar ja na abertura apagaria a distincao entre
 * "autorizado a enviar" e "enviando", e a spec mantem os dois estados separados;
 * um endpoint dedicado so para anunciar o inicio acrescentaria uma rota sem
 * informacao nova.
 *
 * Os pedidos seguintes — inclusive a renovacao de uma URL vencida — encontram a
 * tentativa ja em `uploading` e **nao mudam nada**. Renovar nao cria tentativa,
 * nao repete a transicao e nao toca no dominio.
 *
 * ## A ordem: transicionar, commitar, so entao assinar
 *
 * A assinatura vem depois do commit, e nao antes. Invertida, uma falha ao
 * transicionar depois de a URL ja ter sido entregue deixaria o cliente enviando
 * bytes de verdade com a tentativa parada em `pending` — e a conclusao desse
 * envio seria recusada por estado incompativel, com o arquivo ja no
 * armazenamento.
 *
 * No sentido escolhido, o pior caso e a transicao valer sem URL emitida: a
 * tentativa fica em `uploading`, que e exatamente o estado de um envio comecado
 * e nao concluido (RN-VID-005), e o cliente repete o pedido.
 */
final class IssuePartUrl
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ResolveOwnedAttempt $resolverTentativa,
        private readonly VideoAttemptRepository $attempts,
        private readonly ObjectStorage $storage,
        private readonly UploadPolicy $policy,
        private readonly Clock $clock,
    ) {}

    public function __invoke(IssuePartUrlCommand $comando): PartUrl
    {
        $tentativa = $this->transactions->transactional(
            fn (): VideoAttempt => $this->prepararEnvio($comando),
        );

        $expiraEm = $this->clock->now()->modify('+'.$this->policy->partUrlTtl.' seconds');

        return new PartUrl(
            url: $this->storage->presignUploadPart(
                $tentativa->storageKey(),
                (string) $tentativa->multipartUploadId(),
                $comando->partNumber,
                $expiraEm,
            ),
            expiresAt: $expiraEm,
        );
    }

    /**
     * @throws DomainException
     */
    private function prepararEnvio(IssuePartUrlCommand $comando): VideoAttempt
    {
        $tentativa = $this->resolverTentativa->locked($comando->attemptId, $comando->ownerId);

        if (! $this->policy->temParte($comando->partNumber, $tentativa->declaredSize())) {
            throw new DomainException(Failure::VALIDATION_FAILED);
        }

        return match ($tentativa->state()) {
            VideoState::PENDING => $this->iniciar($tentativa),
            VideoState::UPLOADING => $tentativa,
            // `uploaded`, `processing`, `ready` e `failed` nao aceitam mais
            // partes: o envio ja foi encerrado, com ou sem sucesso. A recusa e
            // `409` com codigo proprio para que a interface distinga "envio
            // encerrado" de "esta aula nao e sua".
            default => throw new DomainException(Failure::VIDEO_UPLOAD_NOT_ACTIVE),
        };
    }

    private function iniciar(VideoAttempt $tentativa): VideoAttempt
    {
        $iniciada = $tentativa->beginUpload();
        $this->attempts->save($iniciada);

        return $iniciada;
    }
}
