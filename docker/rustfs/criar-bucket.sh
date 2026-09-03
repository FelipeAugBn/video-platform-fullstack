#!/bin/sh
#
# Bootstrap do storage de objetos: garante o bucket e aplica a politica de CORS.
#
# Executado pelo servico `setup`, dentro da imagem PHP do backend, antes de a
# aplicacao subir. Nao depende de nenhuma ferramenta externa: usa o SDK S3 para
# PHP, a mesma dependencia que o adapter de storage da aplicacao utiliza.
#
# Idempotente: pode rodar a cada subida do ambiente.
#
# Entrada, por variavel de ambiente:
#   STORAGE_ENDPOINT_INTERNAL  endereco interno do storage na rede do Compose
#   STORAGE_ACCESS_KEY         credencial de acesso
#   STORAGE_SECRET_KEY         credencial secreta
#   STORAGE_REGION             regiao declarada na assinatura
#   STORAGE_BUCKET             nome do bucket
#   APP_ROOT                   raiz da aplicacao PHP (padrao: /app)
#
# A politica de CORS vem de cors.json, ao lado deste script. Ele nao carrega
# copia da politica: aplica e confere exatamente o que esse arquivo declara.

set -eu

DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
CORS_FILE="$DIR/cors.json"
APP_ROOT="${APP_ROOT:-/app}"
AUTOLOAD="$APP_ROOT/vendor/autoload.php"

erro() {
  echo "criar-bucket: $*" >&2
  exit 1
}

[ -f "$CORS_FILE" ] \
  || erro "politica de CORS nao encontrada em $CORS_FILE"

for nome in STORAGE_ENDPOINT_INTERNAL STORAGE_ACCESS_KEY STORAGE_SECRET_KEY \
            STORAGE_REGION STORAGE_BUCKET; do
  eval "valor=\${$nome:-}"
  [ -n "$valor" ] \
    || erro "variavel de ambiente $nome ausente ou vazia.
  Necessarias: STORAGE_ENDPOINT_INTERNAL, STORAGE_ACCESS_KEY,
  STORAGE_SECRET_KEY, STORAGE_REGION, STORAGE_BUCKET."
done

[ -f "$AUTOLOAD" ] \
  || erro "autoload do Composer nao encontrado em $AUTOLOAD.
  Defina APP_ROOT apontando para a raiz da aplicacao PHP, ou instale as
  dependencias antes de executar este script."

echo "criar-bucket: bucket '$STORAGE_BUCKET' em $STORAGE_ENDPOINT_INTERNAL"

CORS_FILE="$CORS_FILE" AUTOLOAD="$AUTOLOAD" php <<'PHP'
<?php

declare(strict_types=1);

require getenv('AUTOLOAD');

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

$endpoint = getenv('STORAGE_ENDPOINT_INTERNAL');
$chave    = getenv('STORAGE_ACCESS_KEY');
$segredo  = getenv('STORAGE_SECRET_KEY');
$regiao   = getenv('STORAGE_REGION');
$bucket   = getenv('STORAGE_BUCKET');
$corsFile = getenv('CORS_FILE');

/**
 * Remove credenciais de qualquer texto antes de imprimir. Mensagens de erro do
 * servico podem ecoar a chave de acesso — nada de credencial chega ao log.
 */
$seguro = static function (string $texto) use ($chave, $segredo): string {
    return str_replace(
        [$chave, $segredo],
        ['<access-key>', '<secret-key>'],
        $texto
    );
};

$abortar = static function (string $mensagem) use ($seguro): never {
    fwrite(STDERR, 'criar-bucket: ' . $seguro($mensagem) . PHP_EOL);
    exit(1);
};

$conteudo = file_get_contents($corsFile);
if ($conteudo === false) {
    $abortar("nao foi possivel ler {$corsFile}");
}

try {
    $cors = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    $abortar("politica de CORS invalida em {$corsFile}: " . $e->getMessage());
}

if (! isset($cors['CORSRules'][0]) || ! is_array($cors['CORSRules'][0])) {
    $abortar("politica de CORS sem regra em {$corsFile}");
}

$s3 = new S3Client([
    // Versao da API do S3 declarada explicitamente, para que uma atualizacao do
    // SDK nao mude o contrato usado aqui.
    'version'                 => '2006-03-01',
    'region'                  => $regiao,
    'endpoint'                => $endpoint,
    'use_path_style_endpoint' => true,
    'signature_version'       => 'v4',
    // Credenciais explicitas: nada de cadeia de provedores, arquivo de perfil
    // ou metadados de instancia. O script nao le nem escreve configuracao no
    // diretorio pessoal do container.
    'credentials'             => ['key' => $chave, 'secret' => $segredo],
]);

// ---------------------------------------------------------------------------
// 1. Bucket. Existir e ser acessivel ja basta; ausencia leva a criacao.
//    Qualquer outra falha interrompe: tratar erro generico como "bucket
//    ausente" mascararia credencial errada e storage fora do ar.
// ---------------------------------------------------------------------------
$precisaCriar = false;

try {
    $s3->headBucket(['Bucket' => $bucket]);
    echo "criar-bucket: bucket ja existe e esta acessivel" . PHP_EOL;
} catch (AwsException $e) {
    $status = $e->getStatusCode();
    $codigo = $e->getAwsErrorCode() ?? '';

    if ($status === 404 || in_array($codigo, ['NoSuchBucket', 'NotFound'], true)) {
        $precisaCriar = true;
    } elseif ($status === 403 || $codigo === 'AccessDenied') {
        $abortar(
            "sem permissao sobre o bucket '{$bucket}'. Ele pode pertencer a "
            . 'outro dono, ou as credenciais nao autorizam a operacao.'
        );
    } else {
        $abortar(
            "falha ao consultar o bucket '{$bucket}' "
            . '(codigo ' . ($codigo !== '' ? $codigo : 'desconhecido')
            . ', status ' . ($status ?? 'sem resposta') . '): ' . $e->getAwsErrorMessage()
        );
    }
}

if ($precisaCriar) {
    try {
        $s3->createBucket(['Bucket' => $bucket]);
        echo "criar-bucket: bucket criado" . PHP_EOL;
    } catch (AwsException $e) {
        $codigo = $e->getAwsErrorCode() ?? '';

        if ($codigo === 'BucketAlreadyOwnedByYou') {
            // Outra execucao criou entre a consulta e a criacao.
            echo "criar-bucket: bucket criado por execucao concorrente" . PHP_EOL;
        } elseif ($codigo === 'BucketAlreadyExists') {
            $abortar("o nome '{$bucket}' ja pertence a outro dono");
        } else {
            $abortar(
                "falha ao criar o bucket '{$bucket}' "
                . '(codigo ' . ($codigo !== '' ? $codigo : 'desconhecido')
                . '): ' . $e->getAwsErrorMessage()
            );
        }
    }
}

// ---------------------------------------------------------------------------
// 2. CORS. Aplicado em toda execucao, inclusive quando o bucket ja existia:
//    a politica pode ter mudado no repositorio desde a ultima subida.
// ---------------------------------------------------------------------------
try {
    $s3->putBucketCors([
        'Bucket'               => $bucket,
        'CORSConfiguration'    => $cors,
    ]);
} catch (AwsException $e) {
    $abortar(
        'falha ao aplicar a politica de CORS '
        . '(codigo ' . ($e->getAwsErrorCode() ?? 'desconhecido') . '): '
        . $e->getAwsErrorMessage()
    );
}

// ---------------------------------------------------------------------------
// 3. Conferencia. Aplicar sem reler nao prova nada: um servico pode aceitar a
//    chamada e guardar outra coisa. O esperado vem do proprio cors.json.
// ---------------------------------------------------------------------------
try {
    $aplicado = $s3->getBucketCors(['Bucket' => $bucket])['CORSRules'] ?? [];
} catch (AwsException $e) {
    $abortar(
        'falha ao reler a politica de CORS '
        . '(codigo ' . ($e->getAwsErrorCode() ?? 'desconhecido') . '): '
        . $e->getAwsErrorMessage()
    );
}

if ($aplicado === []) {
    $abortar('o storage aceitou a politica de CORS mas nao devolveu nenhuma regra');
}

$esperado = $cors['CORSRules'][0];
$regra    = $aplicado[0];
$faltando = [];

foreach ($esperado['AllowedOrigins'] ?? [] as $origem) {
    if (! in_array($origem, $regra['AllowedOrigins'] ?? [], true)) {
        $faltando[] = "origem {$origem}";
    }
}

foreach ($esperado['AllowedMethods'] ?? [] as $metodo) {
    if (! in_array($metodo, $regra['AllowedMethods'] ?? [], true)) {
        $faltando[] = "metodo {$metodo}";
    }
}

// Sem o ETag exposto, o navegador nao consegue ler o identificador de cada
// parte, e a conclusao do envio em partes fica impossivel do lado do cliente.
foreach ($esperado['ExposeHeaders'] ?? [] as $cabecalho) {
    if (! in_array($cabecalho, $regra['ExposeHeaders'] ?? [], true)) {
        $faltando[] = "cabecalho exposto {$cabecalho}";
    }
}

if (isset($esperado['MaxAgeSeconds'])
    && ($regra['MaxAgeSeconds'] ?? null) !== $esperado['MaxAgeSeconds']) {
    $faltando[] = sprintf(
        'cache do preflight %d (o storage devolveu %s)',
        $esperado['MaxAgeSeconds'],
        var_export($regra['MaxAgeSeconds'] ?? null, true)
    );
}

if ($faltando !== []) {
    $abortar(
        'a politica de CORS relida do storage nao confere com ' . $corsFile
        . '. Ausente ou divergente: ' . implode('; ', $faltando)
    );
}

printf(
    "criar-bucket: CORS confirmado (origens %s, metodos %s, expoe %s, cache %ds)%s",
    implode(', ', $regra['AllowedOrigins'] ?? []),
    implode(', ', $regra['AllowedMethods'] ?? []),
    implode(', ', $regra['ExposeHeaders'] ?? []),
    $regra['MaxAgeSeconds'] ?? 0,
    PHP_EOL
);

echo 'criar-bucket: bucket e CORS confirmados' . PHP_EOL;
exit(0);
PHP
