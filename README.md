# map-the-mess-simple

A slimmed-down Map the Mess that does one thing: forward a fly-tipping /
collection report straight to [FixMyStreet](https://www.fixmystreet.com).

No database, no accounts, no build step — just static HTML/JS and two PHP files.

## Files

| File         | What it does                                                          |
|--------------|-----------------------------------------------------------------------|
| `index.html` | The report form (name, email, photo, description, map pin)            |
| `app.js`     | Leaflet map, geolocation, in-browser photo resize, cookie, submission |
| `style.css`  | Styles                                                                |
| `submit.php` | Validates the form and forwards it to FixMyStreet; stores nothing     |
| `fms.php`    | FixMyStreet client (port of `backend/app/fixmystreet.py`)             |
| `config.php` | FixMyStreet URL / service name, UK bounds, upload limits              |

## User details

The full app took the reporter's `full_name` and `email` from the `users` table.
Here they're typed into the form and, if "Remember my details" is ticked, saved
in a `mtm_user` cookie (1 year) on the user's own device. Unticking it deletes
the cookie. The name is sent to FixMyStreet abbreviated ("Sam Taylor" → "Sam T."),
as before.

## Requirements

- PHP 7.4+ with the `curl` and `fileinfo` extensions (standard on most hosts)
- `upload_max_filesize` / `post_max_size` of a few MB — photos are resized to
  1920×1080 JPEG in the browser before upload

## Run locally

    php -S localhost:8000

or, without curl installed locally:

    docker run --rm -p 8000:80 -v "$PWD":/app php:8.1-cli php -S 0.0.0.0:80 -t /app

## Deploy

Copy the files to any PHP web root. Nothing else to set up.
