<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Shared\Interfaces\Http\Resource\PaginatedResponse;
use App\Shared\Interfaces\Http\Resource\PerPage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\Http\ContractCollection;
use Tests\Support\Http\ContractResource;
use Tests\Support\TestDatabase;
use Tests\TestCase;
use ValueError;

/**
 * O contrato de resposta, exercido antes de existir o primeiro endpoint.
 *
 * A ordem e deliberada: envelope, paginacao e formato de erro sao decisoes do
 * projeto inteiro (plan §10), e uma rota que nasca fora desse formato so seria
 * descoberta quando ja houvesse outras copiadas dela.
 *
 * As rotas que provocam cada comportamento sao registradas **dentro de cada
 * teste**, e nao em `setUp`. Nao e estilo: e o que permite a um dos testes
 * afirmar que elas nao existem na aplicacao em execucao. Registrar em `setUp`
 * as colocaria tambem no teste que precisa observar a ausencia delas.
 */
final class HttpContractTest extends TestCase
{
    /**
     * Texto que so existe dentro da excecao inesperada. Se aparecer em qualquer
     * ponto da resposta, houve vazamento de detalhe interno.
     */
    private const MENSAGEM_INTERNA = 'detalhe-interno-que-nunca-pode-vazar-9f3a';

    private const PREFIXO = '_contrato';

    // -----------------------------------------------------------------------
    // Sucesso
    // -----------------------------------------------------------------------

    public function test_recurso_individual_vem_sob_data(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/recurso');

        $resposta->assertOk();
        $resposta->assertExactJson([
            'data' => ['id' => 'curso-1', 'title' => 'Fundamentos de PHP'],
        ]);
    }

    public function test_colecao_vem_sob_data_e_sem_paginacao(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/colecao');

        $resposta->assertOk();
        $resposta->assertExactJson([
            'data' => [
                ['id' => 'curso-1', 'title' => 'Fundamentos de PHP'],
                ['id' => 'curso-2', 'title' => 'Fundamentos de Vue'],
            ],
        ]);
    }

    public function test_colecao_paginada_traz_meta_e_links_no_formato_aprovado(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/pagina?page=2');

        $resposta->assertOk();
        $corpo = $resposta->json();

        // A estrutura inteira e conferida, e nao apenas a presenca das chaves:
        // um campo a mais tambem quebra o contrato, porque o cliente passaria a
        // receber algo que ninguem prometeu manter.
        $this->assertSame(['data', 'meta', 'links'], array_keys($corpo));
        $this->assertSame(
            ['current_page', 'per_page', 'total', 'last_page'],
            array_keys($corpo['meta']),
        );
        $this->assertSame(['first', 'prev', 'next', 'last'], array_keys($corpo['links']));

        $this->assertSame(2, $corpo['meta']['current_page']);
        $this->assertSame(15, $corpo['meta']['per_page']);
        $this->assertSame(120, $corpo['meta']['total']);
        $this->assertSame(8, $corpo['meta']['last_page']);

        $this->assertCount(15, $corpo['data']);
        $this->assertSame(['id' => '16', 'title' => 'Item 16'], $corpo['data'][0]);

        $this->assertNotNull($corpo['links']['prev']);
        $this->assertNotNull($corpo['links']['next']);
    }

    public function test_links_de_borda_sao_nulos_e_nao_ausentes(): void
    {
        $this->registrarRotas();

        $primeira = $this->getJson('/api/'.self::PREFIXO.'/pagina?page=1')->json('links');
        $ultima = $this->getJson('/api/'.self::PREFIXO.'/pagina?page=8')->json('links');

        $this->assertArrayHasKey('prev', $primeira);
        $this->assertNull($primeira['prev']);
        $this->assertArrayHasKey('next', $ultima);
        $this->assertNull($ultima['next']);
    }

    // -----------------------------------------------------------------------
    // Tamanho de pagina
    // -----------------------------------------------------------------------

    public function test_per_page_ausente_usa_o_padrao_de_quinze(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/pagina');

        $resposta->assertJsonPath('meta.per_page', 15);
        $resposta->assertJsonCount(15, 'data');
    }

    public function test_per_page_valido_e_preservado(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/pagina?per_page=30');

        $resposta->assertJsonPath('meta.per_page', 30);
        $resposta->assertJsonCount(30, 'data');
    }

    public function test_per_page_acima_do_maximo_e_limitado_e_nao_recusado(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/pagina?per_page=500');

        // Limitado, e nao rejeitado: a intencao de quem pediu e atendivel, e o
        // teto existe para proteger a resposta, nao para punir o pedido.
        $resposta->assertOk();
        $resposta->assertJsonPath('meta.per_page', PerPage::MAXIMO);
        $resposta->assertJsonCount(PerPage::MAXIMO, 'data');
    }

    public function test_o_limite_do_tamanho_de_pagina_vale_no_componente(): void
    {
        $this->assertSame(15, PerPage::of(null));
        $this->assertSame(1, PerPage::of(1));
        $this->assertSame(50, PerPage::of(50));
        $this->assertSame(50, PerPage::of(51));
        $this->assertSame(50, PerPage::of(10_000));
    }

    // -----------------------------------------------------------------------
    // Erros
    // -----------------------------------------------------------------------

    public function test_erro_de_dominio_responde_com_o_caso_declarado(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/erro-de-dominio');

        $resposta->assertStatus(409);
        $resposta->assertExactJson([
            'type' => 'https://api.example.test/problems/conflict',
            'title' => Failure::CONFLICT->title(),
            'status' => 409,
            'detail' => Failure::CONFLICT->detail(),
            'code' => 'CONFLICT',
        ]);
    }

    public function test_validacao_responde_422_com_errors_por_campo(): void
    {
        $this->registrarRotas();

        $resposta = $this->postJson('/api/'.self::PREFIXO.'/validacao', []);

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $resposta->assertJsonPath('type', 'https://api.example.test/problems/validation-failed');
        $resposta->assertJsonPath('status', 422);
        $resposta->assertJsonStructure(['type', 'title', 'status', 'detail', 'code', 'errors' => ['title']]);
        $this->assertNotEmpty($resposta->json('errors.title'));
    }

    public function test_resposta_de_erro_que_nao_e_validacao_nao_traz_errors(): void
    {
        $this->registrarRotas();
        $this->capturarLog();

        $caminhos = [
            'erro-de-dominio', 'nao-autenticado', 'proibido', 'autorizacao-negada',
            'modelo-ausente', 'indisponivel', 'erro-inesperado',
        ];

        foreach ($caminhos as $caminho) {
            $corpo = $this->getJson('/api/'.self::PREFIXO.'/'.$caminho)->json();

            $this->assertArrayNotHasKey('errors', $corpo, "rota {$caminho}");
        }
    }

    public function test_cada_situacao_recebe_o_status_e_o_codigo_previstos(): void
    {
        $this->registrarRotas();
        $this->capturarLog();

        $esperado = [
            'nao-autenticado' => [401, 'UNAUTHENTICATED'],
            'proibido' => [403, 'FORBIDDEN'],
            'autorizacao-negada' => [403, 'FORBIDDEN'],
            'nao-existe-esta-rota' => [404, 'NOT_FOUND'],
            'modelo-ausente' => [404, 'NOT_FOUND'],
            'erro-de-dominio' => [409, 'CONFLICT'],
            'indisponivel' => [503, 'SERVICE_UNAVAILABLE'],
            'erro-inesperado' => [500, 'INTERNAL_ERROR'],
        ];

        foreach ($esperado as $caminho => [$status, $codigo]) {
            $resposta = $this->getJson('/api/'.self::PREFIXO.'/'.$caminho);

            $resposta->assertStatus($status);
            $this->assertSame($codigo, $resposta->json('code'), "rota {$caminho}");
            $this->assertSame($status, $resposta->json('status'), "rota {$caminho}");
        }
    }

    public function test_todo_erro_da_api_usa_o_content_type_de_problema(): void
    {
        $this->registrarRotas();
        $this->capturarLog();

        $caminhos = [
            'erro-de-dominio', 'nao-autenticado', 'proibido', 'autorizacao-negada',
            'modelo-ausente', 'indisponivel', 'erro-inesperado', 'nao-existe-esta-rota',
        ];

        foreach ($caminhos as $caminho) {
            $resposta = $this->getJson('/api/'.self::PREFIXO.'/'.$caminho);

            $this->assertSame(
                'application/problem+json',
                $resposta->headers->get('Content-Type'),
                "rota {$caminho}",
            );
        }

        $this->postJson('/api/'.self::PREFIXO.'/validacao', [])
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_verbo_incorreto_responde_405_com_o_cabecalho_allow(): void
    {
        $this->registrarRotas();

        $resposta = $this->postJson('/api/'.self::PREFIXO.'/so-get');

        $resposta->assertStatus(405);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertExactJson([
            'type' => 'https://api.example.test/problems/method-not-allowed',
            'title' => Failure::METHOD_NOT_ALLOWED->title(),
            'status' => 405,
            'detail' => Failure::METHOD_NOT_ALLOWED->detail(),
            'code' => 'METHOD_NOT_ALLOWED',
        ]);

        // O cabecalho sobrevive ao tratamento: sem ele a resposta diria que o
        // metodo esta errado sem dizer qual seria o certo.
        $allow = $resposta->headers->get('Allow');
        $this->assertNotNull($allow);
        $this->assertStringContainsString('GET', $allow);
    }

    public function test_rota_inexistente_e_verbo_incorreto_sao_respostas_diferentes(): void
    {
        $this->registrarRotas();

        // O endereco nao existe.
        $this->getJson('/api/'.self::PREFIXO.'/nada-aqui')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');

        // O endereco existe; o metodo e que nao serve.
        $this->postJson('/api/'.self::PREFIXO.'/so-get')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_falha_de_autorizacao_do_framework_responde_403(): void
    {
        $this->registrarRotas();

        $this->getJson('/api/'.self::PREFIXO.'/autorizacao-negada')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('status', 403);
    }

    public function test_modelo_nao_encontrado_responde_404_sem_citar_o_modelo(): void
    {
        $this->registrarRotas();

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/modelo-ausente');

        $resposta->assertStatus(404);
        $resposta->assertHeader('Content-Type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'NOT_FOUND');

        // A excecao do framework carrega o nome da classe e o identificador
        // procurado. Nenhum dos dois pode chegar a resposta.
        $bruto = $resposta->getContent();
        $this->assertIsString($bruto);
        $this->assertStringNotContainsString('Models', $bruto);
        $this->assertStringNotContainsString('inexistente', $bruto);
    }

    // -----------------------------------------------------------------------
    // Campos condicionais
    // -----------------------------------------------------------------------

    public function test_campo_condicional_ausente_nao_aparece_no_recurso_individual(): void
    {
        $this->registrarRotas();

        $this->getJson('/api/'.self::PREFIXO.'/recurso')
            ->assertExactJson(['data' => ['id' => 'curso-1', 'title' => 'Fundamentos de PHP']]);
    }

    public function test_campo_condicional_presente_aparece_no_recurso_individual(): void
    {
        $this->registrarRotas();

        // O par negativo desta afirmacao: sem ele, um campo que nunca aparece
        // passaria no teste de ausencia sem provar que a condicao e avaliada.
        $this->getJson('/api/'.self::PREFIXO.'/recurso-publicado')
            ->assertExactJson(['data' => [
                'id' => 'curso-2',
                'title' => 'Fundamentos de Vue',
                'published_at' => '2026-09-04T12:00:00+00:00',
            ]]);
    }

    public function test_campo_condicional_ausente_tambem_some_dentro_da_pagina(): void
    {
        $this->registrarRotas();

        $corpo = $this->getJson('/api/'.self::PREFIXO.'/pagina?per_page=3')->json();

        foreach ($corpo['data'] as $indice => $item) {
            $this->assertSame(['id', 'title'], array_keys($item), "item {$indice}");
            $this->assertArrayNotHasKey('published_at', $item);
            // Nem o marcador de ausencia entregue como objeto vazio, que e o que
            // apareceria se o recurso fosse convertido sem passar pela resolucao.
            $this->assertNotSame([], $item['title']);
        }

        // E a lista continua sem envelope duplicado: os itens sao objetos
        // diretos, e nao objetos com uma chave `data` dentro.
        $this->assertSame(['data', 'meta', 'links'], array_keys($corpo));
        $this->assertArrayNotHasKey('data', $corpo['data'][0]);
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($corpo['meta']));
        $this->assertSame(['first', 'prev', 'next', 'last'], array_keys($corpo['links']));
    }

    // -----------------------------------------------------------------------
    // Nada de detalhe interno na resposta
    // -----------------------------------------------------------------------

    public function test_erro_inesperado_responde_generico_mesmo_com_depuracao_ligada(): void
    {
        $this->registrarRotas();
        $this->capturarLog();
        config(['app.debug' => true]);

        $resposta = $this->getJson('/api/'.self::PREFIXO.'/erro-inesperado');

        $resposta->assertStatus(500);
        $resposta->assertExactJson([
            'type' => 'https://api.example.test/problems/internal-error',
            'title' => Failure::INTERNAL_ERROR->title(),
            'status' => 500,
            'detail' => Failure::INTERNAL_ERROR->detail(),
            'code' => 'INTERNAL_ERROR',
        ]);
    }

    public function test_resposta_de_erro_nao_carrega_rastro_de_execucao(): void
    {
        $this->registrarRotas();
        $this->capturarLog();
        config(['app.debug' => true]);

        $bruto = $this->getJson('/api/'.self::PREFIXO.'/erro-inesperado')->getContent();

        $this->assertIsString($bruto);
        $this->assertStringNotContainsString(self::MENSAGEM_INTERNA, $bruto);
        $this->assertStringNotContainsString(RuntimeException::class, $bruto);
        $this->assertStringNotContainsString('HttpContractTest.php', $bruto);
        $this->assertStringNotContainsString('/app/', $bruto);

        foreach (['message', 'exception', 'file', 'line', 'trace'] as $chave) {
            $this->assertArrayNotHasKey($chave, json_decode($bruto, true));
        }
    }

    // -----------------------------------------------------------------------
    // O catalogo e fechado
    // -----------------------------------------------------------------------

    public function test_catalogo_recusa_codigo_que_nao_declarou(): void
    {
        $this->assertNull(Failure::tryFrom('CODIGO_QUE_NAO_EXISTE'));

        $this->expectException(ValueError::class);
        Failure::from('CODIGO_QUE_NAO_EXISTE');
    }

    public function test_falha_de_dominio_nao_aceita_mensagem_livre(): void
    {
        $construtor = new ReflectionMethod(DomainException::class, '__construct');
        $parametros = $construtor->getParameters();

        // Um unico parametro, e do tipo da enumeracao: nao existe assinatura por
        // onde texto vindo de um controller, de um request ou da resposta de um
        // servico externo entre numa excecao de dominio.
        $this->assertCount(1, $parametros);
        $this->assertSame(Failure::class, (string) $parametros[0]->getType());
    }

    public function test_toda_falha_declarada_tem_titulo_mensagem_e_status(): void
    {
        foreach (Failure::cases() as $falha) {
            $this->assertNotSame('', $falha->title(), $falha->name);
            $this->assertNotSame('', $falha->detail(), $falha->name);
            $this->assertGreaterThanOrEqual(400, \App\Shared\Interfaces\Http\Problem\ProblemStatus::of($falha));
        }
    }

    // -----------------------------------------------------------------------
    // A aplicacao em execucao nao conhece estas rotas
    // -----------------------------------------------------------------------

    public function test_nenhuma_rota_de_contrato_existe_fora_da_suite(): void
    {
        // Este teste nao chama `registrarRotas()`. A aplicacao aqui e a mesma
        // que sobe em execucao normal, e a lista abaixo e fechada de proposito:
        // uma rota nova que apareca sem passar por uma tarefa faz este teste
        // falhar, que e o comportamento desejado.
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($rota) => (string) $rota->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/'))
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                'api/auth/login', 'api/auth/me', 'api/auth/logout',
                'api/courses', 'api/courses/{course}',
                'api/courses/{course}/modules', 'api/courses/{course}/structure',
                'api/modules/{module}/lessons', 'api/lessons/{lesson}',
                'api/lessons/{lesson}/publish',
                'api/lessons/{lesson}/video', 'api/lessons/{lesson}/video/uploads',
                'api/video-uploads/{attempt}/complete',
                'api/video-uploads/{attempt}/parts/{part}/url',
                'api/webhooks/video-processing',
            ],
            $uris,
            'Sob o prefixo da API existem autenticacao, catalogo do produtor, envio de video, '
                .'publicacao e o callback de processamento nesta etapa.',
        );

        // As rotas que a propria suite registra para exercitar o contrato
        // continuam sem existir na aplicacao.
        $this->getJson('/api/'.self::PREFIXO.'/recurso')->assertNotFound();
    }

    public function test_endpoint_de_saude_continua_respondendo(): void
    {
        $this->get('/up')->assertOk();
    }

    // -----------------------------------------------------------------------
    // A suite fala com o schema de testes, nunca com o da aplicacao
    // -----------------------------------------------------------------------

    public function test_a_suite_usa_o_schema_de_testes_no_mysql(): void
    {
        $esperado = TestDatabase::selected();

        $this->assertNotNull($esperado);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame($esperado, config('database.connections.mysql.database'));

        // A pergunta e feita ao proprio banco: configuracao pode estar certa e a
        // conexao efetiva ter sido aberta em outro lugar.
        $this->assertSame($esperado, DB::selectOne('select database() as banco')->banco);
    }

    public function test_o_banco_da_aplicacao_nao_e_o_banco_da_suite(): void
    {
        $principal = TestDatabase::primary();

        $this->assertNotNull($principal);
        $this->assertNotSame($principal, TestDatabase::selected());
        $this->assertNotSame($principal, DB::selectOne('select database() as banco')->banco);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Captura o log em vez de escreve-lo, e apenas nos testes que provocam de
     * proposito uma excecao inesperada.
     *
     * O canal deste ambiente e a saida de erro do processo: sem isto, a pilha da
     * excecao deliberada apareceria no meio do resultado da suite parecendo
     * defeito. O espiao evita a escrita **sem** desligar o relato — o
     * comportamento continua o de producao, e nenhum outro teste do arquivo tem
     * o log alterado.
     */
    private function capturarLog(): void
    {
        Log::spy();
    }

    /**
     * Registra as rotas que provocam cada comportamento do contrato.
     *
     * Vivem apenas aqui: nao ha arquivo de rotas correspondente na aplicacao, e
     * um teste desta classe confere isso.
     */
    private function registrarRotas(): void
    {
        Route::middleware('api')->prefix('api/'.self::PREFIXO)->group(function (): void {
            Route::get('recurso', fn () => new ContractResource([
                'id' => 'curso-1',
                'title' => 'Fundamentos de PHP',
            ]));

            Route::get('recurso-publicado', fn () => new ContractResource([
                'id' => 'curso-2',
                'title' => 'Fundamentos de Vue',
                'published_at' => '2026-09-04T12:00:00+00:00',
            ]));

            // Existe apenas com `GET`. Chamada com outro metodo, produz a
            // excecao real que o roteador lanca, e nao um dublê.
            Route::get('so-get', fn () => response()->noContent());

            Route::get('colecao', fn () => new ContractCollection([
                ['id' => 'curso-1', 'title' => 'Fundamentos de PHP'],
                ['id' => 'curso-2', 'title' => 'Fundamentos de Vue'],
            ]));

            Route::get('pagina', function (Request $request) {
                $solicitado = $request->has('per_page') ? $request->integer('per_page') : null;
                $porPagina = PerPage::of($solicitado);
                $paginaAtual = max(1, $request->integer('page', 1));

                $todos = array_map(
                    fn (int $n): array => ['id' => (string) $n, 'title' => "Item {$n}"],
                    range(1, 120),
                );

                $pagina = new LengthAwarePaginator(
                    array_slice($todos, ($paginaAtual - 1) * $porPagina, $porPagina),
                    count($todos),
                    $porPagina,
                    $paginaAtual,
                    ['path' => $request->url()],
                );

                return PaginatedResponse::of($pagina, ContractResource::class);
            });

            Route::get('erro-de-dominio', function (): never {
                throw new DomainException(Failure::CONFLICT);
            });

            Route::get('indisponivel', function (): never {
                throw new DomainException(Failure::SERVICE_UNAVAILABLE);
            });

            Route::get('nao-autenticado', function (): never {
                throw new AuthenticationException;
            });

            Route::get('proibido', function (): never {
                throw new AccessDeniedHttpException;
            });

            // As duas excecoes que os casos de uso realmente vao lancar. O
            // framework as normaliza antes do tratamento — autorizacao vira
            // `403`, modelo ausente vira `404` —, e e esse caminho completo que
            // precisa ser exercido, nao o atalho de lancar a excecao HTTP final.
            Route::get('autorizacao-negada', function (): never {
                throw new AuthorizationException;
            });

            Route::get('modelo-ausente', function (): never {
                throw (new ModelNotFoundException)->setModel('App\\Models\\User', ['inexistente']);
            });

            Route::get('erro-inesperado', function (): never {
                throw new RuntimeException(self::MENSAGEM_INTERNA);
            });

            Route::post('validacao', function (Request $request) {
                $request->validate(['title' => ['required', 'string']]);

                return response()->noContent();
            });
        });
    }
}
