<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

use App\Video\Application\Port\Exception\MultipartUploadNotFound;
use App\Video\Application\Port\Exception\ObjectNotFound;
use App\Video\Application\Port\Exception\StorageFailure;
use App\Video\Application\Port\Exception\StorageRejected;
use App\Video\Application\Port\Exception\StorageUnavailable;
use DateTimeImmutable;

/**
 * O armazenamento de objetos, no vocabulario do problema (plan §5.3, ABERTO-002).
 *
 * Cinco operacoes, e cada uma existe porque um passo do fluxo de envio depende
 * dela (plan §11.3, §12.2, §14.2). A porta fala em **abrir um envio, autorizar o
 * envio de uma parte, concluir, inspecionar e autorizar uma leitura** — no
 * vocabulario do problema, e nao no de nenhum protocolo ou provedor. O nome das
 * operacoes de rede fica do lado de fora.
 *
 * E o que sustenta a substituibilidade de ABERTO-002: trocar o armazenamento por
 * outro, compativel ou nao, e escrever outro adapter — nenhum caso de uso muda.
 *
 * ## O que esta porta deliberadamente nao tem
 *
 * Nao ha cancelar envio, listar objetos, excluir objeto nem copiar. Nenhuma
 * dessas operacoes e usada por regra alguma do plano, e uma porta que as
 * declarasse estaria prometendo um contrato mais largo do que o adapter precisa
 * cumprir. O descarte de envios abandonados, em particular, e uma limitacao
 * registrada e consciente (plan §19.1), e nao um metodo esquecido.
 *
 * Tambem nao ha nada sobre **quanto tempo** uma URL vale. O instante de
 * expiracao chega como parametro, ja decidido: os quinze minutos da URL de parte
 * e os cinco da URL de leitura sao politica dos casos de uso (plan §§11.2, 14.2),
 * e um adapter que os conhecesse passaria a ser o lugar onde alguem mudaria uma
 * regra de negocio.
 *
 * ## Bytes nao passam por aqui
 *
 * Nenhuma operacao recebe ou devolve o conteudo do video. Duas delas devolvem uma
 * **URL temporaria** que o navegador usa para falar direto com o armazenamento, e
 * e assim que RNF-001 e atendido: os gigabytes trafegam entre navegador e
 * armazenamento, sem atravessar a aplicacao (AC-VID-001).
 *
 * ## Falhas
 *
 * **Nenhuma falha esperada da integracao com o provedor ou com seu SDK atravessa
 * a porta sem ser traduzida.** Ela chega como uma subclasse de
 * {@see StorageFailure}, num vocabulario proprio — sem codigo de protocolo, sem
 * mensagem de provedor:
 *
 *   {@see StorageUnavailable}       nao ha evidencia confiavel — preservar o estado
 *   {@see ObjectNotFound}           o objeto confirmadamente nao esta la
 *   {@see MultipartUploadNotFound}  o envio nao existe mais — a inspecao decide
 *   {@see StorageRejected}          recusa definitiva sobre o que foi enviado
 *
 * A palavra **esperada** e deliberada. Defeito de programacao — `TypeError`, e o
 * que mais a linguagem levantar — nao e traduzido de proposito: o adapter captura
 * uma lista fechada de excecoes, e nao `Throwable`. Um erro de codigo disfarcado
 * de falha de armazenamento perderia a causa real.
 *
 * O conjunto e fechado, e cada caso descreve uma situacao distinta e suficiente
 * para uma decisao segura. Isso **nao** significa que cada um leve a um
 * comportamento diferente: mais de uma situacao pode terminar na mesma decisao
 * de negocio, e nao ha problema nisso. O que nao pode acontecer e o contrario —
 * duas situacoes que exigem decisoes opostas chegarem indistinguiveis.
 *
 * A porta apenas classifica. O que fazer com cada uma pertence ao caso de uso
 * (plan §12.4).
 *
 * PHP puro, como toda a camada `Application` (plan §5.1): sem SDK, sem
 * `Illuminate`, sem cliente HTTP, sem PSR HTTP, sem Eloquent.
 */
interface ObjectStorage
{
    /**
     * Abre um envio em partes e devolve o identificador que o storage emitiu.
     *
     * **Chave e metadados sao definidos pelo servidor, nunca pelo cliente**
     * (plan §11.3): a chave e derivada do identificador da tentativa, e os
     * metadados carregam esse identificador. E esse vinculo que a inspecao da
     * conclusao confere, e e ele que impede um cliente de concluir uma tentativa
     * apontando para um objeto que nao e dela (plan §12.3).
     *
     * @param  array<string, string>  $metadata  Metadados controlados pelo servidor.
     * @return string Identificador do envio, gerado pelo storage.
     *
     * @throws StorageUnavailable|StorageRejected
     */
    public function createMultipartUpload(string $key, string $contentType, array $metadata): string;

    /**
     * Autoriza o envio de uma parte, por tempo limitado.
     *
     * Devolve uma URL que o **navegador** usa para enviar os bytes daquela parte
     * direto ao armazenamento. Ela e assinada para o endereco publico, e nao para
     * o interno: assinar com o host errado produz uma URL que a aplicacao
     * considera valida e o navegador nao alcanca (plan §16.3).
     *
     * A geracao nao faz chamada de rede e nao altera nada no armazenamento.
     * Renovar uma URL vencida e chamar este metodo de novo — nao ha estado a
     * corrigir.
     *
     * Nao havendo ida ao armazenamento, tambem nao ha resposta dele para
     * classificar: as falhas possiveis aqui sao de credencial, de configuracao e
     * de argumento — uma validade fora do limite que a assinatura aceita, por
     * exemplo. Nenhuma delas e evidencia sobre o objeto, e todas chegam como
     * {@see StorageUnavailable}.
     *
     * @throws StorageUnavailable
     */
    public function presignUploadPart(
        string $key,
        string $uploadId,
        int $partNumber,
        DateTimeImmutable $expiresAt,
    ): string;

    /**
     * Fecha o envio, mandando o storage reunir as partes.
     *
     * Cada parte e identificada pelo numero e pelo comprovante que o proprio
     * armazenamento devolveu ao recebe-la — repassado como veio (ver
     * {@see CompletedPart}).
     *
     * **Concluir nao e verificar.** Esta operacao pede ao armazenamento que monte
     * o objeto; a prova independente de que ele existe e confere vem depois, de
     * {@see inspectObject()}. Confiar so na resposta desta chamada seria confiar
     * no relato do cliente, que e exatamente o que plan §12.1 recusa.
     *
     * @param  list<CompletedPart>  $parts
     *
     * @throws MultipartUploadNotFound|StorageUnavailable|StorageRejected
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void;

    /**
     * Observa um objeto sem baixa-lo.
     *
     * E a prova independente de plan §12: existencia, tamanho, tipo e metadados,
     * consultados no armazenamento e nao informados pelo cliente. Um video de
     * gigabytes nao precisa atravessar a aplicacao para ser verificado.
     *
     * @throws ObjectNotFound|StorageUnavailable|StorageRejected
     */
    public function inspectObject(string $key): StoredObject;

    /**
     * Autoriza a leitura do objeto, por tempo limitado.
     *
     * O que se entrega a quem assiste sao **dados de reproducao**, e nao o
     * arquivo (RF-PLB-005): o armazenamento transmite os bytes, a aplicacao
     * apenas autoriza. Como na URL de parte, a assinatura e para o endereco publico.
     *
     * A URL e emitida **depois** da autorizacao, nunca no lugar dela: ela
     * comprova que a aplicacao permitiu, nao que o solicitante tinha direito
     * (plan §14.2).
     *
     * Como em {@see presignUploadPart()}, nao ha chamada de rede: credencial,
     * configuracao e validade invalida sao as falhas possiveis, e todas chegam
     * como {@see StorageUnavailable}.
     *
     * @throws StorageUnavailable
     */
    public function presignRead(string $key, DateTimeImmutable $expiresAt): string;
}
