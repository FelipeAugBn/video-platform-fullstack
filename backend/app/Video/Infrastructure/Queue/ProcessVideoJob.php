<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Queue;

use App\Video\Application\StartProcessing\StartProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O primeiro trabalho assincrono da aplicacao (RF-PROC-002, plan §13.2).
 *
 * Carrega **apenas o identificador** da tentativa, e recarrega o estado sob
 * trava ao executar. Um job que carregasse a entidade serializada operaria sobre
 * uma copia velha: entre o enfileiramento e a execucao o estado pode ter mudado —
 * por um callback, por uma conclusao repetida, por outro retry — e decidir sobre
 * a copia desfaria em silencio o que ja tinha sido decidido.
 *
 * A decisao inteira vive em {@see StartProcessing}, e nao aqui. Esta classe e a
 * casca de fila: ela existe para que o caso de uso possa ser executado por um
 * consumidor, e para que ele proprio nao precise conhecer `Illuminate`.
 *
 * ## Tentativas, espera e tempo limite nao estao aqui
 *
 * Sao opcoes do **consumidor**, declaradas no comando de cada processo no
 * Compose: tres tentativas, espera de 10, 30 e 60 segundos, 60 segundos de
 * execucao. Repeti-las na classe daria duas origens para o mesmo comportamento,
 * e quem investigasse uma retentativa inesperada procuraria no arquivo errado.
 *
 * ## Esgotar tentativas nao reprova o video
 *
 * Um job que esgota as tentativas termina em `failed_jobs`, e a tentativa de
 * video **permanece como estava** (plan §13.1). E falha de infraestrutura, nao
 * desfecho de negocio: a aplicacao nao recebeu resposta nenhuma sobre o video, e
 * transformar uma queda de rede em video defeituoso e o erro que a distincao
 * entre as duas coisas existe para evitar. Nao ha `failed()` nesta classe
 * justamente por isso — o comportamento correto e nao fazer nada.
 */
final class ProcessVideoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $videoAttemptId) {}

    public function handle(StartProcessing $iniciarProcessamento): void
    {
        $iniciarProcessamento($this->videoAttemptId);
    }
}
