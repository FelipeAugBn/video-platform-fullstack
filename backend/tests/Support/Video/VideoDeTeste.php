<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use App\Catalog\Domain\Lesson;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\OpenUpload\OpenUpload;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\UploadPolicy;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use Illuminate\Support\Facades\DB;

/**
 * Monta tentativas de video para os testes, pelos caminhos de escrita reais.
 *
 * Diferente de `CatalogoDeTeste::umaTentativaDeVideo`, que grava por `insert`
 * porque o agregado ainda nao existia quando ela foi escrita, este apoio usa o
 * **repositorio da aplicacao** e as transicoes do proprio agregado. A diferenca
 * importa: um cenario montado a mao existiria mesmo que a traducao entre
 * agregado e linha estivesse errada, e o teste verificaria comportamento sobre
 * dados que a aplicacao nunca teria escrito.
 *
 * As transicoes sao encadeadas na ordem real — `pending`, `uploading`,
 * `uploaded`, e assim por diante — em vez de gravadas direto no estado desejado.
 * E o que garante que o cenario de teste seja alcancavel pela aplicacao: um
 * estado que a tabela de transicoes nao permite alcancar simplesmente nao pode
 * ser montado aqui.
 */
trait VideoDeTeste
{
    protected function umaTentativa(
        Lesson $aula,
        VideoState $estado = VideoState::PENDING,
        int $tamanho = 1024,
        ?string $referencia = 'videos/reproducao.mp4',
    ): VideoAttempt {
        $repositorio = $this->app->make(VideoAttemptRepository::class);

        $tentativa = VideoAttempt::open(
            id: $repositorio->nextIdentity(),
            lessonId: $aula->id(),
            declaredFilename: 'aula.mp4',
            declaredContentType: 'video/mp4',
            declaredSize: $tamanho,
            multipartUploadId: 'upload-de-teste',
        );

        $tentativa = $this->ate($tentativa, $estado, $tamanho, $referencia);

        $repositorio->save($tentativa);

        DB::table('lessons')
            ->where('id', $aula->id())
            ->update(['current_video_attempt_id' => $tentativa->id()]);

        return $tentativa;
    }

    /**
     * Registra no dublê de armazenamento um objeto que passa nas quatro
     * verificacoes da conclusao.
     */
    protected function objetoValidoPara(VideoAttempt $tentativa, ObjectStorageFake $storage): void
    {
        $storage->guardar(
            $tentativa->storageKey(),
            $tentativa->declaredSize(),
            $this->app->make(UploadPolicy::class)->contentType,
            [OpenUpload::METADADO_TENTATIVA => $tentativa->id()],
        );
    }

    protected function storageFalso(): ObjectStorageFake
    {
        $storage = new ObjectStorageFake;
        $this->app->instance(ObjectStorage::class, $storage);

        return $storage;
    }

    /**
     * Encadeia as transicoes reais ate o estado pedido.
     */
    private function ate(
        VideoAttempt $tentativa,
        VideoState $destino,
        int $tamanho,
        ?string $referencia,
    ): VideoAttempt {
        if ($destino === VideoState::PENDING) {
            return $tentativa;
        }

        $tentativa = $tentativa->beginUpload();

        if ($destino === VideoState::UPLOADING) {
            return $tentativa;
        }

        if ($destino === VideoState::FAILED) {
            return $tentativa->fail(Failure::VIDEO_OBJECT_MISSING);
        }

        $tentativa = $tentativa->markUploaded($tamanho, 'video/mp4');

        if ($destino === VideoState::UPLOADED) {
            return $tentativa;
        }

        $tentativa = $tentativa->startProcessing();

        return $destino === VideoState::READY
            ? $tentativa->markReady((string) $referencia)
            : $tentativa;
    }
}
