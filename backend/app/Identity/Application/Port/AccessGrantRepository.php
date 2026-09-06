<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

/**
 * A concessao de acesso, isolada de tudo o que ela autoriza (RN-AUT-003).
 *
 * Mora em `Identity` porque a pergunta e sobre o usuario, e nao sobre o
 * conteudo: "este consumidor recebeu acesso a este curso?". A resposta e um
 * booleano, e nenhum tipo do catalogo atravessa a porta — so identificadores.
 * E o que mantem a autorizacao verificavel sozinha, sem montar arvore nenhuma.
 *
 * **Concessao nao e propriedade.** O produtor alcanca um curso por ser dono
 * dele; o consumidor, por ter recebido acesso. Sao duas regras diferentes sobre
 * duas tabelas diferentes, e reaproveitar os metodos de dono para representar
 * concessao faria a primeira mudanca em uma delas mexer silenciosamente na
 * outra. Por isso esta porta existe ao lado das do catalogo, e nao dentro delas.
 *
 * O consumidor **nunca** e parametro escolhido pelo cliente: ele vem da sessao,
 * como o dono nas rotas do produtor. Uma assinatura que aceitasse o consumidor
 * do corpo ou da query string transformaria a concessao em algo que quem chama
 * declara sobre si mesmo.
 *
 * Nesta entrega a concessao e **imutavel**: ela nasce no seed e nao ha operacao
 * de conceder nem de revogar (RF-CONS-006). E o que permite responder a pergunta
 * uma vez e seguir usando a resposta no restante da requisicao, sem trava e sem
 * reconferir — nao existe caminho pelo qual ela mude no intervalo.
 *
 * A regra de dependencia vale integralmente (plan §5.1): sem Eloquent, sem Query
 * Builder, sem Request.
 */
interface AccessGrantRepository
{
    /**
     * Existe concessao deste curso para este consumidor?
     *
     * A verificacao acontece **dentro do SQL**, sobre `course_access_grants`.
     * Carregar o curso para so entao comparar em PHP seria ler conteudo que
     * ainda nao se sabe autorizado.
     *
     * **Quem chama isto e a consulta da estrutura**, e so ela. As outras duas
     * rotas de consumo nao passam por aqui: a listagem e a reproducao resolvem a
     * concessao dentro das proprias consultas de leitura, porque nos dois casos
     * ela e inseparavel do que se le — a listagem precisa dela para que `total` e
     * numero de paginas contem apenas o conjunto autorizado, e a reproducao
     * alcanca a aula justamente atravessando `lessons -> modules -> concessao`.
     * Um portao separado ali seria uma segunda ida ao banco para reafirmar o que
     * a consulta ja teria de decidir sozinha.
     *
     * Na estrutura a separacao vale a pena: quando esta pergunta devolve `false`,
     * as consultas de modulos e aulas nao chegam a ser executadas, e a resposta e
     * `404` — indistinguivel da de um curso inexistente (RN-PROP-005,
     * RF-PLB-008, plan §9.3).
     */
    public function grantsCourse(string $consumerId, string $courseId): bool;
}
