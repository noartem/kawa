<?php

namespace Database\Factories;

use App\Models\Flow;
use App\Models\FlowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlowRun>
 */
class FlowRunFactory extends Factory
{
    protected $model = FlowRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement([
            'pending',
            'creating',
            'created',
            'running',
            'stopping',
            'stopped',
            'success',
            'failed',
        ]);
        $startedAt = fake()->optional(0.9)->dateTimeBetween('-10 days', 'now');
        $finishedAt = in_array($status, ['stopped', 'success', 'failed'], true)
            ? fake()->optional(0.8)->dateTimeBetween($startedAt, 'now')
            : null;

        return [
            'flow_id' => Flow::factory(),
            'type' => fake()->randomElement(['development', 'production']),
            'active' => fake()->boolean(30),
            'status' => $status,
            'container_id' => fake()->optional()->bothify('container-??##'),
            'lock' => fake()->optional(0.4)->text(200),
            'meta' => [
                'trace_id' => Str::uuid()->toString(),
                'duration_ms' => $finishedAt && $startedAt ? $finishedAt->getTimestamp() - $startedAt->getTimestamp() : null,
            ],
            'actors' => fake()->optional(0.5)->randomElements(['EmailActor', 'WebhookActor', 'Scheduler'], fake()->numberBetween(1, 2)),
            'events' => fake()->optional(0.5)->randomElements(['cron', 'webhook', 'email'], fake()->numberBetween(1, 2)),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ];
    }

    public function forFlow(Flow $flow): static
    {
        return $this->state(fn () => [
            'flow_id' => $flow->id,
        ]);
    }
}
