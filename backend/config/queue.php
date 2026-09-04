<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    // Fila persistida no proprio MySQL (plan §13.1). O padrao aqui e o mesmo
    // valor que o ambiente injeta.
    //
    // O driver `database` entrega tres coisas: persistencia do trabalho, reserva
    // do job enquanto ele executa e armazenamento da falha definitiva. Quantas
    // tentativas, quanto tempo cada uma pode durar e quanto esperar entre elas
    // nao vem daqui — sao opcoes dos consumidores, declaradas no comando de cada
    // um. Trocar as duas origens leva a procurar no arquivo errado quando o
    // comportamento de retentativa nao for o esperado.
    //
    // O preco e vazao inferior a de um broker dedicado, capacidade que este
    // problema nao precisa.
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    // O framework suporta outros drivers de fila — broker dedicado, fila
    // gerenciada em nuvem, cache em memoria — e mantem conexoes padrao para eles
    // na configuracao resolvida, mesmo quando este arquivo nao as declara. Elas
    // seguem disponiveis; este projeto simplesmente nao as seleciona nem as usa.
    //
    // A conexao escolhida e `database`, declarada como padrao logo acima, e e ela
    // que os dois consumidores executam. O ambiente do Compose nao sobe Redis,
    // SQS, Beanstalkd nem qualquer outro broker — nenhum desses servicos existe
    // para ser alcancado a partir daqui.
    //
    // Existir configuracao padrao do framework nao coloca o servico na
    // arquitetura. O que a define e o que o projeto seleciona e o que o ambiente
    // oferece, e os dois apontam para o MySQL que ja e obrigatorio.
    'connections' => [

        // Execucao imediata, no mesmo processo. E o que a suite de testes usa,
        // para que um caso de teste observe o efeito do job sem depender de um
        // consumidor de pe.
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            // Fixo em `null`, e nao lido do ambiente: a fila usa a conexao
            // padrao da aplicacao, a mesma que grava o dominio. Nao e detalhe de
            // arrumacao — e a premissa da garantia descrita em `after_commit`,
            // logo abaixo. Uma variavel de ambiente poderia aponta-la para outra
            // conexao e desfazer essa garantia em silencio, sem que nenhum teste
            // percebesse.
            'connection' => null,
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            // Fila usada quando o remetente nao nomeia nenhuma. Cada consumidor
            // declara explicitamente a sua no comando, entao este valor so vale
            // para o despacho.
            'queue' => env('DB_QUEUE', 'default'),
            // Janela de reserva: depois dela a fila considera o job abandonado e
            // o devolve. Precisa ser maior que o tempo limite de execucao
            // configurado nos consumidores, que e de 60 segundos. Se a fila
            // devolvesse antes de o processo desistir, o mesmo trabalho passaria
            // a rodar duas vezes em paralelo.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            // Falso de proposito, e assim permanece.
            //
            // Como a fila usa a conexao padrao acima, a linha inserida em `jobs`
            // participa da mesma transacao que altera o estado do dominio.
            // Enfileirar de dentro da transacao passa a ser a estrategia, nao um
            // descuido: antes do commit a linha nao existe para nenhum outro
            // processo, entao o worker nao a alcanca; no rollback, nem o estado
            // nem o job permanecem; no commit, os dois ficam disponiveis juntos.
            //
            // Adiar o despacho para depois do commit abriria justamente a janela
            // que isso fecha: o estado confirmado e o processo morrendo antes de
            // criar o job, deixando um video sem trabalho enfileirado.
            //
            // A garantia depende de fila e dominio compartilharem a mesma
            // conexao MySQL. Mover a fila para outra conexao, ou para Redis, SQS
            // ou qualquer servico externo, invalida o raciocinio e exige revisar
            // a estrategia — provavelmente com despacho pos-commit explicito,
            // outbox ou mecanismo equivalente.
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    // Job que esgota as tentativas termina aqui, com carga e excecao preservadas.
    // Nao desaparece, e tambem nao altera estado de dominio por conta propria:
    // falha de infraestrutura nao e desfecho de negocio (plan §13.1).
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
