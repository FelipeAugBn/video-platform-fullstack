<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Video\Application\Port\CompletedPart;
use App\Video\Application\Port\Exception\MultipartUploadNotFound;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\Exception\StorageUnavailable;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\StoredObject;
use App\Video\Infrastructure\Storage\S3ObjectStorage;
use Aws\S3\S3Client;
use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use Tests\TestCase;

/**
 * A porta de storage contra o RustFS de verdade.
 *
 * Contra o storage real, e nao contra um dublê, porque nada do que importa aqui
 * seria verdade num substituto: a assinatura v4 e recalculada pelo storage, a
 * montagem de um multipart de duas partes acontece do lado dele, e a distincao
 * entre endereco interno e publico so significa alguma coisa quando existe um
 * servico atendendo nos dois. Este e o teste que substitui os scripts de
 * validacao removidos em T003.
 *
 * ## Como uma URL publica e consumida de dentro da rede
 *
 * As URLs assinadas apontam para `localhost:19000`, que e o endereco do **host**.
 * Dentro do container, `localhost` e o proprio container, e ali nao ha storage
 * nenhum.
 *
 * A saida nao e trocar o host da URL — isso invalidaria a assinatura, e o teste
 * passaria a exercitar uma URL que o navegador nunca receberia. A saida e
 * separar **para onde a conexao vai** de **qual autoridade foi assinada**:
 * conecta-se ao endereco interno preservando, no cabecalho `Host`, a autoridade
 * publica que entrou na string assinada. O storage recalcula a assinatura sobre
 * esse cabecalho, e ela confere.
 *
 * O contrato devolvido pelo adapter continua intocado: os testes de endereco
 * abaixo verificam a URL **como ela sai da porta**, com o host publico real.
 */
final class ObjectStorageTest extends TestCase
{
    /** Minimo do protocolo para toda parte que nao seja a ultima. */
    private const PARTE_MINIMA = 5 * 1024 * 1024;

    private const SEGUNDA_PARTE = 4096;

    private const TIPO = 'video/mp4';

    private ObjectStorage $storage;

    private string $prefixo;

    /** @var list<string> */
    private array $chavesCriadas = [];

    /** @var list<array{string, string}> */
    private array $enviosAbertos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->app->make(ObjectStorage::class);

        // Prefixo exclusivo por execucao: duas rodadas simultaneas da suite nao
        // disputam a mesma chave, e o que sobrar de uma falha e identificavel.
        $this->prefixo = 'testes/objectstorage/'.Uuid::uuid7()->toString();
    }

    /**
     * Limpeza pelo SDK, e nao pela porta.
     *
     * Roda mesmo quando uma assercao falha — e por isso que ela existe aqui, e
     * nao no fim do teste. Excluir objeto e abortar envio **nao** foram
     * acrescentados a porta publica: sao operacoes que nenhuma regra do plano
     * usa, e declara-las so para limpar teste alargaria o contrato por um motivo
     * que nao e de producao.
     */
    protected function tearDown(): void
    {
        $s3 = $this->clienteDeLimpeza();
        $bucket = (string) config('storage.bucket');

        foreach ($this->enviosAbertos as [$chave, $uploadId]) {
            try {
                $s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $chave, 'UploadId' => $uploadId]);
            } catch (\Throwable) {
                // Ja concluido ou ja abortado: nada a desfazer.
            }
        }

        foreach ($this->chavesCriadas as $chave) {
            try {
                $s3->deleteObject(['Bucket' => $bucket, 'Key' => $chave]);
            } catch (\Throwable) {
                // Nunca chegou a existir.
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // O fluxo completo, com duas partes reais
    // -----------------------------------------------------------------------

    public function test_envio_multipart_de_duas_partes_de_ponta_a_ponta(): void
    {
        $chave = $this->prefixo.'/aula.mp4';
        $tentativa = (string) Uuid::uuid7();

        $primeira = $this->bytes(self::PARTE_MINIMA, 'parte-1');
        $segunda = $this->bytes(self::SEGUNDA_PARTE, 'parte-2');
        $completo = $primeira.$segunda;

        // 1. Abertura, pelo adapter. Chave, tipo e metadados sao do servidor.
        $uploadId = $this->storage->createMultipartUpload($chave, self::TIPO, [
            'video-attempt-id' => $tentativa,
        ]);
        $this->registrar($chave, $uploadId);
        $this->assertNotSame('', $uploadId);

        // 2 a 5. Cada parte pela URL assinada, guardando o comprovante como veio.
        $partes = [];

        foreach ([1 => $primeira, 2 => $segunda] as $numero => $conteudo) {
            $url = $this->storage->presignUploadPart(
                $chave,
                $uploadId,
                $numero,
                new DateTimeImmutable('+15 minutes'),
            );

            $resposta = $this->pelaUrlAssinada('PUT', $url, $conteudo);
            $this->assertSame(200, $resposta->getStatusCode(), "parte {$numero}");

            $etag = $resposta->getHeaderLine('ETag');
            $this->assertNotSame('', $etag, "parte {$numero} sem ETag");

            // O comprovante e opaco e vai de volta identico — inclusive as aspas,
            // que fazem parte do valor (plan §12.5).
            $this->assertStringStartsWith('"', $etag);
            $this->assertStringEndsWith('"', $etag);

            $partes[] = new CompletedPart($numero, $etag);
        }

        $this->assertCount(2, $partes);

        // 6. Conclusao, pelo adapter.
        $this->storage->completeMultipartUpload($chave, $uploadId, $partes);

        // 7 e 8. Inspecao: tamanho somado, tipo e metadados conferem com o que
        // foi declarado na abertura (plan §12.3).
        $objeto = $this->storage->inspectObject($chave);

        $this->assertInstanceOf(StoredObject::class, $objeto);
        $this->assertSame($chave, $objeto->key);
        $this->assertSame(self::PARTE_MINIMA + self::SEGUNDA_PARTE, $objeto->size);
        $this->assertSame(strlen($completo), $objeto->size);
        $this->assertSame(self::TIPO, $objeto->contentType);
        $this->assertSame($tentativa, $objeto->metadata['video-attempt-id'] ?? null);

        // 9 a 11. Leitura pela URL assinada, comparada byte a byte.
        $leitura = $this->storage->presignRead($chave, new DateTimeImmutable('+5 minutes'));
        $baixado = $this->pelaUrlAssinada('GET', $leitura);

        $this->assertSame(200, $baixado->getStatusCode());
        $corpo = (string) $baixado->getBody();
        $this->assertSame(strlen($completo), strlen($corpo));
        $this->assertTrue($completo === $corpo, 'o conteudo baixado difere do enviado');

        // 12. Sem assinatura, a mesma leitura e recusada. Sem este controle
        // negativo, o passo anterior nao provaria que a assinatura autorizou —
        // provaria apenas que o objeto e legivel.
        $semAssinatura = $this->pelaUrlAssinada('GET', $this->semQueryString($leitura));
        $this->assertContains($semAssinatura->getStatusCode(), [401, 403], 'leitura sem assinatura foi aceita');
    }

    // -----------------------------------------------------------------------
    // Endereco interno e publico
    // -----------------------------------------------------------------------

    public function test_a_url_de_parte_usa_o_endereco_publico_e_nunca_o_interno(): void
    {
        $chave = $this->prefixo.'/endereco-parte.mp4';
        $uploadId = $this->storage->createMultipartUpload($chave, self::TIPO, ['video-attempt-id' => 'x']);
        $this->registrar($chave, $uploadId);

        $url = $this->storage->presignUploadPart($chave, $uploadId, 1, new DateTimeImmutable('+15 minutes'));

        // Assinar com o host errado produz uma URL que a aplicacao considera
        // valida e o navegador nao alcanca (plan §16.3).
        $this->assertStringStartsWith($this->enderecoPublico(), $url);
        $this->assertStringNotContainsString($this->hostInterno(), $url);
    }

    public function test_a_url_de_leitura_usa_o_endereco_publico_e_nunca_o_interno(): void
    {
        $url = $this->storage->presignRead($this->prefixo.'/qualquer.mp4', new DateTimeImmutable('+5 minutes'));

        $this->assertStringStartsWith($this->enderecoPublico(), $url);
        $this->assertStringNotContainsString($this->hostInterno(), $url);
    }

    public function test_os_dois_enderecos_sao_realmente_distintos(): void
    {
        // O par que sustenta os dois testes acima: se os enderecos coincidissem,
        // eles passariam sem provar nada.
        $this->assertNotSame(
            (string) config('storage.endpoints.public'),
            (string) config('storage.endpoints.internal'),
        );
    }

    // -----------------------------------------------------------------------
    // Falhas, traduzidas
    // -----------------------------------------------------------------------

    public function test_objeto_inexistente_produz_a_excecao_propria(): void
    {
        $this->expectException(ObjectNotFound::class);

        $this->storage->inspectObject($this->prefixo.'/nunca-existiu.mp4');
    }

    public function test_envio_inexistente_produz_a_excecao_propria(): void
    {
        $chave = $this->prefixo.'/envio-fantasma.mp4';

        // Um identificador bem formado que o storage nao conhece — e nao um texto
        // qualquer, que seria recusado como argumento invalido antes de o storage
        // procurar o envio.
        $inexistente = (string) Uuid::uuid7();

        $this->expectException(MultipartUploadNotFound::class);

        $this->storage->completeMultipartUpload($chave, $inexistente, [
            new CompletedPart(1, '"0000000000000000000000000000000a"'),
        ]);
    }

    public function test_storage_indisponivel_produz_a_excecao_transitoria(): void
    {
        // Porta onde nao ha ninguem atendendo: a conexao e recusada e a resposta
        // nunca chega. Nao se sabe se a operacao foi aplicada, e e essa duvida
        // que separa o caso transitorio de uma recusa (plan §12.4).
        $this->expectException(StorageUnavailable::class);

        $this->storageIndisponivel()->inspectObject($this->prefixo.'/qualquer.mp4');
    }

    public function test_validade_acima_do_limite_da_assinatura_nao_deixa_escapar_excecao_externa(): void
    {
        // A falha acontece **antes** de qualquer rede: a assinatura recusa uma
        // validade acima do limite dela, e a recusa vem como excecao de argumento
        // do proprio SDK. Era por ai que a fronteira estava aberta — as duas
        // operacoes de assinatura montavam o comando e assinavam fora da traducao.
        $expiracao = new DateTimeImmutable('+8 days');

        $tentativas = [
            'presignUploadPart' => fn (): string => $this->storage->presignUploadPart(
                $this->prefixo.'/validade.mp4',
                (string) Uuid::uuid7(),
                1,
                $expiracao,
            ),
            'presignRead' => fn (): string => $this->storage->presignRead(
                $this->prefixo.'/validade.mp4',
                $expiracao,
            ),
        ];

        foreach ($tentativas as $operacao => $tentativa) {
            try {
                $tentativa();
                $this->fail("{$operacao} deveria ter falhado com validade acima do limite");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(StorageFailure::class, $e, $operacao);

                // A excecao original do SDK nao chega nem como tipo, nem
                // encadeada: `InvalidArgumentException` e uma classe da
                // linguagem que o SDK usa para recusar argumento, e deixa-la
                // passar seria a mesma brecha por outro nome.
                $this->assertNotInstanceOf(\InvalidArgumentException::class, $e, $operacao);
                $this->assertNull($e->getPrevious(), $operacao);
                $this->assertStringStartsWith('App\\Video\\Application\\Port\\Exception\\', $e::class);
            }
        }
    }

    public function test_a_traducao_nao_engole_defeito_de_programacao(): void
    {
        // O par negativo da correcao acima: a fronteira captura uma lista fechada
        // de excecoes esperadas, e nao `Throwable`. Um `catch` generico
        // transformaria um `TypeError` deste arquivo em "o armazenamento nao
        // respondeu", e a causa real sumiria.
        $adapter = new \ReflectionClass(S3ObjectStorage::class);
        $fonte = (string) file_get_contents((string) $adapter->getFileName());

        $this->assertStringNotContainsString('catch (\Throwable', $fonte);
        $this->assertStringNotContainsString('catch (Throwable', $fonte);
    }

    public function test_nenhuma_excecao_externa_atravessa_a_porta(): void
    {
        $absurdo = new DateTimeImmutable('+8 days');

        // Uma tentativa por operacao publica da porta, cada uma por um caminho de
        // falha diferente: resposta de erro do armazenamento, ausencia, falta de
        // resposta e recusa da propria assinatura.
        $tentativas = [
            'createMultipartUpload' => fn () => $this->storageIndisponivel()
                ->createMultipartUpload($this->prefixo.'/ausente.mp4', self::TIPO, []),
            'presignUploadPart' => fn () => $this->storage->presignUploadPart(
                $this->prefixo.'/ausente.mp4',
                (string) Uuid::uuid7(),
                1,
                $absurdo,
            ),
            'completeMultipartUpload' => fn () => $this->storage->completeMultipartUpload(
                $this->prefixo.'/ausente.mp4',
                (string) Uuid::uuid7(),
                [new CompletedPart(1, '"a"')],
            ),
            'inspectObject' => fn () => $this->storage->inspectObject($this->prefixo.'/ausente.mp4'),
            'presignRead' => fn () => $this->storage->presignRead($this->prefixo.'/ausente.mp4', $absurdo),
        ];

        $this->assertCount(5, $tentativas, 'as cinco operacoes da porta precisam estar cobertas');

        foreach ($tentativas as $operacao => $tentativa) {
            try {
                $tentativa();
                $this->fail("{$operacao} deveria ter falhado");
            } catch (\Throwable $e) {
                // A afirmacao central desta tarefa: o que vem de fora fica do
                // lado de fora.
                $this->assertInstanceOf(StorageFailure::class, $e, $operacao);
                $this->assertStringStartsWith('App\\Video\\Application\\Port\\Exception\\', $e::class);
                $this->assertNull($e->getPrevious(), "{$operacao}: a excecao original nao pode vir encadeada");
            }
        }
    }

    public function test_as_falhas_nao_expoem_vocabulario_do_protocolo(): void
    {
        // A porta fala a lingua do problema. Se o codigo do protocolo chegasse
        // ate aqui, o caso de uso teria como examina-lo — e no dia em que uma
        // decisao dependesse disso, a regra de negocio estaria escrita no
        // vocabulario de um provedor especifico.
        $codigos = ['NoSuchUpload', 'NoSuchKey', 'NotFound', 'AccessDenied', 'SignatureDoesNotMatch', 'InvalidPart'];

        $falhas = [];

        try {
            $this->storage->inspectObject($this->prefixo.'/ausente.mp4');
        } catch (StorageFailure $e) {
            $falhas[] = $e;
        }

        try {
            $this->storage->completeMultipartUpload(
                $this->prefixo.'/ausente.mp4',
                (string) Uuid::uuid7(),
                [new CompletedPart(1, '"a"')],
            );
        } catch (StorageFailure $e) {
            $falhas[] = $e;
        }

        $this->assertCount(2, $falhas);

        foreach ($falhas as $falha) {
            foreach ($codigos as $codigo) {
                $this->assertStringNotContainsString($codigo, $falha->getMessage(), $codigo);
            }

            // Tambem nao ha propriedade guardando o codigo. A varredura ignora o
            // que vem de `Exception` — `code` e `message` sao da linguagem, e nao
            // declaracoes destas classes.
            $propriedades = array_values(array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                array_filter(
                    (new \ReflectionClass($falha))->getProperties(),
                    static fn (\ReflectionProperty $p): bool => str_starts_with(
                        $p->getDeclaringClass()->getName(),
                        'App\\Video\\',
                    ),
                ),
            ));

            $this->assertSame(['operation', 'key'], $propriedades);
        }
    }

    public function test_recusa_de_acesso_nao_condena_o_envio(): void
    {
        // Uma credencial errada nao prova nada sobre o arquivo do produtor.
        // Classifica-la como recusa definitiva faria um ambiente mal configurado
        // marcar videos legitimos como defeituosos — por isso ela cai no lado
        // seguro, que preserva o estado (plan §12.4).
        //
        // Credenciais unicas por execucao: a assercao de que elas nao aparecem na
        // mensagem so vale se o valor procurado for improvavel de existir ali por
        // acaso.
        $credencial = 'chave-invalida-'.Uuid::uuid7()->toString();
        $segredo = 'segredo-invalido-'.Uuid::uuid7()->toString();

        $configuracao = (array) config('storage');
        $configuracao['credentials'] = ['key' => $credencial, 'secret' => $segredo];

        $comCredencialErrada = S3ObjectStorage::fromConfig($configuracao);

        // `Throwable`, e nao `StorageFailure`: capturar o tipo esperado faria uma
        // excecao externa escapar como erro em vez de reprovar por classe errada,
        // e o motivo da reprovacao ficaria menos claro. A afirmacao de classe vem
        // logo abaixo, e e ela que fecha o caso.
        $capturada = null;

        try {
            $comCredencialErrada->inspectObject($this->prefixo.'/qualquer.mp4');
        } catch (\Throwable $e) {
            $capturada = $e;
        }

        $this->assertNotNull($capturada, 'a credencial invalida precisa produzir falha');

        // Classe **exata**, e nao `assertInstanceOf`: as quatro falhas descendem
        // de `StorageFailure`, entao uma afirmacao de parentesco passaria tambem
        // com `StorageRejected` — que e justamente a classificacao que este teste
        // existe para proibir.
        $this->assertSame(
            StorageUnavailable::class,
            $capturada::class,
            'recusa de acesso nao pode condenar o envio',
        );

        $this->assertNull($capturada->getPrevious(), 'a excecao original nao pode vir encadeada');

        foreach ([$credencial, $segredo] as $valor) {
            $this->assertStringNotContainsString($valor, $capturada->getMessage());
        }
    }

    public function test_as_mensagens_de_falha_nao_vazam_credencial_nem_url_assinada(): void
    {
        $segredos = [
            (string) config('storage.credentials.key'),
            (string) config('storage.credentials.secret'),
        ];

        try {
            $this->storage->inspectObject($this->prefixo.'/ausente.mp4');
            $this->fail('deveria ter falhado');
        } catch (ObjectNotFound $e) {
            $mensagem = $e->getMessage();

            foreach ($segredos as $segredo) {
                $this->assertNotSame('', $segredo);
                $this->assertStringNotContainsString($segredo, $mensagem);
            }

            // Nem a query string de autorizacao, nem endereco, nem corpo de
            // resposta: a mensagem carrega **somente** a operacao da porta e a
            // chave do objeto — sem codigo do provedor, que fica no adapter.
            foreach (['X-Amz', 'Signature', 'http://', 'https://', '<?xml'] as $vazamento) {
                $this->assertStringNotContainsString($vazamento, $mensagem, $vazamento);
            }
        }
    }

    // -----------------------------------------------------------------------
    // A porta nao conhece o SDK
    // -----------------------------------------------------------------------

    public function test_a_porta_e_os_dtos_nao_importam_framework_nem_sdk(): void
    {
        $arquivos = glob(dirname(__DIR__, 3).'/app/Video/Application/Port/{,Exception/}*.php', GLOB_BRACE) ?: [];

        $this->assertGreaterThanOrEqual(7, count($arquivos), 'a varredura nao encontrou os arquivos da porta');

        $violacoes = [];

        foreach ($arquivos as $arquivo) {
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($arquivo), $encontrados);

            foreach ($encontrados[1] as $importado) {
                $raiz = explode('\\', $importado)[0];

                if (in_array($raiz, ['Aws', 'GuzzleHttp', 'Psr', 'Illuminate', 'Symfony', 'Laravel'], true)) {
                    $violacoes[] = basename($arquivo).' importa '.$importado;
                }
            }
        }

        $this->assertSame([], $violacoes);
    }

    public function test_a_assinatura_da_porta_nao_menciona_tipos_externos(): void
    {
        // A verificacao do tipo declarado, e nao apenas das importacoes: um metodo
        // pode nao importar nada e ainda assim declarar um tipo totalmente
        // qualificado do SDK.
        foreach ((new ReflectionClass(ObjectStorage::class))->getMethods() as $metodo) {
            $tipos = [(string) $metodo->getReturnType()];

            foreach ($metodo->getParameters() as $parametro) {
                $tipos[] = (string) $parametro->getType();
            }

            foreach ($tipos as $tipo) {
                foreach (['Aws', 'GuzzleHttp', 'Psr', 'Illuminate'] as $externo) {
                    $this->assertStringNotContainsString($externo, $tipo, $metodo->getName());
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Consome uma URL assinada de dentro da rede do Compose.
     *
     * A conexao vai para o endereco interno; o cabecalho `Host` preserva a
     * autoridade publica que participou da assinatura. A URL recebida como
     * parametro **nao** e alterada no contrato — so o destino da conexao muda.
     */
    private function pelaUrlAssinada(string $metodo, string $url, string $corpo = ''): ResponseInterface
    {
        $publico = (string) config('storage.endpoints.public');
        $interno = (string) config('storage.endpoints.internal');

        $this->assertStringStartsWith($publico, $url, 'a URL assinada precisa apontar para o endereco publico');

        $destino = $interno.substr($url, strlen($publico));

        return (new HttpClient(['http_errors' => false, 'timeout' => 60]))->request($metodo, $destino, [
            'headers' => ['Host' => (string) parse_url($publico, PHP_URL_HOST).':'.(string) parse_url($publico, PHP_URL_PORT)],
            'body' => $corpo,
        ]);
    }

    private function semQueryString(string $url): string
    {
        $posicao = strpos($url, '?');

        return $posicao === false ? $url : substr($url, 0, $posicao);
    }

    /**
     * Conteudo deterministico: a mesma semente produz sempre os mesmos bytes.
     *
     * Um bloco repetido de um hash, e nao um caractere repetido: com um unico
     * caractere, um erro de ordem ou de deslocamento entre as partes produziria
     * exatamente o mesmo arquivo, e a comparacao byte a byte nao pegaria nada.
     */
    private function bytes(int $tamanho, string $semente): string
    {
        $bloco = hash('sha256', $semente, true);

        return substr(str_repeat($bloco, (int) ceil($tamanho / strlen($bloco))), 0, $tamanho);
    }

    private function registrar(string $chave, string $uploadId): void
    {
        $this->chavesCriadas[] = $chave;
        $this->enviosAbertos[] = [$chave, $uploadId];
    }

    private function enderecoPublico(): string
    {
        return (string) config('storage.endpoints.public');
    }

    private function hostInterno(): string
    {
        return (string) parse_url((string) config('storage.endpoints.internal'), PHP_URL_HOST);
    }

    /**
     * O mesmo adapter, apontado para uma porta onde nao ha ninguem atendendo.
     */
    private function storageIndisponivel(): ObjectStorage
    {
        $configuracao = (array) config('storage');
        $configuracao['endpoints']['internal'] = 'http://'.$this->hostInterno().':9';
        $configuracao['timeouts'] = ['connect' => 1, 'request' => 2];

        return S3ObjectStorage::fromConfig($configuracao);
    }

    private function clienteDeLimpeza(): S3Client
    {
        return new S3Client([
            'version' => '2006-03-01',
            'region' => (string) config('storage.region'),
            'endpoint' => (string) config('storage.endpoints.internal'),
            'use_path_style_endpoint' => true,
            'signature_version' => 'v4',
            'credentials' => (array) config('storage.credentials'),
        ]);
    }
}
