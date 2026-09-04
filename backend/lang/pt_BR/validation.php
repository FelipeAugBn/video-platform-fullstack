<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensagens de validacao
|--------------------------------------------------------------------------
|
| Traducao do proprio projeto, sem dependencia acrescentada so para isto — o
| desafio e explicito em nao considerar diferencial a quantidade de pacotes.
|
| Apenas as regras que a aplicacao usa. O arquivo cresce junto com as tarefas
| que introduzem novas validacoes; uma traducao completa do framework encheria o
| repositorio de texto que ninguem exercita.
|
*/

return [
    'required' => 'O campo :attribute e obrigatorio.',
    'string' => 'O campo :attribute precisa ser um texto.',
    'email' => 'O campo :attribute precisa ser um endereco de e-mail valido.',
    'max' => [
        'string' => 'O campo :attribute nao pode ter mais de :max caracteres.',
        'numeric' => 'O campo :attribute nao pode ser maior que :max.',
        'array' => 'O campo :attribute nao pode ter mais de :max itens.',
        'file' => 'O campo :attribute nao pode ser maior que :max kilobytes.',
    ],
    'min' => [
        'string' => 'O campo :attribute precisa ter ao menos :min caracteres.',
        'numeric' => 'O campo :attribute precisa ser no minimo :min.',
        'array' => 'O campo :attribute precisa ter ao menos :min itens.',
        'file' => 'O campo :attribute precisa ter ao menos :min kilobytes.',
    ],
    'integer' => 'O campo :attribute precisa ser um numero inteiro.',
    'boolean' => 'O campo :attribute precisa ser verdadeiro ou falso.',
    'uuid' => 'O campo :attribute precisa ser um identificador valido.',
    'in' => 'O valor informado em :attribute nao e valido.',
    'unique' => 'Este valor de :attribute ja esta em uso.',
    'exists' => 'O valor selecionado em :attribute nao e valido.',
    'confirmed' => 'A confirmacao de :attribute nao confere.',
    'date' => 'O campo :attribute precisa ser uma data valida.',
    'numeric' => 'O campo :attribute precisa ser um numero.',
    'array' => 'O campo :attribute precisa ser uma lista.',

    'custom' => [],

    /*
     | Nome legivel dos campos, usado no lugar do nome tecnico dentro das
     | mensagens. Cada Form Request pode sobrescrever o seu em `attributes()`.
     */
    'attributes' => [
        'email' => 'e-mail',
        'password' => 'senha',
        'name' => 'nome',
        'title' => 'titulo',
        'description' => 'descricao',
        'role' => 'perfil',
    ],
];
