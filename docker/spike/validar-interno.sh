#!/usr/bin/env bash
#
# Cliente INTERNO do spike.
#
# Roda dentro da rede do Compose e fala com o storage pelo NOME DO SERVICO.
# Representa o que a API vai fazer: operacoes de SDK e assinatura de URLs.
#
# Nunca ve TROCA_DIR. Dentro do container o diretorio compartilhado e sempre
# /troca, caminho fixo.
#
# Uso: validar-interno.sh preparar|concluir

set -euo pipefail

TROCA=/troca
ETAPA="${1:-}"

case "$ETAPA" in
  preparar|concluir) ;;
  *)
    echo "uso: $(basename "$0") preparar|concluir" >&2
    exit 64
    ;;
esac

if [ ! -d "$TROCA" ]; then
  echo "erro: $TROCA nao esta montado; o script foi invocado sem -v \"\$TROCA_DIR:/troca\"" >&2
  exit 78
fi

# umask 022 para que os arquivos escritos aqui (o container roda como root)
# sejam legiveis pelo cliente externo, que roda com o uid do host.
umask 022

if ! python3 -c 'import boto3' >/dev/null 2>&1; then
  echo "  [interno] instalando boto3==${SPIKE_BOTO3_VERSAO} em /pylibs"
  pip install --quiet --no-cache-dir --disable-pip-version-check \
    --root-user-action=ignore --target /pylibs "boto3==${SPIKE_BOTO3_VERSAO}"
fi

export SPIKE_ETAPA="$ETAPA"

python3 - <<'PY'
import hashlib
import json
import os
import socket
from urllib.parse import urlsplit

import boto3
from botocore.config import Config

TROCA = "/troca"
ETAPA = os.environ["SPIKE_ETAPA"]

INTERNO = os.environ["SPIKE_ENDPOINT_INTERNO"]
PUBLICO = os.environ["SPIKE_ENDPOINT_PUBLICO"]
BUCKET = os.environ["SPIKE_BUCKET"]
CHAVE = os.environ["SPIKE_OBJETO"]
TIPO = os.environ["SPIKE_CONTENT_TYPE"]
TAMANHO = int(os.environ["SPIKE_TAMANHO_AMOSTRA"])
VAL_PUT = int(os.environ["SPIKE_VALIDADE_PUT"])
VAL_GET = int(os.environ["SPIKE_VALIDADE_GET"])
ATTEMPT = "spike-video-attempt"

cfg = Config(signature_version="s3v4", s3={"addressing_style": "path"},
             retries={"max_attempts": 3, "mode": "standard"})


def cliente(endpoint):
    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        aws_access_key_id=os.environ["SPIKE_ACCESS_KEY"],
        aws_secret_access_key=os.environ["SPIKE_SECRET_KEY"],
        region_name=os.environ["SPIKE_REGION"],
        config=cfg,
    )


# Operacional: fala com o storage pelo nome do servico, na rede do Compose.
s3 = cliente(INTERNO)
# Assinador: nao faz chamada de rede, so assina para o endereco publicado no
# host. Assinar com o endereco errado produz URL que o navegador nao alcanca.
assinador = cliente(PUBLICO)


def ponto(numero, evidencia):
    with open(f"{TROCA}/pontos.txt", "a", encoding="utf-8") as fh:
        fh.write(f"{numero}|OK|{evidencia}\n")
    print(f"  [interno] ponto {numero}: {evidencia}")


def descrever(url, validade):
    """Descreve uma URL assinada sem jamais expor a query string."""
    p = urlsplit(url)
    porta = p.port if p.port else ("443" if p.scheme == "https" else "80")
    return f"{p.scheme}://{p.hostname}:{porta}{p.path} (validade {validade}s)"


def gravar(nome, conteudo):
    caminho = f"{TROCA}/{nome}"
    with open(caminho, "w", encoding="utf-8") as fh:
        fh.write(conteudo)
    os.chmod(caminho, 0o644)


if ETAPA == "preparar":
    # pontos.txt e criado pelo orquestrador, que ja registrou os pontos 1 a 3.
    # Aqui so se acrescenta.

    # --- ponto 5: endpoint interno, pelo nome do servico ---------------------
    host_interno = urlsplit(INTERNO).hostname
    ip = socket.gethostbyname(host_interno)
    s3.list_buckets()
    ponto(5, f"nome de servico '{host_interno}' resolvido para {ip} e "
             f"respondendo na rede do Compose")

    # --- ponto 4: bucket e CORS ----------------------------------------------
    existentes = [b["Name"] for b in s3.list_buckets().get("Buckets", [])]
    if BUCKET not in existentes:
        s3.create_bucket(Bucket=BUCKET)
    if BUCKET not in [b["Name"] for b in s3.list_buckets().get("Buckets", [])]:
        raise SystemExit("bucket nao aparece na listagem depois de criado")

    with open("/spike/cors.json", encoding="utf-8") as fh:
        cors = json.load(fh)
    s3.put_bucket_cors(Bucket=BUCKET, CORSConfiguration=cors)
    lido = s3.get_bucket_cors(Bucket=BUCKET)["CORSRules"]
    if lido != cors["CORSRules"]:
        raise SystemExit(f"CORS lido difere do aplicado: {lido}")
    regra = lido[0]
    ponto(4, f"bucket '{BUCKET}' listado; CORS aplicado e relido igual "
             f"(origens {regra['AllowedOrigins']}, metodos "
             f"{regra['AllowedMethods']}, expoe {regra['ExposeHeaders']})")

    # --- amostra --------------------------------------------------------------
    dados = os.urandom(TAMANHO)
    with open(f"{TROCA}/amostra.bin", "wb") as fh:
        fh.write(dados)
    os.chmod(f"{TROCA}/amostra.bin", 0o644)

    # --- ponto 6: CreateMultipartUpload --------------------------------------
    mp = s3.create_multipart_upload(
        Bucket=BUCKET, Key=CHAVE, ContentType=TIPO,
        Metadata={"video-attempt-id": ATTEMPT},
    )
    upload_id = mp["UploadId"]
    if not upload_id:
        raise SystemExit("CreateMultipartUpload nao devolveu UploadId")
    ponto(6, f"UploadId recebido ({len(upload_id)} caracteres) e gravado em "
             f"/troca; chave e metadados definidos pelo servidor")

    # --- ponto 7: URL PUT pre-assinada, 15 minutos ---------------------------
    url_put = assinador.generate_presigned_url(
        "upload_part",
        Params={"Bucket": BUCKET, "Key": CHAVE, "UploadId": upload_id,
                "PartNumber": 1},
        ExpiresIn=VAL_PUT,
    )
    if f"X-Amz-Expires={VAL_PUT}" not in url_put:
        raise SystemExit("URL PUT sem a validade esperada")
    gravar("put.url", url_put)
    ponto(7, f"URL PUT assinada para {descrever(url_put, VAL_PUT)}")

    gravar("estado.json", json.dumps({
        "bucket": BUCKET, "chave": CHAVE, "uploadId": upload_id,
        "tipo": TIPO, "tamanho": TAMANHO, "attempt": ATTEMPT,
        "sha256": hashlib.sha256(dados).hexdigest(),
    }))
    print("  [interno] preparacao concluida")

else:  # concluir
    estado = json.load(open(f"{TROCA}/estado.json", encoding="utf-8"))
    caminho_etag = f"{TROCA}/etag.txt"
    if not os.path.exists(caminho_etag):
        raise SystemExit("etag.txt ausente: a etapa 2 nao entregou o ETag")
    etag = open(caminho_etag, encoding="utf-8").read().strip()
    if not etag:
        raise SystemExit("ETag vazio")

    # --- ponto 11: CompleteMultipartUpload com o ETag da etapa 2 -------------
    s3.complete_multipart_upload(
        Bucket=estado["bucket"], Key=estado["chave"],
        UploadId=estado["uploadId"],
        MultipartUpload={"Parts": [{"ETag": etag, "PartNumber": 1}]},
    )
    ponto(11, "CompleteMultipartUpload aceito usando o ETag produzido pelo "
              "cliente externo na etapa 2; objeto passou a existir")

    # --- ponto 12: HeadObject -------------------------------------------------
    h = s3.head_object(Bucket=estado["bucket"], Key=estado["chave"])
    problemas = []
    if h["ContentLength"] != estado["tamanho"]:
        problemas.append(f"tamanho {h['ContentLength']} != {estado['tamanho']}")
    if h["ContentType"] != estado["tipo"]:
        problemas.append(f"tipo {h['ContentType']} != {estado['tipo']}")
    if h.get("Metadata", {}).get("video-attempt-id") != estado["attempt"]:
        problemas.append(f"metadado {h.get('Metadata')}")
    if problemas:
        raise SystemExit("HeadObject divergente: " + "; ".join(problemas))
    ponto(12, f"tamanho {h['ContentLength']} bytes, Content-Type "
              f"{h['ContentType']}, metadado video-attempt-id="
              f"{h['Metadata']['video-attempt-id']} conferem com o declarado")

    # --- ponto 13: URL GET pre-assinada, 5 minutos ---------------------------
    url_get = assinador.generate_presigned_url(
        "get_object",
        Params={"Bucket": estado["bucket"], "Key": estado["chave"]},
        ExpiresIn=VAL_GET,
    )
    if f"X-Amz-Expires={VAL_GET}" not in url_get:
        raise SystemExit("URL GET sem a validade esperada")
    gravar("get.url", url_get)
    ponto(13, f"URL GET assinada para {descrever(url_get, VAL_GET)}")
    print("  [interno] conclusao encerrada")
PY
