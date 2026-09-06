<?php

declare(strict_types=1);

namespace App\Video\Domain;

/**
 * O desfecho definitivo de um `event_id`.
 *
 * Dois casos, e eles instruem o emissor a coisas opostas — que e a razao de
 * existirem separados (plan §13.4):
 *
 *   `ACCEPTED`            o evento foi aplicado; a resposta e `200` e o emissor para
 *   `REJECTED_PERMANENT`  o evento nunca podera ser aplicado; a resposta e `409` e o emissor para
 *
 * O que **nao** e um desfecho: a falha transitoria. Ela nao produz linha
 * nenhuma — a transacao inteira sofre rollback e a reserva desaparece com ela,
 * porque o evento continua elegivel e a reentrega precisa ser avaliada do zero
 * (RN-VID-007). Um terceiro caso aqui seria a forma de gravar "ainda nao sei",
 * e uma linha com essa marca ficaria para sempre bloqueando o `event_id`.
 *
 * PHP puro, como todo o `Domain`.
 */
enum WebhookOutcome: string
{
    case ACCEPTED = 'accepted';
    case REJECTED_PERMANENT = 'rejected_permanent';
}
