<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use Illuminate\Testing\TestResponse;

/**
 * Entrega um callback pelo endpoint real, assinado como o simulador assinaria.
 *
 * O corpo e serializado **uma vez** e a assinatura e calculada sobre exatamente
 * esses bytes, que sao os enviados. Reserializar entre assinar e enviar mudaria
 * a ordem das chaves ou o escape das barras, e a verificacao — que trabalha
 * sobre o corpo bruto — recusaria uma carga perfeitamente valida.
 *
 * Por isso a requisicao usa `call()` com corpo bruto, e nao `postJson()`: este
 * ultimo serializa por conta propria, e o teste passaria a assinar uma coisa e
 * enviar outra.
 */
trait AssinaCallback
{
    protected const ROTA_CALLBACK = '/api/webhooks/video-processing';

    /**
     * @param  array<string, mixed>  $carga
     */
    protected function entregar(array $carga, ?int $timestamp = null, ?string $assinatura = null): TestResponse
    {
        $corpo = (string) json_encode($carga, JSON_THROW_ON_ERROR);
        $momento = (string) ($timestamp ?? time());

        return $this->entregarBruto($corpo, $momento, $assinatura ?? $this->assinar($momento, $corpo));
    }

    protected function entregarBruto(string $corpo, string $timestamp, string $assinatura): TestResponse
    {
        return $this->call(
            'POST',
            self::ROTA_CALLBACK,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $assinatura,
            ],
            content: $corpo,
        );
    }

    protected function assinar(string $timestamp, string $corpo): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$corpo, (string) config('video.webhook.secret'));
    }

    /**
     * @return array<string, string|null>
     */
    protected function cargaDeSucesso(string $videoId, string $eventId, string $referencia = 'videos/pronto.mp4'): array
    {
        return [
            'event_id' => $eventId,
            'video_id' => $videoId,
            'status' => 'ready',
            'playback_reference' => $referencia,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    protected function cargaDeFalha(string $videoId, string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'video_id' => $videoId,
            'status' => 'failed',
            'playback_reference' => null,
        ];
    }
}
