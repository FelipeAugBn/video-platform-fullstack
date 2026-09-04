<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila e falhas de job, no formato padrao do framework.
 *
 * A fila vive no MySQL por decisao do projeto (plan §13.1): o banco ja e
 * obrigatorio, e o volume deste problema nao justifica introduzir um broker.
 * Como `jobs` divide a conexao com as tabelas de dominio, a linha do job
 * participa da mesma transacao que muda o estado — e e isso que torna o
 * enfileiramento atomico descrito em plan §7.4 possivel sem outbox.
 *
 * `job_batches` **nao** e criada. Batching nao e usado por nenhuma decisao do
 * plano, e uma tabela sem consumidor e esquema morto que alguem vai tentar
 * interpretar depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
