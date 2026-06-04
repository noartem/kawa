<?php

namespace Database\Seeders;

use App\Models\Flow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@kawa.localhost';

    private const FLOW_SLUGS = [
        'demo-hello-world',
        'demo-cron-message',
        'demo-webhook-intake',
        'demo-email-notification',
    ];

    public function run(): void
    {
        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if (! $admin instanceof User) {
            $admin = User::factory()->admin()->withoutTwoFactor()->create([
                'name' => 'Админ А.Д.',
                'email' => self::ADMIN_EMAIL,
                'password' => Hash::make('12345678'),
            ]);
        }

        foreach (self::FLOW_SLUGS as $slug) {
            Flow::query()->where('slug', $slug)->first()?->delete();
        }

        $now = CarbonImmutable::now('UTC');

        $this->createFlow($admin, $now, [
            'slug' => 'demo-hello-world',
            'name' => 'Demo · Hello World',
            'description' => 'Простейший флоу: один актор, одно сообщение. Запустите и посмотрите лог.',
            'code' => $this->helloWorldCode(),
        ]);

        $this->createFlow($admin, $now, [
            'slug' => 'demo-cron-message',
            'name' => 'Demo · Cron + Message',
            'description' => 'Срабатывает по расписанию каждую минуту, пишет сообщение в лог.',
            'code' => $this->cronCode(),
        ]);

        $this->createFlow($admin, $now, [
            'slug' => 'demo-webhook-intake',
            'name' => 'Demo · Webhook',
            'description' => 'Принимает входящий webhook и обрабатывает его в акторе.',
            'code' => $this->webhookCode(),
        ]);

        $this->createFlow($admin, $now, [
            'slug' => 'demo-email-notification',
            'name' => 'Demo · Email Notification',
            'description' => 'Cron-триггер → отправка email-уведомления через SendEmail.',
            'code' => $this->emailCode(),
        ]);

        $this->call(AdminShowcaseFlowSeeder::class);
    }

    /**
     * @param  array{slug: string, name: string, description: string, code: string}  $attrs
     */
    private function createFlow(User $admin, CarbonImmutable $now, array $attrs): Flow
    {
        $flow = Flow::query()->create([
            'user_id' => $admin->id,
            'name' => $attrs['name'],
            'slug' => $attrs['slug'],
            'description' => $attrs['description'],
            'code' => $attrs['code'],
            'code_updated_at' => $now,
            'status' => 'draft',
            'container_id' => null,
            'entrypoint' => 'main.py',
            'image' => 'flow:dev',
            'timezone' => 'Europe/Moscow',
            'last_started_at' => null,
            'last_finished_at' => null,
        ]);

        $this->stamp($flow, $now);

        return $flow;
    }

    private function stamp(Model $model, CarbonImmutable $createdAt): void
    {
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }

    private function helloWorldCode(): string
    {
        return <<<'PY'
from kawa import Context, Message, Cron, actor, event


@event
class Greeting:
    text: str


@actor(receives=Cron.by("*/1 * * * *"), sends=(Greeting, Message))
def Greeter(ctx: Context, event: Cron) -> None:
    ctx.dispatch(Greeting(text="Hello, защита диплома!"))
    ctx.dispatch(Message(message="[hello] tick"))


@actor(receives=Greeting, sends=Message)
def PrintGreeting(ctx: Context, event: Greeting) -> None:
    ctx.dispatch(Message(message=f"[hello] {event.text}"))
PY;
    }

    private function cronCode(): string
    {
        return <<<'PY'
from kawa import Context, Message, Cron, actor, event


@event
class Tick:
    label: str


@actor(receives=Cron.by("*/1 * * * *"), sends=(Tick, Message))
def Scheduler(ctx: Context, event: Cron) -> None:
    label = event.datetime.strftime("%H:%M")
    ctx.dispatch(Tick(label=label))
    ctx.dispatch(Message(message=f"[cron] tick at {label}"))


@actor(receives=Tick, sends=Message)
def Logger(ctx: Context, event: Tick) -> None:
    ctx.dispatch(Message(message=f"[cron] обработан тик {event.label}"))
PY;
    }

    private function webhookCode(): string
    {
        return <<<'PY'
from kawa import Context, Message, Webhook, actor, event


@event
class IntakeReceived:
    payload: str


@actor(receives=Webhook.by("demo.intake"), sends=(IntakeReceived, Message))
def ReceiveWebhook(ctx: Context, event: Webhook) -> None:
    ctx.dispatch(IntakeReceived(payload=str(event.payload)))
    ctx.dispatch(Message(message=f"[webhook] получено: {event.slug}"))


@actor(receives=IntakeReceived, sends=Message)
def ProcessIntake(ctx: Context, event: IntakeReceived) -> None:
    ctx.dispatch(Message(message=f"[webhook] обработано: {event.payload}"))
PY;
    }

    private function emailCode(): string
    {
        return <<<'PY'
from kawa import Context, Message, Cron, actor, event
from kawa.email import SendEmail


@event
class Notification:
    subject: str
    body: str


@actor(receives=Cron.by("*/5 * * * *"), sends=(Notification, Message))
def BuildNotification(ctx: Context, event: Cron) -> None:
    when = event.datetime.strftime("%Y-%m-%d %H:%M")
    ctx.dispatch(
        Notification(
            subject="Kawa отчёт",
            body=f"Плановый отчёт от {when}",
        )
    )
    ctx.dispatch(Message(message=f"[email] подготовлено уведомление в {when}"))


@actor(receives=Notification, sends=(SendEmail, Message))
def SendNotification(ctx: Context, event: Notification) -> None:
    ctx.dispatch(
        SendEmail(
            subject=event.subject,
            message=event.body,
            recipient="admin@kawa.localhost",
        )
    )
    ctx.dispatch(Message(message=f"[email] отправлено: {event.subject}"))
PY;
    }
}
