<?php

declare(strict_types=1);

namespace App\Providers;

use App\Catalog\Application\Port\CatalogReadModel;
use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentCatalogReadModel;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentCourseRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentLessonRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentModuleRepository;
use App\Shared\Application\Port\Clock;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Infrastructure\Clock\SystemClock;
use App\Shared\Infrastructure\Transaction\DatabaseTransactionManager;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Infrastructure\Storage\S3ObjectStorage;
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
        $this->app->singleton(TransactionManager::class, DatabaseTransactionManager::class);

        $this->app->singleton(CourseRepository::class, EloquentCourseRepository::class);
        $this->app->singleton(ModuleRepository::class, EloquentModuleRepository::class);
        $this->app->singleton(LessonRepository::class, EloquentLessonRepository::class);
        $this->app->singleton(CatalogReadModel::class, EloquentCatalogReadModel::class);

        // O adapter de storage e o unico registro que nao e uma classe para
        // outra: ele precisa da configuracao para montar os dois clientes do SDK,
        // e a configuracao e resolvida aqui em vez de dentro do adapter — que
        // assim continua construivel com valores explicitos, inclusive nos testes
        // que apontam para um endereco indisponivel de proposito.
        $this->app->singleton(
            ObjectStorage::class,
            static fn (): ObjectStorage => S3ObjectStorage::fromConfig(config('storage')),
        );
    }

    public function boot(): void
    {
        //
    }
}
