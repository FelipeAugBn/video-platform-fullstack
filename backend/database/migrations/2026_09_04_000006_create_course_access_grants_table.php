<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concessoes de acesso: quem pode consumir qual curso.
 *
 * O conceito e explicito no dominio de proposito (spec §5). Sem ele,
 * "consumidor autorizado" viraria uma condicao implicita espalhada por
 * consultas, e a autorizacao deixaria de ser testavel isoladamente.
 *
 * Nesta entrega as concessoes sao criadas **apenas por seed**: nao ha operacao
 * de conceder, revogar ou listar. E por isso que a tabela guarda `granted_at` e
 * nao `timestamps()` — nao existe atualizacao a registrar, o vinculo e imutavel.
 *
 * A UNIQUE `(course_id, consumer_id)` impede conceder o mesmo curso duas vezes
 * ao mesmo consumidor, o que tornaria a contagem de acessos ambigua.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_access_grants', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('course_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->char('consumer_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('granted_at');

            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('consumer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['course_id', 'consumer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_access_grants');
    }
};
