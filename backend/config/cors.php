<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Quem decide se uma pagina de uma origem pode ler a resposta de outra e o
| navegador; esta configuracao e o que ele consulta.
|
| Tres cuidados, e nenhum deles e opcional aqui (plan §9.2):
|
| 1. `supports_credentials` e `true`, porque a sessao viaja em cookie. Sem isso
|    o navegador nao envia o cookie e a autenticacao nunca acontece.
|
| 2. Por consequencia, `allowed_origins` **nunca** pode ser `*`. O proprio
|    navegador recusa curinga junto de credenciais, e o curinga abriria a API a
|    qualquer origem. As origens sao enumeradas, vindas do ambiente.
|
| 3. `X-XSRF-TOKEN` esta em `allowed_headers` porque e o cabecalho que o cliente
|    **envia** a cada operacao mutante, com o valor lido do cookie `XSRF-TOKEN`.
|
| Sobre a leitura desse cookie vale ser exato, porque a confusao e comum:
| `Access-Control-Expose-Headers` governa quais **cabecalhos de resposta** o
| JavaScript pode ler, e nao tem efeito algum sobre cookies — `Set-Cookie` nunca
| se torna acessivel por ele. O que torna o `XSRF-TOKEN` legivel e ele **nao ser
| `HttpOnly`**; o cookie de sessao permanece inacessivel porque **e** `HttpOnly`.
| Sao dois atributos do proprio cookie, decididos por quem o emite, e nao pelo
| CORS.
|
| Cookies tambem nao sao separados por porta: o cookie host-only de `localhost`
| vale para o frontend em `:3000` e para a API em `:8080`.
|
*/

return [

    // `sanctum/csrf-cookie` entra na lista porque e a primeira requisicao que o
    // cliente faz, antes de qualquer operacao mutante.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'Origin',
        'Referer',
    ],

    // Vazio: nenhum cabecalho de resposta desta API precisa ser lido pelo
    // JavaScript. O cliente le o cookie `XSRF-TOKEN` porque ele nao e
    // `HttpOnly` — o que nao passa por aqui.
    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
