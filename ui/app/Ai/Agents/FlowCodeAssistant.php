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
#[Model('qwen/qwen3-coder-next')]
#[Timeout(120)]
class FlowCodeAssistant implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, Message>  $messages
     */
    public function __construct(
        private readonly string $currentCode,
        private readonly array $messages = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
You are an expert Kawa Flow coding assistant.

Your job is to help the user write or update Flow code.

Rules:
- Always return the full updated code in the `code` field.
- Keep the code valid Python for the Flow editor.
- Preserve existing working behavior unless the user asks to change it.
- Prefer small, targeted edits over broad rewrites.
- If the best answer is explanation only, still return the original code unchanged.
- Never wrap the code in Markdown fences.
- In `reply`, explain what changed, what stayed the same, and any important caveats.

Current code:
```python
{$this->currentCode}
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
            'reply' => $schema->string()->required(),
            'code' => $schema->string()->required(),
        ];
    }
}
