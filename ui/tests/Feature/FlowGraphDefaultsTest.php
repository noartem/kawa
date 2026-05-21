<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FlowGraphDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_flow_is_created_without_flow_graph_columns(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'Morning flow',
            'description' => 'Daily cron scenario',
            'template' => 'cron',
            'timezone' => 'Europe/Berlin',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Morning flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame('Europe/Berlin', $flow->timezone);
        $this->assertNotNull($flow->code_updated_at);
        $this->assertSame(File::get(resource_path('flow-templates/cron.py')), $flow->code);
        $this->assertFalse(Schema::hasColumn('flows', 'graph'));
        $this->assertFalse(Schema::hasColumn('flows', 'graph_generated_at'));
        $this->assertArrayNotHasKey('graph', $flow->getAttributes());
        $this->assertArrayNotHasKey('graph_generated_at', $flow->getAttributes());
    }

    public function test_flow_creation_uses_app_timezone_when_not_provided(): void
    {
        config(['app.timezone' => 'UTC']);

        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'Fallback timezone flow',
            'description' => 'Fallback timezone scenario',
            'template' => 'blank',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Fallback timezone flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame('', $flow->code);
        $this->assertSame('UTC', $flow->timezone);
    }

    public function test_webhook_flow_uses_resource_template_code(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'Webhook flow',
            'description' => 'Webhook scenario',
            'template' => 'webhook',
            'timezone' => 'UTC',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Webhook flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame(File::get(resource_path('flow-templates/webhook.py')), $flow->code);
    }

    public function test_rss_flow_uses_resource_template_code(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'RSS flow',
            'description' => 'RSS digest scenario',
            'template' => 'rss',
            'timezone' => 'UTC',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'RSS flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame(File::get(resource_path('flow-templates/rss.py')), $flow->code);
    }

    public function test_imap_flow_uses_resource_template_code(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'IMAP flow',
            'description' => 'IMAP processing scenario',
            'template' => 'imap',
            'timezone' => 'UTC',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'IMAP flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame(File::get(resource_path('flow-templates/imap.py')), $flow->code);
    }

    public function test_imap_flow_template_has_valid_python_syntax(): void
    {
        $process = new Process([
            'python3',
            '-c',
            'import ast, pathlib, sys; ast.parse(pathlib.Path(sys.argv[1]).read_text())',
            resource_path('flow-templates/imap.py'),
        ], base_path());

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_air_quality_flow_uses_resource_template_code(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)->post(route('flows.store'), [
            'name' => 'Air quality flow',
            'description' => 'Air quality alert scenario',
            'template' => 'air_quality',
            'timezone' => 'UTC',
        ])->assertRedirect();

        $flow = Flow::query()
            ->where('user_id', $user->id)
            ->where('name', 'Air quality flow')
            ->first();

        $this->assertNotNull($flow);
        $this->assertSame(File::get(resource_path('flow-templates/air_quality.py')), $flow->code);
    }

    public function test_flow_creation_rejects_invalid_timezone(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $this->actingAs($user)
            ->from(route('flows.create'))
            ->post(route('flows.store'), [
                'name' => 'Invalid timezone flow',
                'description' => 'Should fail validation',
                'template' => 'cron',
                'timezone' => 'Invalid/Timezone',
            ])
            ->assertRedirect(route('flows.create'))
            ->assertSessionHasErrors(['timezone']);

        $this->assertDatabaseMissing('flows', [
            'user_id' => $user->id,
            'name' => 'Invalid timezone flow',
        ]);
    }

    public function test_show_uses_last_development_deployment_as_graph_source(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Legacy cron flow',
            'code' => <<<'PY'
from kawa import actor, Context, Cron


@actor(receives=Cron.by("0 8 * * *"))
def MorningActor(ctx: Context, event):
    print("Good morning!")
PY,
        ]);

        $run = $flow->runs()->create([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'code_snapshot' => $flow->code,
            'graph_snapshot' => [
                'nodes' => [
                    ['id' => 'Cron', 'type' => 'event', 'label' => 'Cron'],
                    ['id' => 'MorningActor', 'type' => 'actor', 'label' => 'MorningActor'],
                ],
                'edges' => [
                    ['from' => 'Cron', 'to' => 'MorningActor'],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('flows.show', $flow))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('flows/Show')
                ->where('lastDevelopmentDeployment.id', $run->id)
                ->where('lastDevelopmentDeployment.graph.nodes.0.id', 'Cron')
                ->where('lastDevelopmentDeployment.graph.edges.0.from', 'Cron')
                ->where('lastDevelopmentDeployment.graph.edges.0.to', 'MorningActor')
            );
    }

    public function test_update_redirects_to_editor_when_origin_is_editor(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Editor redirect flow',
            'description' => 'Editor origin should stay on editor route',
            'code' => 'print("old")',
        ]);

        $this->actingAs($user)
            ->put(route('flows.update', [
                'flow' => $flow,
                'deployment' => 'production',
                'tab' => 'editor',
                'origin' => 'editor',
            ]), [
                'name' => 'Editor redirect flow',
                'description' => 'Editor origin should stay on editor route',
                'code' => 'print("new")',
            ])
            ->assertRedirect(route('flows.editor', [
                'flow' => $flow,
                'deployment' => 'production',
                'tab' => 'editor',
            ]));
    }

    public function test_update_ignores_legacy_graph_payload_and_tracks_code_update_time(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Readonly graph flow',
            'description' => 'Graph should be generated by runtime only',
            'code' => 'print("old")',
            'code_updated_at' => now()->subHour(),
        ]);

        $previousCodeUpdatedAt = $flow->code_updated_at;

        $this->actingAs($user)
            ->put(route('flows.update', [
                'flow' => $flow,
                'deployment' => 'production',
                'tab' => 'editor',
            ]), [
                'name' => 'Readonly graph flow',
                'description' => 'Graph should be generated by runtime only',
                'code' => 'print("new")',
                'graph' => [
                    'nodes' => [
                        ['id' => 'user_defined_node'],
                    ],
                    'edges' => [
                        ['from' => 'user_defined_node', 'to' => 'other'],
                    ],
                ],
            ])
            ->assertRedirect(route('flows.show', [
                'flow' => $flow,
                'deployment' => 'production',
                'tab' => 'editor',
            ]));

        $flow->refresh();

        $this->assertSame('print("new")', $flow->code);
        $this->assertNotNull($flow->code_updated_at);
        $this->assertNotNull($previousCodeUpdatedAt);
        $this->assertTrue($flow->code_updated_at->gt($previousCodeUpdatedAt));
        $this->assertArrayNotHasKey('graph', $flow->getAttributes());
        $this->assertArrayNotHasKey('graph_generated_at', $flow->getAttributes());
    }

    public function test_update_returns_json_for_xhr_requests(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'name' => 'Async save flow',
            'description' => 'Saved without redirect',
            'code' => 'print("old")',
            'timezone' => 'UTC',
            'code_updated_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->put(route('flows.update', [
                'flow' => $flow,
                'deployment' => 'development',
                'tab' => 'editor',
                'origin' => 'editor',
            ]), [
                'name' => 'Async save flow',
                'description' => 'Saved without redirect',
                'code' => 'print("new")',
                'timezone' => 'Europe/Berlin',
            ])
            ->assertOk()
            ->assertJsonPath('flow.name', 'Async save flow')
            ->assertJsonPath('flow.description', 'Saved without redirect')
            ->assertJsonPath('flow.code', 'print("new")')
            ->assertJsonPath('flow.timezone', 'Europe/Berlin');

        $flow->refresh();

        $this->assertSame('print("new")', $flow->code);
        $this->assertSame('Europe/Berlin', $flow->timezone);
        $this->assertNotNull($flow->code_updated_at);
    }

    public function test_flow_update_rejects_invalid_timezone(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $flow = Flow::factory()->forUser($user)->createOne([
            'timezone' => 'UTC',
        ]);

        $this->actingAs($user)
            ->from(route('flows.show', $flow))
            ->put(route('flows.update', $flow), [
                'name' => $flow->name,
                'description' => $flow->description,
                'code' => $flow->code,
                'timezone' => 'Invalid/Timezone',
            ])
            ->assertRedirect(route('flows.show', $flow))
            ->assertSessionHasErrors(['timezone']);

        $flow->refresh();

        $this->assertSame('UTC', $flow->timezone);
    }
}
