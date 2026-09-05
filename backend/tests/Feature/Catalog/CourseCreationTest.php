<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Interfaces\Http\Request\CreateCourseRequest;
use App\Models\User;
use App\Shared\Application\Port\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * `POST /api/courses`.
 *
 * A autenticacao entra por `actingAs`: a prova completa de sessao, cookies e
 * fluxo HTTP real pertence a T030 e ja existe. O que estes testes precisam
 * garantir e outra coisa — que as rotas estao **sob** os middlewares certos, e
 * que o dono do curso criado vem de quem esta autenticado.
 */
final class CourseCreationTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANTE = '2026-09-05T13:45:12+00:00';

    private User $produtor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();

        // O relogio parado transforma a data em afirmacao de igualdade. Com o
        // relogio do sistema, o teste so conseguiria dizer que a data caiu perto
        // do agora — o que passaria tambem se a aplicacao gravasse a data do
        // banco em vez da data da operacao.
        $this->app->instance(Clock::class, FrozenClock::at(self::INSTANTE));
    }

    // -----------------------------------------------------------------------
    // Caminho feliz
    // -----------------------------------------------------------------------

    public function test_o_produtor_cria_um_curso_e_recebe_201(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => 'Uma introducao pratica a linguagem.',
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('data.title', 'Fundamentos de PHP');
        $resposta->assertJsonPath('data.description', 'Uma introducao pratica a linguagem.');
    }

    public function test_a_resposta_traz_exatamente_os_seis_campos_do_contrato(): void
    {
        $dados = $this->criar()->json('data');

        // A lista fechada, e nao apenas a presenca: um campo a mais tambem quebra
        // o contrato, porque passa a ser algo que o cliente recebe e ninguem
        // prometeu manter.
        $this->assertSame(
            ['id', 'title', 'description', 'owner_id', 'state', 'created_at'],
            array_keys($dados),
        );
    }

    public function test_o_curso_nasce_em_rascunho(): void
    {
        $this->criar()->assertJsonPath('data.state', 'draft');
    }

    public function test_o_identificador_e_um_uuid_versao_7(): void
    {
        $id = $this->criar()->json('data.id');

        $this->assertTrue(Uuid::isValid($id));
        $this->assertSame(7, Uuid::fromString($id)->getFields()->getVersion());
    }

    public function test_a_data_de_criacao_vem_em_iso_8601(): void
    {
        $criadoEm = $this->criar()->json('data.created_at');

        $this->assertSame(self::INSTANTE, $criadoEm);
    }

    public function test_o_curso_criado_e_gravado_no_banco(): void
    {
        $id = $this->criar()->json('data.id');

        $this->assertDatabaseHas('courses', [
            'id' => $id,
            'owner_id' => (string) $this->produtor->getKey(),
            'state' => 'draft',
        ]);
    }

    // -----------------------------------------------------------------------
    // O dono vem da sessao
    // -----------------------------------------------------------------------

    public function test_o_proprietario_vem_da_sessao(): void
    {
        $this->criar()->assertJsonPath('data.owner_id', (string) $this->produtor->getKey());
    }

    public function test_owner_id_enviado_no_corpo_nao_sobrescreve_o_backend(): void
    {
        $outro = User::factory()->producer()->create();

        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => 'Descricao.',
            'owner_id' => (string) $outro->getKey(),
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('data.owner_id', (string) $this->produtor->getKey());

        // E o banco concorda: nao basta a resposta esconder, o registro tambem
        // precisa pertencer a quem criou.
        $this->assertDatabaseHas('courses', [
            'id' => $resposta->json('data.id'),
            'owner_id' => (string) $this->produtor->getKey(),
        ]);
        $this->assertDatabaseMissing('courses', ['owner_id' => (string) $outro->getKey()]);
    }

    public function test_state_e_id_enviados_no_corpo_nao_sobrescrevem_o_backend(): void
    {
        $idForjado = (string) Uuid::uuid7();

        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => 'Descricao.',
            'id' => $idForjado,
            'state' => 'available',
            'created_at' => '1999-01-01T00:00:00+00:00',
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('data.state', 'draft');
        $resposta->assertJsonPath('data.created_at', self::INSTANTE);
        $this->assertNotSame($idForjado, $resposta->json('data.id'));

        $this->assertDatabaseMissing('courses', ['id' => $idForjado]);
    }

    // -----------------------------------------------------------------------
    // Entrada invalida
    // -----------------------------------------------------------------------

    public function test_titulo_ausente_responde_422_em_portugues(): void
    {
        $resposta = $this->actingAs($this->produtor)
            ->postJson('/api/courses', ['description' => 'Descricao.']);

        $resposta->assertStatus(422);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(
            ['O campo titulo e obrigatorio.'],
            $resposta->json('errors.title'),
        );
    }

    public function test_descricao_ausente_responde_422_em_portugues(): void
    {
        $resposta = $this->actingAs($this->produtor)
            ->postJson('/api/courses', ['title' => 'Fundamentos de PHP']);

        $resposta->assertStatus(422);
        $this->assertSame(
            ['O campo descricao e obrigatorio.'],
            $resposta->json('errors.description'),
        );
    }

    public function test_titulo_que_nao_e_texto_responde_422(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => ['nao', 'e', 'texto'],
            'description' => 'Descricao.',
        ]);

        $resposta->assertStatus(422);
        $this->assertSame(['O campo titulo precisa ser um texto.'], $resposta->json('errors.title'));
    }

    public function test_titulo_maior_que_a_coluna_responde_422(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => str_repeat('a', CreateCourseRequest::LIMITE_TITULO + 1),
            'description' => 'Descricao.',
        ]);

        $resposta->assertStatus(422);
        $this->assertSame(
            ['O campo titulo nao pode ter mais de 255 caracteres.'],
            $resposta->json('errors.title'),
        );
    }

    public function test_titulo_no_limite_exato_da_coluna_e_aceito(): void
    {
        // O par positivo do teste acima: sem ele, uma regra que recusasse tudo
        // passaria igual.
        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => str_repeat('a', CreateCourseRequest::LIMITE_TITULO),
            'description' => 'Descricao.',
        ]);

        $resposta->assertCreated();
    }

    public function test_descricao_longa_com_acentos_e_recusada_antes_do_banco(): void
    {
        // O caso que o limite por caractere existe para cobrir: `TEXT` guarda
        // 65.535 **bytes**, e cada caractere acentuado ocupa mais de um. Sem a
        // recusa na validacao, o MySQL em modo estrito rejeitaria a escrita e a
        // resposta viraria erro interno em vez de mensagem de campo.
        $resposta = $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => str_repeat('ç', CreateCourseRequest::LIMITE_DESCRICAO + 1),
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertNotEmpty($resposta->json('errors.description'));
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_entrada_invalida_nao_cria_curso(): void
    {
        $this->actingAs($this->produtor)->postJson('/api/courses', [])->assertStatus(422);

        $this->assertDatabaseCount('courses', 0);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function criar(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => 'Uma introducao pratica a linguagem.',
        ]);
    }
}
