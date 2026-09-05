<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * `GET /api/courses/{course}`.
 *
 * O centro deste arquivo e uma afirmacao so, e ela e comparativa: a resposta para
 * o curso de outro produtor precisa ser **identica** a resposta para um curso que
 * nunca existiu (RN-PROP-005, RN-AUT-006).
 *
 * Identica byte a byte, e nao apenas no status. Um `403` distinguiria os casos de
 * forma obvia; mas um `404` com corpo levemente diferente — outro codigo, outra
 * mensagem, outro cabecalho — distinguiria do mesmo jeito, so que de um jeito que
 * passa em revisao. Quem varre identificadores separando as duas respostas obtem
 * exatamente a lista do que existe.
 */
final class CourseDetailTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANTE = '2026-09-05T13:45:12+00:00';

    private User $produtor;

    private User $outro;

    private CourseRepository $repositorio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->outro = User::factory()->producer()->create();
        $this->repositorio = $this->app->make(CourseRepository::class);
    }

    // -----------------------------------------------------------------------
    // Curso proprio
    // -----------------------------------------------------------------------

    public function test_o_detalhe_do_curso_proprio_traz_os_seis_campos(): void
    {
        $curso = $this->cursoDe($this->produtor);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses/'.$curso->id());

        $resposta->assertOk();
        $resposta->assertExactJson([
            'data' => [
                'id' => $curso->id(),
                'title' => $curso->title(),
                'description' => $curso->description(),
                'owner_id' => (string) $this->produtor->getKey(),
                'state' => 'draft',
                'created_at' => self::INSTANTE,
            ],
        ]);
    }

    public function test_o_detalhe_nao_expoe_updated_at_nem_campo_interno(): void
    {
        $curso = $this->cursoDe($this->produtor);

        $dados = $this->actingAs($this->produtor)->getJson('/api/courses/'.$curso->id())->json('data');

        $this->assertSame(
            ['id', 'title', 'description', 'owner_id', 'state', 'created_at'],
            array_keys($dados),
        );
        $this->assertArrayNotHasKey('updated_at', $dados);
    }

    // -----------------------------------------------------------------------
    // As duas negativas indistinguiveis
    // -----------------------------------------------------------------------

    public function test_curso_de_outro_produtor_responde_404(): void
    {
        $alheio = $this->cursoDe($this->outro);

        $this->actingAs($this->produtor)
            ->getJson('/api/courses/'.$alheio->id())
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_curso_inexistente_responde_404(): void
    {
        $this->actingAs($this->produtor)
            ->getJson('/api/courses/'.(string) Uuid::uuid7())
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_as_duas_negativas_sao_identicas_byte_a_byte(): void
    {
        $alheio = $this->cursoDe($this->outro);
        $inexistente = (string) Uuid::uuid7();

        $paraAlheio = $this->actingAs($this->produtor)->getJson('/api/courses/'.$alheio->id());
        $paraInexistente = $this->actingAs($this->produtor)->getJson('/api/courses/'.$inexistente);

        $this->assertSame($paraAlheio->getStatusCode(), $paraInexistente->getStatusCode());
        $this->assertSame(
            $paraAlheio->headers->get('content-type'),
            $paraInexistente->headers->get('content-type'),
        );

        // A comparacao decisiva: o conteudo bruto, e nao o JSON decodificado.
        // Uma diferenca de ordem de chaves ou de espacamento tambem seria um
        // sinal utilizavel por quem estivesse sondando.
        $this->assertSame($paraAlheio->getContent(), $paraInexistente->getContent());
    }

    public function test_a_negativa_nao_carrega_nenhum_dado_do_curso_alheio(): void
    {
        $alheio = $this->cursoDe($this->outro);

        $bruto = $this->actingAs($this->produtor)
            ->getJson('/api/courses/'.$alheio->id())
            ->getContent();

        $this->assertIsString($bruto);

        foreach ([
            'identificador' => $alheio->id(),
            'titulo' => $alheio->title(),
            'descricao' => $alheio->description(),
            'dono' => (string) $this->outro->getKey(),
        ] as $rotulo => $valor) {
            $this->assertStringNotContainsString($valor, $bruto, "vazou o {$rotulo}");
        }
    }

    public function test_o_curso_alheio_continua_existindo_e_acessivel_ao_dono_dele(): void
    {
        $alheio = $this->cursoDe($this->outro);

        // O par positivo da negativa: sem ele, um endpoint que respondesse `404`
        // para todo mundo passaria em todos os testes acima.
        $this->actingAs($this->produtor)->getJson('/api/courses/'.$alheio->id())->assertStatus(404);
        $this->actingAs($this->outro)->getJson('/api/courses/'.$alheio->id())->assertOk();
    }

    // -----------------------------------------------------------------------
    // Identificador malformado
    // -----------------------------------------------------------------------

    #[DataProvider('identificadoresMalformados')]
    public function test_identificador_malformado_responde_404_sem_consultar_o_banco(string $identificador): void
    {
        $consultas = [];
        DB::listen(function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses/'.$identificador);

        $resposta->assertStatus(404);
        $resposta->assertJsonPath('code', 'NOT_FOUND');

        // A restricao de formato na rota recusa antes de qualquer codigo da
        // aplicacao rodar: nenhuma consulta a tabela de cursos chega a ser
        // emitida.
        foreach ($consultas as $sql) {
            $this->assertStringNotContainsString('`courses`', $sql, $sql);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identificadoresMalformados(): iterable
    {
        yield 'texto' => ['nao-e-um-uuid'];
        yield 'numero' => ['12345'];
        yield 'uuid truncado' => ['01936f1a-7c00-7000-8000'];
        yield 'caracteres invalidos' => ['zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'];
    }

    public function test_o_404_do_identificador_malformado_e_igual_ao_do_inexistente(): void
    {
        $malformado = $this->actingAs($this->produtor)->getJson('/api/courses/nao-e-um-uuid');
        $inexistente = $this->actingAs($this->produtor)->getJson('/api/courses/'.(string) Uuid::uuid7());

        $this->assertSame($malformado->getStatusCode(), $inexistente->getStatusCode());
        $this->assertSame($malformado->getContent(), $inexistente->getContent());
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function cursoDe(User $dono): Course
    {
        $curso = Course::create(
            id: $this->repositorio->nextIdentity(),
            ownerId: (string) $dono->getKey(),
            title: 'Curso de '.$dono->getKey(),
            description: 'Descricao do curso de '.$dono->getKey(),
            createdAt: new DateTimeImmutable(self::INSTANTE),
        );

        $this->repositorio->save($curso);

        return $curso;
    }
}
