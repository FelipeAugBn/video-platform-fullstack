<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use DateTimeImmutable;

/**
 * O agora, como dependencia declarada (plan §5.3).
 *
 * Existe porque "quando isto aconteceu" e uma entrada da regra, e nao um efeito
 * ambiente que cada classe busca por conta propria. Um caso de uso que chama a
 * data do sistema diretamente so pode ser testado no instante em que roda: nao
 * ha como afirmar que a data gravada e exatamente a do momento da operacao, so
 * que ela cai perto. Recebendo o relogio, o teste fixa o instante e a afirmacao
 * passa a ser de igualdade.
 *
 * Deliberadamente minimo. Nao e uma camada de datas: nao formata, nao converte
 * fuso, nao soma intervalo. Quem precisa disso faz com o proprio
 * `DateTimeImmutable` devolvido aqui.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
