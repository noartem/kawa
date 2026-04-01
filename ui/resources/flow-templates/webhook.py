from kawa import Context, Webhook, actor  # type: ignore


@actor(receivs=Webhook.by("my-webhook"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    print("Received webhook:", event.payload)
