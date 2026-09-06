<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Catalog\Domain\Lesson;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * `POST /api/video-uploads/{attempt}/parts/{n}/url` (RF-UPL-007, plan §11.3).
 *
 * O que esta rota decide, e o que ela deliberadamente nao decide: o **primeiro**
 * pedido move `pending` para `uploading`, e os seguintes — inclusive a renovacao
 * de uma URL vencida — nao tocam no dominio.
 */
final class UploadPartTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private User $produtor;

    private Lesson $aula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->aula = $this->umaAula($this->umModulo($this->umCurso($this->produtor)));
        $this->storageFalso();
    }

    public function test_o_primeiro_pedido_move_de_pending_para_uploading(): void
    {
        $tentativa = $this->umaTentativa($this->aula, VideoState::PENDING);

        $resposta = $this->pedir($tentativa->id(), 1);

        $resposta->assertOk();
        $resposta->assertJsonStructure(['data' => ['url', 'expires_at']]);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::UPLOADING->value,
        ]);
    }

    public function test_a_renovacao_nao_cria_tentativa_nem_muda_o_estado_de_novo(): void
    {
        // Grande o bastante para ter uma segunda parte: o objetivo do teste e
        // pedir a mesma URL duas vezes e depois outra parte, e todas as tres
        // precisam ser pedidos validos.
        $tentativa = $this->umaTentativa($this->aula, VideoState::PENDING, tamanho: 100 * 1024 * 1024);

        $this->pedir($tentativa->id(), 1)->assertOk();
        $this->pedir($tentativa->id(), 1)->assertOk();
        $this->pedir($tentativa->id(), 2)->assertOk();

        $this->assertDatabaseCount('video_attempts', 1);
        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::UPLOADING->value,
        ]);
    }

    public function test_a_tentativa_de_outro_produtor_responde_404(): void
    {
        $outro = User::factory()->producer()->create();
        $aulaAlheia = $this->umaAula($this->umModulo($this->umCurso($outro)));
        $alheia = $this->umaTentativa($aulaAlheia, VideoState::PENDING);

        $resposta = $this->pedir($alheia->id(), 1);

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');

        // O estado nao mudou: a recusa acontece antes de qualquer decisao.
        $this->assertDatabaseHas('video_attempts', [
            'id' => $alheia->id(),
            'state' => VideoState::PENDING->value,
        ]);
    }

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosEncerrados(): iterable
    {
        yield 'uploaded' => [VideoState::UPLOADED];
        yield 'processing' => [VideoState::PROCESSING];
        yield 'ready' => [VideoState::READY];
        yield 'failed' => [VideoState::FAILED];
    }

    #[DataProvider('estadosEncerrados')]
    public function test_envio_ja_encerrado_nao_autoriza_mais_partes(VideoState $estado): void
    {
        $tentativa = $this->umaTentativa($this->aula, $estado);

        $resposta = $this->pedir($tentativa->id(), 1);

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'VIDEO_UPLOAD_NOT_ACTIVE');

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => $estado->value,
        ]);
    }

    public function test_parte_alem_do_total_do_arquivo_responde_422(): void
    {
        // Um arquivo de 1 KiB cabe em uma unica parte de 64 MiB. Pedir a segunda
        // e pedir autorizacao para enviar um pedaco que a conclusao jamais vai
        // reunir — e o objeto resultante nao bateria com o tamanho declarado.
        $tentativa = $this->umaTentativa($this->aula, VideoState::PENDING, tamanho: 1024);

        $this->pedir($tentativa->id(), 2)->assertStatus(422);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::PENDING->value,
        ]);
    }

    public function test_numero_de_parte_nao_numerico_responde_404_de_rota(): void
    {
        $tentativa = $this->umaTentativa($this->aula, VideoState::PENDING);

        // A restricao esta no roteador: um valor nao numerico nao chega a
        // executar codigo da aplicacao, e recebe o mesmo `404` generico.
        $this->actingAs($this->produtor)
            ->postJson("/api/video-uploads/{$tentativa->id()}/parts/abc/url")
            ->assertNotFound();
    }

    private function pedir(string $attemptId, int $parte): TestResponse
    {
        return $this->actingAs($this->produtor)
            ->postJson("/api/video-uploads/{$attemptId}/parts/{$parte}/url");
    }
}
