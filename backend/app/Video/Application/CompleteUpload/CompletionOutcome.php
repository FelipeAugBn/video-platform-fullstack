<?php

declare(strict_types=1);

namespace App\Video\Application\CompleteUpload;

/**
 * O que a conclusao de envio produziu — e nao em que estado a tentativa ficou.
 *
 * Existe porque os dois nao sao a mesma coisa, e a diferenca e observavel pelo
 * cliente. Uma tentativa recem-concluida e uma tentativa **repetida** estao as
 * duas em `uploaded`: pelo estado, sao indistinguiveis. Mas a primeira deixou um
 * job enfileirado e a segunda nao, e o contrato promete respostas diferentes
 * para isso — `202` para o trabalho aceito, `200` para o resultado ja obtido.
 *
 * Inferir isso do estado seria possivel apenas com sorte: bastaria o callback
 * chegar entre a conclusao e a resposta para a tentativa aparecer em
 * `processing` e o cliente receber `200` por uma conclusao que acabou de
 * enfileirar trabalho.
 *
 * ## Isto nao e status HTTP
 *
 * Os tres casos falam do desfecho da **operacao**, no vocabulario da aplicacao.
 * A traducao para `202`, `200` e `409` acontece na camada de interface, que e
 * onde o protocolo mora (plan §5.4). Um enum com `202` aqui dentro colocaria HTTP
 * dentro do caso de uso.
 *
 * `REJECTED` nao distingue a recusa nova da repetida, e isso e deliberado: as
 * duas respondem o mesmo `409` com o mesmo codigo funcional, lido do motivo
 * gravado na tentativa. Um quarto caso existiria so para ser mapeado ao mesmo
 * lugar.
 */
enum CompletionOutcome
{
    /**
     * A tentativa transicionou para `uploaded` **nesta** chamada, e o
     * processamento foi enfileirado no mesmo commit.
     */
    case ACCEPTED;

    /**
     * A conclusao ja havia sido aceita antes. Nenhum job novo, nenhuma
     * transicao — o desfecho anterior e devolvido como esta (RN-IDM-001).
     */
    case ALREADY_ACCEPTED;

    /**
     * A tentativa esta em `failed`, com motivo gravado. Vale tanto para a
     * verificacao que acabou de reprova-la quanto para a repeticao de uma
     * conclusao ja rejeitada.
     */
    case REJECTED;
}
