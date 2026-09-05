<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Models\User;
use App\Shared\Interfaces\Http\Resource\PerPage;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `GET /api/courses`.
 *
 * Duas coisas sao provadas aqui, e a segunda e a que costuma escapar: alem de a
 * lista trazer somente cursos proprios, os **numeros** da paginacao tambem
 * precisam ignorar os alheios. Uma implementacao que filtrasse depois de carregar
 * passaria no primeiro teste e falharia no segundo — e, em producao, o `total`
 * sozinho ja diria quantos cursos os outros produtores tem (RN-PROP-005).
 */
final class CourseListTest extends TestCase
{
    use RefreshDatabase;

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
    // Isolamento
    // -----------------------------------------------------------------------

    public function test_a_lista_traz_somente_cursos_proprios(): void
    {
        $meus = $this->cursos($this->produtor, 3);
        $this->cursos($this->outro, 4);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses');

        $resposta->assertOk();
        $resposta->assertJsonCount(3, 'data');

        $this->assertEqualsCanonicalizing(
            array_map(fn (Course $c): string => $c->id(), $meus),
            array_column($resposta->json('data'), 'id'),
        );
    }

    public function test_o_total_ignora_cursos_de_outro_produtor(): void
    {
        $this->cursos($this->produtor, 2);
        $this->cursos($this->outro, 40);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses');

        $resposta->assertJsonPath('meta.total', 2);
        $resposta->assertJsonPath('meta.last_page', 1);
    }

    public function test_nenhum_dado_de_curso_alheio_aparece_na_resposta(): void
    {
        $this->cursos($this->produtor, 1);
        $alheio = $this->cursos($this->outro, 1)[0];

        $bruto = $this->actingAs($this->produtor)->getJson('/api/courses')->getContent();
        $this->assertIsString($bruto);

        // Nem identificador, nem titulo, nem dono: a ausencia e verificada no
        // corpo inteiro, e nao apenas nas chaves que o teste conhece.
        $this->assertStringNotContainsString($alheio->id(), $bruto);
        $this->assertStringNotContainsString($alheio->title(), $bruto);
        $this->assertStringNotContainsString((string) $this->outro->getKey(), $bruto);
    }

    public function test_produtor_sem_cursos_recebe_lista_vazia_e_nao_erro(): void
    {
        $this->cursos($this->outro, 3);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses');

        $resposta->assertOk();
        $resposta->assertJsonCount(0, 'data');
        $resposta->assertJsonPath('meta.total', 0);
        // Uma lista vazia continua tendo uma pagina: zero paginas quebraria a
        // navegacao do cliente.
        $resposta->assertJsonPath('meta.last_page', 1);
    }

    // -----------------------------------------------------------------------
    // Formato e ordem
    // -----------------------------------------------------------------------

    public function test_o_envelope_segue_o_formato_aprovado(): void
    {
        $this->cursos($this->produtor, 1);

        $corpo = $this->actingAs($this->produtor)->getJson('/api/courses')->json();

        $this->assertSame(['data', 'meta', 'links'], array_keys($corpo));
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($corpo['meta']));
        $this->assertSame(['first', 'prev', 'next', 'last'], array_keys($corpo['links']));
    }

    public function test_cada_item_da_lista_traz_os_mesmos_seis_campos_do_detalhe(): void
    {
        $this->cursos($this->produtor, 2);

        $itens = $this->actingAs($this->produtor)->getJson('/api/courses')->json('data');

        foreach ($itens as $indice => $item) {
            $this->assertSame(
                ['id', 'title', 'description', 'owner_id', 'state', 'created_at'],
                array_keys($item),
                "item {$indice}",
            );
        }
    }

    public function test_a_ordem_e_do_mais_recente_para_o_mais_antigo(): void
    {
        $antigo = $this->curso($this->produtor, '2026-09-01T10:00:00+00:00');
        $recente = $this->curso($this->produtor, '2026-09-03T10:00:00+00:00');
        $meio = $this->curso($this->produtor, '2026-09-02T10:00:00+00:00');

        $itens = $this->actingAs($this->produtor)->getJson('/api/courses')->json('data');

        $this->assertSame(
            [$recente->id(), $meio->id(), $antigo->id()],
            array_column($itens, 'id'),
        );
    }

    // -----------------------------------------------------------------------
    // Tamanho de pagina
    // -----------------------------------------------------------------------

    public function test_sem_per_page_a_pagina_traz_no_maximo_quinze(): void
    {
        $this->cursos($this->produtor, 20);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses');

        $resposta->assertJsonPath('meta.per_page', PerPage::PADRAO);
        $resposta->assertJsonCount(15, 'data');
        $resposta->assertJsonPath('meta.total', 20);
    }

    public function test_per_page_no_teto_e_atendido(): void
    {
        $this->cursos($this->produtor, 55);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses?per_page=50');

        $resposta->assertOk();
        $resposta->assertJsonPath('meta.per_page', 50);
        $resposta->assertJsonCount(50, 'data');
    }

    public function test_per_page_acima_do_teto_e_limitado_e_nao_recusado(): void
    {
        $this->cursos($this->produtor, 55);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses?per_page=500');

        // Limitado, e nao rejeitado: a intencao e atendivel, e o teto protege a
        // resposta em vez de punir o pedido.
        $resposta->assertOk();
        $resposta->assertJsonPath('meta.per_page', PerPage::MAXIMO);
        $resposta->assertJsonCount(PerPage::MAXIMO, 'data');
    }

    #[DataProvider('tamanhosInvalidos')]
    public function test_per_page_invalido_responde_422(string $valor): void
    {
        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses?per_page='.$valor);

        $resposta->assertStatus(422);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertNotEmpty($resposta->json('errors.per_page'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tamanhosInvalidos(): iterable
    {
        // Aqui nao ha intencao a atender: nao existe pagina de tamanho zero, e
        // escolher um padrao no lugar esconderia de quem pediu que o valor nao
        // faz sentido.
        yield 'zero' => ['0'];
        yield 'negativo' => ['-5'];
        yield 'texto' => ['muitos'];
        yield 'fracionario' => ['2.5'];
    }

    #[DataProvider('paginasInvalidas')]
    public function test_page_invalida_responde_422(string $valor): void
    {
        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses?page='.$valor);

        $resposta->assertStatus(422);
        $this->assertNotEmpty($resposta->json('errors.page'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paginasInvalidas(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negativa' => ['-1'];
        yield 'texto' => ['ultima'];
    }

    public function test_a_segunda_pagina_continua_restrita_ao_dono(): void
    {
        $this->cursos($this->produtor, 20);
        $this->cursos($this->outro, 20);

        $resposta = $this->actingAs($this->produtor)->getJson('/api/courses?page=2');

        $resposta->assertOk();
        $resposta->assertJsonPath('meta.current_page', 2);
        $resposta->assertJsonPath('meta.total', 20);
        $resposta->assertJsonCount(5, 'data');

        foreach ($resposta->json('data') as $item) {
            $this->assertSame((string) $this->produtor->getKey(), $item['owner_id']);
        }
    }

    // -----------------------------------------------------------------------
    // Os links precisam ser navegaveis
    // -----------------------------------------------------------------------

    public function test_os_links_preservam_o_tamanho_da_pagina(): void
    {
        $this->cursos($this->produtor, 5);

        $links = $this->actingAs($this->produtor)
            ->getJson('/api/courses?per_page=2')
            ->json('links');

        foreach (['first', 'next', 'last'] as $nome) {
            $this->assertNotNull($links[$nome], $nome);
            $this->assertStringContainsString('per_page=2', $links[$nome], $nome);
        }
    }

    public function test_cada_link_aponta_para_a_propria_pagina(): void
    {
        $this->cursos($this->produtor, 5);

        $links = $this->actingAs($this->produtor)
            ->getJson('/api/courses?per_page=2&page=2')
            ->json('links');

        // O erro que este teste existe para pegar: todos os links carregarem o
        // numero da pagina atual, o que faria a navegacao girar em falso.
        $this->assertStringContainsString('page=1', $links['first']);
        $this->assertStringContainsString('page=1', $links['prev']);
        $this->assertStringContainsString('page=3', $links['next']);
        $this->assertStringContainsString('page=3', $links['last']);
    }

    public function test_seguir_o_link_next_mantem_o_tamanho_da_pagina(): void
    {
        // A prova que so o percurso completo da: o cliente pede uma pagina, pega
        // o endereco que a **propria API** entregou e o segue. Se o tamanho nao
        // sobrevivesse, a segunda pagina viria com 15 itens e a navegacao pularia
        // registros sem que nenhuma resposta parecesse errada isoladamente.
        $this->cursos($this->produtor, 5);

        $primeira = $this->actingAs($this->produtor)->getJson('/api/courses?per_page=2');
        $primeira->assertJsonPath('meta.per_page', 2);
        $primeira->assertJsonCount(2, 'data');

        $proxima = $primeira->json('links.next');
        $this->assertNotNull($proxima);

        $segunda = $this->actingAs($this->produtor)->getJson($proxima);

        $segunda->assertOk();
        $segunda->assertJsonPath('meta.per_page', 2);
        $segunda->assertJsonPath('meta.current_page', 2);
        $segunda->assertJsonPath('meta.total', 5);
        $segunda->assertJsonCount(2, 'data');

        // E os itens sao outros: seguir o link avancou de verdade.
        $this->assertEmpty(array_intersect(
            array_column($primeira->json('data'), 'id'),
            array_column($segunda->json('data'), 'id'),
        ));
    }

    public function test_o_link_anuncia_o_tamanho_efetivo_e_nao_o_pedido(): void
    {
        $this->cursos($this->produtor, 60);

        $links = $this->actingAs($this->produtor)
            ->getJson('/api/courses?per_page=500')
            ->json('links');

        // Pediu 500, recebeu 50 — e o link precisa dizer 50. Repetir o pedido
        // original faria o link prometer uma pagina que a API nao entrega.
        $this->assertStringContainsString('per_page=50', $links['next']);
        $this->assertStringNotContainsString('per_page=500', $links['next']);

        $segunda = $this->actingAs($this->produtor)->getJson($links['next']);
        $segunda->assertJsonPath('meta.per_page', 50);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Titulos irrepetiveis e atribuiveis a um dono.
     *
     * O prefixo do UUIDv7 nao serve para isso: identificadores gerados no mesmo
     * milissegundo compartilham os primeiros caracteres, e dois cursos de donos
     * diferentes acabariam com o mesmo titulo — o que faria o teste de vazamento
     * acusar uma fuga que nao existe.
     */
    private int $sequencia = 0;

    private function curso(User $dono, string $criadoEm): Course
    {
        $this->sequencia++;

        $curso = Course::create(
            id: $this->repositorio->nextIdentity(),
            ownerId: (string) $dono->getKey(),
            title: sprintf('Curso %s numero %d', $dono->getKey(), $this->sequencia),
            description: 'Descricao do curso.',
            createdAt: new DateTimeImmutable($criadoEm),
        );

        $this->repositorio->save($curso);

        return $curso;
    }

    /**
     * @return list<Course>
     */
    private function cursos(User $dono, int $quantidade): array
    {
        $base = new DateTimeImmutable('2026-09-01T10:00:00+00:00');
        $cursos = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $cursos[] = $this->curso($dono, $base->modify("+{$i} minutes")->format(DATE_ATOM));
        }

        return $cursos;
    }
}
