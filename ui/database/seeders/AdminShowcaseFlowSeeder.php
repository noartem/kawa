<?php

namespace Database\Seeders;

use App\Ai\Agents\FlowChatCompactor;
use App\Ai\Agents\FlowCodeAssistant;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\User;
use App\Support\FlowCodeDiff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class AdminShowcaseFlowSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@kawaflow.localhost';

    private const FLOW_SLUG = 'admin-showcase-flow';

    public function run(): void
    {
        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if (! $admin instanceof User) {
            return;
        }

        Flow::query()->where('slug', self::FLOW_SLUG)->first()?->delete();

        $codeVersionOne = $this->codeVersionOne();
        $codeVersionTwo = $this->codeVersionTwo();
        $codeVersionThree = $this->codeVersionThree();
        $now = CarbonImmutable::now('UTC');

        $productionStartedAt = $now->subDays(3)->setTime(8, 30);
        $productionFinishedAt = $productionStartedAt->addMinutes(7);
        $historyOneCreatedAt = $now->subDays(2)->setTime(10, 5);
        $failedDevelopmentStartedAt = $now->subDay()->setTime(11, 20);
        $failedDevelopmentFinishedAt = $failedDevelopmentStartedAt->addMinutes(5);
        $historyTwoCreatedAt = $now->subDay()->setTime(12, 15);
        $latestDevelopmentStartedAt = $now->setTime(9, 10);
        $latestDevelopmentFinishedAt = $latestDevelopmentStartedAt->addMinutes(9);
        $flowCreatedAt = $productionStartedAt->subHour();

        $flow = Flow::query()->create([
            'user_id' => $admin->id,
            'name' => 'Operations Showcase Flow',
            'slug' => self::FLOW_SLUG,
            'description' => 'Deterministic admin demo with cron, emails, messages, histories, deployments, logs, and chats.',
            'code' => $codeVersionThree,
            'code_updated_at' => $latestDevelopmentFinishedAt,
            'status' => 'stopped',
            'container_id' => null,
            'entrypoint' => 'main.py',
            'image' => 'flow:dev',
            'timezone' => 'Europe/Moscow',
            'last_started_at' => $latestDevelopmentStartedAt,
            'last_finished_at' => $latestDevelopmentFinishedAt,
        ]);
        $this->stamp($flow, $flowCreatedAt, $latestDevelopmentFinishedAt);

        $historyOne = $flow->histories()->create([
            'code' => $codeVersionOne,
            'diff' => null,
        ]);
        $this->stamp($historyOne, $historyOneCreatedAt);

        $historyTwo = $flow->histories()->create([
            'code' => $codeVersionTwo,
            'diff' => $this->diff($codeVersionOne, $codeVersionTwo),
        ]);
        $this->stamp($historyTwo, $historyTwoCreatedAt);

        $productionRun = $flow->runs()->create([
            'type' => 'production',
            'active' => false,
            'status' => 'stopped',
            'container_id' => 'flow-admin-showcase-prod',
            'lock' => "image=flow:dev\ntimezone=Europe/Moscow\nmode=production",
            'meta' => [
                'graph_hash' => 'seeded-prod-v1',
                'mode' => 'production',
                'seeded' => true,
            ],
            'actors' => $this->productionActors(),
            'events' => $this->productionEvents(),
            'started_at' => $productionStartedAt,
            'finished_at' => $productionFinishedAt,
        ]);
        $this->stamp($productionRun, $productionStartedAt, $productionFinishedAt);
        $this->seedRunLogs($productionRun, [
            [
                'level' => 'info',
                'node_key' => 'ScheduleCollector',
                'message' => 'Cron schedule collected a fresh intake ticket.',
                'context' => ['kind' => 'actor_invoked', 'ticket_id' => 'cron-0830'],
                'created_at' => $productionStartedAt->addMinute(),
            ],
            [
                'level' => 'info',
                'node_key' => 'ApprovalRouter',
                'message' => 'Approval email and digest were dispatched.',
                'context' => ['kind' => 'event_dispatched', 'event' => 'SendEmailEvent'],
                'created_at' => $productionStartedAt->addMinutes(3),
            ],
        ]);

        $failedDevelopmentRun = $flow->runs()->create([
            'type' => 'development',
            'active' => false,
            'status' => 'failed',
            'container_id' => 'flow-admin-showcase-dev-failed',
            'lock' => null,
            'meta' => [
                'graph_hash' => 'seeded-dev-v2',
                'mode' => 'development',
                'seeded' => true,
                'error' => 'Digest formatter hit an intentional seeded failure.',
            ],
            'actors' => $this->developmentActors(),
            'events' => $this->developmentEvents(),
            'code_snapshot' => null,
            'graph_snapshot' => null,
            'started_at' => $failedDevelopmentStartedAt,
            'finished_at' => $failedDevelopmentFinishedAt,
        ]);
        $this->stamp($failedDevelopmentRun, $failedDevelopmentStartedAt, $failedDevelopmentFinishedAt);
        $this->seedRunLogs($failedDevelopmentRun, [
            [
                'level' => 'warning',
                'node_key' => 'EscalationMonitor',
                'message' => 'Urgent approval was escalated back into intake.',
                'context' => ['kind' => 'runtime_graph_updated', 'event' => 'EscalationRequested'],
                'created_at' => $failedDevelopmentStartedAt->addMinute(),
            ],
            [
                'level' => 'error',
                'node_key' => 'PublishDigest',
                'message' => 'Digest formatter hit an intentional seeded failure.',
                'context' => ['kind' => 'runtime_error', 'retryable' => false],
                'created_at' => $failedDevelopmentFinishedAt,
            ],
        ]);

        $latestDevelopmentRun = $flow->runs()->create([
            'type' => 'development',
            'active' => false,
            'status' => 'stopped',
            'container_id' => 'flow-admin-showcase-dev-latest',
            'lock' => "image=flow:dev\ntimezone=Europe/Moscow\nmode=development",
            'meta' => [
                'graph_hash' => 'seeded-dev-v3',
                'mode' => 'development',
                'seeded' => true,
                'notes' => 'Latest showcase deployment keeps escalations in the same graph.',
            ],
            'actors' => $this->developmentActors(),
            'events' => $this->developmentEvents(),
            'code_snapshot' => $codeVersionThree,
            'graph_snapshot' => $this->developmentGraphSnapshot(),
            'started_at' => $latestDevelopmentStartedAt,
            'finished_at' => $latestDevelopmentFinishedAt,
        ]);
        $this->stamp($latestDevelopmentRun, $latestDevelopmentStartedAt, $latestDevelopmentFinishedAt);
        $this->seedRunLogs($latestDevelopmentRun, [
            [
                'level' => 'info',
                'node_key' => 'ScheduleCollector',
                'message' => 'Cron trigger created a seeded showcase intake.',
                'context' => ['kind' => 'actor_invoked', 'event' => 'CronEvent.by("*/15 * * * *")'],
                'created_at' => $latestDevelopmentStartedAt->addMinute(),
            ],
            [
                'level' => 'info',
                'node_key' => 'ApprovalRouter',
                'message' => 'Approval request fan-out produced email, digest, and log messages.',
                'context' => ['kind' => 'event_dispatched', 'event' => 'ApprovalRequested'],
                'created_at' => $latestDevelopmentStartedAt->addMinutes(4),
            ],
            [
                'level' => 'info',
                'node_key' => 'PublishDigest',
                'message' => 'Digest publication completed successfully.',
                'context' => ['kind' => 'event_dispatched', 'event' => 'Message'],
                'created_at' => $latestDevelopmentFinishedAt,
            ],
        ]);

        $this->seedFlowLogs($flow, [
            [
                'level' => 'info',
                'node_key' => 'showcase',
                'message' => 'Admin showcase flow seeded successfully.',
                'context' => ['seeded' => true, 'version' => 'v3'],
                'created_at' => $latestDevelopmentFinishedAt->addMinute(),
            ],
            [
                'level' => 'debug',
                'node_key' => 'showcase',
                'message' => 'Use the editor tabs to inspect histories, deployments, logs, and chat threads.',
                'context' => ['seeded' => true, 'hint' => 'editor-tour'],
                'created_at' => $latestDevelopmentFinishedAt->addMinutes(2),
            ],
        ]);

        $archivedConversation = $flow->conversations()->create([
            'user_id' => $admin->id,
            'title' => 'Showcase onboarding',
        ]);
        $this->stamp($archivedConversation, $now->subHours(8), $now->subHours(7)->addMinutes(30));
        $this->createConversationMessage(
            $archivedConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'user',
                'content' => 'Build me a flow that demonstrates the current runtime graph features.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['kind' => 'prompt'],
            ],
            $now->subHours(8),
        );
        $this->createConversationMessage(
            $archivedConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowChatCompactor::class,
                'role' => 'assistant',
                'content' => 'The showcase now covers cron intake, approval fan-out, digest publication, and escalation loops.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => [
                    'kind' => 'compact_summary',
                    'source_conversation_id' => 'seeded-showcase-onboarding',
                ],
            ],
            $now->subHours(7)->addMinutes(30),
        );

        $activeConversation = $flow->conversations()->create([
            'user_id' => $admin->id,
            'title' => 'Escalation loop polish',
        ]);
        $this->stamp($activeConversation, $now->subHours(2), $now->subMinutes(15));
        $this->createConversationMessage(
            $activeConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'user',
                'content' => 'Can escalations feed back into intake without losing the approval digest?',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['kind' => 'prompt'],
            ],
            $now->subHours(2),
        );
        $this->createConversationMessage(
            $activeConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'assistant',
                'content' => 'Yes. Escalations now re-enter the ScheduleCollector actor and keep digest publication intact.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => [
                    'kind' => 'code_suggestion',
                    'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_WITH_CODE,
                    'source_code' => $codeVersionTwo,
                    'proposed_code' => $codeVersionThree,
                    'diff' => $this->diff($codeVersionTwo, $codeVersionThree),
                ],
            ],
            $now->subHours(2)->addMinutes(8),
        );
        $this->createConversationMessage(
            $activeConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'user',
                'content' => 'Great. Keep the approval email copy short and leave the digest note in place.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['kind' => 'prompt'],
            ],
            $now->subMinutes(28),
        );
        $this->createConversationMessage(
            $activeConversation,
            [
                'user_id' => $admin->id,
                'agent' => FlowCodeAssistant::class,
                'role' => 'assistant',
                'content' => 'Done. Approval emails stay concise while the digest still records the priority and summary.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => [
                    'kind' => 'assistant_reply',
                    'response_mode' => FlowCodeAssistant::RESPONSE_MODE_MESSAGE_ONLY,
                    'source_code' => $codeVersionThree,
                    'proposed_code' => $codeVersionThree,
                    'diff' => null,
                ],
            ],
            $now->subMinutes(15),
        );

        $flow->forceFill([
            'active_chat_conversation_id' => $activeConversation->id,
        ])->saveQuietly();
        $this->stamp($flow, $flowCreatedAt, $now->subMinutes(15));
    }

    private function createConversationMessage(
        AgentConversation $conversation,
        array $attributes,
        CarbonImmutable $createdAt,
    ): AgentConversationMessage {
        $message = $conversation->messages()->create($attributes);
        $this->stamp($message, $createdAt);

        return $message;
    }

    /**
     * @param  list<array{level: string, node_key: string, message: string, context: array<string, mixed>, created_at: CarbonImmutable}>  $logs
     */
    private function seedFlowLogs(Flow $flow, array $logs): void
    {
        foreach ($logs as $log) {
            $model = $flow->logs()->create([
                'node_key' => $log['node_key'],
                'level' => $log['level'],
                'message' => $log['message'],
                'context' => $log['context'],
            ]);
            $this->stamp($model, $log['created_at']);
        }
    }

    /**
     * @param  list<array{level: string, node_key: string, message: string, context: array<string, mixed>, created_at: CarbonImmutable}>  $logs
     */
    private function seedRunLogs(FlowRun $run, array $logs): void
    {
        foreach ($logs as $log) {
            $model = $run->logs()->create([
                'flow_id' => $run->flow_id,
                'node_key' => $log['node_key'],
                'level' => $log['level'],
                'message' => $log['message'],
                'context' => $log['context'],
            ]);
            $this->stamp($model, $log['created_at']);
        }
    }

    private function stamp(Model $model, CarbonImmutable $createdAt, ?CarbonImmutable $updatedAt = null): void
    {
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ])->saveQuietly();
    }

    private function diff(string $from, string $to): string
    {
        return app(FlowCodeDiff::class)->build($from, $to);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productionEvents(): array
    {
        return [
            [
                'id' => 'CronEvent',
                'source_kind' => 'import',
                'source_module' => 'kawa.cron',
            ],
            [
                'id' => 'Message',
                'source_kind' => 'import',
                'source_module' => 'kawa.message',
            ],
            [
                'id' => 'IntakeRequested',
                'source_kind' => 'main',
                'source_line' => 9,
            ],
            [
                'id' => 'IntakePrepared',
                'source_kind' => 'main',
                'source_line' => 16,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productionActors(): array
    {
        return [
            [
                'id' => 'ScheduleCollector',
                'receives' => ['CronEvent'],
                'sends' => ['IntakeRequested', 'Message'],
                'source_kind' => 'main',
                'source_line' => 22,
            ],
            [
                'id' => 'NormalizeIntake',
                'receives' => ['IntakeRequested'],
                'sends' => ['IntakePrepared', 'Message'],
                'source_kind' => 'main',
                'source_line' => 36,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function developmentEvents(): array
    {
        return [
            [
                'id' => 'CronEvent',
                'source_kind' => 'import',
                'source_module' => 'kawa.cron',
            ],
            [
                'id' => 'Message',
                'source_kind' => 'import',
                'source_module' => 'kawa.message',
            ],
            [
                'id' => 'SendEmailEvent',
                'source_kind' => 'import',
                'source_module' => 'kawa.email',
            ],
            [
                'id' => 'IntakeRequested',
                'source_kind' => 'main',
                'source_line' => 10,
            ],
            [
                'id' => 'IntakePrepared',
                'source_kind' => 'main',
                'source_line' => 17,
            ],
            [
                'id' => 'ApprovalRequested',
                'source_kind' => 'main',
                'source_line' => 24,
            ],
            [
                'id' => 'EscalationRequested',
                'source_kind' => 'main',
                'source_line' => 31,
            ],
            [
                'id' => 'DigestQueued',
                'source_kind' => 'main',
                'source_line' => 37,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function developmentActors(): array
    {
        return [
            [
                'id' => 'ScheduleCollector',
                'receives' => ['CronEvent.by("*/15 * * * *")', 'EscalationRequested'],
                'sends' => ['IntakeRequested', 'Message'],
                'source_kind' => 'main',
                'source_line' => 44,
                'min_instances' => 1,
                'max_instances' => 2,
                'keep_instance' => true,
            ],
            [
                'id' => 'NormalizeIntake',
                'receives' => ['IntakeRequested'],
                'sends' => ['IntakePrepared', 'Message'],
                'source_kind' => 'main',
                'source_line' => 75,
                'min_instances' => 1,
                'max_instances' => 4,
            ],
            [
                'id' => 'ApprovalRouter',
                'receives' => ['IntakePrepared'],
                'sends' => ['ApprovalRequested', 'DigestQueued', 'SendEmailEvent', 'Message'],
                'source_kind' => 'main',
                'source_line' => 89,
                'keep_instance' => true,
            ],
            [
                'id' => 'EscalationMonitor',
                'receives' => ['ApprovalRequested'],
                'sends' => ['EscalationRequested', 'Message'],
                'source_kind' => 'main',
                'source_line' => 110,
            ],
            [
                'id' => 'PublishDigest',
                'receives' => ['DigestQueued'],
                'sends' => ['Message'],
                'source_kind' => 'main',
                'source_line' => 123,
            ],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function developmentGraphSnapshot(): array
    {
        $events = $this->developmentEvents();
        $actors = $this->developmentActors();

        return [
            'events' => [
                ...$events,
                [
                    'id' => 'CronEvent.by("*/15 * * * *")',
                    'source_kind' => 'main',
                    'source_line' => 46,
                ],
            ],
            'actors' => $actors,
            'nodes' => [
                ['id' => 'CronEvent.by("*/15 * * * *")', 'type' => 'event', 'label' => 'CronEvent.by("*/15 * * * *")', 'source_kind' => 'main', 'source_line' => 46],
                ['id' => 'EscalationRequested', 'type' => 'event', 'label' => 'EscalationRequested', 'source_kind' => 'main', 'source_line' => 31],
                ['id' => 'IntakeRequested', 'type' => 'event', 'label' => 'IntakeRequested', 'source_kind' => 'main', 'source_line' => 10],
                ['id' => 'IntakePrepared', 'type' => 'event', 'label' => 'IntakePrepared', 'source_kind' => 'main', 'source_line' => 17],
                ['id' => 'ApprovalRequested', 'type' => 'event', 'label' => 'ApprovalRequested', 'source_kind' => 'main', 'source_line' => 24],
                ['id' => 'DigestQueued', 'type' => 'event', 'label' => 'DigestQueued', 'source_kind' => 'main', 'source_line' => 37],
                ['id' => 'SendEmailEvent', 'type' => 'event', 'label' => 'SendEmailEvent', 'source_kind' => 'import', 'source_module' => 'kawa.email'],
                ['id' => 'Message', 'type' => 'event', 'label' => 'Message', 'source_kind' => 'import', 'source_module' => 'kawa.message'],
                ['id' => 'ScheduleCollector', 'type' => 'actor', 'label' => 'ScheduleCollector', 'source_kind' => 'main', 'source_line' => 44],
                ['id' => 'NormalizeIntake', 'type' => 'actor', 'label' => 'NormalizeIntake', 'source_kind' => 'main', 'source_line' => 75],
                ['id' => 'ApprovalRouter', 'type' => 'actor', 'label' => 'ApprovalRouter', 'source_kind' => 'main', 'source_line' => 89],
                ['id' => 'EscalationMonitor', 'type' => 'actor', 'label' => 'EscalationMonitor', 'source_kind' => 'main', 'source_line' => 110],
                ['id' => 'PublishDigest', 'type' => 'actor', 'label' => 'PublishDigest', 'source_kind' => 'main', 'source_line' => 123],
            ],
            'edges' => [
                ['from' => 'CronEvent.by("*/15 * * * *")', 'to' => 'ScheduleCollector'],
                ['from' => 'EscalationRequested', 'to' => 'ScheduleCollector'],
                ['from' => 'ScheduleCollector', 'to' => 'IntakeRequested'],
                ['from' => 'ScheduleCollector', 'to' => 'Message'],
                ['from' => 'IntakeRequested', 'to' => 'NormalizeIntake'],
                ['from' => 'NormalizeIntake', 'to' => 'IntakePrepared'],
                ['from' => 'NormalizeIntake', 'to' => 'Message'],
                ['from' => 'IntakePrepared', 'to' => 'ApprovalRouter'],
                ['from' => 'ApprovalRouter', 'to' => 'ApprovalRequested'],
                ['from' => 'ApprovalRouter', 'to' => 'DigestQueued'],
                ['from' => 'ApprovalRouter', 'to' => 'SendEmailEvent'],
                ['from' => 'ApprovalRouter', 'to' => 'Message'],
                ['from' => 'ApprovalRequested', 'to' => 'EscalationMonitor'],
                ['from' => 'EscalationMonitor', 'to' => 'EscalationRequested'],
                ['from' => 'EscalationMonitor', 'to' => 'Message'],
                ['from' => 'DigestQueued', 'to' => 'PublishDigest'],
                ['from' => 'PublishDigest', 'to' => 'Message'],
            ],
        ];
    }

    private function codeVersionOne(): string
    {
        return <<<'PY'
from kawa import Context, Message, actor, event
from kawa.cron import CronEvent


@event
class IntakeRequested:
    ticket_id: str
    requested_by: str


@event
class IntakePrepared:
    ticket_id: str
    summary: str


@actor(receivs=CronEvent.by("0 * * * *"), sends=(IntakeRequested, Message))
class ScheduleCollector:
    def __call__(self, ctx: Context, event: CronEvent) -> None:
        ticket_id = f"cron-{event.datetime.strftime('%H%M')}"
        ctx.dispatch(
            IntakeRequested(ticket_id=ticket_id, requested_by="scheduler")
        )
        ctx.dispatch(Message(message=f"[ops] collected {ticket_id}"))


@actor(receivs=IntakeRequested, sends=(IntakePrepared, Message))
def NormalizeIntake(ctx: Context, event: IntakeRequested) -> None:
    ctx.dispatch(
        IntakePrepared(
            ticket_id=event.ticket_id,
            summary=f"Prepared {event.ticket_id}",
        )
    )
    ctx.dispatch(Message(message=f"[ops] prepared {event.ticket_id}"))
PY;
    }

    private function codeVersionTwo(): string
    {
        return <<<'PY'
from kawa import Context, Message, actor, event
from kawa.cron import CronEvent
from kawa.email import SendEmailEvent


@event
class IntakeRequested:
    ticket_id: str
    requested_by: str
    priority: str


@event
class IntakePrepared:
    ticket_id: str
    summary: str
    priority: str


@event
class ApprovalRequested:
    ticket_id: str
    priority: str


@event
class DigestQueued:
    ticket_id: str
    summary: str


@actor(
    receivs=CronEvent.by("*/15 * * * *"),
    sends=(IntakeRequested, Message),
    min_instances=1,
    max_instances=2,
    keep_instance=True,
)
class ScheduleCollector:
    def __call__(self, ctx: Context, event: CronEvent) -> None:
        ticket_id = f"cron-{event.datetime.strftime('%H%M')}"
        ctx.dispatch(
            IntakeRequested(
                ticket_id=ticket_id,
                requested_by="scheduler",
                priority="high",
            )
        )
        ctx.dispatch(Message(message=f"[ops] collected {ticket_id}"))


@actor(receivs=IntakeRequested, sends=(IntakePrepared, Message), min_instances=1, max_instances=4)
def NormalizeIntake(ctx: Context, event: IntakeRequested) -> None:
    ctx.dispatch(
        IntakePrepared(
            ticket_id=event.ticket_id,
            summary=f"Prepared {event.ticket_id}",
            priority=event.priority,
        )
    )
    ctx.dispatch(Message(message=f"[ops] prepared {event.ticket_id}"))


@actor(receivs=IntakePrepared, sends=(ApprovalRequested, DigestQueued, SendEmailEvent, Message))
class ApprovalRouter:
    def __call__(self, ctx: Context, event: IntakePrepared) -> None:
        ctx.dispatch(
            ApprovalRequested(
                ticket_id=event.ticket_id,
                priority=event.priority,
            )
        )
        ctx.dispatch(
            DigestQueued(
                ticket_id=event.ticket_id,
                summary=event.summary,
            )
        )
        ctx.dispatch(
            SendEmailEvent(
                message=f"Approval requested for {event.ticket_id}",
            )
        )
        ctx.dispatch(Message(message=f"[ops] routed {event.ticket_id}"))


@actor(receivs=DigestQueued, sends=Message)
def PublishDigest(ctx: Context, event: DigestQueued) -> None:
    ctx.dispatch(Message(message=f"[digest] {event.ticket_id}: {event.summary}"))
PY;
    }

    private function codeVersionThree(): string
    {
        return <<<'PY'
from kawa import Context, Message, actor, event
from kawa.cron import CronEvent
from kawa.email import SendEmailEvent


@event
class IntakeRequested:
    ticket_id: str
    requested_by: str
    priority: str


@event
class IntakePrepared:
    ticket_id: str
    summary: str
    priority: str


@event
class ApprovalRequested:
    ticket_id: str
    priority: str


@event
class EscalationRequested:
    ticket_id: str
    reason: str


@event
class DigestQueued:
    ticket_id: str
    summary: str


@actor(
    receivs=(CronEvent.by("*/15 * * * *"), EscalationRequested),
    sends=(IntakeRequested, Message),
    min_instances=1,
    max_instances=2,
    keep_instance=True,
)
class ScheduleCollector:
    def __call__(self, ctx: Context, event) -> None:
        if isinstance(event, CronEvent):
            ticket_id = f"cron-{event.datetime.strftime('%H%M')}"
            requested_by = "scheduler"
            priority = "high"
        else:
            ticket_id = event.ticket_id
            requested_by = "ops"
            priority = "urgent"
            ctx.dispatch(
                Message(
                    message=f"[ops] escalation reopened {event.ticket_id}: {event.reason}"
                )
            )

        ctx.dispatch(
            IntakeRequested(
                ticket_id=ticket_id,
                requested_by=requested_by,
                priority=priority,
            )
        )
        ctx.dispatch(Message(message=f"[ops] collected {ticket_id}"))


@actor(receivs=IntakeRequested, sends=(IntakePrepared, Message), min_instances=1, max_instances=4)
def NormalizeIntake(ctx: Context, event: IntakeRequested) -> None:
    summary = f"Prepared {event.ticket_id} for {event.requested_by}"
    ctx.dispatch(
        IntakePrepared(
            ticket_id=event.ticket_id,
            summary=summary,
            priority=event.priority,
        )
    )
    ctx.dispatch(Message(message=f"[ops] prepared {event.ticket_id}"))


@actor(
    receivs=IntakePrepared,
    sends=(ApprovalRequested, DigestQueued, SendEmailEvent, Message),
    keep_instance=True,
)
class ApprovalRouter:
    def __call__(self, ctx: Context, event: IntakePrepared) -> None:
        ctx.dispatch(
            ApprovalRequested(
                ticket_id=event.ticket_id,
                priority=event.priority,
            )
        )
        ctx.dispatch(
            DigestQueued(
                ticket_id=event.ticket_id,
                summary=event.summary,
            )
        )
        ctx.dispatch(
            SendEmailEvent(
                message=f"Approval requested: {event.ticket_id} ({event.priority})",
            )
        )
        ctx.dispatch(Message(message=f"[ops] routed {event.ticket_id}"))


@actor(receivs=ApprovalRequested, sends=(EscalationRequested, Message))
def EscalationMonitor(ctx: Context, event: ApprovalRequested) -> None:
    if event.priority != "urgent":
        ctx.dispatch(Message(message=f"[ops] approval queued for {event.ticket_id}"))
        return

    ctx.dispatch(
        EscalationRequested(
            ticket_id=event.ticket_id,
            reason="urgent approval requires operator follow-up",
        )
    )
    ctx.dispatch(Message(message=f"[ops] escalated {event.ticket_id}"))


@actor(receivs=DigestQueued, sends=Message)
def PublishDigest(ctx: Context, event: DigestQueued) -> None:
    ctx.dispatch(Message(message=f"[digest] {event.ticket_id}: {event.summary}"))
PY;
    }
}
