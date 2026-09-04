<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Ponto de entrada do `db:seed`, invocado pelo `setup` a cada subida.
 *
 * Delega ao seeder do cenario de avaliacao em vez de escrever aqui: o conteudo
 * dos dados preparados e uma decisao de produto, e mante-lo num arquivo com nome
 * proprio permite que um teste o execute isoladamente.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(EvaluationSeeder::class);
    }
}
