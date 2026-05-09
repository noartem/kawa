# /// script
# dependencies = [
#   "imap-tools",
# ]
# ///
# 
# Flow storage example:
# {
#   "imap": {
#     "host": "imap.example.com",
#     "username": "user@example.com",
#     "password": "app-password",
#     "folder": "INBOX",
#     "last_uid": 0
#   }
# }

from imap_tools import MailBox

from kawa import Context, Cron, Message, actor, event

@event
class ImapEmailReceived:
    uid: int
    sender: str
    subject: str
    body: str
    received_at: str


@actor(receives=Cron.by("0 7 * * *"), sends=ImapEmailReceived)
def FetchImapEmails(ctx: Context, event: Cron):
    host = ctx.storage.get("imap.host")
    username = ctx.storage.get("imap.username")
    password = ctx.storage.get("imap.password")
    folder = ctx.storage.get("imap.folder", "INBOX")

    if not host or not username or not password:
        return

    last_uid = int(ctx.storage.get("imap.last_uid", 0))
    highest_uid = last_uid

    with MailBox(host).login(username, password, folder) as mailbox:
        for message in sorted(mailbox.fetch(), key=lambda message: int(message.uid))
            message_uid = int(message.uid)
            if message_uid <= last_uid:
                continue

            ctx.dispatch(
                ImapEmailReceived(
                    uid=message_uid,
                    sender=str(message.from_ or ""),
                    subject=str(message.subject or "(no subject)"),
                    body=str((message.text or message.html or "").strip()),
                    received_at=(
                        message.date.isoformat() if message.date is not None else ""
                    ),
                )
            )
            highest_uid = message_uid

    if highest_uid > last_uid:
        ctx.storage.set("imap.last_uid", highest_uid)


@actor(receives=ImapEmailReceived, sends=Message)
def ProcessImapEmail(ctx: Context, event: ImapEmailReceived):
    ctx.dispatch(
        Message(
            message=(
                f"Process IMAP email #{event.uid} from {event.sender}: "
                f"{event.subject}"
            )
        )
    )

