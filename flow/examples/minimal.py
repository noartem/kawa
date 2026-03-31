from kawa import actor, event, NotSupported, Context, Cron


@actor(receivs=Cron.by("0 8 * * *"))
def MorningActor(ctx: Context, event):
    print("Good morning!")
