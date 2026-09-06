<?php

declare(strict_types=1);

namespace App\Video\Application\CompleteUpload;

use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\OpenUpload\OpenUpload;
use App\Video\Application\Port\AttemptLock;
use App\Video\Application\Port\Exception\AttemptLockUnavailable;
use App\Video\Application\Port\Exception\MultipartUploadNotFound;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageRejected;
use App\Video\Application\Port\Exception\StorageUnavailable;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\ProcessingQueue;
use App\Video\Application\Port\StoredObject;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Application\Service\ResolveOwnedAttempt;
use App\Video\Domain\UploadPolicy;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;

/**
 * Conclui o envio: verifica o objeto no servidor e so entao muda o estado
 * (RF-UPL-007 a 010, RF-UPL-013).
 *
 * E o caso de uso mais delicado da entrega, e vale ler a sequencia antes do
 * codigo (plan §§8.2, 12.2):
 *
 *     lock por tentativa .......... video-upload-complete:{id}
 *       T1 (transacao curta) ...... le travado, valida o estado, COMMIT
 *       — sem transacao — ......... CompleteMultipartUpload
 *       — sem transacao — ......... HeadObject
 *       verificacao ............... chave, tamanho, tipo, metadados
 *       T2 (transacao curta) ...... rele travado, reavalia, transiciona
 *     liberacao em finally
 *
 * ## Tres coisas que a forma acima existe para garantir
 *
 * **Nenhuma transacao MySQL aberta enquanto o armazenamento responde**
 * (plan §7.4). Uma transacao aberta durante duas chamadas de rede prenderia a
 * linha pelo tempo da latencia alheia e transformaria uma lentidao do storage em
 * contencao no banco.
 *
 * **Duas conclusoes simultaneas nao correm.** O lock de linha morre com T1,
 * entao ele nao serializa nada do que vem depois; quem serializa a operacao
 * inteira e o lock atomico por tentativa, compartilhado entre containers.
 *
 * **T2 reavalia em vez de confiar em T1.** Entre as duas houve duas chamadas de
 * rede, e o estado pode ter mudado. Uma repeticao encontra a tentativa fora de
 * `uploading` e recebe o desfecho ja obtido, sem novo processamento e sem um
 * segundo job (RN-IDM-001).
 *
 * ## So evidencia confiavel autoriza `failed`
 *
 * E a regra central de plan §12.4, e ela e o motivo de este arquivo distinguir
 * quatro falhas do armazenamento em vez de tratar "deu erro" como uma coisa so:
 *
 *   {@see ObjectNotFound}           o objeto **confirmadamente** nao esta la ....... `failed`
 *   {@see StorageRejected}          recusa reconhecida sobre o conteudo ........... `failed`
 *   {@see MultipartUploadNotFound}  ambiguo: a inspecao decide ..................... depende
 *   {@see StorageUnavailable}       **sem evidencia sobre o arquivo** ............. preserva `uploading`
 *
 * A ultima linha e a que importa. Indisponibilidade, tempo esgotado, credencial
 * errada, permissao ausente, assinatura invalida, configuracao equivocada e
 * qualquer resposta que o adapter nao reconheca chegam todas como
 * `StorageUnavailable` — e **nenhuma delas prova coisa alguma sobre o arquivo do
 * produtor**. Tratar isso como falha do video destruiria um envio de gigabytes
 * por um problema do ambiente. A tentativa fica em `uploading`, nenhum
 * processamento comeca, nada definitivo e gravado, e a conclusao continua
 * repetivel depois que a integracao estiver disponivel ou corrigida.
 *
 * ## O desfecho e devolvido, e nao inferido do estado
 *
 * O retorno e um {@see CompletionResult}: a tentativa como ficou, mais **o que
 * aconteceu com ela**. Os dois nao se deduzem um do outro — uma conclusao nova e
 * uma repetida podem devolver a mesma tentativa, no mesmo `uploaded`, e so uma
 * delas enfileirou trabalho. A fronteira usa o desfecho para escolher o status,
 * e nunca o estado.
 *
 * | Desfecho           | Situacao                                              |
 * | ------------------ | ----------------------------------------------------- |
 * | `ACCEPTED`         | transicionou agora, com o job no mesmo commit          |
 * | `ALREADY_ACCEPTED` | ja estava concluida; nenhum job novo                   |
 * | `REJECTED`         | em `failed`, com o motivo gravado na tentativa         |
 *
 * Duas situacoes nao produzem resultado e **lancam**: a tentativa em `pending`,
 * que nem comecou a transferencia, e a ausencia de evidencia sobre o objeto. Nas
 * duas nao ha desfecho a relatar — na primeira porque nada foi concluido, na
 * segunda porque nada foi decidido.
 *
 * ## `uploaded` e o job pertencem ao mesmo commit
 *
 * T2 grava o estado e enfileira o processamento **dentro da mesma transacao**
 * (plan §§7.4, 13.1). A fila usa a conexao padrao da aplicacao, entao a linha de
 * `jobs` participa daquele commit: no rollback nao sobra nem estado nem job; no
 * commit os dois passam a existir juntos; e antes do commit a linha nao existe
 * para nenhum outro processo, entao o `worker` nao a alcanca. Despachar depois
 * do commit abriria a janela em que um processo morrendo deixaria um video
 * confirmado sem trabalho enfileirado.
 */
final class CompleteUpload
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AttemptLock $lock,
        private readonly ResolveOwnedAttempt $resolverTentativa,
        private readonly VideoAttemptRepository $attempts,
        private readonly ObjectStorage $storage,
        private readonly ProcessingQueue $queue,
        private readonly UploadPolicy $policy,
    ) {}

    public function __invoke(CompleteUploadCommand $comando): CompletionResult
    {
        try {
            return $this->lock->serialize(
                $comando->attemptId,
                fn (): CompletionResult => $this->concluir($comando),
            );
        } catch (AttemptLockUnavailable) {
            // Outra requisicao ja esta concluindo esta tentativa. Nao e falha do
            // video: e a exclusao mutua funcionando. A resposta convida a
            // repetir, e a repeticao encontrara o desfecho ja obtido.
            throw new DomainException(Failure::SERVICE_UNAVAILABLE);
        }
    }

    private function concluir(CompleteUploadCommand $comando): CompletionResult
    {
        // ---- T1: valida o estado sob trava, e fecha antes da rede -----------
        $tentativa = $this->transactions->transactional(
            fn (): VideoAttempt => $this->resolverTentativa->locked($comando->attemptId, $comando->ownerId),
        );

        if ($tentativa->state() !== VideoState::UPLOADING) {
            // Ja resolvida — por uma conclusao anterior, por um callback, ou por
            // uma falha. Devolve o desfecho registrado sem reprocessar nada e
            // **sem tocar no armazenamento** (RN-IDM-001, RF-ERR-003).
            return $this->desfechoJaObtido($tentativa);
        }

        // ---- Sem transacao: as duas chamadas ao armazenamento ---------------
        $objeto = $this->reunirEInspecionar($tentativa, $comando);

        // `null` significa evidencia confiavel de que o objeto nao serve.
        $motivo = $objeto === null
            ? Failure::VIDEO_OBJECT_MISSING
            : $this->verificar($tentativa, $objeto);

        // ---- T2: rele travado, reavalia e transiciona -----------------------
        return $this->transactions->transactional(
            fn (): CompletionResult => $this->resolver($comando->attemptId, $objeto, $motivo),
        );
    }

    /**
     * O desfecho de uma tentativa que ja nao esta mais em `uploading`.
     *
     * Chamado das duas pontas — antes das chamadas ao armazenamento e depois
     * delas —, porque a pergunta e a mesma nos dois lugares: o que responder a
     * quem pede para concluir algo que ja foi resolvido.
     *
     * `pending` e o unico caso que **lanca** em vez de devolver resultado, e a
     * diferenca importa. Nao ha desfecho a repetir: o cliente nem chegou a pedir
     * a primeira URL de parte, entao nao existe envio para concluir. E um erro de
     * ordem na conversa, e nao um resultado anterior — por isso o codigo proprio
     * `VIDEO_UPLOAD_NOT_ACTIVE`, e nao a repeticao de um motivo gravado que nao
     * existe.
     *
     * @throws DomainException quando a tentativa nem comecou a transferencia
     */
    private function desfechoJaObtido(VideoAttempt $tentativa): CompletionResult
    {
        return match ($tentativa->state()) {
            VideoState::PENDING => throw new DomainException(Failure::VIDEO_UPLOAD_NOT_ACTIVE),

            // A tentativa foi reprovada — por esta conclusao numa chamada
            // anterior, ou por um callback de falha do processamento. O motivo
            // gravado nela e o que a resposta repete.
            VideoState::FAILED => CompletionResult::rejeitada($tentativa),

            // `uploaded`, `processing` e `ready`: a conclusao ja tinha sido
            // aceita, e o trabalho ja foi enfileirado na ocasiao. Repetir nao
            // cria um segundo job.
            default => CompletionResult::jaAceita($tentativa),
        };
    }

    /**
     * Fecha o envio e observa o objeto. Devolve `null` quando o armazenamento
     * confirma, de forma confiavel, que ele nao esta la.
     *
     * @throws DomainException quando nao ha evidencia sobre o arquivo
     */
    private function reunirEInspecionar(VideoAttempt $tentativa, CompleteUploadCommand $comando): ?StoredObject
    {
        try {
            $this->storage->completeMultipartUpload(
                $tentativa->storageKey(),
                (string) $tentativa->multipartUploadId(),
                $comando->parts,
            );
        } catch (MultipartUploadNotFound) {
            // O caso tipico e uma conclusao anterior que efetivou no
            // armazenamento e perdeu a resposta. Nao ha nada a decidir aqui: a
            // inspecao abaixo diz se o objeto ficou pronto — e se ficou, esta
            // conclusao reconcilia como a anterior bem-sucedida (plan §12.4).
        } catch (StorageRejected) {
            // Recusa reconhecida sobre o conteudo — parte invalida, fora de
            // ordem, menor que o minimo. E a unica familia de resposta que o
            // adapter classifica como evidencia contra o envio.
            return null;
        } catch (StorageUnavailable) {
            throw new DomainException(Failure::SERVICE_UNAVAILABLE);
        }

        try {
            return $this->storage->inspectObject($tentativa->storageKey());
        } catch (ObjectNotFound) {
            return null;
        } catch (StorageUnavailable|StorageRejected) {
            // Tambem `StorageRejected`: uma recusa **na inspecao** nao e recusa
            // do conteudo do video, e sim do pedido de observa-lo. Sem
            // observacao nao ha evidencia, e sem evidencia o estado e preservado.
            throw new DomainException(Failure::SERVICE_UNAVAILABLE);
        }
    }

    /**
     * As quatro verificacoes de plan §12.3, sobre o objeto real.
     *
     * Devolve `null` quando todas passam, ou o caso do catalogo que descreve a
     * primeira divergencia encontrada. **O `ETag` nao entra aqui** — ele nao tem
     * garantia portatil de ser o checksum do arquivo (plan §12.5), e tratá-lo
     * como tal apoiaria a integridade em algo que o protocolo nao promete.
     */
    private function verificar(VideoAttempt $tentativa, StoredObject $objeto): ?Failure
    {
        if ($objeto->key !== $tentativa->storageKey()) {
            return Failure::VIDEO_OBJECT_MISMATCH;
        }

        if ($objeto->size !== $tentativa->declaredSize()) {
            return Failure::VIDEO_OBJECT_MISMATCH;
        }

        if (! $this->policy->aceita($objeto->contentType)) {
            return Failure::VIDEO_OBJECT_MISMATCH;
        }

        // Comparacao insensivel a caixa na **chave** do metadado: o protocolo nao
        // preserva a caixa dos nomes, e provedores diferentes devolvem
        // `attempt-id` ou `Attempt-Id`. O **valor** e comparado exatamente.
        $metadados = array_change_key_case($objeto->metadata);
        $vinculo = $metadados[OpenUpload::METADADO_TENTATIVA] ?? null;

        if ($vinculo !== $tentativa->id()) {
            return Failure::VIDEO_OBJECT_MISMATCH;
        }

        return null;
    }

    /**
     * A transacao final: rele travado, reavalia e aplica o desfecho.
     */
    private function resolver(string $attemptId, ?StoredObject $objeto, ?Failure $motivo): CompletionResult
    {
        $tentativa = $this->attempts->lock($attemptId);

        if ($tentativa === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        if ($tentativa->state() !== VideoState::UPLOADING) {
            // Outra requisicao resolveu a tentativa enquanto o armazenamento
            // respondia. O desfecho dela vale, e este caminho nao produz efeito
            // novo nem um segundo job.
            return $this->desfechoJaObtido($tentativa);
        }

        if ($motivo !== null) {
            // A reprovacao e **gravada e commitada**, e so entao vira `409` na
            // fronteira. Lancar aqui desfaria a transicao para `failed` junto com
            // a transacao, e a tentativa voltaria a `uploading` — o produtor
            // receberia o erro e continuaria sem poder iniciar um novo envio,
            // porque `uploading` bloqueia (RF-UPL-011). E por isso que a recusa
            // atravessa como resultado, e nao como excecao.
            $falhada = $tentativa->fail($motivo);
            $this->attempts->save($falhada);

            return CompletionResult::rejeitada($falhada);
        }

        // Sem motivo de falha, houve objeto observado: os dois sao produzidos
        // pelo mesmo caminho, e um `null` aqui significaria que a verificacao
        // aprovou um objeto que ninguem inspecionou.
        assert($objeto instanceof StoredObject);

        $concluida = $tentativa->markUploaded($objeto->size, $objeto->contentType);
        $this->attempts->save($concluida);

        // Dentro da mesma transacao, e nao depois dela: e o que faz estado e
        // trabalho existirem juntos ou nao existirem (plan §§7.4, 13.1).
        $this->queue->scheduleProcessing($concluida->id());

        return CompletionResult::aceita($concluida);
    }
}
