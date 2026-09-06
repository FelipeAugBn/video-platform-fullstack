<?php

declare(strict_types=1);

namespace App\Video\Domain;

use App\Shared\Domain\Failure\Failure;
use App\Video\Domain\Exception\InvalidVideoTransition;

/**
 * Uma tentativa de envio de video, do primeiro byte autorizado ao desfecho.
 *
 * E o agregado do ciclo de vida do video (plan §6.1). A aula guarda apenas o
 * identificador da tentativa atual; tudo o que acontece com o arquivo — enviar,
 * verificar, processar, concluir ou falhar — acontece aqui.
 *
 * ## Imutavel, como os outros agregados
 *
 * Nenhum metodo altera o objeto: cada transicao devolve **uma nova tentativa**
 * no estado seguinte. E a mesma escolha de `Course` e `Lesson`, e ela vale mais
 * aqui do que la. A conclusao de envio le a tentativa, vai ao storage, e depois
 * rele sob trava para reavaliar (plan §12.2). Com um objeto mutavel, a instancia
 * lida antes da chamada de rede continuaria por perto, ja modificada, parecendo
 * atual — e gravar essa copia velha desfaria em silencio o que outra requisicao
 * decidiu no intervalo.
 *
 * ## A tabela de transicoes nao esta aqui
 *
 * Quem responde se uma mudanca e permitida e `VideoState`, e cada transicao
 * abaixo pergunta a ele antes de construir o proximo estado. Copiar a tabela
 * para dentro do agregado daria duas fontes para a mesma verdade, livres para
 * divergir — e a divergencia so apareceria no dia em que um callback fora de
 * ordem passasse por uma e nao pela outra.
 *
 * A guarda e defesa em profundidade, e nao o mecanismo de recusa dos fluxos.
 * Publicar, aplicar um callback e iniciar o processamento consultam
 * `canTransitionTo()` **antes**, porque cada um responde uma coisa diferente ao
 * estado inelegivel — `409` com codigo proprio, rejeicao permanente registrada,
 * encerramento sem efeito. Chegar a excecao daqui significa que alguem esqueceu
 * de perguntar (ver {@see InvalidVideoTransition}).
 *
 * ## Declarado e verificado sao coisas diferentes
 *
 * `declared*` e o que o cliente afirmou na abertura; `verified*` e o que o
 * `HeadObject` encontrou no objeto real. Os dois convivem de proposito: e a
 * comparacao entre eles que a verificacao de conclusao existe para fazer
 * (plan §12.3), e guardar so um lado apagaria a prova.
 *
 * ## O motivo da falha vem do catalogo
 *
 * `fail()` aceita um caso de {@see Failure}, e nao texto. Nao existe sobrecarga
 * que receba string, entao nao ha caminho pelo qual a mensagem de uma excecao,
 * a resposta bruta de um provedor ou o retorno de um driver chegue ao campo que
 * o produtor le — RN-AUT-005 e RF-WHK-011 passam a valer por construcao.
 *
 * PHP puro, como todo o `Domain` (plan §§5.1, 6.2): sem Eloquent, sem facade,
 * sem HTTP, sem SDK.
 */
final class VideoAttempt
{
    /**
     * Os estados em que a tentativa ainda tem futuro, e que por isso impedem um
     * novo envio para a mesma aula (RF-UPL-011).
     *
     * Declarados como a lista do que **bloqueia**, e nao como a negacao de
     * `failed`, porque `ready` tambem nao bloqueia por estar encerrado: ele
     * bloqueia porque substituir um video pronto esta fora do escopo desta
     * entrega (RF-UPL-012). Sao dois motivos diferentes para o mesmo `409`, e a
     * lista explicita os mantem visiveis.
     */
    private const ATIVOS = [
        VideoState::PENDING,
        VideoState::UPLOADING,
        VideoState::UPLOADED,
        VideoState::PROCESSING,
        VideoState::READY,
    ];

    private function __construct(
        private readonly string $id,
        private readonly string $lessonId,
        private readonly VideoState $state,
        private readonly string $declaredFilename,
        private readonly string $declaredContentType,
        private readonly int $declaredSize,
        private readonly string $storageKey,
        private readonly ?string $multipartUploadId,
        private readonly ?int $verifiedSize,
        private readonly ?string $verifiedContentType,
        private readonly ?string $playbackReference,
        private readonly ?Failure $failure,
    ) {}

    /**
     * Um envio recem-autorizado: `pending`, com o envio em partes ja aberto no
     * armazenamento.
     *
     * O estado nao e parametro, como em `Course` e `Lesson`: ele e resultado da
     * regra (RF-UPL-004). A chave tambem nao e escolhida por quem chama — ela
     * vem de {@see chavePara()}, derivada do proprio identificador da tentativa.
     */
    public static function open(
        string $id,
        string $lessonId,
        string $declaredFilename,
        string $declaredContentType,
        int $declaredSize,
        string $multipartUploadId,
    ): self {
        return new self(
            id: $id,
            lessonId: $lessonId,
            state: VideoState::PENDING,
            declaredFilename: $declaredFilename,
            declaredContentType: $declaredContentType,
            declaredSize: $declaredSize,
            storageKey: self::chavePara($id),
            multipartUploadId: $multipartUploadId,
            verifiedSize: null,
            verifiedContentType: null,
            playbackReference: null,
            failure: null,
        );
    }

    /**
     * Uma tentativa que ja existia.
     *
     * Usada apenas pelo adapter de persistencia. Aqui tudo vem de fora, inclusive
     * a chave: uma tentativa gravada antes de uma eventual mudanca na regra de
     * derivacao precisa voltar com a chave que foi de fato usada no
     * armazenamento, e nao com a que a regra atual produziria.
     */
    public static function reconstitute(
        string $id,
        string $lessonId,
        VideoState $state,
        string $declaredFilename,
        string $declaredContentType,
        int $declaredSize,
        string $storageKey,
        ?string $multipartUploadId,
        ?int $verifiedSize,
        ?string $verifiedContentType,
        ?string $playbackReference,
        ?Failure $failure,
    ): self {
        return new self(
            id: $id,
            lessonId: $lessonId,
            state: $state,
            declaredFilename: $declaredFilename,
            declaredContentType: $declaredContentType,
            declaredSize: $declaredSize,
            storageKey: $storageKey,
            multipartUploadId: $multipartUploadId,
            verifiedSize: $verifiedSize,
            verifiedContentType: $verifiedContentType,
            playbackReference: $playbackReference,
            failure: $failure,
        );
    }

    /**
     * A chave do objeto, derivada do identificador da tentativa.
     *
     * **Nunca do nome do arquivo enviado** (plan §11.3). O nome vem do usuario, e
     * entrada de usuario nao compoe caminho de armazenamento: bastaria um nome
     * com `../` para o objeto de uma tentativa acabar em cima do de outra. O
     * identificador e um UUID gerado pelo servidor, e duas tentativas nunca
     * disputam a mesma chave — o que sustenta a UNIQUE da coluna.
     */
    public static function chavePara(string $id): string
    {
        return 'videos/'.$id.'/original.mp4';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function lessonId(): string
    {
        return $this->lessonId;
    }

    public function state(): VideoState
    {
        return $this->state;
    }

    public function declaredFilename(): string
    {
        return $this->declaredFilename;
    }

    public function declaredContentType(): string
    {
        return $this->declaredContentType;
    }

    public function declaredSize(): int
    {
        return $this->declaredSize;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function multipartUploadId(): ?string
    {
        return $this->multipartUploadId;
    }

    public function verifiedSize(): ?int
    {
        return $this->verifiedSize;
    }

    public function verifiedContentType(): ?string
    {
        return $this->verifiedContentType;
    }

    public function playbackReference(): ?string
    {
        return $this->playbackReference;
    }

    public function failure(): ?Failure
    {
        return $this->failure;
    }

    /**
     * Responde se esta tentativa impede um novo envio para a mesma aula.
     *
     * A pergunta vive no agregado para que os casos de uso nao repitam a lista de
     * estados. Uma lista copiada em dois lugares e como um sexto estado entra
     * so em um deles.
     */
    public function isActive(): bool
    {
        return in_array($this->state, self::ATIVOS, true);
    }

    /**
     * Responde se a aula desta tentativa pode ser publicada por causa dela
     * (RN-PUB-002, RN-PUB-003).
     *
     * As duas condicoes juntas, e nao so o estado: um video em `ready` sem
     * referencia de reproducao publicaria uma aula que ninguem consegue
     * assistir. O callback de sucesso exige a referencia, entao esta combinacao
     * nao deveria existir — e a verificacao continua aqui exatamente porque
     * "nao deveria" nao e garantia.
     */
    public function isPlayable(): bool
    {
        return $this->state === VideoState::READY && $this->playbackReference !== null;
    }

    /**
     * O cliente comecou a transferir: `pending` para `uploading`.
     *
     * Acionada pelo **primeiro** pedido de URL de parte, que e o primeiro sinal
     * observavel de que o navegador esta executando a estrategia que a API
     * entregou (plan §11.3). Os pedidos seguintes nao passam por aqui.
     */
    public function beginUpload(): self
    {
        return $this->transicionar(VideoState::UPLOADING);
    }

    /**
     * O objeto foi verificado no armazenamento: `uploading` para `uploaded`.
     *
     * Recebe o que o `HeadObject` **observou**, e nao o que o cliente declarou.
     * Guardar o valor observado e o que permite, depois, comparar as duas
     * colunas e mostrar em que a verificacao se apoiou (plan §12.3).
     */
    public function markUploaded(int $verifiedSize, string $verifiedContentType): self
    {
        $proxima = $this->transicionar(VideoState::UPLOADED);

        return new self(
            id: $proxima->id,
            lessonId: $proxima->lessonId,
            state: $proxima->state,
            declaredFilename: $proxima->declaredFilename,
            declaredContentType: $proxima->declaredContentType,
            declaredSize: $proxima->declaredSize,
            storageKey: $proxima->storageKey,
            multipartUploadId: $proxima->multipartUploadId,
            verifiedSize: $verifiedSize,
            verifiedContentType: $verifiedContentType,
            playbackReference: null,
            failure: null,
        );
    }

    /**
     * O processamento comecou: `uploaded` para `processing`.
     */
    public function startProcessing(): self
    {
        return $this->transicionar(VideoState::PROCESSING);
    }

    /**
     * O processamento terminou bem: `processing` para `ready`.
     *
     * A referencia de reproducao e obrigatoria na assinatura, e nao anulavel:
     * um `ready` sem ela produziria uma aula publicavel e inassistivel, e o
     * ponto de recusa correto e este — antes de existir, e nao depois, na
     * consulta de quem tentou assistir.
     */
    public function markReady(string $playbackReference): self
    {
        $proxima = $this->transicionar(VideoState::READY);

        return new self(
            id: $proxima->id,
            lessonId: $proxima->lessonId,
            state: $proxima->state,
            declaredFilename: $proxima->declaredFilename,
            declaredContentType: $proxima->declaredContentType,
            declaredSize: $proxima->declaredSize,
            storageKey: $proxima->storageKey,
            multipartUploadId: $proxima->multipartUploadId,
            verifiedSize: $proxima->verifiedSize,
            verifiedContentType: $proxima->verifiedContentType,
            playbackReference: $playbackReference,
            failure: null,
        );
    }

    /**
     * A tentativa terminou mal, com um motivo do catalogo.
     *
     * Dois caminhos chegam aqui e sao muito diferentes entre si: a verificacao de
     * conclusao que **provou** o objeto ausente ou incompativel (RF-UPL-013), e
     * o callback de falha do processamento (RF-WHK-007). Os dois so passam por
     * aqui depois de haver evidencia — nao ha caminho que chegue a `failed` por
     * duvida (plan §12.4).
     *
     * O parametro e um caso de {@see Failure}, e nao uma string. E o que impede,
     * por construcao, que um rastro interno chegue ao campo que o produtor le.
     */
    public function fail(Failure $failure): self
    {
        $proxima = $this->transicionar(VideoState::FAILED);

        return new self(
            id: $proxima->id,
            lessonId: $proxima->lessonId,
            state: $proxima->state,
            declaredFilename: $proxima->declaredFilename,
            declaredContentType: $proxima->declaredContentType,
            declaredSize: $proxima->declaredSize,
            storageKey: $proxima->storageKey,
            multipartUploadId: $proxima->multipartUploadId,
            verifiedSize: $proxima->verifiedSize,
            verifiedContentType: $proxima->verifiedContentType,
            playbackReference: null,
            failure: $failure,
        );
    }

    /**
     * @throws InvalidVideoTransition
     */
    private function transicionar(VideoState $destino): self
    {
        if (! $this->state->canTransitionTo($destino)) {
            throw new InvalidVideoTransition($this->state, $destino);
        }

        return new self(
            id: $this->id,
            lessonId: $this->lessonId,
            state: $destino,
            declaredFilename: $this->declaredFilename,
            declaredContentType: $this->declaredContentType,
            declaredSize: $this->declaredSize,
            storageKey: $this->storageKey,
            multipartUploadId: $this->multipartUploadId,
            verifiedSize: $this->verifiedSize,
            verifiedContentType: $this->verifiedContentType,
            playbackReference: $this->playbackReference,
            failure: $this->failure,
        );
    }
}
