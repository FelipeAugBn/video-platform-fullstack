<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O ponteiro da aula para sua tentativa de video atual.
 *
 * Existe como migration separada porque as duas tabelas se referenciam em
 * sentidos opostos: `video_attempts.lesson_id` aponta para `lessons`, e esta
 * coluna aponta de volta. Uma das duas pontas precisa nascer depois, e escolher
 * esta mantem `lessons` criada antes de `video_attempts`, na ordem natural do
 * dominio.
 *
 * **SET NULL, e nao CASCADE:** perder a tentativa nao pode levar a aula junto.
 * A aula continua existindo e volta a nao ter video atual, que e exatamente o
 * estado em que ela nasce (RF-AUL-004).
 *
 * A reversao remove **primeiro a chave estrangeira e depois a coluna** — nesta
 * ordem. Deixar a chave para tras impediria a migration anterior de derrubar
 * `video_attempts`, e o rollback pararia no meio com erro de integridade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->char('current_video_attempt_id', 36)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->nullable()
                ->after('position');

            $table->foreign('current_video_attempt_id')
                ->references('id')
                ->on('video_attempts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['current_video_attempt_id']);
            $table->dropColumn('current_video_attempt_id');
        });
    }
};
