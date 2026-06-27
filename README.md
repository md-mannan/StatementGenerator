# Statement Analyzer

A web application for reconciling retail client statements across branch records, received statements, and annexure/cheque data. Built for teams that track invoice-level payments per client and branch (for example, hypermarket chains with many store locations).

## Features

### Clients and branches

- Create and manage clients, each with multiple branch codes
- View branch totals and client-level statement summaries
- Generate consolidated client statements with Excel and PDF export

### Branch statements

- Enter, edit, and bulk-manage statement entries per branch
- Filter by statement month and search invoices
- Import entries from Excel; export to Excel or PDF
- Attach invoice scans with polygon crop, image enhancement, webcam capture, and print preview

### Received statements

- Record what the client reported as received, per invoice and branch
- Import and export (Excel/PDF)
- Mark entries where no branch match is expected

### Annexure and cheques

- Track annexure entries and linked cheques
- Import annexure data; export to Excel or PDF
- Review and complete cheque workflows

### Cross-check and dashboard

- **Cross-check** compares branch, received, and annexure amounts per invoice
- Statuses: matched, complete, mismatch, and incomplete
- **Dashboard** shows overview stats, reconciliation totals, per-client progress, and recent uploads
- Global search across clients and entries

### Authentication

- Laravel Fortify: registration, login, email verification, password reset, and two-factor authentication
- Optional passkey support via `@laravel/passkeys`

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13 |
| Frontend | React 19, Inertia.js v3, TypeScript |
| Styling | Tailwind CSS v4, Radix UI |
| Auth | Laravel Fortify |
| Routes (frontend) | Laravel Wayfinder |
| Exports | Maatwebsite Excel, DomPDF |
| Tests | Pest 4 |

## Requirements

- PHP 8.3 or higher with common extensions (`mbstring`, `pdo`, etc.)
- Composer
- Node.js 20+ and npm
- MySQL (default) or another database supported by Laravel

## Installation

1. **Clone the repository** and enter the project directory.

2. **Install PHP dependencies:**

   ```bash
   composer install
   ```

3. **Configure the environment:**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Update `.env` with your database credentials. The example defaults to MySQL database `statements_tracker`:

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=statements_tracker
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. **Run migrations:**

   ```bash
   php artisan migrate
   ```

5. **Install frontend dependencies and build assets:**

   ```bash
   npm install
   npm run build
   ```

6. **Generate Wayfinder route helpers** (after route changes or on first setup):

   ```bash
   php artisan wayfinder:generate --with-form
   ```

Alternatively, run the full setup script:

```bash
composer setup
```

## Development

Start the app, queue worker, and Vite dev server together:

```bash
composer dev
```

This runs:

- `php artisan serve` — application at [http://localhost:8000](http://localhost:8000)
- `php artisan queue:listen` — background jobs (imports, etc.)
- `npm run dev` — hot module replacement for the React frontend

Create a user via registration at `/register`, or seed data if you add seeders.

After changing Laravel routes or controllers used by the frontend, regenerate Wayfinder types:

```bash
php artisan wayfinder:generate --with-form
```

## Testing and quality

Run the full test suite (Pint, PHPStan, Pest):

```bash
composer test
```

Run Pest only:

```bash
php artisan test
```

Frontend checks:

```bash
npm run lint:check
npm run format:check
npm run types:check
```

CI-style check (mirrors GitHub Actions):

```bash
composer ci:check
```

## Project layout

```
app/
  Http/Controllers/   # HTTP layer
  Models/             # Eloquent models
  Services/           # Business logic (statements, cross-check, dashboard, imports)
resources/js/
  pages/              # Inertia React pages
  components/         # Shared UI (tables, dialogs, invoice scan, etc.)
  layouts/            # App and settings layouts
routes/
  web.php             # Main application routes
tests/
  Feature/            # Pest feature tests
```

## License

MIT
