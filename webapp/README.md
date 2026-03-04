# DE2 Web App Client

Ein Web-Client für die API (`/api/v1`) mit Login, Token-Refresh, Dashboard und Flottenbewegung.

## Start (lokal)

```bash
php -S 0.0.0.0:8000 -t .
```

Dann öffnen: `http://localhost:8000/webapp/`

## Features
- Login via `POST /api/v1/auth/login`
- Automatisches Token-Refresh via `POST /api/v1/auth/refresh`
- Dashboard-Abfragen:
  - `GET /api/v1/player/overview`
  - `GET /api/v1/player/resources`
  - `GET /api/v1/player/fleets`
- Flottenbewegung:
  - `POST /api/v1/fleet/move`
