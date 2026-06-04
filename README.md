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

## Render Deploy

Render runs this PHP app with Docker. The project includes:

```text
Dockerfile
.dockerignore
```

The Docker service exposes only `public/` as the web root. Internal folders such as `config/`, `src/`, `data/`, and `database/` are not served publicly.

Create a MySQL service first. If you use Render's MySQL private service, use MySQL 8 and attach a disk:

```text
Mount Path: /var/lib/mysql
Size: 10 GB or more
```

Set the MySQL service variables:

```text
MYSQL_DATABASE=omqr
MYSQL_USER=omqr_user
MYSQL_PASSWORD=choose-a-strong-password
MYSQL_ROOT_PASSWORD=choose-another-strong-password
```

After MySQL is running, create a Render Web Service from this repo:

```text
Language: Docker
Branch: main
```

Set these web service environment variables:

```text
OMQR_DB_HOST=your-render-mysql-private-host
OMQR_DB_PORT=3306
OMQR_DB_NAME=omqr
OMQR_DB_USER=omqr_user
OMQR_DB_PASS=your MYSQL_PASSWORD
OMQR_DB_AUTO_CREATE=false
OMQR_SESSION_DRIVER=database
OMQR_ASSET_BASE=/assets
OMQR_APP_ENTRY=/
```

The app creates its tables on first load if the database user can create tables. You can also import:

```text
database/schema.sql
```
