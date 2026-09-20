#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
command -v composer >/dev/null || { echo "Composer is required."; exit 1; }
command -v php >/dev/null || { echo "PHP is required."; exit 1; }
command -v node >/dev/null || { echo "Node.js is required."; exit 1; }
command -v npm >/dev/null || { echo "npm is required."; exit 1; }

if [ ! -f backend/artisan ]; then
  echo "The backend source is missing. Restore the project checkout before running setup."
  exit 1
fi
cd backend
[ -f .env ] || cp .env.example .env
mkdir -p database
touch database/database.sqlite

if ! grep -Eq '^ADMIN_EMAIL=.+$' .env || ! grep -Eq '^ADMIN_PASSWORD=.+$' .env; then
  echo "Set ADMIN_EMAIL and ADMIN_PASSWORD in backend/.env before setup."
  exit 1
fi

composer install
if ! grep -Eq '^APP_KEY=.+$' .env; then
  php artisan key:generate
fi
php artisan migrate --seed --force
cd ../frontend
[ -f .env ] || cp .env.example .env
npm install

echo ""
echo "Setup complete. Configure the router in the admin dashboard after starting the app."
echo "Backend:   cd backend && php artisan serve --host=0.0.0.0 --port=8000"
echo "Queue:     cd backend && php artisan queue:work"
echo "Scheduler: cd backend && php artisan schedule:work"
echo "Frontend:  cd frontend && npm run dev"
