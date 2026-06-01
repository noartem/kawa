<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openai')]
#[Model(self::MODEL)]
#[Timeout(120)]
class FlowCodeAssistant implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;
    use RemembersConversations {
        messages as rememberedMessages;
    }

    public const MODEL = 'moonshotai/kimi-k2.6';

    public const RESPONSE_MODE_MESSAGE_ONLY = 'message_only';

    public const RESPONSE_MODE_MESSAGE_WITH_CODE = 'message_with_code';

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(
        private readonly string $currentCode,
        private readonly array $messages = [],
        private readonly bool $shouldGenerateTitle = false,
    ) {}

    public function instructions(): Stringable|string
    {
        return strtr(
            File::get(resource_path('prompts/flow-code-assistant.md')),
            [
                '{{title_generation_mode}}' => $this->titleGenerationMode(),
                '{{current_code_preview}}' => $this->currentCodePreview(),
            ],
        );
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'response_mode' => $schema->string()->enum([
                self::RESPONSE_MODE_MESSAGE_ONLY,
                self::RESPONSE_MODE_MESSAGE_WITH_CODE,
            ])->required(),
            'title' => $schema->string()->nullable(),
            'reply' => $schema->string()->required(),
            'code' => $schema->string()->required(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function schemaPreview(bool $shouldGenerateTitle = false): array
    {
        return [
            'title_generation' => sprintf(
                'fixed mode for this request: %s',
                $shouldGenerateTitle ? 'generate_title' : 'skip_title',
            ),
            'response_mode' => sprintf(
                'required enum: %s | %s',
                self::RESPONSE_MODE_MESSAGE_ONLY,
                self::RESPONSE_MODE_MESSAGE_WITH_CODE,
            ),
            'title' => 'nullable string; required to be non-empty only when title_generation = generate_title',
            'reply' => 'required string',
            'code' => 'required string',
        ];
    }

    private function titleGenerationMode(): string
    {
        return $this->shouldGenerateTitle ? 'generate_title' : 'skip_title';
    }

    private function currentCodePreview(): string
    {
        if (trim($this->currentCode) !== '') {
            return $this->currentCode;
        }

        return '# The Flow code is currently empty.';
    }
}
