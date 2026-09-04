<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aulas, ordenadas dentro do modulo.
 *
 * Nasce **sem** `current_video_attempt_id`. A coluna aponta para
 * `video_attempts`, que ainda nao existe nesta altura da ordem, e antecipa-la
 * aqui obrigaria a criar a chave estrangeira depois — ou, pior, a inverter a
 * ordem de criacao das duas tabelas. Ela chega numa migration propria, logo
 * apos `video_attempts`, e a reversao desfaz na ordem inversa.
 *
 * `published_at` anulavel em vez de um booleano: nulo e rascunho, sem
 * ambiguidade, e o instante da publicacao e informacao util que um booleano
 * descartaria (plan §7.2).
 *
 * UNIQUE e CHECK cumprem aqui o mesmo papel que em `modules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('module_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->string('title');
            $table->unsignedInteger('position');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->unique(['module_id', 'position']);
        });

        DB::statement('ALTER TABLE lessons ADD CONSTRAINT lessons_position_minimo CHECK (position >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
