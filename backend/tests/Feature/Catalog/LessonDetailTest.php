<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/lessons/{lesson}`.
 *
 * A consulta individual devolve os mesmos seis campos da criacao, e um deles nao
 * vem do agregado: `video_state` e lido da tentativa atual, em
 * `video_attempts` (plan §6.1). Provar isso exige um cenario com tentativa
 * semeada, e e o que a maior parte deste arquivo faz.
 *
 * O isolamento e o de sempre: aula alheia e aula inexistente respondem o mesmo
 * `404`, indistinguivel (RN-PROP-005).
 */
final class LessonDetailTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

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
        $this->aula = $this->umaAula($this->modulo, 'Primeiros passos');
    }

    // -----------------------------------------------------------------------
    // Conteudo e forma
    // -----------------------------------------------------------------------

    public function test_a_consulta_devolve_a_aula_propria(): void
    {
        $resposta = $this->consultar($this->aula->id());

        $resposta->assertOk();
        $resposta->assertJsonPath('data.id', $this->aula->id());
        $resposta->assertJsonPath('data.title', 'Primeiros passos');
        $resposta->assertJsonPath('data.module_id', $this->modulo->id());
        $resposta->assertJsonPath('data.position', 1);
    }

    public function test_a_resposta_traz_exatamente_os_seis_campos_do_contrato(): void
    {
        $this->assertSame(
            ['id', 'module_id', 'title', 'position', 'published_at', 'video_state'],
            array_keys($this->consultar($this->aula->id())->json('data')),
        );
    }

    public function test_os_seis_campos_sao_os_mesmos_da_criacao(): void
    {
        // Uma aula que mudasse de forma conforme o endereco de onde foi lida
        // obrigaria o cliente a manter dois modelos para a mesma coisa.
        $criada = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$this->modulo->id()}/lessons", ['title' => 'Outra aula'])
            ->json('data');

        $consultada = $this->consultar($criada['id'])->json('data');

        $this->assertSame($criada, $consultada);
    }

    public function test_uma_aula_publicada_traz_a_data_em_iso_8601(): void
    {
        $this->publicadaEm($this->aula, '2026-09-05T13:45:12+00:00');

        $this->consultar($this->aula->id())
            ->assertJsonPath('data.published_at', '2026-09-05T13:45:12+00:00');
    }

    // -----------------------------------------------------------------------
    // O estado do video
    // -----------------------------------------------------------------------

    public function test_aula_sem_tentativa_devolve_video_state_nulo(): void
    {
        $resposta = $this->consultar($this->aula->id());

        $this->assertNull($resposta->json('data.video_state'));
        $this->assertArrayHasKey('video_state', $resposta->json('data'));
    }

    #[DataProvider('estadosDeVideo')]
    public function test_o_estado_reflete_a_tentativa_atual(string $estado): void
    {
        $this->umaTentativaDeVideo($this->aula, VideoState::from($estado));

        // O valor vem de `video_attempts`, e nao de uma copia guardada na aula:
        // uma copia envelheceria a cada callback (plan §6.1).
        $this->consultar($this->aula->id())->assertJsonPath('data.video_state', $estado);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function estadosDeVideo(): iterable
    {
        foreach (VideoState::cases() as $estado) {
            yield $estado->value => [$estado->value];
        }
    }

    public function test_o_estado_acompanha_a_mudanca_da_tentativa(): void
    {
        $tentativa = $this->umaTentativaDeVideo($this->aula, VideoState::PROCESSING);

        $this->consultar($this->aula->id())->assertJsonPath('data.video_state', 'processing');

        DB::table('video_attempts')->where('id', $tentativa)->update(['state' => 'ready']);

        // Sem uma segunda leitura devolvendo o valor novo, a resposta estaria
        // servindo uma copia congelada.
        $this->consultar($this->aula->id())->assertJsonPath('data.video_state', 'ready');
    }

    public function test_a_consulta_da_aula_gasta_uma_unica_ida_ao_banco_para_aula_e_video(): void
    {
        $this->umaTentativaDeVideo($this->aula, VideoState::PROCESSING);

        $consultas = $this->capturarConsultas(fn () => $this->consultar($this->aula->id()));

        $deAula = array_values(array_filter(
            $consultas,
            fn (string $sql): bool => str_contains($sql, 'from `lessons`'),
        ));

        // Uma consulta so, com juncao a esquerda: buscar a tentativa depois de
        // carregar a aula custaria uma ida a mais, e na estrutura viraria uma por
        // aula do curso.
        $this->assertCount(1, $deAula);
        $this->assertStringContainsString('left join `video_attempts`', $deAula[0]);
    }

    // -----------------------------------------------------------------------
    // Isolamento entre produtores
    // -----------------------------------------------------------------------

    public function test_aula_de_outro_produtor_responde_404(): void
    {
        $aulaAlheia = $this->umaAula(
            $this->umModulo($this->umCurso(User::factory()->producer()->create())),
        );

        $resposta = $this->consultar($aulaAlheia->id());

        $resposta->assertNotFound();
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_aula_alheia_e_aula_inexistente_respondem_identicamente(): void
    {
        $aulaAlheia = $this->umaAula(
            $this->umModulo($this->umCurso(User::factory()->producer()->create())),
        );

        $alheia = $this->consultar($aulaAlheia->id());
        $ausente = $this->consultar((string) Uuid::uuid7());

        $this->assertSame($alheia->getStatusCode(), $ausente->getStatusCode());
        $this->assertSame($alheia->getContent(), $ausente->getContent());
        $this->assertSame(
            $alheia->headers->get('content-type'),
            $ausente->headers->get('content-type'),
        );
    }

    public function test_a_cadeia_completa_da_propriedade_e_verificada(): void
    {
        // O caso que uma checagem incompleta deixaria passar: a aula pertence a um
        // modulo cujo curso e de outro produtor. Verificar so `lesson -> module`
        // nao encontraria nada errado.
        $outro = User::factory()->producer()->create();
        $aulaAlheia = $this->umaAula($this->umModulo($this->umCurso($outro)));

        $this->assertDatabaseHas('lessons', ['id' => $aulaAlheia->id()]);
        $this->consultar($aulaAlheia->id())->assertNotFound();

        // O par positivo: o dono de verdade alcanca a mesma aula.
        $this->actingAs($outro)->getJson("/api/lessons/{$aulaAlheia->id()}")->assertOk();
    }

    public function test_identificador_malformado_responde_404(): void
    {
        $this->actingAs($this->produtor)->getJson('/api/lessons/nao-e-uuid')->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function consultar(string $id): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->produtor)->getJson("/api/lessons/{$id}");
    }

    /**
     * @return list<string>
     */
    private function capturarConsultas(callable $acao): array
    {
        $consultas = [];

        DB::listen(function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        $acao();

        DB::flushQueryLog();

        return $consultas;
    }
}
