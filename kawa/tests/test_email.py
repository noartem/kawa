from kawa.email import SendEmail


def test_send_email_creation():
    event = SendEmail(message="Test message")
    assert event.message == "Test message"


def test_send_email_empty_message():
    event = SendEmail(message="")
    assert event.message == ""
