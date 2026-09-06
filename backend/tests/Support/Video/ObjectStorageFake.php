<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use App\Video\Application\Port\CompletedPart;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\StoredObject;
use DateTimeImmutable;

/**
 * A porta de armazenamento substituida, para os testes de envio.
 *
 * O adapter de verdade ja tem teste proprio contra o RustFS (T042), e ele prova
 * o que so o storage real pode provar: assinatura v4, montagem do multipart,
 * traducao das respostas do provedor. **Este dublê existe para provar outra
 * coisa** — o que o caso de uso faz com cada uma das quatro situacoes que a
 * porta classifica.
 *
 * A separacao e o que torna os testes de conclusao deterministas. Produzir
 * `StorageUnavailable` a partir do storage real exigiria derrubar um servico no
 * meio da suite; aqui basta declarar a excecao que a porta ja promete lancar.
 * O que se afirma nao e como a resposta do provedor e classificada — isso e da
 * T042 — e sim que a classificacao leva ao desfecho certo.
 */
final class ObjectStorageFake implements ObjectStorage
{
    /** @var array<string, StoredObject> */
    private array $objetos = [];

    /** @var list<array{key: string, uploadId: string, parts: list<CompletedPart>}> */
    public array $conclusoes = [];

    public int $aberturas = 0;

    public ?StorageFailure $falhaAoConcluir = null;

    public ?StorageFailure $falhaAoInspecionar = null;

    /**
     * Registra um objeto como o armazenamento o reportaria.
     *
     * @param  array<string, string>  $metadata
     */
    public function guardar(string $key, int $size, string $contentType, array $metadata): void
    {
        $this->objetos[$key] = new StoredObject($key, $size, $contentType, $metadata);
    }

    public function createMultipartUpload(string $key, string $contentType, array $metadata): string
    {
        $this->aberturas++;

        return 'upload-de-teste-'.$this->aberturas;
    }

    public function presignUploadPart(
        string $key,
        string $uploadId,
        int $partNumber,
        DateTimeImmutable $expiresAt,
    ): string {
        return 'https://storage.test/'.$key.'?partNumber='.$partNumber.'&uploadId='.$uploadId;
    }

    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        // Registrado antes de a falha ser lancada, de proposito: um teste que
        // afirma "a conclusao repetida nao chamou o storage de novo" precisa
        // contar as tentativas, e nao apenas as bem-sucedidas.
        $this->conclusoes[] = ['key' => $key, 'uploadId' => $uploadId, 'parts' => array_values($parts)];

        if ($this->falhaAoConcluir !== null) {
            throw $this->falhaAoConcluir;
        }
    }

    public function inspectObject(string $key): StoredObject
    {
        if ($this->falhaAoInspecionar !== null) {
            throw $this->falhaAoInspecionar;
        }

        return $this->objetos[$key] ?? throw ObjectNotFound::em('inspectObject', $key);
    }

    public function presignRead(string $key, DateTimeImmutable $expiresAt): string
    {
        return 'https://storage.test/'.$key;
    }
}
