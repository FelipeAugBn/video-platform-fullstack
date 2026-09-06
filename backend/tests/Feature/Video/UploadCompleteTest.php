<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Catalog\Domain\Module;
use App\Models\User;
use App\Video\Application\OpenUpload\OpenUpload;
use App\Video\Application\Port\Exception\MultipartUploadNotFound;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageRejected;
use App\Video\Application\Port\Exception\StorageUnavailable;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Queue\ProcessVideoJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\ObjectStorageFake;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * `POST /api/video-uploads/{attempt}/complete` — a verificacao no servidor
 * (RF-UPL-007 a 010, RF-UPL-013, plan §12).
 *
 * O arquivo esta organizado pela pergunta que plan §12.4 faz de cada falha:
 * **ha evidencia confiavel sobre o objeto?** As duas primeiras secoes cobrem
 * "sim, e ele serve" e "sim, e ele nao serve"; a terceira cobre "nao ha
 * evidencia", que e a que preserva o envio do produtor.
 *
 * Cobre AC-VID-002, AC-VID-003 e AC-VID-012.
 */
final class UploadCompleteTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private const TAMANHO = 4096;

    private User $produtor;

    private Module $modulo;

    private ObjectStorageFake $storage;

    private VideoAttempt $tentativa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->modulo = $this->umModulo($this->umCurso($this->produtor));
        $this->storage = $this->storageFalso();
        $this->tentativa = $this->umaTentativa(
            $this->umaAula($this->modulo),
            VideoState::UPLOADING,
            tamanho: self::TAMANHO,
        );
    }

    // -----------------------------------------------------------------------
    // Conclusao valida
    // -----------------------------------------------------------------------

    public function test_conclusao_nova_e_valida_responde_202_e_grava_uploaded(): void
    {
        Queue::fake();
        $this->objetoValidoPara($this->tentativa, $this->storage);

        $resposta = $this->concluir();

        $resposta->assertStatus(202);
        $resposta->assertJsonPath('data.state', VideoState::UPLOADED->value);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::UPLOADED->value,
            // O que foi **observado** no objeto, e nao o que o cliente declarou.
            'verified_size' => self::TAMANHO,
            'verified_content_type' => 'video/mp4',
            'failure_code' => null,
        ]);

        Queue::assertPushed(ProcessVideoJob::class, 1);
    }

    public function test_o_comprovante_da_parte_e_repassado_como_veio(): void
    {
        Queue::fake();
        $this->objetoValidoPara($this->tentativa, $this->storage);

        // Com aspas, que fazem parte do valor que o armazenamento espera receber
        // de volta. Limpa-las "para ficar bonito" quebraria a conclusao real
        // (plan §12.5).
        $this->concluir([['part_number' => 1, 'etag' => '"abc123"']])->assertStatus(202);

        $this->assertSame('"abc123"', $this->storage->conclusoes[0]['parts'][0]->etag);
    }

    // -----------------------------------------------------------------------
    // Repeticao (AC-VID-003)
    // -----------------------------------------------------------------------

    public function test_conclusao_repetida_responde_200_e_nao_duplica_processamento(): void
    {
        Queue::fake();
        $this->objetoValidoPara($this->tentativa, $this->storage);

        $this->concluir()->assertStatus(202);
        $repetida = $this->concluir();

        // `200` e nao `202`: a repeticao nao enfileirou nada, e prometer trabalho
        // aceito onde nao ha trabalho novo seria mentir no contrato.
        $repetida->assertStatus(200);
        $repetida->assertJsonPath('data.state', VideoState::UPLOADED->value);

        // Um unico job, e uma unica ida ao armazenamento: a repeticao encontra a
        // tentativa fora de `uploading` e devolve o desfecho ja obtido.
        Queue::assertPushed(ProcessVideoJob::class, 1);
        $this->assertCount(1, $this->storage->conclusoes);
    }

    /**
     * Os tres estados em que a conclusao ja tinha sido aceita.
     *
     * `processing` e `ready` sao alcancados depois de `uploaded`, entao repetir a
     * conclusao neles e a mesma pergunta com o callback ja no meio do caminho: o
     * trabalho foi enfileirado na ocasiao, e nao ha nada novo a aceitar.
     *
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosJaConcluidos(): iterable
    {
        yield 'uploaded' => [VideoState::UPLOADED];
        yield 'processing' => [VideoState::PROCESSING];
        yield 'ready' => [VideoState::READY];
    }

    #[DataProvider('estadosJaConcluidos')]
    public function test_conclusao_de_tentativa_ja_aceita_responde_200(VideoState $estado): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->umaAula($this->modulo, 'Aula '.$estado->value), $estado);

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$tentativa->id()}/complete",
            ['parts' => $this->partes()],
        );

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('data.state', $estado->value);

        // Sem job novo e sem ida ao armazenamento: nao ha o que concluir.
        Queue::assertNothingPushed();
        $this->assertSame([], $this->storage->conclusoes);
    }

    public function test_conclusao_em_pending_responde_409_sem_tocar_no_storage(): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->umaAula($this->modulo, 'Aula pendente'), VideoState::PENDING);

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$tentativa->id()}/complete",
            ['parts' => $this->partes()],
        );

        // Nao e repeticao de desfecho nenhum: o cliente nem pediu a primeira URL
        // de parte, entao nao existe envio para concluir. Codigo proprio, e nao
        // um motivo gravado que nao existe.
        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'VIDEO_UPLOAD_NOT_ACTIVE');

        $this->assertSame([], $this->storage->conclusoes);
        Queue::assertNothingPushed();

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::PENDING->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Evidencia confiavel contra o objeto (AC-VID-002)
    // -----------------------------------------------------------------------

    public function test_objeto_ausente_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        // Nenhum objeto registrado: a inspecao devolve ausencia confirmada.

        $resposta = $this->concluir();

        $resposta->assertStatus(409);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'VIDEO_OBJECT_MISSING');
        $this->assertNotEmpty($resposta->json('detail'));

        // **A recusa foi commitada antes de virar `409`.** Se a rejeicao subisse
        // como excecao de dentro da transacao, o `failed` seria desfeito junto e
        // a tentativa voltaria a `uploading` — o produtor receberia o erro e
        // continuaria sem poder iniciar um novo envio.
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::FAILED->value,
            'failure_code' => 'VIDEO_OBJECT_MISSING',
        ]);

        Queue::assertNothingPushed();
    }

    public function test_a_recusa_repetida_devolve_o_mesmo_codigo(): void
    {
        Queue::fake();

        $primeira = $this->concluir();
        $primeira->assertStatus(409);

        // A segunda encontra a tentativa ja em `failed` e repete o motivo
        // gravado, sem tocar no armazenamento de novo.
        $segunda = $this->concluir();

        $segunda->assertStatus(409);
        $this->assertSame($primeira->json('code'), $segunda->json('code'));
        $this->assertSame($primeira->json('detail'), $segunda->json('detail'));

        $this->assertCount(1, $this->storage->conclusoes);
        Queue::assertNothingPushed();
    }

    public function test_o_novo_envio_e_liberado_depois_da_recusa(): void
    {
        Queue::fake();
        $this->concluir()->assertStatus(409);

        // A consequencia pratica de gravar `failed` em vez de desfazer: encerrar
        // a tentativa e o que permite ao produtor comecar de novo, sem depender
        // de uma operacao de descarte que a spec nao preve (RF-UPL-013).
        $this->actingAs($this->produtor)->postJson(
            '/api/lessons/'.$this->tentativa->lessonId().'/video/uploads',
            ['filename' => 'outra.mp4', 'content_type' => 'video/mp4', 'size' => self::TAMANHO],
        )->assertCreated();
    }

    public function test_tamanho_divergente_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        $this->storage->guardar(
            $this->tentativa->storageKey(),
            self::TAMANHO - 1,
            'video/mp4',
            [OpenUpload::METADADO_TENTATIVA => $this->tentativa->id()],
        );

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISMATCH');
        $this->assertEstado(VideoState::FAILED);
        Queue::assertNothingPushed();
    }

    public function test_tipo_divergente_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        $this->storage->guardar(
            $this->tentativa->storageKey(),
            self::TAMANHO,
            'video/quicktime',
            [OpenUpload::METADADO_TENTATIVA => $this->tentativa->id()],
        );

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISMATCH');
        $this->assertEstado(VideoState::FAILED);
    }

    public function test_metadado_ausente_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        // Sem o vinculo, nada impediria concluir uma tentativa apontando para um
        // objeto que nao e dela (plan §12.3).
        $this->storage->guardar($this->tentativa->storageKey(), self::TAMANHO, 'video/mp4', []);

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISMATCH');
        $this->assertEstado(VideoState::FAILED);
    }

    public function test_metadado_de_outra_tentativa_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        $this->storage->guardar(
            $this->tentativa->storageKey(),
            self::TAMANHO,
            'video/mp4',
            [OpenUpload::METADADO_TENTATIVA => '01936f1a-7c00-7a3e-9b7d-000000000000'],
        );

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISMATCH');
        $this->assertEstado(VideoState::FAILED);
    }

    public function test_recusa_de_conteudo_na_conclusao_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        $this->storage->falhaAoConcluir = StorageRejected::em('completeMultipartUpload', 'chave');

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISSING');
        $this->assertEstado(VideoState::FAILED);
    }

    // -----------------------------------------------------------------------
    // Ausencia de evidencia — preserva `uploading` (plan §12.4)
    // -----------------------------------------------------------------------

    public function test_storage_indisponivel_na_conclusao_preserva_uploading(): void
    {
        Queue::fake();
        $this->storage->falhaAoConcluir = StorageUnavailable::em('completeMultipartUpload', 'chave');

        $resposta = $this->concluir();

        $resposta->assertStatus(503);
        $resposta->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

        // Nem transicao, nem processamento, nem marca definitiva: a conclusao
        // continua repetivel depois que a integracao voltar.
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::UPLOADING->value,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_storage_indisponivel_na_inspecao_preserva_uploading(): void
    {
        Queue::fake();
        $this->storage->falhaAoInspecionar = StorageUnavailable::em('inspectObject', 'chave');

        $this->concluir()->assertStatus(503);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::UPLOADING->value,
            'failure_code' => null,
        ]);
    }

    public function test_conclusao_repetida_apos_indisponibilidade_e_aceita(): void
    {
        Queue::fake();
        $this->storage->falhaAoConcluir = StorageUnavailable::em('completeMultipartUpload', 'chave');
        $this->concluir()->assertStatus(503);

        // A integracao volta: a mesma conclusao, agora, e aceita.
        $this->storage->falhaAoConcluir = null;
        $this->objetoValidoPara($this->tentativa, $this->storage);

        $this->concluir()->assertStatus(202);
        $this->assertEstado(VideoState::UPLOADED);
    }

    // -----------------------------------------------------------------------
    // Resultado ambiguo (plan §12.4)
    // -----------------------------------------------------------------------

    public function test_envio_inexistente_com_objeto_valido_reconcilia(): void
    {
        Queue::fake();
        // O caso tipico: uma conclusao anterior efetivou no armazenamento e
        // perdeu a resposta. O objeto esta la e passa nas quatro verificacoes.
        $this->storage->falhaAoConcluir = MultipartUploadNotFound::em('completeMultipartUpload', 'chave');
        $this->objetoValidoPara($this->tentativa, $this->storage);

        $this->concluir()->assertStatus(202);

        $this->assertEstado(VideoState::UPLOADED);
        Queue::assertPushed(ProcessVideoJob::class, 1);
    }

    public function test_envio_inexistente_sem_objeto_responde_409_e_persiste_failed(): void
    {
        Queue::fake();
        $this->storage->falhaAoConcluir = MultipartUploadNotFound::em('completeMultipartUpload', 'chave');
        $this->storage->falhaAoInspecionar = ObjectNotFound::em('inspectObject', 'chave');

        $this->concluir()->assertStatus(409)->assertJsonPath('code', 'VIDEO_OBJECT_MISSING');
        $this->assertEstado(VideoState::FAILED);
    }

    // -----------------------------------------------------------------------
    // Envio interrompido (AC-VID-012)
    // -----------------------------------------------------------------------

    public function test_sem_pedido_de_conclusao_a_tentativa_fica_em_uploading(): void
    {
        // Nenhuma requisicao de conclusao — e o cenario de AC-VID-012: a
        // transferencia foi interrompida e o cliente nunca pediu para concluir.
        $this->assertEstado(VideoState::UPLOADING);

        $this->assertDatabaseMissing('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::UPLOADED->value,
        ]);
        $this->assertDatabaseMissing('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => VideoState::READY->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Isolamento
    // -----------------------------------------------------------------------

    public function test_tentativa_de_outro_produtor_responde_404_sem_tocar_no_storage(): void
    {
        $outro = User::factory()->producer()->create();
        $alheia = $this->umaTentativa(
            $this->umaAula($this->umModulo($this->umCurso($outro))),
            VideoState::UPLOADING,
        );

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/video-uploads/{$alheia->id()}/complete", ['parts' => $this->partes()]);

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');
        $this->assertSame([], $this->storage->conclusoes);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * @return list<array{part_number: int, etag: string}>
     */
    private function partes(): array
    {
        return [['part_number' => 1, 'etag' => '"etag-da-parte-1"']];
    }

    /**
     * @param  list<array{part_number: int, etag: string}>|null  $partes
     */
    private function concluir(?array $partes = null): TestResponse
    {
        return $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$this->tentativa->id()}/complete",
            ['parts' => $partes ?? $this->partes()],
        );
    }

    private function assertEstado(VideoState $esperado): void
    {
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->tentativa->id(),
            'state' => $esperado->value,
        ]);
    }
}
