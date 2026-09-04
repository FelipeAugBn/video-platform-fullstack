<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Database\Seeders\EvaluationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * O cenario de avaliacao, conferido registro a registro.
 *
 * Duas coisas sao provadas aqui, e elas sao diferentes.
 *
 * A primeira e que o seed **monta o cenario certo**: as tres contas, o curso
 * vazio da jornada, a concessao, o curso de outro produtor e a cadeia dedicada
 * a falha. Um seed que rode sem erro mas prepare o cenario errado quebra a
 * demonstracao e o E2E longe daqui, e o defeito aparece como "o teste de jornada
 * esta instavel".
 *
 * A segunda e a **idempotencia**, e ela precisa ser exercida de verdade:
 * `migrate:fresh --seed` nao serve como prova, porque apaga tudo antes de semear
 * e a segunda execucao comeca de um banco vazio. O teste do fim deste arquivo
 * executa o seeder duas vezes sobre o mesmo banco e compara.
 */
final class SeedTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUTOR = 'producer@video-platform.test';

    private const CONSUMIDOR = 'consumer@video-platform.test';

    private const SEGUNDO_PRODUTOR = 'other-producer@video-platform.test';

    private const SENHA = 'VideoDemo2026!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EvaluationSeeder::class);
    }

    // -----------------------------------------------------------------------
    // Contas
    // -----------------------------------------------------------------------

    public function test_prepara_as_tres_contas_com_seus_perfis(): void
    {
        $this->assertSame(3, DB::table('users')->count());

        $this->assertSame('producer', $this->usuario(self::PRODUTOR)->role);
        $this->assertSame('consumer', $this->usuario(self::CONSUMIDOR)->role);
        $this->assertSame('producer', $this->usuario(self::SEGUNDO_PRODUTOR)->role);
    }

    public function test_a_senha_de_demonstracao_e_gravada_como_hash_e_confere(): void
    {
        foreach ([self::PRODUTOR, self::CONSUMIDOR, self::SEGUNDO_PRODUTOR] as $email) {
            $hash = $this->usuario($email)->password;

            $this->assertNotSame(self::SENHA, $hash, 'a senha nao pode estar em texto puro');
            $this->assertTrue(Hash::check(self::SENHA, $hash), "credencial invalida para {$email}");
        }
    }

    // -----------------------------------------------------------------------
    // Cenario principal
    // -----------------------------------------------------------------------

    public function test_o_curso_da_jornada_pertence_ao_produtor_e_esta_em_rascunho(): void
    {
        $curso = $this->cursoPrincipal();

        $this->assertSame('draft', $curso->state);
        $this->assertSame($this->usuario(self::PRODUTOR)->id, $curso->owner_id);
    }

    public function test_o_consumidor_tem_concessao_para_o_curso_da_jornada(): void
    {
        $this->assertSame(1, DB::table('course_access_grants')->count());

        $this->assertDatabaseHas('course_access_grants', [
            'course_id' => $this->cursoPrincipal()->id,
            'consumer_id' => $this->usuario(self::CONSUMIDOR)->id,
        ]);
    }

    public function test_o_curso_da_jornada_comeca_vazio(): void
    {
        // O vazio nao e detalhe: e ele que AC-E2E-001 preenche pela interface.
        // Um modulo semeado aqui faria a jornada partir de um estado que ela
        // deveria construir.
        $this->assertSame(0, $this->modulosDoCurso($this->cursoPrincipal()->id));
        $this->assertSame(0, $this->aulasDoCurso($this->cursoPrincipal()->id));
    }

    // -----------------------------------------------------------------------
    // Cenario de isolamento
    // -----------------------------------------------------------------------

    public function test_existe_um_curso_de_outro_produtor_sem_concessao(): void
    {
        $alheio = DB::table('courses')->where('owner_id', $this->usuario(self::SEGUNDO_PRODUTOR)->id)->first();

        $this->assertNotNull($alheio, 'o cenario de isolamento precisa de um curso de outro dono');
        $this->assertSame(0, DB::table('course_access_grants')->where('course_id', $alheio->id)->count());
    }

    // -----------------------------------------------------------------------
    // Cenario dedicado de falha
    // -----------------------------------------------------------------------

    public function test_a_tentativa_de_falha_usa_o_identificador_fixo_e_esta_em_processamento(): void
    {
        $tentativa = $this->tentativaDeFalha();

        $this->assertSame('01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60', $tentativa->id);
        $this->assertSame('processing', $tentativa->state);
    }

    public function test_a_cadeia_do_cenario_de_falha_pertence_ao_produtor_principal(): void
    {
        $tentativa = $this->tentativaDeFalha();

        $aula = DB::table('lessons')->where('id', $tentativa->lesson_id)->first();
        $modulo = DB::table('modules')->where('id', $aula->module_id)->first();
        $curso = DB::table('courses')->where('id', $modulo->course_id)->first();

        $this->assertSame($this->usuario(self::PRODUTOR)->id, $curso->owner_id);

        // E um curso SEPARADO do da jornada: acionar a falha nao pode sujar o
        // caminho da demonstracao de sucesso.
        $this->assertNotSame($this->cursoPrincipal()->id, $curso->id);
    }

    public function test_a_aula_do_cenario_aponta_para_a_tentativa_e_nao_esta_publicada(): void
    {
        $tentativa = $this->tentativaDeFalha();
        $aula = DB::table('lessons')->where('id', $tentativa->lesson_id)->first();

        $this->assertSame($tentativa->id, $aula->current_video_attempt_id);
        $this->assertNull($aula->published_at);
    }

    public function test_a_tentativa_de_falha_ainda_nao_tem_desfecho(): void
    {
        $tentativa = $this->tentativaDeFalha();

        // Nem referencia de reproducao nem falha registrada: o callback e quem
        // vai produzir um dos dois, em T067.
        $this->assertNull($tentativa->playback_reference);
        $this->assertNull($tentativa->failure_code);
        $this->assertNull($tentativa->failure_message);
    }

    public function test_nenhum_evento_de_webhook_e_semeado(): void
    {
        $this->assertSame(0, DB::table('webhook_events')->count());
    }

    // -----------------------------------------------------------------------
    // Identificadores
    // -----------------------------------------------------------------------

    public function test_todo_identificador_preparado_e_um_uuid_versao_7(): void
    {
        $tabelas = ['users', 'courses', 'modules', 'lessons', 'video_attempts', 'course_access_grants'];

        foreach ($tabelas as $tabela) {
            foreach (DB::table($tabela)->pluck('id') as $id) {
                $this->assertSame(
                    7,
                    Uuid::fromString($id)->getFields()->getVersion(),
                    "{$tabela}.{$id} deveria ser UUIDv7"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Idempotencia
    // -----------------------------------------------------------------------

    public function test_a_segunda_execucao_nao_altera_nada(): void
    {
        $antes = $this->retrato();

        // Sem `migrate:fresh` entre as duas: recriar o banco comecaria de vazio
        // e nao exerceria a repeticao, que e justamente o que esta sob teste.
        $this->seed(EvaluationSeeder::class);

        $depois = $this->retrato();

        $this->assertSame($antes, $depois);

        // E as afirmacoes que mais importam, ditas com nome proprio:
        $this->assertSame(3, DB::table('users')->count());
        $this->assertSame(3, DB::table('courses')->count());
        $this->assertSame(1, DB::table('course_access_grants')->count());
        $this->assertSame('01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60', $this->tentativaDeFalha()->id);
        $this->assertSame('processing', $this->tentativaDeFalha()->state);
        $this->assertSame(0, $this->modulosDoCurso($this->cursoPrincipal()->id));
        $this->assertSame(0, $this->aulasDoCurso($this->cursoPrincipal()->id));
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Retrato comparavel do banco: contagens, identificadores, relacoes e o
     * proprio hash da senha — que mudaria se o seeder reescrevesse a linha,
     * porque cada hash carrega um sal novo.
     *
     * @return array<string, mixed>
     */
    private function retrato(): array
    {
        $retrato = [];

        foreach (['users', 'courses', 'modules', 'lessons', 'video_attempts', 'course_access_grants', 'webhook_events'] as $tabela) {
            $retrato["contagem.{$tabela}"] = DB::table($tabela)->count();
            $retrato["ids.{$tabela}"] = DB::table($tabela)->orderBy('id')->pluck('id')->all();
        }

        $retrato['relacoes.cursos'] = $this->linhas('courses', ['id', 'owner_id', 'state']);
        $retrato['relacoes.aulas'] = $this->linhas('lessons', ['id', 'module_id', 'current_video_attempt_id', 'published_at']);
        $retrato['relacoes.concessoes'] = $this->linhas('course_access_grants', ['course_id', 'consumer_id']);
        $retrato['relacoes.tentativas'] = $this->linhas('video_attempts', ['id', 'lesson_id', 'state']);
        $retrato['senhas'] = DB::table('users')->orderBy('id')->pluck('password')->all();

        return $retrato;
    }

    /**
     * Linhas como arrays puros, e nao objetos.
     *
     * Duas leituras produzem instancias diferentes de `stdClass`, e uma
     * comparacao estrita as consideraria distintas mesmo com o mesmo conteudo —
     * o retrato acusaria uma mudanca que nao houve.
     *
     * @param  list<string>  $colunas
     * @return list<array<string, mixed>>
     */
    private function linhas(string $tabela, array $colunas): array
    {
        return DB::table($tabela)
            ->orderBy('id')
            ->get($colunas)
            ->map(static fn (object $linha): array => (array) $linha)
            ->all();
    }

    private function usuario(string $email): object
    {
        $usuario = DB::table('users')->where('email', $email)->first();

        $this->assertNotNull($usuario, "conta {$email} nao foi preparada");

        return $usuario;
    }

    private function cursoPrincipal(): object
    {
        $curso = DB::table('courses')
            ->join('course_access_grants', 'course_access_grants.course_id', '=', 'courses.id')
            ->select('courses.*')
            ->first();

        $this->assertNotNull($curso, 'o curso da jornada precisa existir e estar concedido');

        return $curso;
    }

    private function tentativaDeFalha(): object
    {
        $tentativa = DB::table('video_attempts')->first();

        $this->assertNotNull($tentativa, 'o cenario de falha precisa de uma tentativa preparada');

        return $tentativa;
    }

    private function modulosDoCurso(string $curso): int
    {
        return DB::table('modules')->where('course_id', $curso)->count();
    }

    private function aulasDoCurso(string $curso): int
    {
        return DB::table('lessons')
            ->join('modules', 'modules.id', '=', 'lessons.module_id')
            ->where('modules.course_id', $curso)
            ->count();
    }
}
