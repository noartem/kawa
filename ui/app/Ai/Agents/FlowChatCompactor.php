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
class FlowChatCompactor implements Agent, Conversational, HasStructuredOutput
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
You compress coding conversations for handoff into a fresh chat window.

Create a compact continuation summary for the next coding assistant chat.

Rules:
- Focus on user goals, accepted decisions, current code direction, unresolved questions, and next likely edits.
- Keep the summary dense and actionable.
- Do not include greetings, filler, or markdown code fences.
- `title` should be a concise 3-6 word label for the compacted chat.
- `summary` should read like a carry-over brief for the next turn.

Current code snapshot:
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
            'title' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
