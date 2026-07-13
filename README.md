# Decant Please!

Perfume decant catalog + ordering for a Myanmar decanter. Monorepo:

- `backend/` — Laravel 13 + Filament v5 admin panel (`/admin`)
- `frontend/` — Next.js 16 (App Router, TypeScript, Tailwind v4) customer site

## Prerequisites

- PHP 8.3+, Composer
- Node.js 24 LTS
- MySQL 8.0+ — or use Docker:

```bash
docker run -d --name decant-mysql \
  -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=decant_please \
  -p 3306:3306 -v decant_mysql_data:/var/lib/mysql mysql:8
```

## Backend (port 8000)

```bash
cd backend
composer install
cp .env.example .env        # then set DB_* and ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Admin panel: http://localhost:8000/admin — login `admin@decantplease.local`,
password = `ADMIN_PASSWORD` from `.env`.

## Frontend (port 3000)

```bash
cd frontend
npm install
cp .env.local.example .env.local
npm run dev
```

Customer site: http://localhost:3000 (talks to the API at `NEXT_PUBLIC_API_URL`).
