# TODO

- [ ] Добавить новые шаблоны
  - [ ] Шаблон RSS фида
    Из списка RSS подписок создавать краткую сводку о всем новом и отправлять по
    почте. Список подписок может быть как сразу в коде, так и доступен по ссылке,
    например на github gists. Нужно сохранять в storage какие элементы уже были в
    прошлых сводках, чтобы не повторяться. Если новых элементов нет, то ничего не
    отправляем.
  - [ ] IMAP получение писем
    Получение писем через IMAP, создание из этого события и обработка. Для базового
    примера можем сделать обзор всех писем за день в 7 утра.
    ```python
    #!/usr/bin/env -S uv run --script
    # /// script
    # requires-python = ">=3.11"
    # dependencies = [
    #   "imap-tools",
    # ]
    # ///
    
    from imap_tools import MailBox, AND
    
    EMAIL = "your@email.com"
    PASSWORD = "your_password"
    
    with MailBox("imap.gmail.com").login(EMAIL, PASSWORD) as mailbox:
        for msg in mailbox.fetch(AND(seen=False)):
            print("New:", msg.subject, msg.from_)
    ```

  - [ ] Алерт на почту при плохом воздухе
    Несколько мест в коде, раз в час проверка через openweathermap.org
    (через pypi.org/project/pyowm) - если есть
    проблемы, то письмо на почту.

    ```python
    #!/usr/bin/env -S uv run
    # /// script
    # requires-python = ">=3.11"
    # dependencies = [
    #   "pyowm",
    # ]
    # ///
    
    from pyowm import OWM
    
    
    DANGEROUS_AQI_MIN = 4 # 4 - плохой воздух, 5 - очень плохой
    API_KEY = "YOUR_OPENWEATHER_API_KEY"
    PLACES = [
        "Helsinki,FI",
        "Chelyabinsk,RU",
        "Moscow,RU",
    ]
    
    
    owm = OWM(API_KEY)
    geo = owm.geocoding_manager()
    air = owm.airpollution_manager()
    
    dangerous_rows: list[str] = []
    
    for place in PLACES:
        place = place.strip()
        if not place:
            continue
    
        locations = geo.geocode(place, limit=1)
        if not locations:
            continue
    
        loc = locations[0]
        status = air.air_quality_at_coords(loc.lat, loc.lon)
        aqi = status.aqi
    
        if aqi >= DANGEROUS_AQI_MIN:
            dangerous_rows.append(
                f"{place}: AQI={aqi}, "
                f"pm2_5={status.pm2_5}, pm10={status.pm10}, "
                f"o3={status.o3}, no2={status.no2}, so2={status.so2}, co={status.co}"
            )
    
    if dangerous_rows:
        print("⚠️ Обнаружено опасное качество воздуха :\n\n" + "\n".join(dangerous_rows) + "\n")
    ```
