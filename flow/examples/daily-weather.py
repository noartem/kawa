# /// script
# dependencies = [
#   "pyowm",
# ]
# ///

from datetime import datetime, timedelta

from kawa import actor, event, NotSupported, Context, Cron
from kawa.email import SendEmail


@event
class GetDateWeatherInfo:
    date: datetime


@event
class DateWeatherInfo:
    date: datetime
    data: str


def format_weather_info(data) -> str:
    return f"some weather info: {data}"


@actor(
    receivs=(Cron.by("0 8 * * *"), DateWeatherInfo),
    sends=(GetDateWeatherInfo, SendEmail, NotSupported),
)
def CreateDailyMessageActor(ctx: Context, event):
    """
    Create daily message
    """
    match event:
        case Cron():
            ctx.dispatch(GetDateWeatherInfo(date=datetime.now()))
        case DateWeatherInfo():
            ctx.dispatch(SendEmail(message=format_weather_info(event.data)))


@actor(
    receivs=GetDateWeatherInfo,
    sends=DateWeatherInfo,
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
            case GetDateWeatherInfo():
                # data = self.get_weather_info()
                data = f"some weather info: {event.date}"
                ctx.dispatch(DateWeatherInfo(date=event.date, data=data))
