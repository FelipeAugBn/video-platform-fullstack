<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * A barreira que impede a suite de rodar no banco da aplicacao.
 *
 * Ela e o unico ponto entre uma variavel mal configurada e a perda dos dados
 * preparados para a avaliacao, e por isso e exercida diretamente, sem framework
 * e sem conexao: o que esta sob teste e a decisao, nao o efeito colateral dela.
 *
 * Este arquivo estende o `TestCase` do PHPUnit, e nao o do projeto. Nao e
 * detalhe: e a prova de que a regra pode ser exercida sem banco, sem variavel
 * de ambiente e sem framework. A afirmacao de que a troca *aconteceu* mora nos
 * testes de feature, que sao os que inicializam a aplicacao.
 */
final class TestDatabaseTest extends TestCase
{
    public function test_usa_o_schema_declarado_para_testes(): void
    {
        $escolhido = TestDatabase::select([
            'DB_DATABASE' => 'aplicacao',
            'DB_TEST_DATABASE' => 'aplicacao_testes',
        ]);

        $this->assertSame('aplicacao_testes', $escolhido);
    }

    public function test_recusa_quando_a_variavel_esta_ausente(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB_TEST_DATABASE ausente ou vazia/');

        TestDatabase::select(['DB_DATABASE' => 'aplicacao']);
    }

    public function test_recusa_quando_a_variavel_esta_vazia(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB_TEST_DATABASE ausente ou vazia/');

        TestDatabase::select([
            'DB_DATABASE' => 'aplicacao',
            'DB_TEST_DATABASE' => '   ',
        ]);
    }

    public function test_recusa_quando_aponta_para_o_banco_da_aplicacao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mesmo schema/');

        TestDatabase::select([
            'DB_DATABASE' => 'aplicacao',
            'DB_TEST_DATABASE' => 'aplicacao',
        ]);
    }
}
