<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rotas da API
|--------------------------------------------------------------------------
|
| Carregadas com o prefixo `api` e o grupo de middleware de mesmo nome.
|
| O arquivo existe vazio de proposito. A infraestrutura do contrato — envelope,
| paginacao e o formato de erro — foi construida antes do primeiro endpoint,
| para que nenhuma rota nasca num formato que depois precise ser reescrito. Os
| endpoints chegam junto dos casos de uso que os justificam.
|
| As rotas que exercitam o contrato nos testes sao registradas pela propria
| suite e nao existem na aplicacao em execucao.
|
| O endpoint de saude nao esta aqui: `/up` e servido fora deste prefixo, e e o
| que o healthcheck do servico web consulta.
|
*/
