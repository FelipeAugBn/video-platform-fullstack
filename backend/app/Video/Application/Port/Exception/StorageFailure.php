<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

use RuntimeException;

/**
 * Base das falhas que a porta de armazenamento pode produzir.
 *
 * Existe para que **nenhuma falha esperada da integracao com o provedor ou com
 * seu SDK atravesse a porta sem ser traduzida**. Sem ela, uma excecao de
 * infraestrutura chegaria ao caso de uso, e a camada `Application` passaria a
 * capturar um tipo que nao deveria conhecer — a regra de dependencia cairia pelo
 * caminho do tratamento de erro, que e o mais facil de nao notar em revisao.
 *
 * O que **nao** e traduzido, e de proposito: defeito de programacao. `TypeError`
 * e os demais erros da linguagem continuam subindo intactos, porque o adapter
 * captura uma lista fechada de excecoes esperadas em vez de `Throwable`. Vestir
 * um bug de falha de armazenamento esconderia a causa real.
 *
 * ## O vocabulario e da aplicacao, nao do provedor
 *
 * Estas excecoes **nao carregam codigo de protocolo** — nem em propriedade, nem
 * em mensagem. Elas dizem o que aconteceu em termos do problema: qual operacao
 * da porta falhou, sobre qual chave, e qual e a **situacao**.
 *
 * A razao e concreta. Se o codigo do protocolo vazasse ate aqui, o caso de uso
 * teria como examina-lo — e no dia em que uma decisao dependesse desse exame, a
 * regra de negocio passaria a estar escrita no vocabulario de um provedor
 * especifico. Trocar de armazenamento deixaria de ser trocar o adapter. O
 * adapter usa esses codigos para **classificar**; o que ele entrega e o
 * resultado da classificacao, e nao a evidencia dela.
 *
 * ## As quatro situacoes
 *
 * O conjunto e pequeno e fechado. Cada caso descreve uma situacao distinta e
 * suficiente para uma decisao segura — o que **nao** significa que cada um leve
 * a um comportamento diferente: mais de uma situacao pode terminar na mesma
 * decisao de negocio, e isso e aceitavel. O que nao pode acontecer e o
 * contrario — duas situacoes que exigem decisoes opostas chegarem
 * indistinguiveis.
 *
 * ## O que estas excecoes nao carregam
 *
 * URL assinada, credencial, corpo bruto da resposta e mensagem interna do
 * provedor ficam de fora da mensagem, e a excecao original **nao e encadeada**.
 * A query string de uma URL assinada contem os dados temporarios de
 * autorizacao, e a mensagem do provedor pode ecoar a chave de acesso; encadear a
 * original levaria as duas coisas para o log por `getPrevious()`, e de la para
 * qualquer relatorio que o imprimisse.
 *
 * O custo assumido e a perda do texto original para diagnostico. Se ele fizer
 * falta, o lugar de registra-lo e a infraestrutura, que ja conhece o provedor —
 * nao esta classe.
 *
 * PHP puro: sem SDK, sem framework, sem HTTP.
 */
abstract class StorageFailure extends RuntimeException
{
    /**
     * @param  string  $operation  Operacao da porta que falhou.
     * @param  string  $key  Chave do objeto envolvido.
     */
    final public function __construct(
        public readonly string $operation,
        public readonly string $key,
        string $message,
    ) {
        parent::__construct($message);
    }
}
