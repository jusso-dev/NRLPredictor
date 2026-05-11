FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

# Node + claude-code CLI are required by the claude-agent-sdk package,
# which spawns the CLI as a subprocess to drive the agent loop.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g @anthropic-ai/claude-code \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN useradd -m -s /bin/bash agent && chown -R agent:agent /app
USER agent

EXPOSE 5000

# Gunicorn with 2 sync workers is plenty — agent runs are long-lived
# and we don't want concurrent agents from the same container.
CMD ["gunicorn", "-w", "2", "-b", "0.0.0.0:5000", "--timeout", "600", "app:app"]
