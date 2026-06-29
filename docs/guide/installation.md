# Installation

> Vaulter is in early scaffolding. The commands below describe the intended install flow;
> some artifacts are not published to Packagist / npm yet.

## Backend (Laravel package)

```bash
composer require axioma-studio/vaulter-core
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-migrations
php artisan migrate
```

Or run the bundled install command:

```bash
php artisan vaulter:install
```

## Frontend (Vue components)

```bash
npm install @vaulter/vue
```

## Collaboration server (optional)

Real-time editing uses a small Node.js Hocuspocus server, shipped with a `Dockerfile` and
`docker-compose.yml` under `hocuspocus-server/`:

```bash
cd hocuspocus-server
cp .env.example .env
docker compose up -d
```

Next: [Quick Start](/guide/quick-start).
