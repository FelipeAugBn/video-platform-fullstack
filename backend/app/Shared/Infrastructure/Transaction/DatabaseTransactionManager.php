<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Transaction;

use App\Shared\Application\Port\TransactionManager;
use Illuminate\Support\Facades\DB;

/**
 * A porta de transacao, sobre a conexao do Laravel.
 *
 * E o unico arquivo da solucao que sabe que existe `DB::transaction`. Toda a
 * mecanica fica contida aqui: abrir, confirmar no retorno normal, desfazer
 * diante de excecao e propaga-la.
 *
 * Nada e capturado nem traduzido. Uma excecao que atravessa este metodo continua
 * sendo a mesma que foi lancada la dentro — se ela virasse outra coisa aqui, o
 * tratamento centralizado da API perderia o caso do catalogo que o dominio
 * escolheu e responderia erro generico no lugar do `404` previsto.
 *
 * O `DB::transaction` do framework tambem sabe repetir a operacao diante de
 * deadlock, quando recebe um numero de tentativas. Este adapter chama a versao
 * de **uma tentativa so**, porque nenhuma politica de retry foi definida nesta
 * entrega.
 *
 * A ausencia e deliberada, e nao um esquecimento. Ligar o retry aqui valeria
 * para toda operacao que passa por esta porta, e adota-lo exigiria antes
 * verificar se cada uma delas e segura para repeticao — inclusive as que a
 * funcao anonima executa alem da escrita no banco, que o rollback nao desfaz.
 * Essa verificacao pertence a quem conhece o efeito de cada operacao, e nao a
 * uma camada que so enxerga uma funcao anonima.
 */
final class DatabaseTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return DB::transaction(static fn (): mixed => $operation());
    }
}
