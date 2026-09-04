<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Video\Domain\VideoState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tabela de transicoes do video, exercida em toda a sua extensao (AC-VID-008).
 *
 * A prova desta suite nao sao as seis transicoes que funcionam: sao as trinta
 * que precisam ser recusadas. Uma implementacao que aceite tudo passa em
 * qualquer teste positivo, e o defeito so aparece quando um callback atrasado
 * regride um video ja pronto. Por isso a matriz e percorrida inteira — seis
 * origens por seis destinos, trinta e seis pares —, e a lista de permitidas e
 * escrita aqui de forma independente, copiada da especificacao e nao do codigo
 * sob teste. Duas fontes que precisam concordar; se o enum mudar sozinho, elas
 * discordam.
 *
 * Este arquivo estende o `TestCase` do PHPUnit, e nao o do projeto: a regra e
 * PHP puro e precisa continuar demonstravel sem banco, sem variavel de ambiente
 * e sem framework inicializado (plan §§5.1, 17.2).
 */
final class VideoStateTest extends TestCase
{
    /**
     * As seis transicoes da tabela, na notacao `origem->destino`.
     *
     * @var list<string>
     */
    private const PERMITIDAS = [
        'pending->uploading',
        'uploading->uploaded',
        'uploading->failed',
        'uploaded->processing',
        'processing->ready',
        'processing->failed',
    ];

    public function test_declara_exatamente_os_seis_estados_do_ciclo_de_vida(): void
    {
        $valores = array_map(
            static fn (VideoState $estado): string => $estado->value,
            VideoState::cases(),
        );

        $this->assertCount(6, VideoState::cases());
        $this->assertSame(
            ['pending', 'uploading', 'uploaded', 'processing', 'ready', 'failed'],
            $valores,
        );
    }

    /**
     * A matriz completa: cada um dos trinta e seis pares possiveis, com o
     * resultado esperado vindo da lista da especificacao.
     */
    #[DataProvider('matrizCompletaDeTransicoes')]
    public function test_a_matriz_completa_responde_conforme_a_tabela(
        VideoState $origem,
        VideoState $destino,
        bool $permitida,
    ): void {
        $this->assertSame($permitida, $origem->canTransitionTo($destino));
    }

    /**
     * @return iterable<string, array{VideoState, VideoState, bool}>
     */
    public static function matrizCompletaDeTransicoes(): iterable
    {
        foreach (VideoState::cases() as $origem) {
            foreach (VideoState::cases() as $destino) {
                $par = $origem->value.'->'.$destino->value;

                yield $par => [$origem, $destino, in_array($par, self::PERMITIDAS, true)];
            }
        }
    }

    public function test_a_matriz_cobre_as_trinta_e_seis_combinacoes(): void
    {
        $pares = iterator_to_array(self::matrizCompletaDeTransicoes());

        $this->assertCount(36, $pares);
    }

    public function test_existem_exatamente_seis_transicoes_permitidas(): void
    {
        $aceitas = [];

        foreach (VideoState::cases() as $origem) {
            foreach (VideoState::cases() as $destino) {
                if ($origem->canTransitionTo($destino)) {
                    $aceitas[] = $origem->value.'->'.$destino->value;
                }
            }
        }

        $this->assertSame(self::PERMITIDAS, $aceitas);
        $this->assertCount(6, $aceitas);
    }

    public function test_o_envio_percorre_o_caminho_feliz_ate_ready(): void
    {
        $this->assertTrue(VideoState::PENDING->canTransitionTo(VideoState::UPLOADING));
        $this->assertTrue(VideoState::UPLOADING->canTransitionTo(VideoState::UPLOADED));
        $this->assertTrue(VideoState::UPLOADED->canTransitionTo(VideoState::PROCESSING));
        $this->assertTrue(VideoState::PROCESSING->canTransitionTo(VideoState::READY));
    }

    /**
     * A conclusao solicitada sobre um objeto ausente ou incompativel encerra a
     * tentativa em `failed`, sem passar por `uploaded` (RF-UPL-013). E o ramo
     * que libera um novo envio para a aula sem depender de uma operacao de
     * descarte, e o mais facil de esquecer ao transcrever a tabela.
     */
    public function test_uploading_falha_quando_a_verificacao_recusa_o_objeto(): void
    {
        $this->assertTrue(VideoState::UPLOADING->canTransitionTo(VideoState::FAILED));
    }

    public function test_o_processamento_pode_terminar_em_falha(): void
    {
        $this->assertTrue(VideoState::PROCESSING->canTransitionTo(VideoState::FAILED));
    }

    /**
     * `ready` e `failed` encerram a tentativa (RN-VID-002). Sem saida alguma,
     * um callback repetido ou atrasado nao tem por onde regredir o video.
     */
    #[DataProvider('estadosTerminais')]
    public function test_estado_terminal_nao_transiciona_para_lugar_nenhum(VideoState $terminal): void
    {
        foreach (VideoState::cases() as $destino) {
            $this->assertFalse(
                $terminal->canTransitionTo($destino),
                "{$terminal->value} nao deveria transicionar para {$destino->value}",
            );
        }
    }

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosTerminais(): iterable
    {
        yield 'ready' => [VideoState::READY];
        yield 'failed' => [VideoState::FAILED];
    }

    /**
     * Repetir uma operacao ja efetivada e reconhecido pelo caso de uso, que
     * constata que o estado atual ja e o desejado e nao altera nada. Nao ha
     * transicao a permitir, e por isso nenhum estado leva a si mesmo — o que
     * nao contradiz RN-VID-003.
     */
    public function test_nenhum_estado_transiciona_para_ele_mesmo(): void
    {
        foreach (VideoState::cases() as $estado) {
            $this->assertFalse(
                $estado->canTransitionTo($estado),
                "{$estado->value} nao deveria transicionar para ele mesmo",
            );
        }
    }

    /**
     * Saltos e regressoes sao os dois erros que RN-VID-001 nomeia. Ja estao
     * cobertos pela matriz; ficam aqui com nome proprio porque sao o que a
     * regra existe para impedir.
     */
    #[DataProvider('saltosERegressoes')]
    public function test_salto_e_regressao_sao_recusados(VideoState $origem, VideoState $destino): void
    {
        $this->assertFalse($origem->canTransitionTo($destino));
    }

    /**
     * @return iterable<string, array{VideoState, VideoState}>
     */
    public static function saltosERegressoes(): iterable
    {
        yield 'salto de pending para ready' => [VideoState::PENDING, VideoState::READY];
        yield 'salto de pending para uploaded' => [VideoState::PENDING, VideoState::UPLOADED];
        yield 'salto de uploading para processing' => [VideoState::UPLOADING, VideoState::PROCESSING];
        yield 'salto de uploaded para ready' => [VideoState::UPLOADED, VideoState::READY];
        yield 'regressao de ready para processing' => [VideoState::READY, VideoState::PROCESSING];
        yield 'regressao de processing para uploaded' => [VideoState::PROCESSING, VideoState::UPLOADED];
        yield 'regressao de uploaded para uploading' => [VideoState::UPLOADED, VideoState::UPLOADING];
        yield 'regressao de failed para pending' => [VideoState::FAILED, VideoState::PENDING];
    }
}
