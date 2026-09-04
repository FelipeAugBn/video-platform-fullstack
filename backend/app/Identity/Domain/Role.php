<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Perfil de um usuario.
 *
 * Sao dois, e um usuario tem exatamente um deles nesta entrega (spec §4). O
 * desafio nao pede acumulo de papeis, e permitir que um produtor tambem consuma
 * cursos de terceiros ampliaria a matriz de autorizacao sem tornar possivel
 * nenhuma jornada exigida.
 *
 * Existe como enumeracao, e nao como constante de string, porque e ela que
 * garante que so estes dois valores circulem — do middleware de fronteira ate a
 * coluna do banco, que declara o mesmo par. PHP puro, como todo o `Domain`.
 *
 * Este perfil responde "que tipo de usuario e este", e nao "este recurso e
 * dele". A segunda pergunta e de propriedade, decidida pelos casos de uso a
 * partir de T034 (plan §9.3).
 */
enum Role: string
{
    case PRODUCER = 'producer';
    case CONSUMER = 'consumer';
}
