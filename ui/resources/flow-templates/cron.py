from kawa import Context, Message, Cron, actor  # type: ignore


@actor(
    receivs=Cron.by("* * * * *"),
    sends=Message,
)
def EveryMinuteMessage(ctx: Context, event: Cron) -> None:
    ctx.dispatch(
        Message(
            message=(
                f"[system] cron tick {event.template} at {event.datetime.isoformat()}"
            )
        )
    )
