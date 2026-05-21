from kawa import Context, Webhook, Message, actor 


@actor(receives=Webhook.by("my-webhook"), sends=Message)
def HandleWebhook(ctx: Context, event: Webhook) -> None:
    ctx.dispatch(Message(f"Received webhook: {event.payload}"))
