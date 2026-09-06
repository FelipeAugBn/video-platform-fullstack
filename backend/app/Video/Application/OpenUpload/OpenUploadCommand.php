<?php

declare(strict_types=1);

namespace App\Video\Application\OpenUpload;

/**
 * A intencao de abrir um envio, ja separada do transporte (RF-UPL-001).
 *
 * O `ownerId` nao vem do corpo da requisicao: ele e o usuario autenticado,
 * colocado aqui pelo controller. Um campo de dono vindo do cliente seria uma
 * autorizacao que o proprio solicitante preenche.
 *
 * O que **nao** esta aqui e tao importante quanto o que esta: nao ha chave de
 * armazenamento, nem metadados, nem tamanho de parte, nem estado inicial.
 * Todos sao definidos pelo servidor (plan §11.3), e um comando sem propriedade
 * para recebe-los e o que impede um cliente de tentar escolhe-los.
 */
final class OpenUploadCommand
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $ownerId,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly int $size,
    ) {}
}
