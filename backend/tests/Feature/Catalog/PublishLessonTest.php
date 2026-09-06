<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseState;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * `POST /api/lessons/{lesson}/publish` (RF-PUB-001 a 003, plan §§8.3, 14.1).
 *
 * Cobre AC-PROD-004, AC-PROD-005 e AC-PROD-006.
 *
 * A operacao coordena tres agregados numa transacao so, e os testes seguem essa
 * divisao: o que a aula precisa ter, o que o video precisa estar, e o que
 * acontece com o curso na primeira vez.
 */
final class PublishLessonTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private User $produtor;

    private Course $curso;

    private Module $modulo;

    private Lesson $aula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor);
        $this->modulo = $this->umModulo($this->curso);
        $this->aula = $this->umaAula($this->modulo);
    }

    // -----------------------------------------------------------------------
    // Publicacao aceita (AC-PROD-005)
    // -----------------------------------------------------------------------

    public function test_aula_com_video_ready_e_referencia_e_publicada(): void
    {
        $this->umaTentativa($this->aula, VideoState::READY);

        $resposta = $this->publicar();

        $resposta->assertOk();
        $this->assertNotNull($resposta->json('data.published_at'));
        $resposta->assertJsonPath('data.video_state', VideoState::READY->value);

        $this->assertNotNull(
            DB::table('lessons')->where('id', $this->aula->id())->value('published_at'),
        );
    }

    public function test_republicar_responde_200_sem_novo_efeito(): void
    {
        $this->umaTentativa($this->aula, VideoState::READY);

        $primeira = $this->publicar();
        $primeira->assertOk();
        $instante = DB::table('lessons')->where('id', $this->aula->id())->value('published_at');

        $segunda = $this->publicar();

        $segunda->assertOk();
        // O instante da primeira publicacao e preservado: republicar nao reescreve
        // o que ja aconteceu (RN-PUB-005, RN-IDM-004).
        $this->assertSame(
            $instante,
            DB::table('lessons')->where('id', $this->aula->id())->value('published_at'),
        );
    }

    // -----------------------------------------------------------------------
    // Estados nao elegiveis (AC-PROD-004, RF-ERR-010)
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosNaoElegiveis(): iterable
    {
        yield 'pending' => [VideoState::PENDING];
        yield 'uploading' => [VideoState::UPLOADING];
        yield 'uploaded' => [VideoState::UPLOADED];
        yield 'processing' => [VideoState::PROCESSING];
        yield 'failed' => [VideoState::FAILED];
    }

    #[DataProvider('estadosNaoElegiveis')]
    public function test_video_fora_de_ready_recusa_a_publicacao_com_409(VideoState $estado): void
    {
        $this->umaTentativa($this->aula, $estado);

        $resposta = $this->publicar();

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_VIDEO_NOT_READY');
        // A resposta identifica a condicao **e** o estado, para a interface
        // distinguir "espere" de "envie de novo" (RF-UI-010).
        $resposta->assertJsonPath('video_state', $estado->value);

        $this->assertNull(
            DB::table('lessons')->where('id', $this->aula->id())->value('published_at'),
        );
    }

    public function test_aula_sem_video_recusa_com_codigo_proprio(): void
    {
        $resposta = $this->publicar();

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_WITHOUT_VIDEO');
    }

    public function test_video_ready_sem_referencia_recusa_com_codigo_proprio(): void
    {
        $tentativa = $this->umaTentativa($this->aula, VideoState::READY);

        // O callback de sucesso exige a referencia, entao esta combinacao nao
        // deveria existir — e a verificacao existe porque "nao deveria" nao e
        // garantia.
        DB::table('video_attempts')
            ->where('id', $tentativa->id())
            ->update(['playback_reference' => null]);

        $resposta = $this->publicar();

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'LESSON_PLAYBACK_REFERENCE_MISSING');
    }

    public function test_o_video_em_failed_bloqueia_a_publicacao(): void
    {
        // A outra metade de AC-VID-007: a falha e visivel **e** impede publicar.
        $this->umaTentativa($this->aula, VideoState::FAILED);

        $this->publicar()->assertStatus(409);

        $this->assertNull(
            DB::table('lessons')->where('id', $this->aula->id())->value('published_at'),
        );
    }

    // -----------------------------------------------------------------------
    // Promocao do curso (AC-PROD-006)
    // -----------------------------------------------------------------------

    public function test_a_primeira_publicacao_torna_o_curso_disponivel(): void
    {
        $this->assertSame(
            CourseState::DRAFT->value,
            DB::table('courses')->where('id', $this->curso->id())->value('state'),
        );

        $this->umaTentativa($this->aula, VideoState::READY);
        $this->publicar()->assertOk();

        $this->assertSame(
            CourseState::AVAILABLE->value,
            DB::table('courses')->where('id', $this->curso->id())->value('state'),
        );
    }

    public function test_a_segunda_publicacao_nao_altera_o_curso_de_novo(): void
    {
        $this->umaTentativa($this->aula, VideoState::READY);
        $this->publicar()->assertOk();

        $outraAula = $this->umaAula($this->modulo, 'Segunda aula');
        $this->umaTentativa($outraAula, VideoState::READY);

        $this->actingAs($this->produtor)
            ->postJson("/api/lessons/{$outraAula->id()}/publish")
            ->assertOk();

        $this->assertSame(
            CourseState::AVAILABLE->value,
            DB::table('courses')->where('id', $this->curso->id())->value('state'),
        );
    }

    // -----------------------------------------------------------------------
    // Isolamento
    // -----------------------------------------------------------------------

    public function test_aula_de_outro_produtor_responde_404_e_nao_publica(): void
    {
        $outro = User::factory()->producer()->create();
        $aulaAlheia = $this->umaAula($this->umModulo($this->umCurso($outro)));
        $this->umaTentativa($aulaAlheia, VideoState::READY);

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/lessons/{$aulaAlheia->id()}/publish");

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');

        $this->assertNull(
            DB::table('lessons')->where('id', $aulaAlheia->id())->value('published_at'),
        );
    }

    public function test_o_consumidor_nao_alcanca_a_rota(): void
    {
        $consumidor = User::factory()->consumer()->create();

        $this->actingAs($consumidor)
            ->postJson("/api/lessons/{$this->aula->id()}/publish")
            ->assertForbidden();
    }

    private function publicar(): TestResponse
    {
        return $this->actingAs($this->produtor)->postJson("/api/lessons/{$this->aula->id()}/publish");
    }
}
