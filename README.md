# HeroKid - Personalized Storybook Platform (Arabic-First)

HeroKid is a monolithic Laravel application designed for a personalized children's storybook publishing business.

## Technical Stack
- **Backend:** Laravel 11 (PHP 8.2+)
- **Database:** MySQL
- **Frontend:** Vanilla CSS (Tailwind) + Blade Templates
- **Localization:** Arabic-First (RTL support via `dir="rtl"`)

## Local Development (Laravel Sail/Docker)
Since this environment might not have native PHP/Composer installed globally:

1. Copy `.env.example` to `.env`.
   ```bash
   cp .env.example .env
   ```
2. Bring up the Docker containers using Laravel Sail:
   ```bash
   ./vendor/bin/sail up -d
   ```
3. Generate the application key (if missing):
   ```bash
   ./vendor/bin/sail artisan key:generate
   ```
4. Run migrations and seed the initial data (Admin user + 8 Story templates):
   ```bash
   ./vendor/bin/sail artisan migrate:fresh --seed
   ```
5. **Crucial Step for Image Uploads:** Create the symbolic link for storage:
   ```bash
   ./vendor/bin/sail artisan storage:link
   ```
6. Visit `http://localhost`

## Admin Panel Access
- **Email:** `admin@herokid.test`
- **Password:** `password`

## Deployment Checklist (Hostinger / Shared Hosting)
To migrate this codebase to your Hostinger shared hosting:
1. Export the MySQL database you generated via Sail locally.
2. Upload the entire project directory (excluding `.git` and `vendor/...` if you plan to run composer install on the server).
3. Update the `.env` file on the production server with the new DB credentials and set `APP_ENV=production`, `APP_DEBUG=false`.
4. Point the domain document root to the `/public` folder.
5. Manually run `php artisan storage:link` on the server or use cPanel terminal.
6. Import the SQL dump into the production Hostinger database via phpMyAdmin.
