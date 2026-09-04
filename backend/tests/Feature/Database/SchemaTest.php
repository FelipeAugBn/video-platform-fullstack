<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O esquema, exercido contra o MySQL de verdade.
 *
 * A distincao que organiza este arquivo: uma migration descreve uma intencao, o
 * banco e quem cumpre. Ler o arquivo da migration provaria apenas que alguem
 * escreveu a linha certa — por isso cada garantia aqui e verificada **no
 * catalogo do proprio servidor** ou, quando ha comportamento envolvido,
 * exercida com uma escrita que precisa ser recusada.
 *
 * Rodar contra SQLite nao serviria: as constraints, os tipos e o
 * comportamento de exclusao em cascata sao exatamente o que esta sob teste
 * (plan §17.2).
 */
final class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const INFRAESTRUTURA = ['cache', 'cache_locks', 'failed_jobs', 'jobs', 'sessions'];

    private const DOMINIO = [
        'course_access_grants', 'courses', 'lessons', 'modules', 'users', 'video_attempts', 'webhook_events',
    ];

    // -----------------------------------------------------------------------
    // Conjunto de tabelas
    // -----------------------------------------------------------------------

    public function test_o_esquema_tem_exatamente_as_tabelas_declaradas(): void
    {
        $esperado = array_merge(['migrations'], self::INFRAESTRUTURA, self::DOMINIO);
        sort($esperado);

        $this->assertSame($esperado, $this->tabelas());
        $this->assertCount(13, $esperado);
    }

    public function test_nao_recria_as_tabelas_sem_uso_do_esqueleto(): void
    {
        $tabelas = $this->tabelas();

        // Recuperacao de senha esta fora de escopo e batching nao e usado por
        // nenhuma decisao do plano. Tabela sem consumidor e esquema morto.
        $this->assertNotContains('password_reset_tokens', $tabelas);
        $this->assertNotContains('job_batches', $tabelas);
    }

    public function test_toda_tabela_usa_innodb_e_a_collation_do_plano(): void
    {
        $linhas = DB::select(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$this->schema()]
        );

        foreach ($linhas as $linha) {
            $this->assertSame('InnoDB', $linha->ENGINE, "{$linha->TABLE_NAME} deveria ser InnoDB");
            $this->assertSame(
                'utf8mb4_0900_ai_ci',
                $linha->TABLE_COLLATION,
                "{$linha->TABLE_NAME} deveria usar a collation do plano"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Identificadores
    // -----------------------------------------------------------------------

    public function test_todo_identificador_de_dominio_e_char_36_ascii_binario(): void
    {
        $colunas = [
            'users' => ['id'],
            'courses' => ['id', 'owner_id'],
            'modules' => ['id', 'course_id'],
            'lessons' => ['id', 'module_id', 'current_video_attempt_id'],
            'video_attempts' => ['id', 'lesson_id'],
            'course_access_grants' => ['id', 'course_id', 'consumer_id'],
            'webhook_events' => ['id', 'received_video_id', 'video_attempt_id'],
            // A excecao da tabela de sessoes: o framework grava aqui o
            // identificador do usuario autenticado, e ele e um UUID.
            'sessions' => ['user_id'],
        ];

        foreach ($colunas as $tabela => $nomes) {
            foreach ($nomes as $nome) {
                $coluna = $this->coluna($tabela, $nome);

                $this->assertSame('char(36)', $coluna->COLUMN_TYPE, "{$tabela}.{$nome}");
                $this->assertSame('ascii', $coluna->CHARACTER_SET_NAME, "{$tabela}.{$nome}");
                $this->assertSame('ascii_bin', $coluna->COLLATION_NAME, "{$tabela}.{$nome}");
            }
        }
    }

    public function test_a_sessao_preserva_um_uuid_completo_sem_conversao(): void
    {
        $usuario = $this->criarProdutor();
        $identificador = 'sessao-'.Str::random(20);

        DB::table('sessions')->insert([
            'id' => $identificador,
            'user_id' => $usuario,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => 'carga',
            'last_activity' => time(),
        ]);

        $gravado = DB::table('sessions')->where('id', $identificador)->value('user_id');

        // O ponto do teste: 36 caracteres de volta, identicos. Uma coluna
        // BIGINT — o padrao do framework — nao comporta o UUID textual, e o
        // MySQL em modo estrito recusa a gravacao em vez de acomoda-la.
        $this->assertSame($usuario, $gravado);
        $this->assertSame(36, strlen((string) $gravado));
    }

    public function test_a_coluna_de_usuario_da_sessao_e_anulavel_e_indexada_sem_chave_estrangeira(): void
    {
        $this->assertSame('YES', $this->coluna('sessions', 'user_id')->IS_NULLABLE);
        $this->assertTrue($this->temIndice('sessions', ['user_id']));

        $estrangeiras = array_filter(
            $this->chavesEstrangeiras(),
            static fn (object $fk): bool => $fk->TABLE_NAME === 'sessions'
        );

        // Sem chave estrangeira de proposito: sessao orfa e lixo a expirar, nao
        // um erro de integridade que impede apagar um usuario.
        $this->assertSame([], array_values($estrangeiras));
    }

    // -----------------------------------------------------------------------
    // Enums, defaults e anulabilidade
    // -----------------------------------------------------------------------

    public function test_os_enums_declaram_exatamente_os_valores_do_plano(): void
    {
        $this->assertSame("enum('producer','consumer')", $this->coluna('users', 'role')->COLUMN_TYPE);
        $this->assertSame("enum('draft','available')", $this->coluna('courses', 'state')->COLUMN_TYPE);
        $this->assertSame(
            "enum('pending','uploading','uploaded','processing','ready','failed')",
            $this->coluna('video_attempts', 'state')->COLUMN_TYPE
        );
        $this->assertSame(
            "enum('accepted','rejected_permanent')",
            $this->coluna('webhook_events', 'outcome')->COLUMN_TYPE
        );
    }

    public function test_o_curso_nasce_em_rascunho(): void
    {
        $this->assertSame('draft', $this->coluna('courses', 'state')->COLUMN_DEFAULT);

        $curso = $this->criarCurso($this->criarProdutor());

        $this->assertSame('draft', DB::table('courses')->where('id', $curso)->value('state'));
    }

    public function test_as_colunas_que_precisam_admitir_ausencia_sao_anulaveis(): void
    {
        // Cada nulo aqui carrega significado: rascunho, sem video atual, evento
        // sobre tentativa inexistente, reserva ainda sem desfecho.
        $this->assertSame('YES', $this->coluna('lessons', 'published_at')->IS_NULLABLE);
        $this->assertSame('YES', $this->coluna('lessons', 'current_video_attempt_id')->IS_NULLABLE);
        $this->assertSame('YES', $this->coluna('webhook_events', 'video_attempt_id')->IS_NULLABLE);
        $this->assertSame('YES', $this->coluna('webhook_events', 'outcome')->IS_NULLABLE);
        $this->assertSame('YES', $this->coluna('video_attempts', 'verified_size')->IS_NULLABLE);
        $this->assertSame('YES', $this->coluna('video_attempts', 'playback_reference')->IS_NULLABLE);

        // E o que nao pode faltar continua obrigatorio.
        $this->assertSame('NO', $this->coluna('webhook_events', 'received_video_id')->IS_NULLABLE);
        $this->assertSame('NO', $this->coluna('webhook_events', 'processed_at')->IS_NULLABLE);
        $this->assertSame('NO', $this->coluna('video_attempts', 'declared_size')->IS_NULLABLE);
    }

    public function test_os_tamanhos_declarados_e_verificados_sao_inteiros_sem_sinal(): void
    {
        foreach (['declared_size', 'verified_size'] as $nome) {
            $this->assertStringContainsString('unsigned', $this->coluna('video_attempts', $nome)->COLUMN_TYPE);
        }

        foreach ([['modules', 'position'], ['lessons', 'position']] as [$tabela, $nome]) {
            $this->assertStringContainsString('unsigned', $this->coluna($tabela, $nome)->COLUMN_TYPE);
        }
    }

    // -----------------------------------------------------------------------
    // Chaves estrangeiras e acoes de exclusao
    // -----------------------------------------------------------------------

    public function test_as_chaves_estrangeiras_usam_as_acoes_de_exclusao_do_plano(): void
    {
        $esperado = [
            'courses.owner_id' => ['users', 'RESTRICT'],
            'modules.course_id' => ['courses', 'CASCADE'],
            'lessons.module_id' => ['modules', 'CASCADE'],
            'lessons.current_video_attempt_id' => ['video_attempts', 'SET NULL'],
            'video_attempts.lesson_id' => ['lessons', 'CASCADE'],
            'course_access_grants.course_id' => ['courses', 'CASCADE'],
            'course_access_grants.consumer_id' => ['users', 'CASCADE'],
            'webhook_events.video_attempt_id' => ['video_attempts', 'SET NULL'],
        ];

        $encontrado = [];
        foreach ($this->chavesEstrangeiras() as $fk) {
            $encontrado["{$fk->TABLE_NAME}.{$fk->COLUMN_NAME}"] = [$fk->REFERENCED_TABLE_NAME, $fk->DELETE_RULE];
        }

        ksort($esperado);
        ksort($encontrado);

        $this->assertSame($esperado, $encontrado);
    }

    public function test_o_identificador_recebido_pelo_webhook_nao_tem_chave_estrangeira(): void
    {
        // A ausencia e deliberada: um evento legitimo pode apontar para uma
        // tentativa que nao existe, e ele precisa receber desfecho registrado.
        $chaves = array_filter(
            $this->chavesEstrangeiras(),
            static fn (object $fk): bool => $fk->TABLE_NAME === 'webhook_events' && $fk->COLUMN_NAME === 'received_video_id'
        );

        $this->assertSame([], array_values($chaves));
    }

    public function test_evento_para_tentativa_inexistente_pode_ser_registrado_com_rejeicao_permanente(): void
    {
        $inexistente = (string) Str::uuid7();

        DB::table('webhook_events')->insert([
            'id' => (string) Str::uuid7(),
            'event_id' => 'evt_orfao',
            'received_video_id' => $inexistente,
            'video_attempt_id' => null,
            'outcome' => 'rejected_permanent',
            'received_status' => 'ready',
            'processed_at' => now(),
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt_orfao',
            'received_video_id' => $inexistente,
            'video_attempt_id' => null,
        ]);
    }

    public function test_apagar_a_tentativa_anula_o_ponteiro_da_aula(): void
    {
        [$aula, $tentativa] = $this->criarAulaComTentativa();

        DB::table('lessons')->where('id', $aula)->update(['current_video_attempt_id' => $tentativa]);
        DB::table('video_attempts')->where('id', $tentativa)->delete();

        // A aula sobrevive e volta a nao ter video atual, que e o estado em que
        // ela nasce. CASCADE aqui apagaria a aula junto — perda de conteudo por
        // causa de uma midia.
        $this->assertDatabaseHas('lessons', ['id' => $aula]);
        $this->assertNull(DB::table('lessons')->where('id', $aula)->value('current_video_attempt_id'));
    }

    public function test_apagar_o_curso_leva_modulos_e_aulas(): void
    {
        $curso = $this->criarCurso($this->criarProdutor());
        $modulo = $this->criarModulo($curso);
        $aula = $this->criarAula($modulo);

        DB::table('courses')->where('id', $curso)->delete();

        $this->assertDatabaseMissing('modules', ['id' => $modulo]);
        $this->assertDatabaseMissing('lessons', ['id' => $aula]);
    }

    public function test_apagar_produtor_com_curso_e_recusado(): void
    {
        $produtor = $this->criarProdutor();
        $this->criarCurso($produtor);

        $this->assertRecusado(fn () => DB::table('users')->where('id', $produtor)->delete());
    }

    // -----------------------------------------------------------------------
    // Unicidade, ordem e indices
    // -----------------------------------------------------------------------

    public function test_email_duplicado_e_recusado(): void
    {
        $this->criarProdutor('repetido@video-platform.test');

        $this->assertRecusado(fn () => $this->criarProdutor('repetido@video-platform.test'));
    }

    public function test_duas_posicoes_iguais_dentro_do_mesmo_pai_sao_recusadas(): void
    {
        $curso = $this->criarCurso($this->criarProdutor());
        $modulo = $this->criarModulo($curso, 1);

        $this->assertRecusado(fn () => $this->criarModulo($curso, 1));

        $this->criarAula($modulo, 1);
        $this->assertRecusado(fn () => $this->criarAula($modulo, 1));
    }

    public function test_a_mesma_posicao_em_pais_diferentes_e_aceita(): void
    {
        $produtor = $this->criarProdutor();
        $primeiro = $this->criarModulo($this->criarCurso($produtor), 1);
        $segundo = $this->criarModulo($this->criarCurso($produtor), 1);

        $this->assertDatabaseHas('modules', ['id' => $primeiro, 'position' => 1]);
        $this->assertDatabaseHas('modules', ['id' => $segundo, 'position' => 1]);

        $this->criarAula($primeiro, 1);
        $this->criarAula($segundo, 1);

        $this->assertSame(2, DB::table('lessons')->where('position', 1)->count());
    }

    public function test_posicao_zero_e_recusada(): void
    {
        $curso = $this->criarCurso($this->criarProdutor());

        // `UNSIGNED` ja barra o negativo; o zero so e barrado pelo CHECK.
        $this->assertRecusado(fn () => $this->criarModulo($curso, 0));

        $modulo = $this->criarModulo($curso, 1);
        $this->assertRecusado(fn () => $this->criarAula($modulo, 0));
    }

    public function test_concessao_duplicada_e_recusada(): void
    {
        $curso = $this->criarCurso($this->criarProdutor());
        $consumidor = $this->criarConsumidor();

        $this->conceder($curso, $consumidor);

        $this->assertRecusado(fn () => $this->conceder($curso, $consumidor));
    }

    public function test_event_id_duplicado_e_recusado(): void
    {
        $this->registrarEvento('evt_repetido');

        // A UNIQUE e o que sustenta a estrategia de reservar primeiro: duas
        // entregas simultaneas do mesmo evento nao passam juntas.
        $this->assertRecusado(fn () => $this->registrarEvento('evt_repetido'));
    }

    public function test_chave_de_storage_duplicada_e_recusada(): void
    {
        [, $tentativa] = $this->criarAulaComTentativa();
        $chave = DB::table('video_attempts')->where('id', $tentativa)->value('storage_key');
        $aula = DB::table('video_attempts')->where('id', $tentativa)->value('lesson_id');

        $this->assertRecusado(fn () => $this->criarTentativa($aula, chave: $chave));
    }

    public function test_os_indices_de_leitura_declarados_pelo_plano_existem(): void
    {
        $this->assertTrue($this->temIndice('courses', ['owner_id', 'created_at']));
        $this->assertTrue($this->temIndice('video_attempts', ['lesson_id', 'created_at']));

        // A UNIQUE dentro do pai tambem e o indice que ordena a leitura da
        // estrutura: um segundo indice nas mesmas colunas seria duplicata paga
        // em toda escrita.
        $this->assertTrue($this->temIndice('modules', ['course_id', 'position'], unico: true));
        $this->assertTrue($this->temIndice('lessons', ['module_id', 'position'], unico: true));
        $this->assertTrue($this->temIndice('course_access_grants', ['course_id', 'consumer_id'], unico: true));
        $this->assertTrue($this->temIndice('webhook_events', ['event_id'], unico: true));
        $this->assertTrue($this->temIndice('video_attempts', ['storage_key'], unico: true));
        $this->assertTrue($this->temIndice('users', ['email'], unico: true));
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function schema(): string
    {
        return DB::getDatabaseName();
    }

    /**
     * @return list<string>
     */
    private function tabelas(): array
    {
        $nomes = array_map(
            static fn (object $linha): string => $linha->TABLE_NAME,
            DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$this->schema()])
        );

        sort($nomes);

        return $nomes;
    }

    private function coluna(string $tabela, string $nome): object
    {
        $linhas = DB::select(
            'SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->schema(), $tabela, $nome]
        );

        $this->assertCount(1, $linhas, "coluna {$tabela}.{$nome} nao encontrada");

        return $linhas[0];
    }

    /**
     * @return list<object>
     */
    private function chavesEstrangeiras(): array
    {
        return DB::select(
            'SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
             WHERE rc.CONSTRAINT_SCHEMA = ?',
            [$this->schema()]
        );
    }

    /**
     * @param  list<string>  $colunas
     */
    private function temIndice(string $tabela, array $colunas, bool $unico = false): bool
    {
        $linhas = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->schema(), $tabela]
        );

        $indices = [];
        foreach ($linhas as $linha) {
            $indices[$linha->INDEX_NAME]['colunas'][] = $linha->COLUMN_NAME;
            $indices[$linha->INDEX_NAME]['unico'] = (int) $linha->NON_UNIQUE === 0;
        }

        foreach ($indices as $indice) {
            $prefixo = array_slice($indice['colunas'], 0, count($colunas));

            if ($prefixo === $colunas && (! $unico || $indice['unico'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Afirma que o banco recusou a escrita.
     *
     * O que importa e a recusa vir do servidor, e nao de uma checagem em PHP:
     * e a constraint que continua valendo quando alguem escreve por fora da
     * aplicacao.
     */
    private function assertRecusado(callable $acao): void
    {
        try {
            $acao();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('o banco aceitou uma escrita que deveria ter sido recusada');
    }

    private function criarProdutor(?string $email = null): string
    {
        return $this->criarUsuario('producer', $email);
    }

    private function criarConsumidor(?string $email = null): string
    {
        return $this->criarUsuario('consumer', $email);
    }

    private function criarUsuario(string $perfil, ?string $email = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Usuario de Teste',
            'email' => $email ?? Str::random(12).'@video-platform.test',
            'password' => 'hash-ficticio-de-teste',
            'role' => $perfil,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function criarCurso(string $dono): string
    {
        $id = (string) Str::uuid7();

        DB::table('courses')->insert([
            'id' => $id,
            'owner_id' => $dono,
            'title' => 'Curso de Teste',
            'description' => 'Descricao de teste.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function criarModulo(string $curso, int $posicao = 1): string
    {
        $id = (string) Str::uuid7();

        DB::table('modules')->insert([
            'id' => $id,
            'course_id' => $curso,
            'title' => 'Modulo de Teste',
            'position' => $posicao,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function criarAula(string $modulo, int $posicao = 1): string
    {
        $id = (string) Str::uuid7();

        DB::table('lessons')->insert([
            'id' => $id,
            'module_id' => $modulo,
            'title' => 'Aula de Teste',
            'position' => $posicao,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function criarTentativa(string $aula, ?string $chave = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('video_attempts')->insert([
            'id' => $id,
            'lesson_id' => $aula,
            'state' => 'pending',
            'declared_filename' => 'teste.mp4',
            'declared_content_type' => 'video/mp4',
            'declared_size' => 1024,
            'storage_key' => $chave ?? "videos/{$id}/original.mp4",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array{string, string}
     */
    private function criarAulaComTentativa(): array
    {
        $aula = $this->criarAula($this->criarModulo($this->criarCurso($this->criarProdutor())));

        return [$aula, $this->criarTentativa($aula)];
    }

    private function conceder(string $curso, string $consumidor): void
    {
        DB::table('course_access_grants')->insert([
            'id' => (string) Str::uuid7(),
            'course_id' => $curso,
            'consumer_id' => $consumidor,
            'granted_at' => now(),
        ]);
    }

    private function registrarEvento(string $eventId): void
    {
        DB::table('webhook_events')->insert([
            'id' => (string) Str::uuid7(),
            'event_id' => $eventId,
            'received_video_id' => (string) Str::uuid7(),
            'video_attempt_id' => null,
            'outcome' => 'accepted',
            'received_status' => 'ready',
            'processed_at' => now(),
        ]);
    }
}
