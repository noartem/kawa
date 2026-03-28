<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class FlowWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function payload(): mixed
    {
        $contentType = Str::lower((string) $this->header('Content-Type', ''));
        if (! str_contains($contentType, 'application/json')) {
            throw ValidationException::withMessages([
                'payload' => 'The webhook request must use application/json.',
            ]);
        }

        $content = trim($this->getContent());
        if ($content === '') {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => 'The webhook request body must contain valid JSON.',
            ]);
        }
    }
}
