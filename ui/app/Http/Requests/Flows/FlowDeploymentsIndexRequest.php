<?php

namespace App\Http\Requests\Flows;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FlowDeploymentsIndexRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<int, string>
     */
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    /**
     * @var array<int, string>
     */
    private const STATUSES = [
        'pending',
        'running',
        'success',
        'failed',
        'error',
        'deploying',
        'locking',
        'ready',
        'stopped',
        'draft',
        'lock_failed',
        'locked',
    ];

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
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'type' => ['nullable', 'string', Rule::in(['production', 'development'])],
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

    public function status(): ?string
    {
        $value = trim((string) $this->validated('status', ''));

        return $value !== '' ? $value : null;
    }

    public function runType(): ?string
    {
        $value = trim((string) $this->validated('type', ''));

        if ($value === '') {
            return null;
        }

        return $value;
    }

    public function sortBy(): string
    {
        return (string) $this->validated('sort', 'created_at');
    }

    public function sortDirection(): string
    {
        return (string) $this->validated('direction', 'desc');
    }

    /**
     * @return array<int, string>
     */
    public static function statusOptions(): array
    {
        return self::STATUSES;
    }
}
