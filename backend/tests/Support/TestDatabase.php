<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Escolha do schema usado pela suite.
 *
 * A suite roda contra MySQL de verdade, e nao contra um banco em memoria: os
 * testes precisam exercer o comportamento de lock, constraint e transacao do
 * banco que a aplicacao usa em execucao (plan §17.2). O preco disso e que uma
 * configuracao errada aqui nao produz um teste vermelho — produz uma suite
 * apagando os dados preparados para a avaliacao.
 *
 * Por isso a escolha e uma barreira, e nao uma conveniencia. Ela recusa em vez
 * de adivinhar:
 *
 *   - o nome vem sempre de `DB_TEST_DATABASE`, e nunca esta escrito no codigo
 *     nem no arquivo de configuracao da suite. Um nome fixo continuaria valendo
 *     depois que alguem renomeasse o banco no ambiente, e passaria a apontar
 *     para um lugar que ninguem inspeciona;
 *   - variavel ausente ou vazia interrompe a execucao com mensagem clara. Sem
 *     isso a suite cairia no banco principal por omissao;
 *   - nome igual ao banco principal interrompe. E a protecao que importa: e o
 *     unico erro de digitacao capaz de destruir dados.
 *
 * A troca acontece em `Tests\TestCase::createApplication()`, antes de o
 * framework carregar a configuracao e abrir a primeira conexao. Depois disso
 * seria tarde: a conexao ja estaria aberta no banco errado.
 *
 * Nao acontece num bootstrap global da suite de proposito. Ali ela valeria
 * tambem para os testes unitarios de dominio, que sao PHP puro e nao devem
 * exigir banco configurado para rodar (plan §17.2).
 */
final class TestDatabase
{
    private static ?string $primary = null;

    private static ?string $selected = null;

    /**
     * Le o ambiente, valida e substitui `DB_DATABASE` pelo schema de testes.
     *
     * Chamada uma vez por teste, e nao uma vez por processo — cada teste cria a
     * propria aplicacao. Por isso e idempotente: depois da primeira troca,
     * `DB_DATABASE` ja aponta para o banco de testes, e repetir a validacao
     * compararia o banco de testes consigo mesmo e interromperia a suite
     * acusando um erro que nao existe.
     */
    public static function apply(): void
    {
        if (self::$selected !== null) {
            return;
        }

        $primary = self::read('DB_DATABASE');
        $selected = self::select($_SERVER + $_ENV);

        self::$primary = $primary;
        self::$selected = $selected;

        // Os tres canais sao escritos porque o leitor de ambiente do framework
        // consulta os tres, em ordem, e qual deles responde depende de como o
        // processo foi iniciado. Escrever em um so deixaria a substituicao
        // valendo em algumas execucoes e nao em outras.
        putenv("DB_DATABASE={$selected}");
        $_ENV['DB_DATABASE'] = $selected;
        $_SERVER['DB_DATABASE'] = $selected;

        // A conexao tambem e fixada: se o ambiente vier sem ela, o padrao do
        // framework e SQLite, e a suite passaria a validar um banco que a
        // aplicacao nao usa.
        putenv('DB_CONNECTION=mysql');
        $_ENV['DB_CONNECTION'] = 'mysql';
        $_SERVER['DB_CONNECTION'] = 'mysql';
    }

    /**
     * Regra de escolha, isolada do efeito colateral para poder ser exercida
     * diretamente por teste.
     *
     * @param  array<string, mixed>  $environment
     *
     * @throws RuntimeException quando a configuracao nao permite uma escolha segura
     */
    public static function select(array $environment): string
    {
        $test = self::text($environment['DB_TEST_DATABASE'] ?? null);
        $primary = self::text($environment['DB_DATABASE'] ?? null);

        if ($test === null) {
            throw new RuntimeException(
                'DB_TEST_DATABASE ausente ou vazia. A suite roda contra MySQL real e '
                .'precisa de um schema proprio, separado do banco da aplicacao. '
                .'Defina DB_TEST_DATABASE no .env da raiz e recrie o servico da API.'
            );
        }

        if ($primary !== null && $test === $primary) {
            throw new RuntimeException(
                'DB_TEST_DATABASE e DB_DATABASE apontam para o mesmo schema. A suite '
                .'limpa o proprio banco entre execucoes: seguir assim apagaria os '
                .'dados preparados para a avaliacao. Use nomes distintos.'
            );
        }

        return $test;
    }

    /**
     * Schema efetivamente escolhido para a suite.
     */
    public static function selected(): ?string
    {
        return self::$selected;
    }

    /**
     * Banco da aplicacao, guardado antes da primeira substituicao. Existe para
     * que um teste possa afirmar que os dois nunca coincidem.
     */
    public static function primary(): ?string
    {
        return self::$primary;
    }

    private static function read(string $name): ?string
    {
        return self::text($_SERVER[$name] ?? $_ENV[$name] ?? getenv($name) ?: null);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
