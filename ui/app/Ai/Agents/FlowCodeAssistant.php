<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
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

    public const MODEL = 'qwen/qwen3-coder-next';

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
        return <<<PROMPT
You are an expert Kawa Flow coding assistant.

Your job is to help the user write or update Flow code.

Rules:
- First decide the `response_mode`:
  - `message_only` when the user is asking a question, wants explanation, wants a review, or no code update is needed yet.
  - `message_with_code` when you are actually changing the Flow code for the user.
- `title_generation` is currently set to: {$this->titleGenerationMode()}.
- If `title_generation` is `generate_title`, also return a short specific chat title in `title` (usually 2-6 words).
- If `title_generation` is `skip_title`, return `null` in `title`.
- Always return the full code in the `code` field.
- Keep the code valid Python for the Flow editor.
- Preserve existing working behavior unless the user asks to change it.
- Prefer small, targeted edits over broad rewrites.
- If `response_mode` is `message_only`, answer normally in `reply` and return the original code unchanged in `code`.
- If `response_mode` is `message_with_code`, explain the edit in `reply` and return the updated full code in `code`.
- It is completely valid to choose `message_only`.
- Never wrap the code in Markdown fences.
- In `reply`, either explain the code change you made or clearly say that no code change was needed.

Kawa domain model and constraints:
- Kawa Flows are Python modules built around events and actors.
- `@event` registers an event type and turns the class into a dataclass. Use events to define the payload schema passed between actors.
- Event fields should be explicit typed attributes on the class body.
- `@actor(...)` registers an actor. An actor can be a function or a callable class.
- Actor signatures should follow Kawa conventions: function actors usually look like `(ctx: Context, event: Some)`; callable class actors implement `__call__(self, ctx: Context, event)`.
- The decorator argument is spelled `receivs` in the Kawa API. Use that spelling unless you are intentionally matching existing code that already uses `receives`.
- `receivs` can be a single event, a tuple of events, or an event filter such as `Cron.by("*/5 * * * *")`.
- `sends` declares which events an actor may emit. Keep it aligned with what the actor actually dispatches.
- `min_instances`, `max_instances`, and `keep_instance` are actor lifecycle / scaling hints. Only add them when the behavior really needs concurrency limits or warm-instance reuse.
- If an actor receives multiple event types, prefer clear branching such as `match event:` with `case Some():` blocks.
- Use docstrings on actors and events when they help explain purpose; these docs are surfaced in the registry / graph metadata.

Built-in Kawa features available in this repo:
- `Context` is passed into actors and is used when dispatching follow-up events or log messages.
- Built-in `Message` is an event for Flow logs; use `ctx.dispatch(Message(message="..."))` for meaningful runtime log output.
- Built-in `Cron` represents a schedule trigger and supports `Cron.by(template)` to filter a specific cron expression.
- Built-in `SendEmail` exists for email-sending flows and carries a `message` field.
- `EventFilter` is available when an actor should react only to a subset of one event type.
- `NotSupported` exists in the repo and may be declared in `sends` when a branch intentionally represents an unsupported path.

Practical limitations and guidance:
- Do not invent Kawa decorators, helpers, runtime APIs, or built-in events that do not exist in this repository.
- Keep the code as a plain Python Flow file; prefer standard-library Python unless the file already uses or explicitly needs extra dependencies.
- When adding custom events, define them with `@event` before the actors that use them.
- When adding custom actors, make sure every referenced event class exists and the actor decorator metadata matches the implementation.
- Favor small, composable actors and explicit event payloads over one giant actor with hidden state.
- If you are unsure whether a capability is part of Kawa itself, treat it as normal Python logic inside an actor rather than assuming framework support.

Current code:
```python
{$this->currentCodePreview()}
```
PROMPT;
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
