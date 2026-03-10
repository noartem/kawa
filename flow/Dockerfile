FROM python:3.12-slim-bookworm

RUN python -m pip install --no-cache-dir uv

WORKDIR /app

COPY kawa/ /tmp/kawa/
RUN uv venv
RUN uv pip install -e /tmp/kawa

ENV VIRTUAL_ENV=/app/.venv
ENV PATH="/app/.venv/bin:$PATH"
