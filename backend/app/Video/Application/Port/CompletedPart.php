<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

/**
 * Uma parte que o navegador ja enviou, e o comprovante que o armazenamento
 * devolveu.
 *
 * Dois campos, e o segundo e o unico motivo desta classe existir: concluir um
 * envio em partes exige devolver ao armazenamento, para cada parte, exatamente o
 * identificador que ele proprio emitiu.
 *
 * **O `etag` e opaco.** Ele nao e verificado, nao e comparado e nao e
 * interpretado por nenhuma camada desta solucao — e repassado como veio,
 * inclusive as aspas, que fazem parte do valor que o armazenamento espera
 * receber de volta. Limpa-las "para ficar bonito" quebra a conclusao do envio.
 *
 * O erro que esta classe existe para tornar improvavel e trata-lo como checksum.
 * **O `ETag` nao possui garantia portatil de ser o MD5 do arquivo completo:** seu
 * formato e seu calculo podem variar conforme o provedor, a quantidade de
 * partes, criptografia e configuracao (plan §12.5). Neste projeto ele e um
 * comprovante opaco da parte, necessario para concluir o envio, e nao um checksum
 * do video — a verificacao de integridade que a solucao faz esta em plan §12.3, e
 * usa outros dados.
 *
 * PHP puro, como tudo em `Application`: sem SDK, sem framework, sem HTTP.
 */
final class CompletedPart
{
    /**
     * @param  int  $partNumber  Numero da parte, na sequencia que o envio usou.
     * @param  string  $etag  Comprovante emitido pelo armazenamento, preservado byte a byte.
     */
    public function __construct(
        public readonly int $partNumber,
        public readonly string $etag,
    ) {}
}
