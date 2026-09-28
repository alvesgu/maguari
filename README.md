# Maguari

Lightweight, self-hosted watchdog for Ubuntu VM instances on Google Compute Engine: monitoring, email alerts and safe automatic recovery across GCP projects.

> **Work in progress.** Maguari is in early development and not ready for use yet.

## What it does

- Shows one daily dashboard for instances, databases, web apps, disk usage and certificate expiry across multiple GCP projects
- Sends email alerts and shows notifications when something goes wrong
- Fixes what it can automatically: restarts a service, reboots the OS or, as a last resort, resets the instance through the Compute Engine API, all behind configurable safeguards

## Requirements

- **Server:** Ubuntu 22.04+ on a dedicated instance (designed for the free tier e2-micro), PHP 8.1+, SQLite, nginx and certbot
- **Monitored instances:** Ubuntu 22.04+ and PHP 8.1+ (`php-cli`)

## Name

The maguari stork is a bird of the Pantanal, in Mato Grosso do Sul, Brazil. It soars at great heights on thermals, watching over the wetlands below.

## Documentation

See [`docs/DESIGN.md`](docs/DESIGN.md) for the full design.

Maguari is not an official Google product.

## License

MIT
