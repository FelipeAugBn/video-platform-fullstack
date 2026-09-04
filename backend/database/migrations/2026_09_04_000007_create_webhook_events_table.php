<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de idempotencia dos callbacks de processamento.
 *
 * Nao e um agregado de negocio: existe para dar a cada `event_id` um desfecho
 * definitivo, e e a UNIQUE nessa coluna que sustenta a estrategia de **reservar
 * primeiro** descrita em plan §8.4. Verificar antes e inserir depois abriria uma
 * janela pela qual duas entregas simultaneas do mesmo evento passariam juntas.
 *
 * Tres colunas merecem explicacao, porque cada uma existe por um motivo que nao
 * e obvio olhando so o nome:
 *
 * **`received_video_id` nao tem chave estrangeira, e isso e deliberado.** Um
 * callback pode chegar com um UUID valido que nao corresponde a tentativa
 * alguma. Com uma chave estrangeira, a insercao falharia na constraint e esse
 * `event_id` ficaria eternamente sem desfecho gravado — e o emissor
 * reentregaria para sempre um evento que jamais seria aceito. Sem ela, o evento
 * e registrado como rejeicao permanente e a reentrega recebe a mesma resposta.
 *
 * **`video_attempt_id` e a referencia que a aplicacao conseguiu resolver.** Nula
 * quando nao ha tentativa correspondente. Sao colunas diferentes de proposito:
 * a primeira guarda o que chegou, a segunda o que foi resolvido.
 *
 * **`outcome` admite nulo apenas dentro da transacao de reserva.** Nenhuma linha
 * confirmada fica sem desfecho: os dois caminhos conclusivos gravam antes do
 * commit, e uma falha transitoria faz rollback — a reserva desaparece com ela, e
 * a reentrega posterior e avaliada do zero (RN-VID-007).
 *
 * `received_status` fica gravado como evidencia do que chegou, nunca como
 * criterio de decisao: o conteudo da carga nao e comparado entre entregas
 * (RN-IDM-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('id', 36)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('event_id', 128)->unique();
            $table->char('received_video_id', 36)->charset('ascii')->collation('ascii_bin');
            $table->char('video_attempt_id', 36)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->enum('outcome', ['accepted', 'rejected_permanent'])->nullable();
            $table->string('received_status', 32);
            $table->timestamp('processed_at');

            $table->foreign('video_attempt_id')->references('id')->on('video_attempts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
