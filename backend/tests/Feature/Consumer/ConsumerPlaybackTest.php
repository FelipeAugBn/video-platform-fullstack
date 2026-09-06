<?php

declare(strict_types=1);

namespace Tests\Feature\Consumer;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Models\User;
use App\Shared\Application\Port\Clock;
use App\Video\Application\Port\Exception\StorageUnavailable;
use App\Video\Domain\VideoState;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\FrozenClock;
use Tests\Support\Video\ObjectStorageFake;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/lessons/{lesson}/playback` — a rota nomeada pelo desafio.
 *
 * A afirmacao central deste arquivo nao e sobre o caminho feliz: e sobre a
 * **ordem**. O armazenamento so pode ser chamado depois das quatro verificacoes
 * (plan §14.2), e cada caminho negativo termina com `$this->storage->leituras`
 * vazio — a prova de que nenhuma URL foi assinada antes de a autorizacao e a
 * disponibilidade estarem resolvidas.
 *
 * A segunda afirmacao e sobre **qual chave** e assinada. A tentativa de teste
 * nasce com a chave de armazenamento derivada do identificador e uma
 * `playback_reference` diferente dela; assinar a referencia gravada e a unica
 * forma de a assercao passar, e uma chave remontada a partir do parametro de
 * rota falharia.
 */
final class ConsumerPlaybackTest extends TestCase
{
    use CatalogoDeTeste, RefreshDatabase, VideoDeTeste;

    private const AGORA = '2026-09-06T12:00:00+00:00';

    private const VALIDADE = 5 * 60;

    private const REFERENCIA = 'videos/reproducao-gravada.mp4';

    private User $consumidor;

    private User $produtor;

    private Course $curso;

    private Module $modulo;

    private ObjectStorageFake $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumidor = User::factory()->consumer()->create();
        $this->produtor = User::factory()->producer()->create();

        $this->curso = $this->umCurso($this->produtor);
        $this->modulo = $this->umModulo($this->curso);
        $this->concedidoA($this->curso, $this->consumidor);

        $this->storage = $this->storageFalso();
        $this->app->instance(Clock::class, FrozenClock::at(self::AGORA));
    }

    // -----------------------------------------------------------------------
    // O caminho autorizado
    // -----------------------------------------------------------------------

    public function test_consumidor_concedido_recebe_os_dados_de_reproducao(): void
    {
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertOk();
        $resposta->assertJsonPath('data.content_type', 'video/mp4');
        $this->assertStringStartsWith(
            'https://storage.test/'.self::REFERENCIA,
            (string) $resposta->json('data.playback_url'),
        );
    }

    public function test_a_resposta_traz_somente_os_tres_campos_de_reproducao(): void
    {
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertOk();
        $this->assertSame(['data'], array_keys($resposta->json()));
        $this->assertSame(
            ['playback_url', 'expires_at', 'content_type'],
            array_keys($resposta->json('data')),
        );

        // O arquivo nao vem junto, e nada do envio vaza: nem o identificador da
        // tentativa, nem a chave real do objeto, nem o estado do video
        // (RF-PLB-005, RN-AUT-005).
        $corpo = (string) $resposta->getContent();

        foreach (['original.mp4', 'ready', 'video_state', 'storage_key', 'attempt'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    public function test_a_url_vale_cinco_minutos_a_partir_do_instante_da_requisicao(): void
    {
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $esperado = (new DateTimeImmutable(self::AGORA))->modify('+'.self::VALIDADE.' seconds');

        $resposta->assertOk();
        $resposta->assertJsonPath('data.expires_at', $esperado->format(DateTimeInterface::ATOM));

        // A validade anunciada e a mesma que foi pedida ao armazenamento. Sem
        // esta afirmacao, uma resposta poderia prometer cinco minutos e assinar
        // outra coisa.
        $this->assertCount(1, $this->storage->leituras);
        $this->assertSame(
            $esperado->getTimestamp(),
            $this->storage->leituras[0]['expiresAt']->getTimestamp(),
        );
    }

    public function test_a_url_e_assinada_sobre_a_referencia_gravada_pelo_processamento(): void
    {
        $aula = $this->aulaReproduzivel();

        $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback')
            ->assertOk();

        // A chave do objeto e derivada do identificador da tentativa e e
        // **diferente** da referencia gravada. Assinar a referencia e o que
        // prova que nenhuma chave foi remontada a partir do parametro de rota.
        $this->assertSame(self::REFERENCIA, $this->storage->leituras[0]['key']);
        $this->assertStringNotContainsString($aula->id(), $this->storage->leituras[0]['key']);
    }

    // -----------------------------------------------------------------------
    // Negativa por autorizacao — `404`, e sem tocar no storage
    // -----------------------------------------------------------------------

    public function test_consumidor_sem_concessao_nao_recebe_url(): void
    {
        $outro = User::factory()->consumer()->create();
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($outro)->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');
        $this->assertSame([], $this->storage->leituras);
        $this->assertStringNotContainsString(self::REFERENCIA, (string) $resposta->getContent());
    }

    public function test_aula_inexistente_e_aula_sem_concessao_respondem_o_mesmo(): void
    {
        $outro = User::factory()->consumer()->create();
        $aula = $this->aulaReproduzivel();

        $existente = $this->actingAs($outro)->getJson('/api/lessons/'.$aula->id().'/playback');
        $inexistente = $this->actingAs($outro)
            ->getJson('/api/lessons/'.(string) Str::uuid7().'/playback');

        $existente->assertNotFound();
        $inexistente->assertNotFound();

        $this->assertSame($inexistente->json(), $existente->json());
        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // Negativa por disponibilidade — `409`, distinguivel da anterior
    // -----------------------------------------------------------------------

    public function test_aula_nao_publicada_responde_conflito(): void
    {
        $aula = $this->umaAula($this->modulo);
        $this->umaTentativa($aula, VideoState::READY, referencia: self::REFERENCIA);

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        // `409`, e nao `404`: a aula esta dentro de um curso concedido, e a
        // interface precisa separar "ainda nao esta pronto" de "nao e para voce"
        // (RF-PLB-007, RF-UI-015).
        $resposta->assertStatus(409);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'LESSON_NOT_PUBLISHED');
        $this->assertSame([], $this->storage->leituras);
    }

    #[DataProvider('estadosQueNaoReproduzem')]
    public function test_video_fora_de_ready_responde_conflito(VideoState $estado): void
    {
        $aula = $this->umaAula($this->modulo);
        $this->umaTentativa($aula, $estado);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_VIDEO_NOT_READY');
        $this->assertSame([], $this->storage->leituras);
    }

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosQueNaoReproduzem(): iterable
    {
        yield 'aguardando o primeiro byte' => [VideoState::PENDING];
        yield 'em envio' => [VideoState::UPLOADING];
        yield 'enviado' => [VideoState::UPLOADED];
        yield 'processando' => [VideoState::PROCESSING];
        yield 'com falha' => [VideoState::FAILED];
    }

    public function test_aula_publicada_sem_video_responde_conflito(): void
    {
        $aula = $this->umaAula($this->modulo);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_VIDEO_NOT_READY');
        $this->assertSame([], $this->storage->leituras);
    }

    public function test_video_pronto_sem_referencia_responde_conflito(): void
    {
        $aula = $this->umaAula($this->modulo);
        $tentativa = $this->umaTentativa($aula, VideoState::READY, referencia: self::REFERENCIA);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        // A tentativa e montada pelo caminho normal e so entao tem a referencia
        // retirada direto no banco. Nao ha como chegar a este estado pela
        // aplicacao: `markReady()` exige a referencia na assinatura, e o callback
        // de sucesso e recusado sem ela. Montar o cenario por fora e a unica
        // forma de exercitar a guarda — que existe justamente para o caso de o
        // dado ser corrompido por outro caminho.
        DB::table('video_attempts')
            ->where('id', $tentativa->id())
            ->update(['playback_reference' => null]);

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_VIDEO_NOT_READY');

        // Nenhum dado de reproducao, e o storage intocado: a guarda age antes de
        // assinar, e nao depois de descobrir que nao ha o que assinar.
        $this->assertStringNotContainsString('playback_url', (string) $resposta->getContent());
        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // Falha ao assinar
    // -----------------------------------------------------------------------

    public function test_falha_ao_assinar_vira_indisponibilidade_sem_vazar_a_excecao(): void
    {
        $aula = $this->aulaReproduzivel();
        $this->storage->falhaAoAssinarLeitura = StorageUnavailable::em('presignRead', self::REFERENCIA);

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertStatus(503);
        $resposta->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

        $corpo = (string) $resposta->getContent();

        foreach (['presignRead', 'StorageUnavailable', self::REFERENCIA, '/app/'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Uma aula publicada, com tentativa em `ready` e referencia gravada.
     *
     * A referencia e deliberadamente diferente da chave derivada do
     * identificador da tentativa: e o que permite afirmar qual das duas foi
     * assinada.
     */
    private function aulaReproduzivel(): Lesson
    {
        $aula = $this->umaAula($this->modulo);

        $this->umaTentativa($aula, VideoState::READY, referencia: self::REFERENCIA);
        $this->publicadaEm($aula, '2026-09-06T10:00:00+00:00');

        return $aula;
    }
}
