from kawa.email import SendEmail


def test_send_email_creation():
    event = SendEmail(message="Test message")
    assert event.message == "Test message"
    assert event.recipient is None
    assert event.subject is None


def test_send_email_empty_message():
    event = SendEmail(message="")
    assert event.message == ""


def test_send_email_with_recipient():
    event = SendEmail(message="Test message", recipient="user@example.com")

    assert event.message == "Test message"
    assert event.recipient == "user@example.com"


def test_send_email_with_recipient_list_and_subject():
    event = SendEmail(
        message="Test message",
        recipient=["user@example.com", "admin@example.com"],
        subject="Custom subject",
    )

    assert event.message == "Test message"
    assert event.recipient == ["user@example.com", "admin@example.com"]
    assert event.subject == "Custom subject"
