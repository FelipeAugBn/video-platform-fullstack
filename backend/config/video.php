<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Envio, processamento e callback de video
    |--------------------------------------------------------------------------
    |
    | Os parametros que o backend decide e o cliente nao escolhe. Estao juntos
    | num arquivo proprio, separado de `storage.php`, porque sao coisas
    | diferentes: aquele configura *como falar* com o armazenamento; este define
    | *o que a solucao aceita e quanto tempo espera*.
    |
    | Os valores vem de plan §§11.2, 13.1 e 13.4. Os que nao dependem do ambiente
    | estao escritos aqui, e nao em variavel: um limite de tamanho de arquivo nao
    | muda entre maquinas, e transforma-lo em variavel de ambiente criaria uma
    | configuracao que ninguem define e cujo valor efetivo ninguem sabe.
    |
    */

    'upload' => [

        /*
        | Unico tipo aceito.
        |
        | Nao ha transcodificacao nesta entrega (RF-PROC-005). Aceitar outros
        | formatos seria prometer uma reproducao que a solucao nao entrega.
        */
        'content_type' => 'video/mp4',

        // 10 GiB. Cobre o cenario de varios gigabytes do desafio e e recusado
        // ainda na abertura, antes de qualquer transferencia (RF-UPL-006).
        'max_size' => 10 * 1024 * 1024 * 1024,

        // 64 MiB por parte. Acima do minimo de 5 MiB do protocolo; mantem o
        // numero de partes administravel e limita o custo de reenviar uma.
        'part_size' => 64 * 1024 * 1024,

        // Teto do protocolo. Com 64 MiB por parte, o limite pratico fica em
        // aproximadamente 625 GiB — bem acima do tamanho maximo aceito.
        'max_parts' => 10_000,

        // 15 minutos, renovaveis pela mesma rota. Janela curta reduz o valor de
        // uma URL vazada, e renovar nao altera o dominio (plan §11.2).
        'part_url_ttl' => 15 * 60,

        /*
        | O lock atomico que serializa a conclusao (plan §8.2).
        |
        | O lease precisa ser **maior que a soma dos tempos limite do cliente S3**
        | declarados em `storage.php` — 5 segundos de conexao mais 30 de
        | requisicao, duas vezes, porque a conclusao faz duas chamadas. Se ele
        | expirasse com uma chamada ainda em voo, uma segunda requisicao
        | adquiriria o lock e as duas concluiriam a mesma tentativa em paralelo.
        |
        | A espera e curta de proposito: quem chega durante uma conclusao em
        | andamento desiste em poucos segundos e recebe indisponibilidade
        | temporaria. Bloquear ate o lease inteiro prenderia um processo PHP-FPM
        | pelo tempo da operacao alheia, e um punhado de repeticoes esgotaria o
        | pool.
        */
        'lock' => [
            'store' => 'database',
            'lease' => 120,
            'wait' => 5,
        ],

    ],

    'webhook' => [

        /*
        | Segredo compartilhado da assinatura (plan §13.4).
        |
        | **Sem padrao.** O ambiente do Compose aborta a subida quando a variavel
        | falta, e o verificador recusa toda entrega quando o valor chega vazio —
        | calcular HMAC sobre chave vazia aceitaria qualquer emissor que soubesse
        | do descuido. O mesmo valor serve as duas pontas: o simulador assina com
        | ele e o endpoint confere com ele.
        */
        'secret' => env('WEBHOOK_SECRET', ''),

        // Janela de cinco minutos entre o timestamp assinado e o relogio do
        // servidor, conferida nos dois sentidos. Limita replay de uma assinatura
        // capturada.
        'tolerance' => 5 * 60,

        // O `Retry-After` da falha transitoria. Alinhado ao primeiro degrau do
        // backoff dos consumidores, que e de 10 segundos.
        'retry_after' => 10,

    ],

    'playback' => [

        /*
        | Validade da URL de leitura (plan §14.2).
        |
        | Cinco minutos: janela curta o bastante para reduzir o valor de uma URL
        | copiada, e longa o bastante para o player comecar a reproduzir sem
        | pedir outra. A limitacao esta assumida — a URL funciona ate expirar, e
        | eliminar a redistribuicao exigiria cookie assinado, token por sessao de
        | player ou DRM, fora do escopo.
        */
        'url_ttl' => 5 * 60,

    ],

    'simulator' => [

        /*
        | O endereco do callback **dentro da rede do Compose**.
        |
        | E o nome do servico que atende HTTP, e nao o endereco publico: o
        | publico e o do navegador, e usa-lo aqui faria a entrega depender do
        | encaminhamento de porta do host, que nao existe do ponto de vista de um
        | container.
        */
        'callback_url' => env('WEBHOOK_CALLBACK_URL', 'http://web/api/webhooks/video-processing'),

        // Finito, como os do storage: sem limite, uma API que aceita a conexao e
        // nao responde prenderia o `simulator-worker` indefinidamente.
        'timeout' => 10,

    ],

];
