<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Armazenamento de objetos
    |--------------------------------------------------------------------------
    |
    | Configuracao propria do storage de video, separada de `filesystems.php`
    | de proposito. O disco do Laravel resolve "guardar um arquivo"; o que esta
    | solucao precisa e outra coisa — abrir um envio em partes, assinar a URL de
    | **uma parte**, concluir e inspecionar —, e a fachada de Filesystem nao
    | expoe nada disso. Passar o SDK por ela transformaria a porta num invólucro
    | generico e esconderia justamente as operacoes que o plano usa.
    |
    | Nenhuma variavel de ambiente nova foi criada. As seis que ja existem sao as
    | mesmas que o `docker-compose.yml` entrega ao backend e ao servico de
    | preparacao do bucket; o que nao vem do ambiente tem padrao explicito aqui.
    |
    */

    /*
    | Os dois enderecos do storage (plan §16.3).
    |
    | Confundi-los e a origem mais provavel de uma URL que a aplicacao considera
    | valida e o navegador nao alcanca. As portas sao diferentes de proposito —
    | 9000 dentro da rede, 19000 no host — para que a troca seja um erro visivel,
    | e nao um acerto por coincidencia.
    |
    |   internal  as chamadas de API do backend, dentro da rede do Compose
    |   public    o host que participa da assinatura das URLs entregues ao navegador
    */
    'endpoints' => [
        'internal' => env('STORAGE_ENDPOINT_INTERNAL'),
        'public' => env('STORAGE_ENDPOINT_PUBLIC'),
    ],

    'bucket' => env('STORAGE_BUCKET'),

    'region' => env('STORAGE_REGION', 'us-east-1'),

    /*
    | Credenciais explicitas, sem cadeia de provedores.
    |
    | O SDK, por padrao, procura credenciais em arquivo de perfil, variaveis
    | convencionais da AWS e metadados de instancia. Nada disso existe aqui, e
    | deixar a busca ligada faria uma configuracao ausente falhar tarde e com
    | mensagem sobre a AWS — em vez de falhar no lugar onde o valor deveria estar.
    */
    'credentials' => [
        'key' => env('STORAGE_ACCESS_KEY'),
        'secret' => env('STORAGE_SECRET_KEY'),
    ],

    /*
    | Tempos limite finitos, em segundos.
    |
    | Sem eles o cliente HTTP espera indefinidamente, e um storage que aceita a
    | conexao mas nao responde prende o processo da API — que e pior do que
    | falhar. Com limite, a falha vira `StorageUnavailable`, que e classificada
    | como transitoria e permite ao caso de uso preservar o estado e repetir
    | (plan §12.4).
    |
    | O limite de requisicao e maior que o de conexao porque as operacoes de
    | conclusao de multipart mandam o storage reunir as partes, o que leva mais
    | tempo do que abrir uma conexao.
    */
    'timeouts' => [
        'connect' => 5,
        'request' => 30,
    ],

];
