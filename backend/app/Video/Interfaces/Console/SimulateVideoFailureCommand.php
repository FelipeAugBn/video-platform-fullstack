<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Console;

use App\Video\Domain\ProcessingScenario;
use App\Video\Infrastructure\Simulator\CallbackDelivery;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aciona o cenario de falha de processamento, pelo caminho de verdade
 * (RF-WHK-008, plan §13.3).
 *
 * A falha nao e sorteada e nao e disparada por convencao escondida no nome do
 * arquivo — um gatilho magico desses e invisivel para quem le o codigo e fragil
 * para quem escreve o teste. Ela e provocada explicitamente, por este comando.
 *
 * ## O que este comando **nao** faz
 *
 * Nao escreve em tabela de dominio. Ele nao conhece repositorio, model nem
 * conexao: chama o **mesmo** componente que o `simulator-worker` usa, com o
 * mesmo HMAC, o mesmo contrato e o mesmo endpoint HTTP. A unica forma de afetar
 * o estado continua sendo o callback, e e por isso que a demonstracao de falha
 * exercita exatamente o que a producao exercitaria.
 *
 * ## Repetir e inofensivo
 *
 * O `event_id` do cenario de falha e estavel — derivado da tentativa e do
 * cenario —, entao executar duas vezes reentrega o **mesmo** evento. O webhook
 * reconhece a repeticao e repete o desfecho ja registrado, sem efeito novo. E
 * uma demonstracao de idempotencia que cabe em duas execucoes seguidas.
 *
 * ## O identificador vem por argumento
 *
 * O seed prepara uma tentativa **dedicada** ao cenario de falha, com UUID
 * literal, separada do curso da jornada principal: acionar a falha nao pode sujar
 * o curso que a demonstracao usa. O identificador e argumento e nao constante
 * para que o comando sirva a qualquer tentativa em `processing` — inclusive uma
 * criada durante a avaliacao.
 */
final class SimulateVideoFailureCommand extends Command
{
    protected $signature = 'demo:simulate-video-failure {videoAttemptId : Identificador da tentativa que recebera o callback de falha}';

    protected $description = 'Entrega um callback de falha de processamento para a tentativa informada, pelo endpoint HTTP real';

    public function handle(CallbackDelivery $entrega): int
    {
        $id = (string) $this->argument('videoAttemptId');

        try {
            $entrega->deliver($id, ProcessingScenario::FAILURE);
        } catch (Throwable $e) {
            // Comando de operacao, e nao resposta de API: aqui a mensagem serve a
            // quem esta no terminal diagnosticando o ambiente, e o codigo de
            // saida diferente de zero e o que um script percebe.
            $this->components->error('A entrega do callback de falha nao foi concluida: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Callback de falha entregue para a tentativa '.$id.'.');

        return self::SUCCESS;
    }
}
