<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cursos, sempre de um dono.
 *
 * A chave estrangeira de `owner_id` usa **RESTRICT**, nao CASCADE: apagar um
 * produtor nao pode levar junto, em silencio, o catalogo dele e tudo que pende
 * dali. Nao existe operacao de exclusao de usuario nesta entrega, e a restricao
 * garante que, se um dia existir, ela precise ser pensada em vez de acontecer.
 *
 * `state` reflete RN-CUR-001 a 003: o curso nasce `draft` e passa a `available`
 * quando sua primeira aula e publicada. Nao ha operacao separada de publicacao
 * de curso, e por isso nao ha terceiro valor.
 *
 * O indice `(owner_id, created_at)` cobre a unica leitura de lista que existe
 * para o produtor: os proprios cursos, do mais recente ao mais antigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('owner_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->string('title');
            $table->text('description');
            $table->enum('state', ['draft', 'available'])->default('draft');
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
