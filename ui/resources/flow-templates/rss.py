# /// script
# dependencies = [
#   "feedparser",
# ]
# ///

from hashlib import sha256
from urllib.request import urlopen

import feedparser

from kawa import Context, Cron, actor
from kawa.email import SendEmail

SEEN_ITEMS_LIMIT = 500


def load_feed_list(feed_list_url: str) -> list[str]:
    if not feed_list_url:
        return []

    with urlopen(feed_list_url, timeout=10) as response:
        content = response.read().decode("utf-8")

    return [
        line.strip()
        for line in content.splitlines()
        if line.strip() and not line.lstrip().startswith("#")
    ]


def configured_feed_urls(ctx: Context) -> list[str]:
    inline_feeds = [
        str(feed).strip()
        for feed in ctx.storage.get("rss.inline_feeds", [])
        if str(feed).strip()
    ]
    remote_feeds = load_feed_list(str(ctx.storage.get("rss.feed_list_url", "")).strip())

    return list(dict.fromkeys([*inline_feeds, *remote_feeds]))


def entry_id(feed_url: str, entry) -> str:
    parts = [
        feed_url,
        str(entry.get("id") or entry.get("guid") or ""),
        str(entry.get("link") or ""),
        str(entry.get("published") or entry.get("updated") or ""),
        str(entry.get("title") or ""),
    ]

    return sha256("|".join(parts).encode("utf-8")).hexdigest()


def entry_summary(feed_title: str, entry) -> str:
    title = str(entry.get("title") or "Untitled item")
    link = str(entry.get("link") or "")
    published = str(entry.get("published") or entry.get("updated") or "Unknown date")

    lines = [f"- [{feed_title}] {title}"]

    if link:
        lines.append(f"  {link}")

    lines.append(f"  Published: {published}")

    return "\n".join(lines)


@actor(receives=Cron.by("0 * * * *"), sends=SendEmail)
def SendRssDigest(ctx: Context, event: Cron) -> None:
    """
    Flow storage example:

    {
      "rss": {
        "inline_feeds": ["https://example.com/feed.xml"],
        "feed_list_url": "https://example.com/feeds.txt",
        "seen_item_ids": []
      }
    }
    """
    feed_urls = configured_feed_urls(ctx)
    if not feed_urls:
        return

    seen_item_ids = [str(item) for item in ctx.storage.get("rss.seen_item_ids", [])]
    seen_lookup = set(seen_item_ids)
    digest_items: list[str] = []

    for feed_url in feed_urls:
        parsed_feed = feedparser.parse(feed_url)
        feed_title = str(parsed_feed.feed.get("title") or feed_url)

        for entry in parsed_feed.entries:
            item_id = entry_id(feed_url, entry)
            if item_id in seen_lookup:
                continue

            seen_lookup.add(item_id)
            seen_item_ids.append(item_id)
            digest_items.append(entry_summary(feed_title, entry))

    if not digest_items:
        return

    ctx.storage.set("rss.seen_item_ids", seen_item_ids[-SEEN_ITEMS_LIMIT:])
    ctx.dispatch(
        SendEmail(
            subject=f"RSS digest: {len(digest_items)} new items",
            message="\n\n".join(digest_items),
        )
    )
