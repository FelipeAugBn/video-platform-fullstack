<?php

declare(strict_types=1);

namespace App\Providers;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentCourseRepository;
use App\Shared\Application\Port\Clock;
use App\Shared\Infrastructure\Clock\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Onde cada porta encontra o seu adapter.
 *
 * E o unico ponto da aplicacao em que uma classe de `Application` e ligada a uma
 * de `Infrastructure`. Os casos de uso declaram a interface no construtor e o
 * container resolve a implementacao a partir daqui — e por isso que trocar o
 * armazenamento de cursos, ou congelar o relogio num teste, nao exige tocar em
 * nenhum caso de uso (plan §5.1).
 *
 * `singleton`, e nao `bind`: as duas implementacoes sao sem estado, e uma
 * instancia por requisicao basta.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(CourseRepository::class, EloquentCourseRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
