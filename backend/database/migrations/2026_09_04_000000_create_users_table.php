<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produtores e consumidores.
 *
 * Escrita do zero, e nao herdada do esqueleto: a versao demonstrativa do
 * framework traz chave auto-incremento, verificacao de e-mail e token de
 * "lembrar de mim", nenhum dos tres previsto pelo plano. Cadastro publico e
 * recuperacao de senha estao explicitamente fora de escopo (spec §16), e uma
 * coluna sem regra que a use e esquema morto.
 *
 * `role` como enumeracao de dois valores implementa a decisao da spec §4: um
 * usuario tem exatamente um perfil nesta entrega. Deixar isso ao banco significa
 * que um terceiro valor nao entra nem por escrita direta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['producer', 'consumer']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
