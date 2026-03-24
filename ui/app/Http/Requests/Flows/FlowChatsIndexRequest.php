<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowChatsIndexRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'title',
        'created_at',
        'updated_at',
        'messages_count',
    ];

    /**
     * @var array<int, string>
     */
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'direction' => ['nullable', 'string', Rule::in(self::SORT_DIRECTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): ?string
    {
        $value = trim((string) $this->validated('search', ''));

        return $value !== '' ? $value : null;
    }

    public function sortBy(): string
    {
        return (string) $this->validated('sort', 'updated_at');
    }

    public function sortDirection(): string
    {
        return (string) $this->validated('direction', 'desc');
    }
}
