<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * O limite da transacao, declarado por quem sabe qual e (plan §§5.3, 7.4).
 *
 * Quem decide o que precisa valer junto e o caso de uso, e nao o armazenamento:
 * so ele sabe que travar o curso, calcular a proxima posicao e inserir o modulo
 * sao uma operacao unica, e que metade disso gravado nao e um resultado
 * aceitavel. Essa decisao e de aplicacao.
 *
 * Sem esta porta o caso de uso chamaria `DB::transaction` diretamente, e
 * `Application` passaria a importar `Illuminate` — a regra de dependencia
 * cairia justamente na camada que existe para nao conhecer framework (plan
 * §5.1). Com ela, o caso de uso diz **o que** pertence a operacao, e o adapter
 * de `Infrastructure` diz **como** o banco a executa.
 *
 * Deliberadamente minima. Nao fala em conexao, savepoint, nivel de isolamento
 * nem transacao aninhada: sao vocabulario do mecanismo, e um caso de uso que
 * precisasse escolher entre eles ja estaria decidindo persistencia. Um unico
 * metodo, que executa e devolve.
 *
 * O tipo do parametro e `callable`, e nao `Closure`, pelo mesmo motivo pelo qual
 * nao ha `Illuminate` aqui: manter a assinatura em vocabulario da linguagem.
 */
interface TransactionManager
{
    /**
     * Executa a operacao como uma unidade e devolve o resultado dela.
     *
     * O retorno e preservado porque o caso de uso normalmente precisa do que foi
     * criado la dentro — o modulo com a posicao ja calculada, por exemplo. Uma
     * porta que devolvesse `void` obrigaria cada chamador a contrabandear o
     * resultado por uma variavel capturada por referencia, o que e a mesma coisa
     * escrita de forma pior.
     *
     * Excecao lancada dentro da operacao **desfaz tudo** e continua subindo. O
     * caso de uso nao a captura para "converter em rollback": quem cuida disso e
     * o adapter, e a excecao segue ate o tratamento centralizado, que a traduz em
     * resposta.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
