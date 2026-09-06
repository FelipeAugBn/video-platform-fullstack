<?php

declare(strict_types=1);

namespace App\Providers;

use App\Catalog\Application\Port\CatalogReadModel;
use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentCatalogReadModel;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentConsumerCatalogReadModel;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentCourseRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentLessonRepository;
use App\Catalog\Infrastructure\Persistence\Eloquent\EloquentModuleRepository;
use App\Identity\Application\Port\AccessGrantRepository;
use App\Identity\Infrastructure\Persistence\Eloquent\EloquentAccessGrantRepository;
use App\Shared\Application\Port\Clock;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Infrastructure\Clock\SystemClock;
use App\Shared\Infrastructure\Transaction\DatabaseTransactionManager;
use App\Video\Application\GetPlayback\GetPlayback;
use App\Video\Application\Port\AttemptLock;
use App\Video\Application\Port\ObjectStorage;
use App\Video\Application\Port\ProcessingQueue;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Application\Port\WebhookEventStore;
use App\Video\Domain\UploadPolicy;
use App\Video\Infrastructure\Lock\CacheAttemptLock;
use App\Video\Infrastructure\Persistence\Eloquent\EloquentVideoAttemptRepository;
use App\Video\Infrastructure\Persistence\Eloquent\EloquentWebhookEventStore;
use App\Video\Infrastructure\Queue\QueuedProcessing;
use App\Video\Infrastructure\Simulator\CallbackDelivery;
use App\Video\Infrastructure\Storage\S3ObjectStorage;
use App\Video\Infrastructure\Webhook\VerifyWebhookSignature;
use App\Video\Interfaces\Http\Controller\ProcessingCallbackController;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
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
        $this->app->singleton(ConsumerCatalogReadModel::class, EloquentConsumerCatalogReadModel::class);

        // Concessao mora em `Identity`, e nao no catalogo: a pergunta e sobre o
        // usuario, e nao sobre o conteudo. Propriedade e concessao continuam
        // sendo duas portas separadas, com duas tabelas separadas (plan §9.3).
        $this->app->singleton(AccessGrantRepository::class, EloquentAccessGrantRepository::class);

        // O adapter de storage e o unico registro que nao e uma classe para
        // outra: ele precisa da configuracao para montar os dois clientes do SDK,
        // e a configuracao e resolvida aqui em vez de dentro do adapter — que
        // assim continua construivel com valores explicitos, inclusive nos testes
        // que apontam para um endereco indisponivel de proposito.
        $this->app->singleton(
            ObjectStorage::class,
            static fn (): ObjectStorage => S3ObjectStorage::fromConfig(config('storage')),
        );

        $this->app->singleton(VideoAttemptRepository::class, EloquentVideoAttemptRepository::class);
        $this->app->singleton(WebhookEventStore::class, EloquentWebhookEventStore::class);
        $this->app->singleton(ProcessingQueue::class, QueuedProcessing::class);

        // Os quatro registros abaixo leem `config/video.php` **aqui**, e nao
        // dentro das classes. Nenhuma delas conhece a existencia de um arquivo
        // de configuracao: `UploadPolicy` vive no dominio e nao pode conhece-lo;
        // as outras tres continuam construiveis com valores explicitos, o que e
        // o que permite a um teste apontar o simulador para outro endereco ou
        // encurtar a espera do lock sem tocar no ambiente.
        $this->app->singleton(UploadPolicy::class, static fn (): UploadPolicy => new UploadPolicy(
            contentType: (string) config('video.upload.content_type'),
            maxSize: (int) config('video.upload.max_size'),
            partSize: (int) config('video.upload.part_size'),
            maxParts: (int) config('video.upload.max_parts'),
            partUrlTtl: (int) config('video.upload.part_url_ttl'),
        ));

        $this->app->singleton(AttemptLock::class, static fn ($app): AttemptLock => new CacheAttemptLock(
            cache: $app->make(CacheFactory::class),
            store: (string) config('video.upload.lock.store'),
            leaseSeconds: (int) config('video.upload.lock.lease'),
            waitSeconds: (int) config('video.upload.lock.wait'),
        ));

        $this->app->singleton(CallbackDelivery::class, static fn ($app): CallbackDelivery => new CallbackDelivery(
            http: $app->make(HttpFactory::class),
            callbackUrl: (string) config('video.simulator.callback_url'),
            secret: (string) config('video.webhook.secret'),
            timeout: (int) config('video.simulator.timeout'),
        ));

        $this->app->singleton(
            VerifyWebhookSignature::class,
            static fn (): VerifyWebhookSignature => new VerifyWebhookSignature(
                secret: (string) config('video.webhook.secret'),
                toleranceSeconds: (int) config('video.webhook.tolerance'),
            ),
        );

        // O controller do callback recebe um escalar de configuracao, e o
        // container nao adivinha escalares: sem este registro ele falharia ao
        // resolver a rota, e nao ao processar a requisicao.
        $this->app->when(ProcessingCallbackController::class)
            ->needs('$retryAfterSeconds')
            ->give(static fn (): int => (int) config('video.webhook.retry_after'));

        // Mesmo motivo, do outro lado do fluxo: a validade da URL de reproducao
        // e politica do caso de uso (plan §14.2), e ele continua construivel com
        // um valor explicito — o que permite a um teste encurtar ou alongar a
        // janela sem tocar no ambiente.
        $this->app->when(GetPlayback::class)
            ->needs('$urlTtlSeconds')
            ->give(static fn (): int => (int) config('video.playback.url_ttl'));
    }

    public function boot(): void
    {
        //
    }
}
