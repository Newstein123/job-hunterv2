<?php

namespace App\Services\AiService;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the Python AI service. Only call this from queued jobs, never the request cycle.
 */
class AiServiceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('services.ai.url'), config('services.ai.token'), config('services.ai.timeout'));
    }

    /**
     * @param  array<string, mixed>  $payload  see PipelinePayload
     * @return array<string, mixed> PipelineResult JSON
     */
    public function generateApplication(array $payload): array
    {
        return $this->post('/v1/applications/generate', $payload);
    }

    /**
     * Stages 1-4 only (analysis + fit), one LLM call.
     *
     * @param  array<string, mixed>  $payload  see PipelinePayload
     * @return array<string, mixed> MatchPreview JSON
     */
    public function matchJob(array $payload): array
    {
        return $this->post('/v1/jobs/match', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> ReplyAnalysis JSON
     */
    public function analyzeReply(array $payload): array
    {
        return $this->post('/v1/replies/analyze', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> ReplyDraft JSON
     */
    public function draftReply(array $payload): array
    {
        return $this->post('/v1/replies/draft', $payload);
    }

    /**
     * Course checklist + AI suggestions for one resume.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reviewResume(array $payload): array
    {
        return $this->post('/v1/resumes/review', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->token) {
            throw new AiServiceException('AI_SERVICE_TOKEN is not configured');
        }

        try {
            $response = $this->request()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new AiServiceException('The AI service is unreachable: '.$e->getMessage());
        }

        if ($response->failed()) {
            $detail = $response->json('detail');
            $message = is_string($detail) ? $detail : 'The AI service rejected the request';
            throw new AiServiceException($message, $response->status());
        }

        return $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Service-Token' => $this->token])
            ->acceptJson()
            ->timeout($this->timeout);
    }
}
