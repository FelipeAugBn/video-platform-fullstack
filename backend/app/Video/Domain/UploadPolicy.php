<?php

declare(strict_types=1);

namespace App\Video\Domain;

/**
 * Os limites do envio, decididos pelo backend (plan §11.2).
 *
 * Um objeto so, e ele e a **unica** definicao desses valores na solucao. O
 * detalhe importa: o tipo aceito e o tamanho maximo aparecem em dois lugares
 * muito distantes — nas regras de validacao da requisicao de abertura, que
 * produzem `422` com erro por campo, e na conta que decide em quantas partes o
 * arquivo sera dividido. Escritos duas vezes, eles divergiriam no dia em que
 * alguem mudasse so um. Aqui a requisicao **pergunta** o limite em vez de
 * repeti-lo.
 *
 * Nada disto vem do cliente. RF-UPL-006 e explicito: tipo e tamanho declarados
 * sao validados contra o que o backend define, e um cliente que informasse os
 * proprios limites estaria validando a si mesmo.
 *
 * O tamanho de parte nao e negociavel pelo cliente pelo mesmo motivo, e ha um a
 * mais: a conclusao confere o tamanho do objeto contra o declarado na abertura,
 * e um particionamento escolhido pelo navegador quebraria essa conta sem que a
 * aplicacao percebesse.
 *
 * PHP puro, como todo o `Domain`: os valores chegam prontos de quem le a
 * configuracao, e este objeto nao sabe que existe um arquivo de configuracao.
 */
final class UploadPolicy
{
    /**
     * @param  string  $contentType  Unico tipo aceito; sem transcodificacao, aceitar outro seria prometer reproducao que a solucao nao entrega.
     * @param  int  $maxSize  Tamanho maximo declarado, em bytes.
     * @param  int  $partSize  Tamanho de cada parte, em bytes.
     * @param  int  $maxParts  Teto de partes do protocolo.
     * @param  int  $partUrlTtl  Validade da URL de parte, em segundos.
     */
    public function __construct(
        public readonly string $contentType,
        public readonly int $maxSize,
        public readonly int $partSize,
        public readonly int $maxParts,
        public readonly int $partUrlTtl,
    ) {}

    public function aceita(string $contentType): bool
    {
        return $contentType === $this->contentType;
    }

    /**
     * Quantas partes um arquivo deste tamanho tera.
     *
     * Divisao para cima, e nunca zero: um arquivo menor que uma parte continua
     * sendo uma parte. Zero faria a abertura devolver um plano sem nenhuma URL a
     * pedir, e o envio ficaria preso em `pending` sem que o cliente tivesse o que
     * fazer.
     */
    public function partesPara(int $size): int
    {
        return max(1, (int) ceil($size / $this->partSize));
    }

    /**
     * Responde se o numero de parte pedido existe para um arquivo deste tamanho.
     *
     * As duas pontas importam. Abaixo de 1 nao e parte; acima do total, o
     * cliente estaria pedindo autorizacao para enviar um pedaco que a conclusao
     * jamais vai reunir — e o objeto resultante nao bateria com o tamanho
     * declarado, transformando um erro de cliente em `failed` do video.
     */
    public function temParte(int $partNumber, int $size): bool
    {
        return $partNumber >= 1 && $partNumber <= $this->partesPara($size);
    }
}
