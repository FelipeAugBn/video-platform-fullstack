<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensagens de autenticacao
|--------------------------------------------------------------------------
|
| `failed` e usada tanto para e-mail inexistente quanto para senha incorreta, e
| e por isso que ela nao nomeia o campo que falhou (RF-AUT-008). Duas mensagens
| diferentes transformariam a tela de login num verificador de cadastro.
|
*/

return [
    'failed' => 'As credenciais informadas nao conferem.',
    'password' => 'A senha informada esta incorreta.',
    'throttle' => 'Excesso de tentativas de acesso. Tente novamente em :seconds segundos.',
];
