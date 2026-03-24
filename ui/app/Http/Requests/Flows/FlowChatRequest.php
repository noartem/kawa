<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;

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
}
