<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Controller;

use App\Shared\Domain\Failure\Failure;
use App\Shared\Interfaces\Http\Controller\IdentifiesTheOwner;
use App\Shared\Interfaces\Http\Problem\ProblemDetails;
use App\Video\Application\CompleteUpload\CompleteUpload;
use App\Video\Application\CompleteUpload\CompleteUploadCommand;
use App\Video\Application\CompleteUpload\CompletionOutcome;
use App\Video\Application\GetLessonVideo\GetLessonVideo;
use App\Video\Application\GetLessonVideo\GetLessonVideoQuery;
use App\Video\Application\IssuePartUrl\IssuePartUrl;
use App\Video\Application\IssuePartUrl\IssuePartUrlCommand;
use App\Video\Application\OpenUpload\OpenUpload;
use App\Video\Application\OpenUpload\OpenUploadCommand;
use App\Video\Application\Port\CompletedPart;
use App\Video\Interfaces\Http\Request\CompleteUploadRequest;
use App\Video\Interfaces\Http\Request\OpenUploadRequest;
use App\Video\Interfaces\Http\Resource\PartUrlResource;
use App\Video\Interfaces\Http\Resource\UploadPlanResource;
use App\Video\Interfaces\Http\Resource\VideoAttemptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * O envio de video, na fronteira HTTP.
 *
 * Quatro acoes que entram por dois identificadores diferentes — a abertura e a
 * consulta pela **aula**, a URL de parte e a conclusao pela **tentativa** — e as
 * quatro resolvem a propriedade dentro da consulta, pela cadeia
 * `video_attempt -> lesson -> module -> course -> owner_id` (RN-PROP-004).
 *
 * Sem route model binding, pelo motivo de sempre: carregar antes de saber de
 * quem e seria ler recurso alheio para so entao recusa-lo.
 *
 * Os status sao diferentes e cada um diz uma coisa (plan §10.3):
 *
 *   `201`  a tentativa foi criada e o plano de envio esta na resposta
 *   `200`  a autorizacao da parte, a consulta de estado, e a conclusao repetida
 *   `202`  a conclusao foi aceita **agora** e deixou trabalho enfileirado
 *   `409`  a conclusao foi recusada, com o motivo gravado na tentativa
 *   `503`  nao houve evidencia sobre o objeto; a tentativa segue em `uploading`
 *
 * O `202` da conclusao e o unico da API, e ele e literal: quando a resposta sai,
 * o job de processamento ja existe na mesma transacao que gravou `uploaded`. E
 * por isso que a repeticao responde `200` e nao `202` — ela nao enfileirou nada,
 * e prometer trabalho aceito onde nao ha trabalho novo seria mentir no contrato.
 */
final class VideoUploadController
{
    use IdentifiesTheOwner;

    public function __construct(
        private readonly OpenUpload $abrirEnvio,
        private readonly IssuePartUrl $emitirUrlDeParte,
        private readonly CompleteUpload $concluirEnvio,
        private readonly GetLessonVideo $obterVideo,
    ) {}

    public function store(OpenUploadRequest $request, string $lesson): JsonResponse
    {
        $plano = ($this->abrirEnvio)(new OpenUploadCommand(
            lessonId: $lesson,
            ownerId: $this->donoAutenticado($request),
            filename: $request->validated('filename'),
            contentType: $request->validated('content_type'),
            size: (int) $request->validated('size'),
        ));

        return UploadPlanResource::make($plano)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function partUrl(Request $request, string $attempt, string $part): JsonResponse
    {
        $url = ($this->emitirUrlDeParte)(new IssuePartUrlCommand(
            attemptId: $attempt,
            ownerId: $this->donoAutenticado($request),
            // A rota ja restringe o parametro a digitos, entao a conversao aqui
            // nao esconde entrada invalida: um valor nao numerico nao chega a
            // este metodo.
            partNumber: (int) $part,
        ));

        return PartUrlResource::make($url)->response();
    }

    /**
     * A conclusao, com quatro respostas possiveis.
     *
     * O status vem do **desfecho** devolvido pelo caso de uso, e nunca do estado
     * da tentativa: `uploaded` e o estado tanto de uma conclusao aceita agora
     * quanto de uma repetida, e as duas respondem coisas diferentes.
     *
     * A recusa sai como `application/problem+json` com o motivo **gravado na
     * tentativa** — o mesmo codigo funcional e o mesmo texto que a consulta do
     * video devolve, para que o produtor nao veja duas descricoes da mesma
     * falha. O `CONFLICT` generico e um fio-terra que so seria alcancado por uma
     * tentativa em `failed` sem motivo registrado, o que nenhum caminho da
     * aplicacao produz.
     */
    public function complete(CompleteUploadRequest $request, string $attempt): JsonResponse
    {
        /** @var list<array{part_number: int|string, etag: string}> $partes */
        $partes = $request->validated('parts');

        $resultado = ($this->concluirEnvio)(new CompleteUploadCommand(
            attemptId: $attempt,
            ownerId: $this->donoAutenticado($request),
            parts: array_map(
                // O comprovante e repassado como veio, inclusive as aspas: elas
                // fazem parte do valor que o armazenamento espera receber de
                // volta, e limpa-las quebraria a conclusao (plan §12.5).
                static fn (array $parte): CompletedPart => new CompletedPart(
                    (int) $parte['part_number'],
                    $parte['etag'],
                ),
                array_values($partes),
            ),
        ));

        return match ($resultado->outcome) {
            CompletionOutcome::ACCEPTED => VideoAttemptResource::make($resultado->attempt)
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED),

            CompletionOutcome::ALREADY_ACCEPTED => VideoAttemptResource::make($resultado->attempt)->response(),

            CompletionOutcome::REJECTED => ProblemDetails::response(
                $resultado->attempt->failure() ?? Failure::CONFLICT,
            ),
        };
    }

    public function show(Request $request, string $lesson): JsonResponse
    {
        $tentativa = ($this->obterVideo)(new GetLessonVideoQuery(
            lessonId: $lesson,
            ownerId: $this->donoAutenticado($request),
        ));

        // Aula sem video responde `200` com `data: null`, e nao `404`: "ainda nao
        // enviei nada" e o estado inicial de toda aula recem-criada, e trata-lo
        // como recurso inexistente obrigaria a interface a lidar com o normal
        // como se fosse falha.
        return $tentativa === null
            ? new JsonResponse(['data' => null])
            : VideoAttemptResource::make($tentativa)->response();
    }
}
