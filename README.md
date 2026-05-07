# Med Log

Medication tracking and reminder web app built with HTML, CSS3, JavaScript, PHP, and MySQL.

## MVP scope

Med Log validates the core product experience for medication tracking:

- Maintain an active medication plan with dose, reminder time, and instructions.
- See the next unlogged dose and a daily adherence percentage.
- Log doses with optional notes.
- Review recent dose history.
- Store application data in MySQL using PHP PDO prepared statements.

> This project is a tracking aid only and does not provide medical advice or clinical decision support.

## Requirements

- PHP 8.1 or newer with the PDO MySQL extension enabled.
- MySQL 8.0 or compatible MariaDB.
- A local PHP-compatible web server.

## Database setup

Create the schema and optional seed data from the repository root:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

The app reads these environment variables, with local defaults shown below:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=med_log
DB_USERNAME=root
DB_PASSWORD=
```

## Running locally

Use PHP's built-in server for local development:

```bash
php -S localhost:8000
```

Then open <http://localhost:8000/index.php>.

## Project structure

- `index.php` handles page rendering and form submissions.
- `config/database.php` creates the PDO MySQL connection.
- `includes/MedicationRepository.php` contains database queries and mutations.
- `includes/helpers.php` contains escaping, request, and redirect helpers.
- `assets/css/styles.css` contains the CSS3 UI styling.
- `assets/js/app.js` contains lightweight JavaScript enhancements.
- `database/schema.sql` and `database/seed.sql` set up MySQL data storage.
