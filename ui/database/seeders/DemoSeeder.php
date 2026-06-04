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

        $this->createFlow($admin, $now, [
            'slug' => 'demo-imap-inbox',
            'name' => 'Demo · Email Inbox (IMAP)',
            'description' => 'Опрос почтового ящика по IMAP (Cron, раз в минуту) и публикация входящих писем как событий.',
            'code' => $this->imapInboxCode(),
        ]);
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

    private function imapInboxCode(): string
    {
        return <<<'PY'
import email
import imaplib
from email.header import decode_header, make_header

from kawa import Context, Message, Cron, actor, event

IMAP_HOST = "imap.yandex.ru"
IMAP_PORT = 993
IMAP_USER = "noonartem@yandex.ru"
IMAP_PASSWORD = "burwhdrvgaimltnh"

# Максимум писем, обрабатываемых за один опрос, чтобы не переполнять журнал.
BATCH_LIMIT = 10


@event
class EmailReceived:
    uid: str
    sender: str
    subject: str


def _decode_header(value: str | None) -> str:
    if not value:
        return ""
    try:
        return str(make_header(decode_header(value)))
    except Exception:
        return value


@actor(receives=Cron.by("*/1 * * * *"), sends=(EmailReceived, Message))
def PollInbox(ctx: Context, event: Cron) -> None:
    last_uid = int(ctx.storage.get("last_uid", 0))

    try:
        client = imaplib.IMAP4_SSL(IMAP_HOST, IMAP_PORT)
        client.login(IMAP_USER, IMAP_PASSWORD)
    except Exception as exc:
        ctx.dispatch(Message(message=f"[imap] не удалось подключиться: {exc}"))
        return

    try:
        client.select("INBOX")
        status, data = client.uid("search", None, "ALL")
        if status != "OK":
            ctx.dispatch(Message(message="[imap] поиск писем не выполнен"))
            return

        all_uids = [int(raw) for raw in data[0].split()]
        new_uids = [uid for uid in all_uids if uid > last_uid][-BATCH_LIMIT:]

        if not new_uids:
            ctx.dispatch(Message(message="[imap] новых писем нет"))
            if all_uids:
                ctx.storage.set("last_uid", max(all_uids))
            return

        for uid in new_uids:
            status, msg_data = client.uid("fetch", str(uid), "(RFC822)")
            if status != "OK" or not msg_data or msg_data[0] is None:
                continue

            message = email.message_from_bytes(msg_data[0][1])
            sender = _decode_header(message.get("From"))
            subject = _decode_header(message.get("Subject"))

            ctx.dispatch(
                EmailReceived(uid=str(uid), sender=sender, subject=subject)
            )
            ctx.dispatch(Message(message=f"[imap] письмо от {sender}: {subject}"))

        ctx.storage.set("last_uid", max(new_uids))
    finally:
        try:
            client.logout()
        except Exception:
            pass


@actor(receives=EmailReceived, sends=Message)
def HandleEmail(ctx: Context, event: EmailReceived) -> None:
    ctx.dispatch(
        Message(
            message=f"[imap] обработано письмо #{event.uid}: «{event.subject}» от {event.sender}"
        )
    )
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
