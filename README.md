# OMSONS QR Label Generator

A small PHP app for creating product QR labels with login, product master, batch generation, serial ranges, printable label sheets, and MySQL storage. It runs locally on XAMPP and is prepared for Vercel using the community `vercel-php` runtime.

## Run

Use XAMPP Apache and open:

```text
http://localhost/omQr/
```

Make sure MySQL is running in XAMPP Control Panel before opening the app.

Or run the PHP built-in server:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t C:\xampp\htdocs\omQr
```

Then open:

```text
http://127.0.0.1:8080
```

## Dummy Login

```text
Email: demo@omsons.local
Password: demo123
```

The login page also has a `Dummy Login` button.

## Data

Database settings are in:

```text
config/database.php
```

Default XAMPP settings:

```text
Host: 127.0.0.1
Port: 3306
Database: omqr
User: root
Password: empty
```

The app creates the database and tables automatically:

```text
products
batches
labels
sessions
```

## Vercel Deploy

Create a hosted MySQL/MariaDB database first. Local XAMPP MySQL cannot be reached by Vercel.

Set these Vercel environment variables:

```text
OMQR_DB_HOST=your-mysql-host
OMQR_DB_PORT=3306
OMQR_DB_NAME=omqr
OMQR_DB_USER=your-mysql-user
OMQR_DB_PASS=your-mysql-password
OMQR_DB_AUTO_CREATE=false
```

You can also use a single MySQL URL:

```text
DATABASE_URL=mysql://user:password@host:3306/database
```

Create the database itself in your provider dashboard, then either let the app create the tables on first load or run:

```text
database/schema.sql
```

Deploy from the project root:

```powershell
npm i -g vercel
vercel login
vercel
vercel --prod
```

Vercel files:

```text
api/index.php
vercel.json
.vercelignore
.env.example
```

## Railway Deploy

This repo is configured for Railway Railpack without a Dockerfile:

```text
railway.json
composer.json
```

Create a Railway project from GitHub repo `devbigu/omsons-qr`, then add a MySQL service in the same Railway project.

Set these app service variables:

```text
DATABASE_URL=${{MySQL.MYSQL_URL}}
OMQR_DB_AUTO_CREATE=true
OMQR_SESSION_DRIVER=database
OMQR_ASSET_BASE=/assets
OMQR_APP_ENTRY=/
RAILPACK_PHP_ROOT_DIR=/app/public
RAILPACK_PHP_EXTENSIONS=pdo_mysql,mysqli
```

If your database service is not named `MySQL`, replace `MySQL` with the exact service name.

After the app opens once and tables are created, change:

```text
OMQR_DB_AUTO_CREATE=false
```

Then redeploy.

## Import From Local phpMyAdmin

Your local phpMyAdmin is useful for exporting your XAMPP database.

1. Open `http://localhost/phpmyadmin`.
2. Select database `omqr`.
3. Click `Export`.
4. Choose `Custom`.
5. Export tables and data as SQL.
6. Import that SQL into the hosted MySQL database, or run it with the MySQL CLI using the hosted database connection details.

Do not use `127.0.0.1` on Railway. That only points to the app container itself, not your XAMPP MySQL.
