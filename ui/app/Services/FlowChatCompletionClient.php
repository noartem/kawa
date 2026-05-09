<?php

namespace App\Services;

use App\Ai\Agents\FlowCodeAssistant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\ObjectSchema;

class FlowChatCompletionClient
{
    private const PROVIDER = 'openai';

    /**
     * @return array<string, mixed>
     */
    public function complete(FlowCodeAssistant $assistant, string $message): array
    {
        if (FlowCodeAssistant::isFaked()) {
            $response = $assistant->prompt($message);

            return [
                'response_mode' => $response['response_mode'] ?? null,
                'title' => $response['title'] ?? null,
                'reply' => $response['reply'] ?? null,
                'code' => $response['code'] ?? null,
                'conversation_id' => $response->conversationId,
                'provider' => self::PROVIDER,
                'model' => FlowCodeAssistant::MODEL,
                'usage' => [],
                'persisted_by_agent' => true,
            ];
        }

        try {
            $response = $this->httpClient()->post(
                $this->endpointUrl('chat/completions'),
                $this->buildRequestBody($assistant, $message),
            )->throw();
        } catch (RequestException $exception) {
            $this->throwMappedException($exception);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        $content = $this->extractAssistantContent($payload);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->normalizeJsonContent($content), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiException('AI provider returned invalid structured JSON.', previous: $exception);
        }

        return [
            'response_mode' => $decoded['response_mode'] ?? null,
            'title' => $decoded['title'] ?? null,
            'reply' => $decoded['reply'] ?? null,
            'code' => $decoded['code'] ?? null,
            'conversation_id' => $assistant->currentConversation(),
            'provider' => self::PROVIDER,
            'model' => is_string($payload['model'] ?? null)
                ? $payload['model']
                : FlowCodeAssistant::MODEL,
            'usage' => is_array($payload['usage'] ?? null) ? $payload['usage'] : [],
            'persisted_by_agent' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(FlowCodeAssistant $assistant, string $message): array
    {
        $schema = $assistant->schema(new JsonSchemaTypeFactory);

        return [
            'model' => FlowCodeAssistant::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => (string) $assistant->instructions()],
                ...$this->mapHistoryMessages($assistant->messages()),
                ['role' => 'user', 'content' => $message],
            ],
            'response_format' => $this->buildResponseFormat($schema),
        ];
    }

    /**
     * @param  iterable<int, Message>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function mapHistoryMessages(iterable $messages): array
    {
        return collect($messages)
            ->map(fn (Message $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function buildResponseFormat(array $schema): array
    {
        $schemaArray = (new ObjectSchema($schema))->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractAssistantContent(array $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        if (is_array($content)) {
            $text = collect($content)
                ->map(fn (mixed $part): string => is_array($part)
                    ? (string) ($part['text'] ?? '')
                    : '')
                ->implode('');

            if (trim($text) !== '') {
                return $text;
            }
        }

        throw new AiException('AI provider returned an empty chat response.');
    }

    private function normalizeJsonContent(string $content): string
    {
        $trimmed = trim($content);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }

    private function httpClient(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withToken((string) config('ai.providers.'.self::PROVIDER.'.key'))
            ->timeout(120)
            ->connectTimeout(10);
    }

    private function endpointUrl(string $path): string
    {
        return rtrim((string) config('ai.providers.'.self::PROVIDER.'.url'), '/').'/'.$path;
    }

    private function throwMappedException(RequestException $exception): never
    {
        $response = $exception->response;
        $status = $response->status();
        $body = (string) $response->body();
        $bodyLower = Str::lower($body);

        if (str_contains($bodyLower, 'insufficient')
            || str_contains($bodyLower, 'quota')
            || str_contains($bodyLower, 'credit')) {
            throw InsufficientCreditsException::forProvider(
                self::PROVIDER,
                $status,
                $exception,
            );
        }

        if ($status === 429) {
            throw RateLimitedException::forProvider(self::PROVIDER, $status, $exception);
        }

        if ($status === 503) {
            throw ProviderOverloadedException::forProvider(self::PROVIDER, $status, $exception);
        }

        throw new AiException(
            sprintf('OpenAI Error [%d]: %s', $status, $body !== '' ? $body : 'Unknown error'),
            $status,
            $exception,
        );
    }
}
