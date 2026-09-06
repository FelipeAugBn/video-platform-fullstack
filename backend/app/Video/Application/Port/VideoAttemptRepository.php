<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

use App\Video\Domain\VideoAttempt;

/**
 * O que os casos de uso precisam de um armazenamento de tentativas de video
 * (plan §5.3).
 *
 * Duas familias de metodo, e a diferenca entre elas e a razao de esta porta ser
 * maior que as do catalogo.
 *
 * **Com dono.** As operacoes que nascem de uma requisicao do produtor —
 * autorizar uma parte, concluir, consultar o estado — resolvem a propriedade
 * **dentro do SQL**, atravessando `video_attempt -> lesson -> module -> course
 * -> owner_id`. Tentativa inexistente e tentativa alheia devolvem `null` sem
 * distincao (RN-PROP-005), e a de outro produtor nunca chega a ser carregada.
 *
 * **Sem dono.** O `ProcessVideoJob` e o callback do webhook nao rodam em nome de
 * ninguem: o primeiro e um trabalho da fila, o segundo e um servico externo
 * autenticado por assinatura. Exigir um `ownerId` deles obrigaria a inventar um
 * — e um dono inventado e uma verificacao de propriedade que sempre passa.
 *
 * As duas familias existem lado a lado de proposito, com nomes que dizem qual e
 * qual. Um unico metodo servindo aos dois casos teria de aceitar dono nulo, e
 * `null` viraria a forma de pular a checagem: o dia em que um controller
 * passasse `null` por engano, a rota do produtor abriria para qualquer um.
 *
 * A regra de dependencia vale integralmente (plan §5.1): sem Eloquent, sem Query
 * Builder, sem Request. A porta fala em `VideoAttempt` e em tipos da linguagem.
 */
interface VideoAttemptRepository
{
    public function nextIdentity(): string;

    public function save(VideoAttempt $attempt): void;

    /**
     * A tentativa, se ela pertencer a um produtor **deste** dono.
     *
     * Devolve `null` para tentativa inexistente e para tentativa alheia, sem
     * distincao.
     */
    public function findOwned(string $attemptId, string $ownerId): ?VideoAttempt;

    /**
     * O mesmo que {@see findOwned()}, com a linha travada ate o fim da transacao.
     */
    public function lockOwned(string $attemptId, string $ownerId): ?VideoAttempt;

    /**
     * A tentativa atual de uma aula — aquela para a qual a aula aponta.
     *
     * Sem dono: quem chama ja resolveu a aula pela porta do catalogo, e repetir a
     * cadeia aqui seria percorrer de novo o que ja foi verificado.
     */
    public function findCurrentOfLesson(string $lessonId): ?VideoAttempt;

    /**
     * O mesmo que {@see findCurrentOfLesson()}, com a linha da tentativa travada.
     *
     * Existe ao lado da versao sem trava por uma razao especifica do MySQL: sob
     * `REPEATABLE READ`, uma leitura comum enxerga o instantaneo da transacao, e
     * nao necessariamente o que foi commitado por outra requisicao depois que ela
     * comecou. Uma leitura **travada** le sempre a ultima versao confirmada — e e
     * disso que a reavaliacao da abertura precisa para nao decidir sobre um
     * estado velho (plan §12.2).
     */
    public function lockCurrentOfLesson(string $lessonId): ?VideoAttempt;

    /**
     * A tentativa, sem verificacao de propriedade e com a linha travada.
     *
     * Usada apenas pelo job de processamento e pelo callback, que rodam fora de
     * uma sessao de produtor. E a leitura travada de plan §8.5: iniciar,
     * retomar ou encerrar sao decisoes que precisam de um estado estavel, e ler
     * sem travar deixaria duas entregas simultaneas decidirem sobre a mesma
     * leitura.
     */
    public function lock(string $attemptId): ?VideoAttempt;
}
