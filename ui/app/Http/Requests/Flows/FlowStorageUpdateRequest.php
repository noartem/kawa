<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class FlowStorageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $flow = $this->route('flow');

        return $flow !== null && $this->user() !== null && $this->user()->can('update', $flow);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', Rule::in(['development', 'production'])],
            'content' => ['required', 'string'],
        ];
    }

    public function environment(): string
    {
        return (string) $this->validated('environment');
    }

    /**
     * @return array<mixed>
     */
    public function content(): array
    {
        $content = trim((string) $this->validated('content'));

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'content' => __('validation.json', ['attribute' => 'content']),
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'content' => __('validation.array', ['attribute' => 'content']),
            ]);
        }

        return $decoded;
    }
}
