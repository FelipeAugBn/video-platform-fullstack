<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Infrastructure\Transaction\DatabaseTransactionManager;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * A porta de transacao, contra o MySQL de verdade.
 *
 * Contra o banco real, e nao contra um dublê, porque o que precisa ser provado
 * aqui e justamente o que um dublê nao teria: que o rollback de fato desfaz a
 * escrita. Um substituto em memoria que apenas executasse a funcao passaria no
 * teste de retorno e falharia silenciosamente no que importa (plan §17.2).
 */
final class TransactionManagerTest extends TestCase
{
    use RefreshDatabase;

    private TransactionManager $transacoes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transacoes = $this->app->make(TransactionManager::class);
    }

    // -----------------------------------------------------------------------
    // O resultado atravessa
    // -----------------------------------------------------------------------

    public function test_o_retorno_da_operacao_e_preservado(): void
    {
        // E o motivo de a porta nao devolver `void`: o caso de uso precisa do que
        // foi criado la dentro — o modulo com a posicao ja calculada. Sem retorno,
        // cada chamador contrabandearia o resultado por uma variavel capturada
        // por referencia.
        $this->assertSame('resultado', $this->transacoes->transactional(fn (): string => 'resultado'));
    }

    public function test_o_retorno_preserva_o_tipo_original(): void
    {
        $objeto = new \stdClass;

        $this->assertSame($objeto, $this->transacoes->transactional(fn (): object => $objeto));
        $this->assertSame(42, $this->transacoes->transactional(fn (): int => 42));
        $this->assertNull($this->transacoes->transactional(fn () => null));
        $this->assertSame([], $this->transacoes->transactional(fn (): array => []));
    }

    public function test_a_operacao_roda_dentro_de_uma_transacao_aberta(): void
    {
        // A comparacao e relativa, e nao contra zero: a propria suite mantem uma
        // transacao aberta para desfazer o que cada teste escreve. O que precisa
        // valer e que a porta abre um nivel a mais e o fecha ao terminar.
        $nivelFora = DB::transactionLevel();
        $nivelDentro = $this->transacoes->transactional(fn (): int => DB::transactionLevel());

        $this->assertSame($nivelFora + 1, $nivelDentro);
        $this->assertSame($nivelFora, DB::transactionLevel(), 'a transacao precisa fechar ao terminar');
    }

    // -----------------------------------------------------------------------
    // A excecao desfaz
    // -----------------------------------------------------------------------

    public function test_excecao_desfaz_tudo_o_que_foi_gravado(): void
    {
        $this->assertDatabaseCount('courses', 0);

        try {
            $this->transacoes->transactional(function (): void {
                $this->gravarUmCurso();

                // A escrita ja aconteceu neste ponto — dentro da transacao.
                $this->assertSame(1, DB::table('courses')->count());

                throw new RuntimeException('falha no meio da operacao');
            });

            $this->fail('a excecao deveria ter atravessado a porta');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertDatabaseCount('courses', 0);
    }

    public function test_a_excecao_original_continua_subindo(): void
    {
        // O adapter nao captura e nao traduz. Se traduzisse, o tratamento
        // centralizado da API perderia o caso do catalogo escolhido pelo dominio
        // e responderia erro generico no lugar do previsto.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('falha no meio da operacao');

        $this->transacoes->transactional(function (): void {
            throw new RuntimeException('falha no meio da operacao');
        });
    }

    public function test_sem_excecao_a_escrita_e_confirmada(): void
    {
        // O par positivo do rollback: sem ele, um adapter que desfizesse sempre
        // passaria em todos os testes acima.
        $this->transacoes->transactional(fn () => $this->gravarUmCurso());

        $this->assertDatabaseCount('courses', 1);
    }

    // -----------------------------------------------------------------------
    // A porta nao conhece o framework
    // -----------------------------------------------------------------------

    public function test_a_assinatura_da_porta_nao_menciona_o_framework(): void
    {
        // A varredura completa de `Domain` e `Application` esta em
        // `HexagonalBoundariesTest`. Aqui a afirmacao e sobre a assinatura desta
        // porta especifica: um metodo pode nao importar nada e ainda assim
        // declarar `Closure` do framework ou um tipo de conexao no parametro.
        $metodo = new ReflectionMethod(TransactionManager::class, 'transactional');

        $this->assertSame('callable', (string) $metodo->getParameters()[0]->getType());
        $this->assertSame('mixed', (string) $metodo->getReturnType());
    }

    public function test_o_adapter_e_o_unico_lado_que_conhece_o_banco(): void
    {
        $porta = (string) file_get_contents(
            (new \ReflectionClass(TransactionManager::class))->getFileName(),
        );
        $adapter = (string) file_get_contents(
            (new \ReflectionClass(DatabaseTransactionManager::class))->getFileName(),
        );

        $this->assertStringNotContainsString('use Illuminate', $porta);
        $this->assertStringContainsString('use Illuminate\Support\Facades\DB;', $adapter);
    }

    // -----------------------------------------------------------------------
    // Nada disto depende de Redis
    // -----------------------------------------------------------------------

    public function test_a_transacao_roda_sobre_a_conexao_mysql_da_aplicacao(): void
    {
        // A garantia da §7.4 depende disso: fila e dominio compartilham a mesma
        // conexao MySQL, e e por isso que o enfileiramento pode ser atomico com a
        // mudanca de estado. Uma conexao diferente aqui invalidaria a decisao.
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_nenhuma_conexao_de_apoio_usa_redis(): void
    {
        // O plano recusa Redis de proposito: o MySQL ja e obrigatorio, e o volume
        // do desafio nao justifica um componente a mais (plan §§2, 13.1).
        foreach (['database.default', 'cache.default', 'session.driver', 'queue.default'] as $chave) {
            $this->assertNotSame('redis', config($chave), $chave);
        }

        $this->assertArrayNotHasKey('redis', array_flip(array_keys(config('database.connections'))));
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function gravarUmCurso(): Course
    {
        $repositorio = $this->app->make(CourseRepository::class);
        $dono = \App\Models\User::factory()->producer()->create();

        $curso = Course::create(
            id: $repositorio->nextIdentity(),
            ownerId: (string) $dono->getKey(),
            title: 'Curso gravado dentro da transacao',
            description: 'Descricao.',
            createdAt: new DateTimeImmutable('2026-09-05T13:45:12+00:00'),
        );

        $repositorio->save($curso);

        return $curso;
    }
}
