<?php

namespace App\Providers;

use App\Services\AiService\AiServiceClient;
use App\Services\Mail\DnsDomainMailChecker;
use App\Services\Mail\DomainMailChecker;
use App\Services\Mail\GmailProvider;
use App\Services\Mail\MailProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiServiceClient::class, fn () => AiServiceClient::fromConfig());
        // Gmail only for now; Outlook would add a provider chosen per account.
        $this->app->bind(MailProvider::class, GmailProvider::class);
        $this->app->singleton(DomainMailChecker::class, DnsDomainMailChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `composer dev` also starts the Python AI service (see ../ai-service/README.md).
        if (is_file(base_path('../ai-service/.venv/bin/uvicorn'))) {
            DevCommands::register(
                'cd ../ai-service && .venv/bin/uvicorn app.api.main:app --port 8001 --env-file .env --reload',
                'ai',
            );
        }
        // Runs emails:dispatch-due (every minute) and emails:sync (every 10 minutes).
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
