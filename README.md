# Bayline: GPU smart parking app (PHP + SQLite)

## Run it (needs PHP 8.1+ with pdo_sqlite)
    php -S localhost:8000 -t public
    open http://localhost:8000
The database file `data/parking.sqlite` is created on first request with 3 car parks and 48 bays each.

## Make it feel live without a GPU
    php tools/simulate.php        # in a second terminal

## Connect a real GPU
Edit `gpu_worker/worker.py` (camera address and bay rectangles), then run it on the GPU machine.
It posts to `api/index.php?r=ingest` with the `X-Ingest-Key` header. Set `INGEST_KEY` in the server environment and in the worker.
Plate reads go to `?r=plate`; the reply says whether to open the gate for a booked plate.

## Install as an app
Open the site over HTTPS on a phone, then "Add to Home Screen" (or the install icon in Chrome). It has a manifest and service worker.

## What is stored
users (password hashes only), lots, bays, bookings, plate_events, gpu_stats.
Card numbers and security codes are never sent to or stored by the server: only the last 4 digits.

## Before going live
1. Replace `charge()` in `api/index.php` with a real provider (Paymob, Fawry or similar) using their hosted checkout or tokens. InstaPay goes through the same provider.
2. Serve over HTTPS and set a strong `INGEST_KEY`.
3. Add login rate limiting, and for many users move from SQLite to MySQL or PostgreSQL (change the DSN in `db.php`).
4. Real Google sign-in needs Google OAuth credentials from your own Google Cloud project.
5. Replace the placeholder car park names, coordinates and prices.
