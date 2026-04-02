<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $flow = $this->route('flow');

        return $flow !== null && $this->user() !== null && $this->user()->can('update', $flow);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:10000'],
            'current_code' => ['present', 'nullable', 'string'],
            'history' => ['sometimes', 'array', 'max:10'],
            'history.*.client_id' => ['required_with:history', 'string', 'max:100'],
            'history.*.kind' => [
                'required_with:history',
                'string',
                Rule::in(['apply_proposal', 'apply_and_save_proposal']),
            ],
            'history.*.content' => ['required_with:history', 'string', 'max:10000'],
            'history.*.source_code' => ['present', 'nullable', 'string', 'max:100000'],
            'history.*.proposed_code' => ['present', 'nullable', 'string', 'max:100000'],
        ];
    }

    public function message(): string
    {
        return trim((string) $this->validated('message'));
    }

    public function currentCode(): string
    {
        return (string) ($this->validated('current_code') ?? '');
    }

    /**
     * @return array<int, array{client_id: string, kind: string, content: string, source_code: string, proposed_code: string}>
     */
    public function history(): array
    {
        $validatedHistory = $this->validated('history') ?? [];

        if (! is_array($validatedHistory)) {
            return [];
        }

        $history = [];

        foreach ($validatedHistory as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $history[] = [
                'client_id' => trim((string) ($entry['client_id'] ?? '')),
                'kind' => (string) ($entry['kind'] ?? ''),
                'content' => trim((string) ($entry['content'] ?? '')),
                'source_code' => (string) ($entry['source_code'] ?? ''),
                'proposed_code' => (string) ($entry['proposed_code'] ?? ''),
            ];
        }

        return $history;
    }
}
