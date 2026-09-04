<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessoes em banco, no formato padrao do framework — com uma excecao.
 *
 * A sessao vive no MySQL pelo mesmo motivo da fila: o banco ja e obrigatorio e
 * nao ha volume que justifique um servico a mais (plan §9.1). E aqui que a
 * autenticacao em cookie `HttpOnly` guarda estado, e por isso esta tabela e
 * pre-requisito do login que chega em T030.
 *
 * **A excecao e `user_id`.** A migration padrao a declara como `BIGINT`, porque
 * o esqueleto do framework pressupoe chave primaria auto-incremento. Este
 * projeto usa UUIDv7 em `CHAR(36)` (plan §7.1), e e o identificador do usuario
 * autenticado que o framework grava nesta coluna. Um `BIGINT` nao comporta o
 * UUID textual: com o MySQL em modo estrito, a gravacao da sessao falha, e por
 * consequencia o fluxo de autenticacao nao consegue persistir a sessao.
 *
 * A coluna continua **anulavel** (sessao de visitante nao tem usuario),
 * **indexada** (o framework consulta por ela ao invalidar as sessoes de alguem)
 * e **sem chave estrangeira**, preservando o acoplamento fraco da tabela padrao:
 * uma sessao orfa e lixo a expirar, nao um erro de integridade que impede
 * apagar um usuario.
 *
 * Charset `ascii` e collation `ascii_bin` acompanham as demais colunas de
 * identificador: um byte por caractere e comparacao exata, sem regra de caixa
 * ou acento que nao faz sentido para um UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->string('id')->primary();
            $table->char('user_id', 36)->charset('ascii')->collation('ascii_bin')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
