<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

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
 * `GET /api/lessons/{lesson}/video/playback` — o produtor confere o proprio
 * video (RF-PLB-009, AC-PROD-008).
 *
 * ## As tres afirmacoes deste arquivo
 *
 * **A publicacao nao alcanca esta operacao.** E a razao de ela existir: quem
 * enviou o video precisa assisti-lo para decidir se publica, e exigir publicacao
 * antes inverteria a ordem da decisao. O primeiro teste e o da aula em rascunho,
 * e ele e o que separa esta rota da do consumidor.
 *
 * **A propriedade decide, e decide antes do armazenamento.** Cada caminho
 * negativo termina com `$this->storage->leituras` vazio — a prova de que nenhuma
 * URL foi assinada antes de a autorizacao e a disponibilidade estarem
 * resolvidas.
 *
 * **A chave assinada e a gravada.** A tentativa de teste nasce com a chave de
 * armazenamento derivada do identificador e uma `playback_reference` diferente
 * dela; assinar a referencia e a unica forma de a assercao passar, e uma chave
 * remontada a partir do parametro de rota falharia.
 *
 * A rota do consumidor continua sendo o que sempre foi, e
 * {@see \Tests\Feature\Consumer\ConsumerPlaybackTest} continua provando isso. As
 * duas suites existem separadas porque as duas politicas de autorizacao sao
 * separadas.
 */
final class ProducerPlaybackTest extends TestCase
{
    use CatalogoDeTeste, RefreshDatabase, VideoDeTeste;

    private const AGORA = '2026-09-06T12:00:00+00:00';

    private const VALIDADE = 5 * 60;

    private const REFERENCIA = 'videos/reproducao-gravada.mp4';

    private const PUBLICADA_EM = '2026-09-06T10:00:00+00:00';

    private User $produtor;

    private Module $modulo;

    private ObjectStorageFake $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();

        $curso = $this->umCurso($this->produtor);
        $this->modulo = $this->umModulo($curso);

        $this->storage = $this->storageFalso();
        $this->app->instance(Clock::class, FrozenClock::at(self::AGORA));
    }

    // -----------------------------------------------------------------------
    // O caminho autorizado, com e sem publicacao
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{?string}>
     */
    public static function estadosDePublicacao(): iterable
    {
        yield 'aula em rascunho' => [null];
        yield 'aula publicada' => [self::PUBLICADA_EM];
    }

    /**
     * A afirmacao central: o estado de publicacao **nao** participa da decisao.
     *
     * O rascunho e o caso que motiva a operacao, e a aula publicada esta aqui
     * para provar que nada foi trocado de lugar — conferir o video depois de
     * publicar continua valendo.
     */
    #[DataProvider('estadosDePublicacao')]
    public function test_o_produtor_dono_reproduz_o_proprio_video(?string $publicadaEm): void
    {
        $aula = $this->aulaReproduzivel($publicadaEm);

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

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

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertOk();
        $this->assertSame(['data'], array_keys($resposta->json()));
        $this->assertSame(
            ['playback_url', 'expires_at', 'content_type'],
            array_keys($resposta->json('data')),
        );

        // Mesmo sendo o dono, quem pede recebe dados de reproducao e nada mais:
        // o identificador da tentativa, a chave real do objeto e o estado do
        // video sao assunto da consulta de video, que e outra rota.
        $corpo = (string) $resposta->getContent();

        foreach (['original.mp4', 'ready', 'video_state', 'storage_key', 'attempt'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    public function test_a_url_vale_cinco_minutos_a_partir_do_instante_da_requisicao(): void
    {
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $esperado = (new DateTimeImmutable(self::AGORA))->modify('+'.self::VALIDADE.' seconds');

        $resposta->assertOk();
        $resposta->assertJsonPath('data.expires_at', $esperado->format(DateTimeInterface::ATOM));

        // O prazo e o mesmo da rota do consumidor porque a emissao e a mesma
        // colaboracao. Duas copias da assinatura poderiam divergir no primeiro
        // ajuste de janela, e nada avisaria.
        $this->assertCount(1, $this->storage->leituras);
        $this->assertSame(
            $esperado->getTimestamp(),
            $this->storage->leituras[0]['expiresAt']->getTimestamp(),
        );
    }

    public function test_a_url_e_assinada_sobre_a_referencia_gravada_pelo_processamento(): void
    {
        $aula = $this->aulaReproduzivel();

        $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback')
            ->assertOk();

        // A chave do objeto e derivada do identificador da tentativa e e
        // **diferente** da referencia gravada. Assinar a referencia e o que
        // prova que nenhuma chave foi remontada a partir do parametro de rota.
        $this->assertSame(self::REFERENCIA, $this->storage->leituras[0]['key']);
        $this->assertStringNotContainsString($aula->id(), $this->storage->leituras[0]['key']);
    }

    // -----------------------------------------------------------------------
    // Negativa por perfil — antes de qualquer consulta
    // -----------------------------------------------------------------------

    public function test_visitante_recebe_401(): void
    {
        $aula = $this->aulaReproduzivel();

        $resposta = $this->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertUnauthorized();
        $resposta->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertSame([], $this->storage->leituras);
    }

    /**
     * O consumidor recebe `403`, e nao `404`.
     *
     * A diferenca e deliberada e nao abre oraculo nenhum: `403` aqui responde
     * sobre o **perfil** de quem chamou, que ele mesmo conhece, e nao sobre a
     * existencia da aula. Quem tem perfil errado recebe a mesma resposta para
     * toda aula, exista ela ou nao.
     *
     * O consumidor que quiser assistir tem a rota dele, com a regra dele:
     * `GET /api/lessons/{lesson}/playback`.
     */
    public function test_consumidor_autenticado_recebe_403(): void
    {
        $aula = $this->aulaReproduzivel();
        $consumidor = User::factory()->consumer()->create();

        $resposta = $this->actingAs($consumidor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertForbidden();
        $resposta->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // Negativa por propriedade — `404`, e sem tocar no storage
    // -----------------------------------------------------------------------

    public function test_produtor_alheio_nao_recebe_url(): void
    {
        $outro = User::factory()->producer()->create();
        $aula = $this->aulaReproduzivel();

        $resposta = $this->actingAs($outro)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');
        $this->assertSame([], $this->storage->leituras);
        $this->assertStringNotContainsString(self::REFERENCIA, (string) $resposta->getContent());
    }

    public function test_aula_inexistente_e_aula_alheia_respondem_o_mesmo(): void
    {
        $outro = User::factory()->producer()->create();
        $aula = $this->aulaReproduzivel();

        $existente = $this->actingAs($outro)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');
        $inexistente = $this->actingAs($outro)
            ->getJson('/api/lessons/'.(string) Str::uuid7().'/video/playback');

        $existente->assertNotFound();
        $inexistente->assertNotFound();

        // Corpos identicos: quem varresse identificadores separando as duas
        // respostas obteria a lista do que existe (RN-PROP-005, AC-PROD-007).
        $this->assertSame($inexistente->json(), $existente->json());
        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // Negativa por disponibilidade — `409`, distinguivel da anterior
    // -----------------------------------------------------------------------

    public function test_aula_sem_tentativa_responde_conflito(): void
    {
        $aula = $this->umaAula($this->modulo);

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        // `409`, e nao `404`: a aula existe e e desta pessoa. O que falta e o
        // video, e a interface precisa separar "ainda nao ha o que assistir" de
        // "nao e sua".
        $resposta->assertStatus(409);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
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

    #[DataProvider('estadosQueNaoReproduzem')]
    public function test_video_fora_de_ready_responde_conflito(VideoState $estado): void
    {
        $aula = $this->umaAula($this->modulo);
        $this->umaTentativa($aula, $estado);

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_VIDEO_NOT_READY');
        $this->assertSame([], $this->storage->leituras);
    }

    public function test_video_pronto_sem_referencia_responde_conflito(): void
    {
        $aula = $this->umaAula($this->modulo);
        $tentativa = $this->umaTentativa($aula, VideoState::READY, referencia: self::REFERENCIA);

        // A tentativa e montada pelo caminho normal e so entao tem a referencia
        // retirada direto no banco. Nao ha como chegar a este estado pela
        // aplicacao: `markReady()` exige a referencia na assinatura, e o callback
        // de sucesso e recusado sem ela. Montar o cenario por fora e a unica
        // forma de exercitar a guarda.
        DB::table('video_attempts')
            ->where('id', $tentativa->id())
            ->update(['playback_reference' => null]);

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

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

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/video/playback');

        $resposta->assertStatus(503);
        $resposta->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

        $corpo = (string) $resposta->getContent();

        foreach (['presignRead', 'StorageUnavailable', self::REFERENCIA, '/app/'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    // -----------------------------------------------------------------------
    // A separacao entre as duas rotas
    // -----------------------------------------------------------------------

    /**
     * A rota do consumidor nao foi afrouxada para caber o produtor.
     *
     * Sem esta afirmacao, uma implementacao que simplesmente aceitasse os dois
     * perfis no endereco original passaria em todo o resto deste arquivo.
     */
    public function test_a_rota_do_consumidor_continua_recusando_o_produtor(): void
    {
        $aula = $this->aulaReproduzivel(self::PUBLICADA_EM);

        $resposta = $this->actingAs($this->produtor)
            ->getJson('/api/lessons/'.$aula->id().'/playback');

        $resposta->assertForbidden();
        $resposta->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame([], $this->storage->leituras);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Uma aula deste produtor, com tentativa em `ready` e referencia gravada.
     *
     * A referencia e deliberadamente diferente da chave derivada do
     * identificador da tentativa: e o que permite afirmar qual das duas foi
     * assinada.
     */
    private function aulaReproduzivel(?string $publicadaEm = null): Lesson
    {
        $aula = $this->umaAula($this->modulo);

        $this->umaTentativa($aula, VideoState::READY, referencia: self::REFERENCIA);

        if ($publicadaEm !== null) {
            $this->publicadaEm($aula, $publicadaEm);
        }

        return $aula;
    }
}
