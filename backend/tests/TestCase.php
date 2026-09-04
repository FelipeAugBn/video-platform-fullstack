<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabase;

/**
 * Base dos testes que inicializam a aplicacao.
 *
 * A escolha do schema de testes acontece aqui, e nao num bootstrap global da
 * suite. A diferenca importa: um bootstrap global obrigaria todo teste a ter
 * banco configurado, inclusive os unitarios de dominio, que sao PHP puro e
 * rodam sem framework e sem conexao — exatamente o retorno pratico da separacao
 * de camadas (plan §17.2). Amarrar os dois destruiria essa propriedade sem que
 * ninguem percebesse, porque a suite continuaria verde.
 *
 * Quem estende esta classe usa MySQL real. Quem estende diretamente o
 * `TestCase` do PHPUnit nao depende de banco, de variavel de ambiente nem de
 * framework.
 *
 * A chamada acontece antes de `parent::createApplication()`, que e o ultimo
 * instante util: e ali que o framework le a configuracao e abre a primeira
 * conexao. Uma correcao depois disso atuaria sobre um banco ja tocado.
 */
abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        TestDatabase::apply();

        return parent::createApplication();
    }
}
