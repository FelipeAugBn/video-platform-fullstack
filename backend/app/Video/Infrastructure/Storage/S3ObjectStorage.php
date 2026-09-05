<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Storage;

use App\Video\Application\Port\CompletedPart;
use App\Video\Application\Port\Exception\MultipartUploadNotFound;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\Exception\StorageRejected;
use App\Video\Application\Port\Exception\StorageUnavailable;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\StoredObject;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Exception\InvalidRegionException;
use Aws\Exception\UnresolvedApiException;
use Aws\Exception\UnresolvedEndpointException;
use Aws\Exception\UnresolvedSignatureException;
use Aws\S3\S3Client;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A porta de armazenamento, sobre o protocolo S3.
 *
 * Chama-se `S3ObjectStorage`, e nao `RustFsObjectStorage`, porque nada aqui e
 * especifico do RustFS: o adapter foi desenhado para provedores compativeis com
 * S3 e pode ser reaproveitado por outros.
 *
 * **Reaproveitavel nao quer dizer intercambiavel.** Trocar de provedor exige
 * configurar endereco, credenciais, regiao e forma de enderecamento, e exige
 * mais do que configuracao: provedores compativeis divergem em comportamento —
 * formato e calculo do comprovante de parte, limites de tamanho, codigos de erro
 * devolvidos, suporte a assinatura. Nada disso se descobre lendo documentacao; a
 * troca so esta feita quando o teste de integracao desta tarefa passa contra o
 * provedor novo.
 *
 * Este e o unico arquivo da area de video que conhece o SDK. Toda a traducao
 * vive aqui, nos dois sentidos: comando do SDK para operacao do problema, e
 * excecao do SDK para o catalogo fechado de falhas da porta.
 *
 * ## Dois clientes, e por que nao um
 *
 * O armazenamento tem dois enderecos (plan §16.3), e eles nao sao
 * intercambiaveis:
 *
 *   `interno`  faz as chamadas de API — abrir, concluir, inspecionar. E o unico
 *              que abre conexao de rede.
 *   `publico`  **nunca** faz chamada de rede. Existe so para que o host publico
 *              participe da assinatura das URLs entregues ao navegador.
 *
 * Um cliente so nao resolve. A assinatura v4 inclui o host na string assinada,
 * entao assinar com o endereco interno produz uma URL que o navegador nao
 * alcanca, e trocar o host depois de assinar invalida a assinatura. Sao dois
 * clientes porque sao duas autoridades diferentes, e nao por redundancia.
 *
 * Gerar uma URL assinada nao envia nada: o SDK monta e assina localmente. E por
 * isso que o cliente publico pode apontar para um endereco que o proprio
 * processo nao alcanca.
 *
 * ## O que este arquivo nao decide
 *
 * Nao decide validade de URL — o instante chega pronto. Nao decide o que fazer
 * com uma falha: classifica e devolve. As duas coisas sao regra de negocio, e
 * pertencem aos casos de uso (plan §§11.2, 12.4, 14.2).
 */
final class S3ObjectStorage implements ObjectStorage
{
    /**
     * Recusas reconhecidas e permanentes **sobre o conteudo ou a forma do
     * envio**.
     *
     * Lista fechada, e curta de proposito. E ela que autoriza a unica
     * classificacao capaz de condenar um envio; tudo o que nao estiver aqui cai
     * no lado seguro. Um "tudo o mais e recusa" colocaria credencial errada e
     * permissao ausente no mesmo balde, e uma configuracao equivocada passaria a
     * marcar videos legitimos como defeituosos.
     */
    private const RECUSAS_DE_CONTEUDO = [
        'InvalidPart',
        'InvalidPartOrder',
        'InvalidPartNumber',
        'EntityTooSmall',
    ];

    public function __construct(
        private readonly S3Client $interno,
        private readonly S3Client $publico,
        private readonly string $bucket,
    ) {}

    /**
     * Monta o adapter a partir da configuracao de `config/storage.php`.
     *
     * Os dois clientes recebem **as mesmas** credenciais, regiao, versao de
     * assinatura e enderecamento por caminho. So o endpoint difere: qualquer
     * outra diferenca entre eles faria a URL assinada divergir do que o
     * armazenamento espera validar, e o sintoma seria uma assinatura recusada sem
     * causa obvia.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $comum = [
            // Versao da API declarada explicitamente, para que uma atualizacao do
            // SDK nao mude o contrato usado aqui.
            'version' => '2006-03-01',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            // Enderecamento por caminho: `http://host/bucket/chave`. O padrao do
            // SDK e por subdominio — `http://bucket.host/chave` —, que exige DNS
            // curinga e nao funciona contra um endpoint local.
            'use_path_style_endpoint' => true,
            'signature_version' => 'v4',
            'credentials' => [
                'key' => (string) ($config['credentials']['key'] ?? ''),
                'secret' => (string) ($config['credentials']['secret'] ?? ''),
            ],
            'http' => [
                'connect_timeout' => (int) ($config['timeouts']['connect'] ?? 5),
                'timeout' => (int) ($config['timeouts']['request'] ?? 30),
            ],
        ];

        return new self(
            interno: new S3Client($comum + ['endpoint' => (string) ($config['endpoints']['internal'] ?? '')]),
            publico: new S3Client($comum + ['endpoint' => (string) ($config['endpoints']['public'] ?? '')]),
            bucket: (string) ($config['bucket'] ?? ''),
        );
    }

    public function createMultipartUpload(string $key, string $contentType, array $metadata): string
    {
        $resultado = $this->executar('createMultipartUpload', $key, fn (): mixed => $this->interno->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
            // Metadados controlados pelo servidor. E o vinculo que a inspecao da
            // conclusao confere (plan §12.3): o cliente nao os escolhe e nao os
            // envia.
            'Metadata' => $metadata,
        ]));

        return (string) $resultado['UploadId'];
    }

    public function presignUploadPart(
        string $key,
        string $uploadId,
        int $partNumber,
        DateTimeImmutable $expiresAt,
    ): string {
        // A montagem do comando entra na protecao junto com a assinatura. Nao e
        // zelo excessivo: `getCommand` valida os argumentos, e `createPresignedRequest`
        // recusa uma validade fora do limite da assinatura — as duas falham com
        // excecoes do SDK, e nenhuma delas pode atravessar a porta.
        return $this->executar('presignUploadPart', $key, function () use ($key, $uploadId, $partNumber, $expiresAt): string {
            // Cliente **publico**: e o host dele que entra na string assinada.
            $comando = $this->publico->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);

            return $this->assinar($comando, $expiresAt);
        });
    }

    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $this->executar('completeMultipartUpload', $key, fn (): mixed => $this->interno->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(
                    // O comprovante vai como veio. Nao se removem aspas e nao se
                    // interpreta o valor: ele foi emitido pelo armazenamento, e a
                    // conclusao so e aceita se voltar identico (plan §12.5).
                    static fn (CompletedPart $parte): array => [
                        'PartNumber' => $parte->partNumber,
                        'ETag' => $parte->etag,
                    ],
                    array_values($parts),
                ),
            ],
        ]));
    }

    public function inspectObject(string $key): StoredObject
    {
        $resultado = $this->executar(
            'inspectObject',
            $key,
            fn (): mixed => $this->interno->headObject(['Bucket' => $this->bucket, 'Key' => $key]),
            // Uma consulta de metadados nao devolve corpo, entao o armazenamento
            // nao tem onde escrever o motivo: a ausencia chega como `404` seco. E
            // por isso que o `404` so significa "objeto ausente" **nesta**
            // operacao — em qualquer outra, ele e um motivo desconhecido.
            quatroCentosEQuatroEAusencia: true,
        );

        /** @var array<string, string> $metadados */
        $metadados = $resultado['Metadata'] ?? [];

        return new StoredObject(
            key: $key,
            size: (int) ($resultado['ContentLength'] ?? 0),
            contentType: (string) ($resultado['ContentType'] ?? ''),
            metadata: $metadados,
        );
    }

    public function presignRead(string $key, DateTimeImmutable $expiresAt): string
    {
        return $this->executar('presignRead', $key, function () use ($key, $expiresAt): string {
            $comando = $this->publico->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return $this->assinar($comando, $expiresAt);
        });
    }

    /**
     * Executa uma operacao do SDK e converte **qualquer** falha esperada dele
     * numa falha da porta.
     *
     * E o unico ponto de saida das cinco operacoes publicas. Concentrar aqui
     * significa que uma operacao nova so escapa da traducao se deixar de usar
     * este metodo — o que e visivel em revisao, ao contrario de um `try` que
     * alguem esqueceu de escrever.
     *
     * **A lista de excecoes capturadas e fechada, e nao um `Throwable`.** Um
     * `catch` generico engoliria `TypeError`, `Error` e qualquer defeito de
     * programacao deste arquivo, transformando um bug em "o armazenamento nao
     * respondeu" — e a causa real sumiria. As classes abaixo sao as que o SDK
     * declara para falha de chamada, de validacao de argumento, de credencial e
     * de resolucao de endpoint, regiao, API e assinatura.
     *
     * @template T
     *
     * @param  callable(): T  $operacao
     * @return T
     *
     * @throws StorageFailure
     */
    private function executar(
        string $nome,
        string $key,
        callable $operacao,
        bool $quatroCentosEQuatroEAusencia = false,
    ): mixed {
        try {
            return $operacao();
        } catch (AwsException $e) {
            throw $this->traduzirResposta($e, $nome, $key, $quatroCentosEQuatroEAusencia);
        } catch (
            CredentialsException
            |UnresolvedEndpointException
            |UnresolvedApiException
            |UnresolvedSignatureException
            |InvalidRegionException
            |InvalidArgumentException
        ) {
            // Nenhuma dessas chega a falar com o armazenamento: sao problemas de
            // credencial, de configuracao ou de argumento, detectados antes do
            // envio. Como nao houve resposta, nao ha evidencia sobre o objeto —
            // e a classificacao segura preserva o estado.
            throw StorageUnavailable::em($nome, $key);
        }
    }

    /**
     * Assina um comando para o endereco publico e devolve a URL.
     *
     * Sem `try` proprio: quem chama ja esta dentro de {@see executar()}, e as
     * falhas de assinatura — validade fora do limite, credencial ausente — sao
     * capturadas la.
     */
    private function assinar(CommandInterface $comando, DateTimeImmutable $expiresAt): string
    {
        return (string) $this->publico->createPresignedRequest($comando, $expiresAt)->getUri();
    }

    /**
     * Converte uma resposta de erro do armazenamento numa das quatro situacoes da
     * porta.
     *
     * A ordem das verificacoes e o conteudo da regra:
     *
     *   1. envio inexistente **antes** da ausencia do objeto, porque ele chega
     *      com o mesmo status — invertido, um envio ja consumido viraria "objeto
     *      inexistente", e a decisao seguinte perderia a distincao (plan §12.4);
     *   2. ausencia do objeto, apenas onde o status tem esse significado;
     *   3. recusa reconhecida sobre o conteudo — a unica classificacao capaz de
     *      condenar o envio, e por isso restrita a uma lista fechada;
     *   4. **todo o resto e falta de evidencia.** Sem resposta, pedido de nova
     *      tentativa, falha do servidor, recusa de acesso, credencial,
     *      assinatura, configuracao e qualquer motivo desconhecido caem aqui.
     *
     * O quarto item e o que mudou de lado. Antes, um `4xx` desconhecido virava
     * recusa definitiva; mas `AccessDenied` e assinatura invalida nao provam nada
     * sobre o arquivo do produtor, e trata-los como recusa faria um ambiente mal
     * configurado marcar videos legitimos como defeituosos.
     *
     * Nada da excecao original e propagado: nem a mensagem, nem o corpo, nem o
     * codigo, nem o encadeamento. O codigo do protocolo e usado **aqui**, para
     * decidir, e nao atravessa a porta (ver {@see StorageFailure}).
     */
    private function traduzirResposta(
        AwsException $e,
        string $operacao,
        string $key,
        bool $quatroCentosEQuatroEAusencia,
    ): StorageFailure {
        $codigo = (string) ($e->getAwsErrorCode() ?? '');
        $status = $e->getStatusCode();

        if ($codigo === 'NoSuchUpload') {
            return MultipartUploadNotFound::em($operacao, $key);
        }

        $ausente = in_array($codigo, ['NoSuchKey', 'NotFound'], true)
            || ($quatroCentosEQuatroEAusencia && $status === 404);

        if ($ausente) {
            return ObjectNotFound::em($operacao, $key);
        }

        if (in_array($codigo, self::RECUSAS_DE_CONTEUDO, true)) {
            return StorageRejected::em($operacao, $key);
        }

        return StorageUnavailable::em($operacao, $key);
    }
}
