<?php

namespace App\Services\Mail;

use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gmail REST API. Scopes: gmail.send (send) and gmail.readonly (read employer replies so they can be
 * shown, classified and answered). Accounts connected earlier with gmail.metadata still get headers only.
 */
class GmailProvider implements MailProvider
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/gmail.readonly',
    ];

    public const REQUIRED_SCOPES = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/gmail.readonly',
    ];

    public const READ_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const THREAD_HEADERS = ['From', 'Subject', 'Message-ID', 'Auto-Submitted', 'X-Autoreply', 'X-Autorespond', 'Precedence'];

    public function send(EmailAccount $account, string $rawMessage, ?string $threadId = null): SentMessage
    {
        $response = $this->request($account, 'post', '/messages/send', array_filter([
            'raw' => rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '='),
            'threadId' => $threadId,
        ]));

        return new SentMessage((string) $response->json('id'), (string) $response->json('threadId'));
    }

    public function threadMessages(EmailAccount $account, string $threadId): array
    {
        // Repeated query keys: metadataHeaders=From&metadataHeaders=Subject&...
        $query = 'format=metadata&'.implode('&', array_map(fn ($h) => 'metadataHeaders='.rawurlencode($h), self::THREAD_HEADERS));
        $response = $this->request($account, 'get', '/threads/'.rawurlencode($threadId).'?'.$query, []);

        $messages = [];
        foreach ($response->json('messages') ?? [] as $message) {
            $headers = $this->headers($message['payload']['headers'] ?? []);
            $messages[] = new ThreadMessage(
                (string) $message['id'],
                $headers['from'] ?? '',
                array_values($message['labelIds'] ?? []),
                CarbonImmutable::createFromTimestampMs((int) ($message['internalDate'] ?? 0)),
                $headers,
            );
        }

        return $messages;
    }

    public function message(EmailAccount $account, string $messageId): FullMessage
    {
        $response = $this->request($account, 'get', '/messages/'.rawurlencode($messageId), ['format' => 'full']);
        $payload = (array) $response->json('payload');
        $headers = $this->headers($payload['headers'] ?? []);

        return new FullMessage(
            $headers['subject'] ?? '',
            $this->bodyText($payload),
            html_entity_decode((string) $response->json('snippet', ''), ENT_QUOTES | ENT_HTML5),
            $headers,
        );
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<string, string>
     */
    private function headers(array $raw): array
    {
        $headers = [];
        foreach ($raw as $header) {
            if (is_array($header) && isset($header['name'])) {
                $headers[strtolower((string) $header['name'])] = (string) ($header['value'] ?? '');
            }
        }

        return $headers;
    }

    /**
     * Plain-text body: the first text/plain part, else text/html with tags stripped.
     *
     * @param  array<string, mixed>  $payload
     */
    private function bodyText(array $payload): string
    {
        $plain = null;
        $html = null;
        $walk = function (array $part) use (&$walk, &$plain, &$html): void {
            $mime = strtolower((string) ($part['mimeType'] ?? ''));
            $data = $part['body']['data'] ?? null;
            if (is_string($data) && $data !== '' && empty($part['filename'])) {
                $decoded = (string) base64_decode(strtr($data, '-_', '+/'));
                if ($mime === 'text/plain' && $plain === null) {
                    $plain = $decoded;
                } elseif ($mime === 'text/html' && $html === null) {
                    $html = $decoded;
                }
            }
            foreach ($part['parts'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($payload);

        if ($plain !== null) {
            return trim(str_replace("\r\n", "\n", $plain));
        }
        if ($html !== null) {
            $text = preg_replace(['/<(br|\/p|\/div|\/li)[^>]*>/i', '/<(style|script)[^>]*>.*?<\/\1>/is'], ["\n", ''], $html);

            return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
        }

        return '';
    }

    public function revoke(EmailAccount $account): void
    {
        $token = $account->refresh_token ?? $account->access_token;
        if ($token) {
            rescue(fn () => Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]), report: false);
        }
    }

    /** @param array<string, mixed> $data */
    private function request(EmailAccount $account, string $method, string $path, array $data): Response
    {
        $token = $this->accessToken($account);
        $response = $this->call($token, $method, $path, $data);

        if ($response->status() === 401) {
            $response = $this->call($this->refresh($account), $method, $path, $data);
        }
        if ($response->failed()) {
            throw $this->error($response);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function call(string $token, string $method, string $path, array $data): Response
    {
        try {
            $request = Http::withToken($token)->acceptJson()->timeout(30);

            return $method === 'get' ? $request->get(self::API.$path, $data) : $request->post(self::API.$path, $data);
        } catch (ConnectionException $e) {
            throw new MailProviderException('Could not reach Gmail: '.$e->getMessage(), MailProviderException::TRANSIENT);
        }
    }

    private function accessToken(EmailAccount $account): string
    {
        $fresh = $account->access_token && $account->token_expires_at?->isAfter(now()->addMinute());

        return $fresh ? (string) $account->access_token : $this->refresh($account);
    }

    private function refresh(EmailAccount $account): string
    {
        if (! $account->refresh_token) {
            throw new MailProviderException('No refresh token. Reconnect the account.', MailProviderException::AUTH);
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $e) {
            throw new MailProviderException('Could not reach Google: '.$e->getMessage(), MailProviderException::TRANSIENT);
        }

        if ($response->failed()) {
            $kind = in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true)
                ? MailProviderException::AUTH : MailProviderException::TRANSIENT;

            throw new MailProviderException('Google refused to refresh the access token ('.$response->json('error', 'error').').', $kind);
        }

        $account->forceFill([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return (string) $response->json('access_token');
    }

    private function error(Response $response): MailProviderException
    {
        $message = (string) $response->json('error.message', 'Gmail request failed');
        $reasons = array_values(array_filter(array_map(
            fn ($e) => is_array($e) ? ($e['reason'] ?? null) : null,
            (array) ($response->json('error.errors') ?? []),
        )));
        $status = $response->status();

        $kind = match (true) {
            $status === 401 => MailProviderException::AUTH,
            $status === 403 && array_intersect($reasons, ['insufficientPermissions', 'forbidden']) !== [] => MailProviderException::AUTH,
            $status === 403 && array_intersect($reasons, ['dailyLimitExceeded', 'quotaExceeded', 'domainPolicy']) !== [] => MailProviderException::RESTRICTED,
            $status === 429, $status === 403 && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded']) !== [] => MailProviderException::TRANSIENT,
            $status >= 500 => MailProviderException::TRANSIENT,
            default => MailProviderException::PERMANENT,
        };

        return new MailProviderException("Gmail: {$message}", $kind);
    }
}
