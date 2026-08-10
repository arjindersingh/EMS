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
