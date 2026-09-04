<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache e locks em banco, no formato padrao do framework.
 *
 * `cache_locks` nao e acessorio aqui: e ela que sustenta o lock atomico da
 * conclusao de envio (plan §§8.2, 12.2). O driver de cache e `database` e nao
 * `file` justamente por isso — um lock em arquivo vive no sistema de arquivos
 * de um container e nao seria enxergado por `api`, `worker` e
 * `simulator-worker`, que sao processos distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
