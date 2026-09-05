<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Models\User;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use DateTimeImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * Duas garantias do contrato de erro que nao dependem de quem chama.
 *
 * **A primeira: o formato da resposta nao depende do cabecalho `Accept`.** Uma
 * rota sob `/api/` e uma API, e responde como tal mesmo quando o cliente nao
 * declara o que espera — que e exatamente o que um navegador faz ao abrir a URL
 * na barra de enderecos.
 *
 * **A segunda: recusa esperada nao e defeito.** Um curso que nao existe para
 * quem pediu produz `404` e nada no log de erro; uma excecao inesperada produz
 * `500` generico e o registro completo. As duas coisas precisam continuar
 * distintas.
 */
final class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAGEM_INTERNA = 'detalhe-que-nunca-pode-vazar-c4f1';

    // -----------------------------------------------------------------------
    // Sem `Accept`, a API continua sendo uma API
    // -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function rotasProtegidas(): iterable
    {
        yield 'auth/me' => ['/api/auth/me'];
        yield 'lista de cursos' => ['/api/courses'];
        yield 'detalhe de curso' => ['/api/courses/'.'01936f1a-7c00-7aaa-8bbb-ccccccccdddd'];
    }

    #[DataProvider('rotasProtegidas')]
    public function test_visitante_sem_cabecalho_accept_recebe_401_no_contrato_da_api(string $caminho): void
    {
        // `get`, e nao `getJson`: o helper de JSON acrescenta
        // `Accept: application/json`, e era justamente esse cabecalho que
        // escondia o defeito. Aqui a requisicao chega como a de um navegador.
        $resposta = $this->get($caminho);

        $resposta->assertStatus(401);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'UNAUTHENTICATED');
        $resposta->assertJsonPath('status', 401);
    }

    #[DataProvider('rotasProtegidas')]
    public function test_visitante_nao_e_redirecionado_para_tela_de_login(string $caminho): void
    {
        $resposta = $this->get($caminho);

        // Nem redirecionamento, nem erro interno: as duas respostas que o
        // comportamento padrao do framework produzia neste caminho.
        $this->assertNotSame(302, $resposta->getStatusCode());
        $this->assertNotSame(500, $resposta->getStatusCode());
        $this->assertNull($resposta->headers->get('Location'));
    }

    #[DataProvider('rotasProtegidas')]
    public function test_a_resposta_sem_accept_e_identica_a_com_accept(string $caminho): void
    {
        // A afirmacao mais forte deste arquivo: o cabecalho nao muda nada. Sem
        // ela, uma correcao que apenas trocasse o `500` por outro erro qualquer
        // passaria nos testes acima.
        $sem = $this->get($caminho);
        $com = $this->getJson($caminho);

        $this->assertSame($com->getStatusCode(), $sem->getStatusCode());
        $this->assertSame($com->headers->get('content-type'), $sem->headers->get('content-type'));
        $this->assertSame($com->getContent(), $sem->getContent());
    }

    public function test_nao_existe_rota_com_o_nome_login_para_redirecionamento(): void
    {
        // Cuidado com a semelhanca de nomes: a aplicacao **tem**
        // `POST /api/auth/login`, e o nome dela e `auth.login`. Esse endpoint e a
        // autenticacao da API e continua existindo.
        //
        // O que nao pode existir e uma segunda rota chamada simplesmente `login`
        // — o nome que o middleware do framework procura ao montar o
        // redirecionamento do visitante. A correcao foi remover o
        // redirecionamento, e nao criar o destino dele: uma rota com esse nome
        // faria os testes acima passarem enquanto reintroduzia a tela que esta
        // aplicacao nao tem.
        $this->assertNull(Route::getRoutes()->getByName('login'));

        // E o par positivo, para que a afirmacao acima nao passe por engano se
        // um dia o roteador deixar de resolver nomes.
        $this->assertNotNull(Route::getRoutes()->getByName('auth.login'));
    }

    public function test_o_formato_tambem_vale_para_metodo_mutante_sem_accept(): void
    {
        $resposta = $this->post('/api/courses', ['title' => 'x', 'description' => 'y']);

        $this->assertNotSame(302, $resposta->getStatusCode());
        $this->assertNotSame(500, $resposta->getStatusCode());
        $resposta->assertHeader('content-type', 'application/problem+json');
    }

    // -----------------------------------------------------------------------
    // Recusa esperada nao vira registro de erro
    // -----------------------------------------------------------------------

    public function test_o_404_de_curso_alheio_nao_e_reportado_como_erro(): void
    {
        [$produtor, $alheio] = $this->cursoDeOutroProdutor();

        // O espiao substitui o canal de log: nada e escrito de verdade, e o que
        // teria sido escrito fica registrado para ser conferido. E o oposto de
        // desligar o log — aqui a pergunta *e* sobre o que foi registrado.
        Log::spy();

        $this->actingAs($produtor)
            ->getJson('/api/courses/'.$alheio->id())
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    public function test_o_404_de_curso_inexistente_nao_e_reportado_como_erro(): void
    {
        $produtor = User::factory()->producer()->create();

        Log::spy();

        $this->actingAs($produtor)
            ->getJson('/api/courses/'.(string) Uuid::uuid7())
            ->assertStatus(404);

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    public function test_o_handler_declara_que_nao_reporta_excecao_de_dominio(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        // A verificacao direta da decisao, alem do comportamento: um caso do
        // catalogo que passasse a ser reportado voltaria a poluir o log sem que
        // nenhuma resposta mudasse.
        foreach (Failure::cases() as $falha) {
            $this->assertFalse(
                $handler->shouldReport(new DomainException($falha)),
                $falha->name,
            );
        }
    }

    // -----------------------------------------------------------------------
    // O par negativo: defeito continua sendo defeito
    // -----------------------------------------------------------------------

    public function test_excecao_inesperada_continua_sendo_reportavel(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertTrue($handler->shouldReport(new RuntimeException(self::MENSAGEM_INTERNA)));
    }

    public function test_excecao_inesperada_e_registrada_e_responde_500_sem_vazar(): void
    {
        Route::middleware('api')->get('/api/_erro-inesperado', function (): never {
            throw new RuntimeException(self::MENSAGEM_INTERNA);
        });

        // O espiao serve aqui a dois propositos: permite afirmar que o registro
        // aconteceu, e impede que a pilha real seja escrita na saida de erro do
        // processo — que e o canal deste ambiente. O silenciamento fica restrito
        // a este teste, que e o unico que provoca a excecao de proposito.
        Log::spy();

        $resposta = $this->getJson('/api/_erro-inesperado');

        $resposta->assertStatus(500);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'INTERNAL_ERROR');

        // Registrado para quem mantem...
        Log::shouldHaveReceived('error')->once();

        // ...e invisivel para quem chamou.
        $bruto = (string) $resposta->getContent();
        $this->assertStringNotContainsString(self::MENSAGEM_INTERNA, $bruto);
        $this->assertStringNotContainsString('RuntimeException', $bruto);
        $this->assertStringNotContainsString('/app/', $bruto);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * @return array{User, Course}
     */
    private function cursoDeOutroProdutor(): array
    {
        $produtor = User::factory()->producer()->create();
        $outro = User::factory()->producer()->create();
        $repositorio = $this->app->make(CourseRepository::class);

        $curso = Course::create(
            id: $repositorio->nextIdentity(),
            ownerId: (string) $outro->getKey(),
            title: 'Curso do outro produtor',
            description: 'Descricao.',
            createdAt: new DateTimeImmutable('2026-09-05T13:45:12+00:00'),
        );
        $repositorio->save($curso);

        return [$produtor, $curso];
    }
}
