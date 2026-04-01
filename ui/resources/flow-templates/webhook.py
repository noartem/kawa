from kawa import Context, Webhook, Message, actor 


@actor(receivs=Webhook.by("my-webhook"))
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    ctx.dispatch(Message(f"Received webhook: {event.payload}"))

