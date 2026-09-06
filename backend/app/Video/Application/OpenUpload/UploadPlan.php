<?php

declare(strict_types=1);

namespace App\Video\Application\OpenUpload;

use App\Video\Domain\VideoAttempt;

/**
 * O que o navegador precisa para transferir o arquivo sozinho (RF-UPL-003).
 *
 * E a resposta de RNF-001 e de AC-VID-001: o conteudo nao atravessa o Laravel
 * nem o servidor Nuxt, entao o que a abertura devolve nao e um destino de upload
 * da aplicacao — e o plano para o cliente falar direto com o armazenamento.
 *
 * Cinco campos, e cada um responde uma pergunta do cliente: qual tentativa e
 * esta, sobre qual objeto ela e, sob qual envio em partes, de que tamanho cada
 * parte e quantas ele vai precisar pedir.
 *
 * **Nao ha URL aqui, e a ausencia e a decisao.** As URLs de parte tem quinze
 * minutos de validade (plan §11.2), e emitir todas de uma vez na abertura faria
 * as ultimas vencerem antes de o navegador chegar nelas — num arquivo de
 * gigabytes, a maioria. Elas sao pedidas sob demanda, uma por parte, pela rota
 * propria.
 */
final class UploadPlan
{
    public function __construct(
        public readonly string $attemptId,
        public readonly string $storageKey,
        public readonly string $uploadId,
        public readonly int $partSize,
        public readonly int $partCount,
    ) {}

    public static function de(VideoAttempt $tentativa, int $partSize, int $partCount): self
    {
        return new self(
            attemptId: $tentativa->id(),
            storageKey: $tentativa->storageKey(),
            // A tentativa so chega aqui depois de o envio em partes ter sido
            // aberto no armazenamento, entao o identificador nunca e nulo neste
            // ponto. A afirmacao existe para que uma futura mudanca na ordem do
            // caso de uso falhe alto, em vez de devolver `null` ao cliente.
            uploadId: (string) $tentativa->multipartUploadId(),
            partSize: $partSize,
            partCount: $partCount,
        );
    }
}
