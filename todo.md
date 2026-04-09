# TODO

## 1. Основные доработки

- [ ] Убрать анимацию на появление логов
- [ ] В модалке графа в панели дисковери список акторов/событий можно отрисоывать без бордера и лишних отступов. Пусть определяется в компоненте, где используется компонент этого списка, а не в самом списке.
- [ ] Должны подсвечивать с затуханиям ребра при возникновении событий dispatch
- [ ] Сделать quick send json более компактным, убрать лишнее
- [ ] В просмотр payload в логах добавить кнопку "скопировать"
- [ ] Выделить шаблон промпта чата в отдельный md файл
- [ ] Обновить промпт чата с последними изменениями kawa
- [ ] Не блокировать скролл в модалке деплоя
- [ ] Страница Flows/
- [ ] Страница Dashboard/
- [ ] Добавить логотип и ico
- [ ] Chat
    - [ ] При Apply не должна быть перезагрузка
    - [ ] Не работает второе сообщение в чат, что-то не то с историей
    - [ ] Посмотреть откуда берется инфа в промпте
        - [ ] Надо шаблон в .md файл
        - [ ] Генерировать детали kawa в промпте?
- [ ] Надо разобраться с синхронизацией
    Остался один заметный хвост: UI видел container_id, но статус deployment
    местами еще оставался created, хотя runtime уже работал. Это уже не блокер
    старта, а отдельная проблема синхронизации статуса в UI.

## 2. `ctx.storage()`

Нужно добавить в контекст, который передаётся актору, общий `storage` — аналог `localStorage` для flow/акторов.

`storage` представляет собой объект с методами `get`, `set`, `delete`,
работающий по принципу `ключ → значение` и поддерживающий вложенные ключи
(например: `users.0.name`, `settings.profile.language`).

Основные требования:
- общий для всех акторов в рамках одного запуска;
- сохраняется между перезапусками;
- существует отдельно для каждого окружения (dev/prod), при этом одновременно активен только один запуск;
- при старте Flow Manager получает текущее состояние storage от UI;
- после обработки событий Flow Manager отправляет обновления storage в UI;
- UI сохраняет данные в своей базе.

Также storage должен отображаться в интерфейсе как отдельная вкладка (рядом с чатами и changes):
- всегда доступен для просмотра в виде JSON;
- доступен для редактирования, если flow не запущен.

## 3. Добавить новые шаблоны

### 3.1. Шаблон RSS фида

Из списка RSS подписок создавать краткую сводку о всем новом и отправлять по
почте. Список подписок может быть как сразу в коде, так и доступен по ссылке,
например на github gists. Нужно сохранять в storage какие элементы уже были в
прошлых сводках, чтобы не повторяться. Если новых элементов нет, то ничего не
отправляем.

### 3.2. IMAP получение писем

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

### 3.3. Алерт на почту при плохом воздухе

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

## 4. Кнопки рестарт и стоп

Если Flow запущен, то показывать две кнопки Restart и Stop. Если не запущен, то только Start.

## 5. Доработать деплои

В списке и таблице больше не показывать модалку, а перекидывать на отдельную
страницу. Модалку удалить. На это странице сделать UI максимально похожим на
редактор, но без чатов и истории. Сохранять снапшот storage при остановке деплоя
и показывать его на странице деплоя только для чтения.

## 6. Улучшить промпт в чате

Агент должен знать, что может в скрипте использовать [PEP 723](https://peps.python.org/pep-0723/) и что скрипт запускается
через через [UV Script](https://docs.astral.sh/uv/guides/scripts/#declaring-script-dependencies)
