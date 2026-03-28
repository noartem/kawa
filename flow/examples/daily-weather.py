# /// script
# dependencies = [
#   "pyowm",
# ]
# ///

from datetime import datetime, timedelta

from kawa import actor, event, NotSupportedEvent, Context
from kawa.cron import CronEvent
from kawa.email import SendEmail


@event
class GetDateWeatherInfoEvent:
    date: datetime


@event
class DateWeatherInfoEvent:
    date: datetime
    data: str


def format_weather_info(data) -> str:
    return f"some weather info: {data}"


@actor(
    receivs=(CronEvent.by("0 8 * * *"), DateWeatherInfoEvent),
    sends=(GetDateWeatherInfoEvent, SendEmail, NotSupportedEvent),
)
def CreateDailyMessageActor(ctx: Context, event):
    """
    Create daily message
    """
    match event:
        case CronEvent():
            ctx.dispatch(GetDateWeatherInfoEvent(date=datetime.now()))
        case DateWeatherInfoEvent():
            ctx.dispatch(SendEmail(message=format_weather_info(event.data)))


@actor(
    receivs=GetDateWeatherInfoEvent,
    sends=DateWeatherInfoEvent,
    max_instances=1,
    keep_instance=timedelta(minutes=1),
)
class WeatherActor:
    """
    Retrieve weather info.
    Using OpenWeather API
    """

    def __init__(self):
        # init weather api
        pass

    def __call__(self, ctx: Context, event):
        match event:
            case GetDateWeatherInfoEvent():
                # data = self.get_weather_info()
                data = f"some weather info: {event.date}"
                ctx.dispatch(DateWeatherInfoEvent(date=event.date, data=data))
