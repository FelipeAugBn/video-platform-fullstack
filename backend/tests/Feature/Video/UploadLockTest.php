<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Application\Port\AttemptLock;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Lock\CacheAttemptLock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\ObjectStorageFake;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * A exclusao mutua da conclusao de envio (plan §8.2).
 *
 * O lock nao e um detalhe de arrumacao: sem ele, `SELECT ... FOR UPDATE` nao
 * serializa nada, porque a transacao — e com ela a trava de linha — precisa ser
 * fechada antes das chamadas ao armazenamento. Duas conclusoes simultaneas
 * ficariam livres para executar `CompleteMultipartUpload` sobre a mesma
 * tentativa.
 *
 * ## O teste usa o store `database`, e nao o da suite
 *
 * `phpunit.xml` configura `CACHE_STORE=array` deliberadamente, e sob ele um lock
 * viveria na memoria do processo — dois containers o obteriam ao mesmo tempo, e
 * a serializacao seria uma ilusao. O adapter nomeia o store `database`
 * explicitamente por isso, e este teste exerce esse mesmo caminho: as tabelas
 * `cache` e `cache_locks` do MySQL de testes.
 *
 * ## A espera e encurtada, e nao anulada
 *
 * O adapter e reconstruido com um segundo de espera em vez dos cinco
 * configurados. Anular a espera testaria outra coisa — "desiste na hora" em vez
 * de "espera e desiste" —, e a diferenca importa: a espera curta e o que faz
 * duas conclusoes proximas se resolverem sozinhas na maioria dos casos.
 */
final class UploadLockTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private const PREFIXO = 'video-upload-complete:';

    private User $produtor;

    private VideoAttempt $tentativa;

    private ObjectStorageFake $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $aula = $this->umaAula($this->umModulo($this->umCurso($this->produtor)));
        $this->storage = $this->storageFalso();
        $this->tentativa = $this->umaTentativa($aula, VideoState::UPLOADING);

        $this->app->instance(AttemptLock::class, new CacheAttemptLock(
            cache: $this->app->make(CacheFactory::class),
            store: 'database',
            leaseSeconds: 120,
            waitSeconds: 1,
        ));
    }

    public function test_conclusao_concorrente_nao_entra_e_recebe_indisponibilidade(): void
    {
        Queue::fake();

        // Simula a outra requisicao: o lock ja esta em maos de alguem.
        $ocupado = Cache::store('database')->lock(self::PREFIXO.$this->tentativa->id(), 120);
        $this->assertTrue($ocupado->get());

        try {
            $resposta = $this->actingAs($this->produtor)->postJson(
                "/api/video-uploads/{$this->tentativa->id()}/complete",
                ['parts' => [['part_number' => 1, 'etag' => '"etag"']]],
            );

            $resposta->assertStatus(503);
            $resposta->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

            // Nada aconteceu: nem transicao, nem falha definitiva, nem job.
            $this->assertDatabaseHas('video_attempts', [
                'id' => $this->tentativa->id(),
                'state' => VideoState::UPLOADING->value,
                'failure_code' => null,
            ]);
            Queue::assertNothingPushed();
        } finally {
            $ocupado->release();
        }
    }

    public function test_o_lock_e_liberado_e_a_conclusao_seguinte_passa(): void
    {
        Queue::fake();
        $this->objetoValidoPara($this->tentativa, $this->storage);

        $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$this->tentativa->id()}/complete",
            ['parts' => [['part_number' => 1, 'etag' => '"etag"']]],
        )->assertStatus(202);

        // Liberado em `finally`: um lock retido apos a conclusao deixaria a
        // tentativa trancada ate o lease expirar. A prova e conseguir toma-lo.
        $livre = Cache::store('database')->lock(self::PREFIXO.$this->tentativa->id(), 5);
        $this->assertTrue($livre->get());
        $livre->release();
    }

    public function test_tentativas_diferentes_nao_disputam_o_mesmo_lock(): void
    {
        Queue::fake();

        // O lock e **por tentativa**: um lock global serializaria envios que nao
        // tem nada a ver um com o outro.
        $outraAula = $this->umaAula($this->umModulo($this->umCurso($this->produtor)), 'Outra');
        $outra = $this->umaTentativa($outraAula, VideoState::UPLOADING);

        $ocupado = Cache::store('database')->lock(self::PREFIXO.$outra->id(), 120);
        $this->assertTrue($ocupado->get());

        try {
            $this->objetoValidoPara($this->tentativa, $this->storage);

            $this->actingAs($this->produtor)->postJson(
                "/api/video-uploads/{$this->tentativa->id()}/complete",
                ['parts' => [['part_number' => 1, 'etag' => '"etag"']]],
            )->assertStatus(202);
        } finally {
            $ocupado->release();
        }
    }
}
