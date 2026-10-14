FROM node:24-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libx11-6 libxcomposite1 \
    libxdamage1 libxext6 libxfixes3 libxrandr2 libgbm1 libxcb1 libxkbcommon0 \
    libdrm2 libpango-1.0-0 libcairo2 libasound2 fonts-noto-cjk \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
WORKDIR /browser
ENV PLAYWRIGHT_BROWSERS_PATH=/browser/browsers
RUN npm install --no-audit --no-fund --save-exact playwright@1.56.1
RUN node node_modules/playwright/cli.js install chromium --only-shell
ENV NODE_PATH=/browser/node_modules
WORKDIR /app
