<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Public pages: the home page, Privacy Policy and Terms of Service (linked from the Google OAuth consent screen). */
class LegalController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('welcome', ['contactEmail' => $this->details()['contact_email']]);
    }

    public function privacy(): Response
    {
        return Inertia::render('legal/privacy', ['legal' => $this->details()]);
    }

    public function terms(): Response
    {
        return Inertia::render('legal/terms', ['legal' => $this->details()]);
    }

    /** @return array{app: string, operator: string, contact_email: string|null, jurisdiction: string|null, updated: string} */
    private function details(): array
    {
        return [
            'app' => (string) config('app.name'),
            'operator' => (string) config('legal.operator'),
            'contact_email' => config('legal.contact_email') ?: null,
            'jurisdiction' => config('legal.jurisdiction') ?: null,
            'updated' => (string) config('legal.updated'),
        ];
    }
}
