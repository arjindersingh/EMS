# EMS

Event Management System

## Stack
- PHP 8.2
- MySQL 8.0
- Apache via Docker Compose

## Setup
1. Copy `.env.example` to `.env`.
2. Run:
   ```bash
   docker compose up --build
   ```
3. Open http://localhost:8000

## Database
The application uses PDO with MySQL. Configure connection values in `.env` or via Docker Compose environment variables.

## Deploy to Vercel

Vercel builds `Dockerfile.vercel` automatically and runs the application as a
container-backed Function. Import this repository into Vercel with the project
root set to the repository root. Leave Framework Preset as `Other` and do not
set Build Command, Output Directory, or Install Command overrides.

Add these variables under **Project Settings > Environment Variables** for
Production and Preview as appropriate:

```text
DB_HOST=your-public-or-managed-mysql-host
DB_PORT=3306
DB_DATABASE=your-database
DB_USERNAME=your-username
DB_PASSWORD=your-password
APP_BASE_URL=https://your-production-domain.example
ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@example.com
ADMIN_USERNAME=admin
ADMIN_PASSWORD=use-a-long-random-password
```

The MySQL service in `docker-compose.yml` is only for local development. The
Vercel deployment must use a persistent MySQL database reachable from Vercel;
run `php scripts/create_tables.php` against that database once before opening
the application.

Vercel container instances have ephemeral local filesystems. Registration
photos, generated QR images, and admin-uploaded audio currently write beneath
`assets/`; those writes are not durable across deployments or scaling. Use
external object storage for those features in production.
