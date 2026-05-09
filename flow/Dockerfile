FROM python:3.12-slim-bookworm@sha256:58525e1a8dada8e72d6f8a11a0ddff8d981fd888549108db52455d577f927f77

RUN python -m pip install --no-cache-dir uv

WORKDIR /app

COPY kawa/ /tmp/kawa/
RUN uv venv
RUN uv pip install -e /tmp/kawa

ENV VIRTUAL_ENV=/app/.venv
ENV PATH="/app/.venv/bin:$PATH"
