<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tentativas de envio de video, com o ciclo de vida inteiro.
 *
 * Cada envio cria uma **linha nova**; a aula aponta para a atual. Substituir
 * nao e sobrescrever, e por isso o historico de tentativas fica preservado sem
 * custo adicional — e o que torna RF-UPL-005 e RN-VID-002 naturais em vez de
 * exigirem uma coluna de versao.
 *
 * O enum de `state` repete exatamente os seis casos de `VideoState` (T017). A
 * regra de quais transicoes sao permitidas vive no dominio, nao aqui: o banco
 * garante que o valor gravado e um dos seis, nao que o caminho ate ele foi
 * valido.
 *
 * **Campos declarados e campos verificados sao colunas diferentes de proposito.**
 * `declared_*` e o que o cliente afirmou na abertura do envio; `verified_*` e o
 * que o `HeadObject` encontrou no objeto real (plan §12.3). Guardar os dois no
 * mesmo lugar apagaria justamente a comparacao que a verificacao existe para
 * fazer.
 *
 * `storage_key` e unica e derivada do identificador da tentativa — nunca do nome
 * do arquivo enviado, que e entrada nao confiavel (plan §11.3).
 *
 * `failure_message` guarda mensagem **ja segura para exibicao**, vinda do
 * catalogo fechado de falhas. Nunca retorno de driver, resposta de servico
 * externo ou texto livre (RN-AUT-005, RF-WHK-011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_attempts', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('lesson_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->enum('state', ['pending', 'uploading', 'uploaded', 'processing', 'ready', 'failed']);

            $table->string('declared_filename');
            $table->string('declared_content_type', 127);
            $table->unsignedBigInteger('declared_size');

            $table->string('storage_key', 512)->unique();
            $table->string('multipart_upload_id')->nullable();

            $table->unsignedBigInteger('verified_size')->nullable();
            $table->string('verified_content_type', 127)->nullable();

            $table->string('playback_reference')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 512)->nullable();

            $table->timestamps();

            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            $table->index(['lesson_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_attempts');
    }
};
