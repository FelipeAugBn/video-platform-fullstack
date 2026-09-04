<?php

declare(strict_types=1);

namespace App\Video\Domain;

/**
 * Ciclo de vida de uma tentativa de envio de video.
 *
 * Este e o unico lugar da solucao que responde se uma mudanca de estado e
 * permitida. A tabela abaixo e a mesma da especificacao, e nao ha segunda copia
 * dela em caso de uso, controller ou consulta — espalhar a regra por esses
 * lugares e como uma transicao invalida entra pela porta que ninguem revisou.
 *
 * Transicoes permitidas (RF-VID-001, RN-VID-001):
 *
 *     pending    ──▶ uploading     inicio da transferencia pelo cliente
 *     uploading  ──▶ uploaded      conclusao verificada pelo backend
 *     uploading  ──▶ failed        objeto ausente ou incompativel na verificacao
 *     uploaded   ──▶ processing    inicio do processamento assincrono
 *     processing ──▶ ready         callback de sucesso
 *     processing ──▶ failed        callback de falha
 *
 * Qualquer outro par e recusado, incluindo saltos como `pending` para `ready` e
 * regressoes como `ready` para `processing`. `ready` e `failed` encerram a
 * tentativa e nao levam a lugar nenhum: sair de `failed` exige um novo envio,
 * que produz outra tentativa em `pending` (RN-VID-002).
 *
 * **Um estado nao transiciona para ele mesmo, e isso nao contradiz RN-VID-003.**
 * Repetir uma operacao ja efetivada e reconhecido sem erro e sem efeito novo —
 * mas quem reconhece isso e o caso de uso, ao constatar que o estado atual ja e
 * o desejado. Ele nao pergunta a este enum se pode transicionar, porque nao ha
 * transicao alguma a fazer. Declarar `ready para ready` como permitida diria que
 * existe uma mudanca onde nao existe, e apagaria a diferenca entre repetir uma
 * operacao e refazer um efeito.
 *
 * PHP puro, como todo o `Domain`: sem framework, sem persistencia, sem HTTP
 * (plan §§5.1, 6.2). Traduzir uma recusa em erro funcional pertence aos casos
 * de uso e a camada de interface.
 */
enum VideoState: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case UPLOADED = 'uploaded';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';

    /**
     * Responde se a mudanca deste estado para o informado esta na tabela.
     *
     * Nao lanca e nao altera nada: e uma pergunta, e a decisao sobre o que
     * fazer com a resposta negativa e de quem perguntou.
     */
    public function canTransitionTo(self $destination): bool
    {
        return in_array($destination, $this->allowedTransitions(), true);
    }

    /**
     * A tabela, em um unico `match` exaustivo.
     *
     * Exaustivo de proposito: um estado novo acrescentado a enumeracao sem
     * destino declarado aqui faz o `match` falhar em execucao, em vez de herdar
     * silenciosamente um comportamento que ninguem decidiu.
     *
     * @return list<self>
     */
    private function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::UPLOADING],
            self::UPLOADING => [self::UPLOADED, self::FAILED],
            self::UPLOADED => [self::PROCESSING],
            self::PROCESSING => [self::READY, self::FAILED],
            self::READY, self::FAILED => [],
        };
    }
}
