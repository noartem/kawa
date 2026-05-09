# /// script
# dependencies = [
#   "pyowm",
# ]
# ///
# 
# Storage should contains:
# {
#   "air_quality": {
#     "api_key": "your-openweather-api-key",
#     "places": [
#       {"name": "Berlin", "lat": 52.52, "lon": 13.405}
#     ],
#     "threshold": 3
#   }
# }
# OpenWeather AQI values range from 1 (good) to 5 (very poor).

from pyowm import OWM

from kawa import Context, Cron, actor
from kawa.email import SendEmail

DEFAULT_THRESHOLD = 3


def configured_places(ctx: Context) -> list[dict[str, float | str]]:
    places: list[dict[str, float | str]] = []

    for raw_place in ctx.storage.get("air_quality.places", []):
        if not isinstance(raw_place, dict):
            continue

        latitude = raw_place.get("lat")
        longitude = raw_place.get("lon")
        if latitude is None or longitude is None:
            continue

        places.append({
            "name": str(raw_place.get("name") or f"{latitude}, {longitude}"),
            "lat": float(latitude),
            "lon": float(longitude),
        })

    return places


@actor(receives=Cron.by("0 * * * *"), sends=SendEmail)
def SendAirQualityAlert(ctx: Context, event: Cron) -> None:
    api_key = str(ctx.storage.get("air_quality.api_key", "")).strip()
    places = configured_places(ctx)
    threshold = int(ctx.storage.get("air_quality.threshold", DEFAULT_THRESHOLD))

    if not api_key or not places:
        return

    air_quality_manager = OWM(api_key).airpollution_manager()
    alerts: list[str] = []

    for place in places:
        status = air_quality_manager.air_quality_at_coords(
            float(place["lat"]),
            float(place["lon"]),
        )
        aqi = int(status.aqi)

        if aqi < threshold:
            continue

        alerts.append(
            "\n".join([
                f"- {place['name']}",
                f"  AQI: {aqi}",
                f"  Measured at: {status.reference_time('iso')}",
            ])
        )

    if not alerts:
        return

    ctx.dispatch(
        SendEmail(
            subject=f"Air quality alert: {len(alerts)} place(s)",
            message="\n\n".join(alerts),
        )
    )
