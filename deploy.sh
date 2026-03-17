#!/usr/bin/env bash
# =============================================================================
# HeroKid — Production Deployment Script
# Run this after every git pull on the production server.
# Usage: bash deploy.sh
# =============================================================================

set -e  # Exit immediately on error

echo "🚀 Starting HeroKid deployment..."

# ── 1. Install / update PHP dependencies ─────────────────────────────────────
echo "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# ── 2. Install / build frontend assets ───────────────────────────────────────
echo "🎨 Building frontend assets..."
npm ci --prefer-offline
npm run build

# ── 3. Clear and re-cache config / routes / views ────────────────────────────
echo "🧹 Clearing old caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo "⚡ Caching config, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── 4. Run database migrations ────────────────────────────────────────────────
echo "🗄️  Running database migrations..."
php artisan migrate --force

# ── 5. Create storage symlink — CRITICAL for uploaded image display ───────────
# This creates public/storage → storage/app/public
# Without this, all uploaded images return 404.
# Safe to run multiple times — Laravel skips if symlink already exists.
echo "🔗 Creating storage symlink..."
php artisan storage:link

# ── 6. Fix file permissions ───────────────────────────────────────────────────
echo "🔐 Setting file permissions..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ── 7. Restart queue workers (if using queues) ───────────────────────────────
# Uncomment if you use php artisan queue:work in production
# echo "🔄 Restarting queue workers..."
# php artisan queue:restart

echo ""
echo "✅ Deployment complete!"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Post-deploy checklist:"
echo "  ✓ Composer dependencies installed"
echo "  ✓ Frontend assets built"
echo "  ✓ Config / route / view caches refreshed"
echo "  ✓ Database migrations applied"
echo "  ✓ Storage symlink created (public/storage → storage/app/public)"
echo "  ✓ File permissions set"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
