<?php

namespace Tests;

use App\Services\Mail\DomainMailChecker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** Domains the fake DNS treats as unable to receive mail. */
    public const NO_MAIL_DOMAINS = ['no-mail-servers.com', 'typo-domain.cmo'];

    protected function setUp(): void
    {
        parent::setUp();

        // Never reach a real AI service, Gmail or Google from tests: every HTTP call must be faked.
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'test-token']);
        Http::preventStrayRequests();

        // No real DNS lookups in tests.
        $this->app->instance(DomainMailChecker::class, new class implements DomainMailChecker
        {
            public function acceptsMail(string $domain): ?bool
            {
                return ! in_array(strtolower($domain), TestCase::NO_MAIL_DOMAINS, true);
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
