<?php

namespace Database\Factories;

use App\Models\Flow;
use App\Models\FlowStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<FlowStorage>
 */
class FlowStorageFactory extends Factory
{
    protected $model = FlowStorage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flow_id' => Flow::factory(),
            'environment' => fake()->randomElement(['development', 'production']),
            'content' => [
                'users' => [
                    ['name' => fake()->name()],
                ],
                'settings' => [
                    'profile' => [
                        'language' => fake()->randomElement(['en', 'ru']),
                    ],
                ],
            ],
        ];
    }

    public function forFlow(Flow $flow): static
    {
        return $this->state(fn () => [
            'flow_id' => $flow->id,
        ]);
    }
}
