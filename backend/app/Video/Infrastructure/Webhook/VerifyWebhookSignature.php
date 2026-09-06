<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Webhook;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validacao de origem do callback: HMAC-SHA256 sobre timestamp e corpo bruto
 * (RF-WHK-001, plan §13.4).
 *
 * O emissor e um servico, nao um navegador — nao ha sessao, nao ha cookie e nao
 * ha CSRF a conferir. O que prova a origem e o segredo compartilhado, e este
 * middleware e o unico lugar que o confere.
 *
 * ## Quatro decisoes que sustentam a verificacao
 *
 * **Segredo vazio nao assina nada.** Sem valor configurado, a verificacao e
 * recusada de saida em vez de calcular HMAC sobre chave vazia — o que aceitaria
 * qualquer emissor que soubesse do descuido. O ambiente ja falha ao subir sem a
 * variavel; esta guarda cobre o caso de ela existir vazia.
 *
 * **O corpo e o bruto, byte a byte.** A assinatura e conferida **antes** de
 * qualquer desserializacao. Reserializar o JSON para conferir mudaria os bytes —
 * ordem de chaves, escape de barras, espacos — e a verificacao quebraria para
 * cargas perfeitamente validas.
 *
 * **A comparacao e em tempo constante.** `hash_equals` em vez de `===`: uma
 * comparacao que sai no primeiro byte diferente vaza, pelo tempo de resposta,
 * quantos bytes iniciais estavam certos — e isso e suficiente para descobrir uma
 * assinatura valida byte a byte.
 *
 * **A janela e de cinco minutos.** Assinatura valida capturada e reenviada mais
 * tarde deixa de ser aceita. A janela e conferida nos **dois sentidos**: um
 * timestamp muito no futuro tambem e recusado, senao bastaria assinar com uma
 * data distante para produzir uma entrega que vale para sempre.
 *
 * Toda recusa e o mesmo `401`, sem distinguir qual verificacao falhou: dizer se
 * o problema foi a assinatura ou o horario ensinaria a quem tenta.
 */
final class VerifyWebhookSignature
{
    private const PREFIXO = 'v1=';

    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->secret === '') {
            // Configuracao ausente e defeito do ambiente, nao entrega invalida.
            // A resposta e a generica de erro interno — descrever a causa diria
            // a um emissor qualquer que basta esperar o descuido.
            throw new DomainException(Failure::INTERNAL_ERROR);
        }

        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
        $assinatura = (string) $request->header('X-Webhook-Signature', '');

        if (! $this->dentroDaJanela($timestamp) || ! $this->confere($timestamp, $assinatura, $request)) {
            throw new DomainException(Failure::WEBHOOK_SIGNATURE_INVALID);
        }

        return $next($request);
    }

    private function dentroDaJanela(string $timestamp): bool
    {
        // Exige digitos: `(int) "abc"` seria zero, e zero e um epoch valido — a
        // entrega passaria a ser recusada por estar fora da janela, e nao por ter
        // um cabecalho malformado. O desfecho seria o mesmo por acidente, e um
        // acidente nao e uma verificacao.
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $this->toleranceSeconds;
    }

    private function confere(string $timestamp, string $assinatura, Request $request): bool
    {
        if (! str_starts_with($assinatura, self::PREFIXO)) {
            return false;
        }

        $esperada = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $this->secret);

        return hash_equals(self::PREFIXO.$esperada, $assinatura);
    }
}
