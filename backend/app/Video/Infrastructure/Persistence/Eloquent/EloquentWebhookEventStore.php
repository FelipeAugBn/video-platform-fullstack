<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Persistence\Eloquent;

use App\Video\Application\Port\Exception\TransientStoreFailure;
use App\Video\Application\Port\WebhookEventStore;
use App\Video\Domain\WebhookOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O registro de idempotencia, sobre a tabela `webhook_events` (plan §8.4).
 *
 * Escreve pelo query builder, e nao por um model: a tabela nao e um agregado de
 * negocio — existe para dar a cada `event_id` um desfecho definitivo — e um model
 * traria eventos, escopos e casts que nada aqui usa.
 *
 * ## A colisao na UNIQUE e o mecanismo, nao um erro
 *
 * `reserve()` **tenta inserir** e devolve `false` na colisao. Verificar antes e
 * inserir depois abriria a janela entre a leitura e a escrita pela qual duas
 * entregas simultaneas do mesmo evento passam juntas.
 *
 * `processed_at` entra no proprio `INSERT`: a coluna e obrigatoria e nao tem
 * valor padrao, entao nao existe um segundo momento em que preenche-la, e
 * nenhuma linha pode existir sem ela.
 *
 * ## A leitura do desfecho e travada, e por uma razao especifica do MySQL
 *
 * Sob `REPEATABLE READ`, uma leitura comum enxerga o instantaneo da transacao — e
 * a linha inserida pela transacao concorrente pode nao estar nele nem depois de
 * ela commitar. `lockForUpdate()` le sempre a ultima versao confirmada e
 * **espera** a transacao concorrente terminar. E o que faz a segunda entrega ler
 * um desfecho definitivo em vez de correr com a primeira.
 *
 * ## A traducao da falha transitoria
 *
 * Toda operacao converte `QueryException` em {@see TransientStoreFailure}. Sem
 * isso o caso de uso teria de capturar a excecao de consulta do framework para
 * distinguir "falhou e pode voltar" de "falhou de vez", e a camada `Application`
 * passaria a conhecer o mecanismo de persistencia pelo caminho do tratamento de
 * erro.
 *
 * Nada da excecao original atravessa — nem a consulta, nem os valores
 * vinculados, nem a mensagem do driver —, e ela **nao e encadeada**: por
 * `getPrevious()`, a consulta e seus parametros chegariam a qualquer relatorio
 * que a imprimisse.
 *
 * A ordem dos `catch` importa: `UniqueConstraintViolationException` estende
 * `QueryException`, entao ela e capturada **antes**. Invertida, a colisao viraria
 * falha transitoria e o evento seria reentregue para sempre.
 */
final class EloquentWebhookEventStore implements WebhookEventStore
{
    private const TABELA = 'webhook_events';

    public function reserve(string $eventId, string $receivedVideoId, string $receivedStatus): bool
    {
        try {
            DB::table(self::TABELA)->insert([
                'id' => (string) Str::uuid7(),
                'event_id' => $eventId,
                'received_video_id' => $receivedVideoId,
                // Nula na reserva: neste instante ainda nao se sabe se existe
                // tentativa correspondente. A referencia resolvida entra em
                // `settle()`.
                'video_attempt_id' => null,
                'outcome' => null,
                'received_status' => $receivedStatus,
                'processed_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException) {
            throw TransientStoreFailure::em('reserve');
        }
    }

    public function outcomeOf(string $eventId): ?WebhookOutcome
    {
        try {
            $desfecho = DB::table(self::TABELA)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->value('outcome');
        } catch (QueryException) {
            throw TransientStoreFailure::em('outcomeOf');
        }

        // Nulo tem dois significados, e os dois levam a mesma acao: a linha
        // desapareceu com o rollback da transacao concorrente, ou ela existe mas
        // ainda nao foi resolvida — o que so acontece dentro da propria
        // transacao de reserva. Em ambos, quem chamou avalia o evento do zero.
        return $desfecho === null ? null : WebhookOutcome::from($desfecho);
    }

    public function settle(string $eventId, ?string $videoAttemptId, WebhookOutcome $outcome): void
    {
        try {
            DB::table(self::TABELA)
                ->where('event_id', $eventId)
                ->update([
                    'video_attempt_id' => $videoAttemptId,
                    'outcome' => $outcome->value,
                ]);
        } catch (QueryException) {
            throw TransientStoreFailure::em('settle');
        }
    }
}
