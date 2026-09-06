<?php

declare(strict_types=1);

namespace App\Video\Application\OpenUpload;

use App\Catalog\Application\Port\LessonRepository;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\Exception\UploadRejected;
use App\Video\Domain\UploadPolicy;
use App\Video\Domain\VideoAttempt;

/**
 * Abre um envio de video para uma aula propria (RF-UPL-001 a 006, RF-UPL-011).
 *
 * ## A ordem das tres etapas e a parte interessante
 *
 * O armazenamento precisa ser chamado — e o `CreateMultipartUpload` que devolve
 * o identificador do envio —, e **nenhuma transacao MySQL pode ficar aberta
 * enquanto ele responde** (plan §7.4). Isso obriga a quebrar a operacao:
 *
 *   1. **leitura, sem transacao** — resolve a aula do dono e recusa o obvio:
 *      aula alheia ou inexistente (`404`), tentativa atual ainda ativa (`409`).
 *      E uma recusa barata, feita antes de gastar uma chamada de rede;
 *   2. **chamada ao armazenamento** — abre o envio em partes sobre a chave
 *      derivada do identificador que acabamos de reservar;
 *   3. **transacao** — trava a aula, **reavalia** a tentativa atual e so entao
 *      grava.
 *
 * O passo 3 reavalia em vez de confiar no passo 1: entre os dois houve uma
 * chamada de rede, e outra requisicao pode ter aberto um envio no intervalo. E o
 * mesmo raciocinio da conclusao (plan §12.2), pela mesma razao.
 *
 * **O custo assumido:** se o passo 3 recusar, o envio aberto no passo 2 fica
 * orfao no armazenamento. Nao ha operacao de descarte nesta entrega, e envios
 * abandonados ja sao uma limitacao registrada (plan §19.1). A alternativa —
 * segurar a transacao durante a chamada de rede — prenderia a linha da aula pelo
 * tempo da latencia alheia e transformaria uma lentidao do armazenamento em
 * contencao no banco.
 *
 * ## O identificador vem antes do objeto
 *
 * A chave e os metadados sao derivados do identificador da tentativa, entao ele
 * e reservado **antes** da chamada ao armazenamento. E esse vinculo que a
 * verificacao da conclusao confere, e e ele que impede um cliente de concluir
 * uma tentativa apontando para um objeto que nao e dela (plan §12.3).
 */
final class OpenUpload
{
    /**
     * A chave dos metadados controlados que carregam o identificador da
     * tentativa.
     *
     * Com hifen, e nao com sublinhado: metadados de objeto viajam como
     * cabecalhos HTTP, e sublinhado em nome de cabecalho e descartado por parte
     * da infraestrutura de rede. O valor volta em {@see CompleteUpload} para ser
     * conferido.
     */
    public const METADADO_TENTATIVA = 'attempt-id';

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LessonRepository $lessons,
        private readonly VideoAttemptRepository $attempts,
        private readonly ObjectStorage $storage,
        private readonly UploadPolicy $policy,
    ) {}

    public function __invoke(OpenUploadCommand $comando): UploadPlan
    {
        $this->garantirDeclaracaoAceitavel($comando);
        $this->recusarCedo($comando);

        $id = $this->attempts->nextIdentity();

        // Fora de qualquer transacao, de proposito (plan §7.4).
        $uploadId = $this->storage->createMultipartUpload(
            VideoAttempt::chavePara($id),
            $comando->contentType,
            [self::METADADO_TENTATIVA => $id],
        );

        $tentativa = $this->transactions->transactional(
            fn (): VideoAttempt => $this->gravar($id, $comando, $uploadId),
        );

        return UploadPlan::de(
            $tentativa,
            $this->policy->partSize,
            $this->policy->partesPara($comando->size),
        );
    }

    /**
     * Tipo e tamanho, contra os limites do backend (RF-UPL-006).
     *
     * A fronteira HTTP ja recusa os dois com `422` e erro por campo, lendo os
     * mesmos limites desta politica. A verificacao continua aqui porque a
     * fronteira nao e a unica porta de entrada possivel de um caso de uso, e
     * porque a regra e de negocio: e o dominio que decide o que a solucao
     * consegue reproduzir, nao o validador da requisicao.
     *
     * @throws DomainException
     */
    private function garantirDeclaracaoAceitavel(OpenUploadCommand $comando): void
    {
        $aceitavel = $this->policy->aceita($comando->contentType)
            && $comando->size >= 1
            && $comando->size <= $this->policy->maxSize;

        if (! $aceitavel) {
            throw new DomainException(Failure::VALIDATION_FAILED);
        }
    }

    /**
     * A recusa barata: antes de qualquer chamada de rede.
     *
     * @throws DomainException|UploadRejected
     */
    private function recusarCedo(OpenUploadCommand $comando): void
    {
        if ($this->lessons->findOwned($comando->lessonId, $comando->ownerId) === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        $this->garantirSemTentativaAtiva($comando->lessonId);
    }

    /**
     * A gravacao, com a aula travada e a tentativa atual reavaliada.
     *
     * @throws DomainException|UploadRejected
     */
    private function gravar(string $id, OpenUploadCommand $comando, string $uploadId): VideoAttempt
    {
        $aula = $this->lessons->lockOwned($comando->lessonId, $comando->ownerId);

        if ($aula === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        // Leitura **travada** da tentativa atual, e nao a mesma consulta do passo
        // 1. Sob `REPEATABLE READ`, uma leitura comum aqui poderia enxergar o
        // instantaneo anterior a abertura concorrente que acabou de commitar, e
        // a reavaliacao decidiria sobre um estado velho.
        $atual = $this->attempts->lockCurrentOfLesson($aula->id());

        if ($atual !== null && $atual->isActive()) {
            throw new UploadRejected($atual->state());
        }

        $tentativa = VideoAttempt::open(
            id: $id,
            lessonId: $aula->id(),
            declaredFilename: $comando->filename,
            declaredContentType: $comando->contentType,
            declaredSize: $comando->size,
            multipartUploadId: $uploadId,
        );

        // A tentativa e gravada antes de a aula apontar para ela: a chave
        // estrangeira de `lessons.current_video_attempt_id` exige que a linha
        // exista, e inverter a ordem quebraria a integridade dentro da propria
        // transacao.
        $this->attempts->save($tentativa);
        $this->lessons->save($aula->attachVideoAttempt($tentativa->id()));

        return $tentativa;
    }

    /**
     * @throws UploadRejected
     */
    private function garantirSemTentativaAtiva(string $lessonId): void
    {
        $atual = $this->attempts->findCurrentOfLesson($lessonId);

        if ($atual !== null && $atual->isActive()) {
            throw new UploadRejected($atual->state());
        }
    }
}
