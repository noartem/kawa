<?php

namespace Database\Factories;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Flow>
 */
class FlowFactory extends Factory
{
    protected $model = Flow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);
        $status = fake()->randomElement(['running', 'stopped', 'error', 'draft']);
        $startedAt = fake()->optional(0.8)->dateTimeBetween('-14 days', '-1 hour');
        $finishedAt = $startedAt ? fake()->optional(0.7)->dateTimeBetween($startedAt, 'now') : null;

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name) ?: Str::uuid()->toString(),
            'description' => fake()->sentence(12),
            'code' => "# kawaflow demo\nprint('flow: {$name}')\n",
            'status' => $status,
            'container_id' => $status === 'draft' ? null : 'flow-'.Str::lower(Str::random(8)),
            'entrypoint' => 'main.py',
            'image' => 'flow:dev',
            'timezone' => 'UTC',
            'last_started_at' => $startedAt,
            'last_finished_at' => $status === 'running' ? null : $finishedAt,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
        ]);
    }
}
