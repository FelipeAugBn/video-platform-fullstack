<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modulos, ordenados dentro do curso.
 *
 * Duas garantias estruturais, e nenhuma delas e decorativa.
 *
 * A **UNIQUE `(course_id, position)`** e a ultima linha de defesa de RN-ORD-003.
 * A regra de ordem e calculada pelo caso de uso sob lock da linha do curso
 * (plan §8.1), mas duas inclusoes simultaneas ainda podem ler o mesmo
 * `MAX(position)` se o lock for esquecido em alguma evolucao futura — e e o
 * banco que recusa a segunda.
 *
 * O **CHECK `position >= 1`** fecha o unico buraco que `UNSIGNED` deixa: o
 * zero. As posicoes comecam em 1 (plan §7.2), e uma linha em zero produziria
 * uma ordem valida para o banco e errada para o produto.
 *
 * A UNIQUE tambem **e** o indice que ordena a leitura da estrutura: MySQL usa
 * indice unico como qualquer outro. Um segundo indice nas mesmas colunas seria
 * duplicata paga em toda escrita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('course_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->unique(['course_id', 'position']);
        });

        DB::statement('ALTER TABLE modules ADD CONSTRAINT modules_position_minimo CHECK (position >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
