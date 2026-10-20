<?php

namespace App\Services\AiService;

use App\Models\LlmUsage;

/**
 * Records the token usage the AI service reports for each call, priced with config('services.ai_pricing').
 * Thinking tokens are billed at the output rate.
 */
class UsageRecorder
{
    /**
     * @param  array<int, array<string, mixed>>|null  $usage  LLMUsage[] from the AI service
     */
    public function record(?array $usage, int $userId, ?int $jobId = null, ?int $applicationId = null): void
    {
        foreach ($usage ?? [] as $call) {
            $input = (int) ($call['input_tokens'] ?? 0);
            $output = (int) ($call['output_tokens'] ?? 0);
            $thinking = (int) ($call['thinking_tokens'] ?? 0);
            $model = (string) ($call['model'] ?? 'unknown');

            LlmUsage::create([
                'user_id' => $userId,
                'job_id' => $jobId,
                'application_id' => $applicationId,
                'task' => (string) ($call['task'] ?? 'llm'),
                'model' => $model,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'thinking_tokens' => $thinking,
                'cost_usd' => self::cost($model, $input, $output + $thinking),
            ]);
        }
    }

    public static function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        // Look the model up by array key: config('a.gemini-2.5-flash') would split on the dots in the name.
        $table = (array) config('services.ai_pricing');
        $prices = $table[$model] ?? $table['default'];

        return round(($inputTokens * $prices['input'] + $outputTokens * $prices['output']) / 1_000_000, 6);
    }
}
