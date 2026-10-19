# HerbalEASE

A full-stack herbal reference web app with user accounts, a community discussion board, and a user-editable herb list.

**Live demo:** https://marlenafabrick.net/herbalease/
**Demo login:** `demo` / `HerbalTest1`

## Features
- Searchable database of 265 herbs, loaded from JSON
- Save herbs to personal boards
- User accounts: sign up, log in, log out
- Community discussion board: posts are saved in MySQL and tied to the logged-in user
- User-added herb list with full CRUD (create, read, update, delete), saved in MySQL
- Ownership rules: users can only edit or delete their own herbs and posts, enforced on the server
- User survey used to guide design changes

## Tech stack
JavaScript, jQuery, Bootstrap, HTML, CSS, PHP (PDO), MySQL, hosted on Hostinger

## Project structure
| Path | Purpose |
|---|---|
| `index.html` | Log in / create account |
| `database.html` | Searchable herb database |
| `boards.html` | Saved herb boards |
| `discussion.html` | Community discussion board |
| `cruddb.html` | Herbs added by users (CRUD) |
| `survey.html` | User feedback survey |
| `api/auth.php` | Sign up, log in, log out, current user |
| `api/herbs.php` | User-added herbs API |
| `api/posts.php` | Discussion board API |
| `api/boards.php` | Saved boards API |
| `api/db.php` | Shared database connection, sessions, and validation rules |

## Security
- Passwords hashed with PHP `password_hash()` and never stored in plain text
- Prepared SQL statements to prevent SQL injection
- Login lockout after repeated failed attempts, per username and per IP address
- Custom request header check to block cross-site request forgery
- Session cookies set to HttpOnly and SameSite=Lax
- All user input displayed as plain text to prevent cross-site scripting (XSS)
- Server-side validation and length limits on every field
- `.htaccess` blocks direct access to configuration files

## Project history
Originally built with Google Apps Script and browser localStorage. I later migrated the backend to a PHP API and a MySQL database, so accounts, discussion posts, and user-added herbs are shared across all users and devices.

## Running it yourself
1. Upload the files to a web host with PHP 7.4+ and MySQL.
2. Copy `api/config.example.php` to `api/config.php` and fill in your database details.
3. Open the site. The database tables are created automatically the first time they're needed.

## Disclaimer
Herb information is for general educational purposes only and is not medical advice.
