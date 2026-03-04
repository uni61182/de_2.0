# Native App + Web-Client auf bestehender DE2-Datenbank

## Kurzantwort
Ja – technisch ist das machbar. Die bestehende PHP/MySQL-Installation bleibt als **Single Source of Truth** erhalten, und wir setzen davor eine stabile API-Schicht. Darauf bauen wir eine iPhone-App und einen Web-Client mit identischer Spiellogik.

## Zielbild

- **Backend bleibt bestehen:** bestehende DB + Tick-System (`wt.php`, `mt.php`, `kt.php`) laufen weiter.
- **Neue API-Schicht:** Versionierte JSON-API unter `api/v1/*` mit Auth, Validierung und Rate Limits.
- **Clients:**
  - iOS-App (nativ in SwiftUI)
  - Web-App (z. B. Next.js/Vue)
- **Regel:** Kritische Spielberechnungen (Bau, Kampf, Ressourcen) passieren ausschließlich serverseitig.

## Warum diese Architektur?

1. Keine doppelte Business-Logik im Client.
2. Bestehende Runde/Tick-Mechanik bleibt unverändert nutzbar.
3. iOS und Web nutzen dieselben Endpunkte.
4. Schrittweise Migration möglich, ohne Big-Bang-Rewrite.

## Empfohlene Umsetzung in Phasen

### Phase 1 – API-Härtung (Pflicht)

#### 1.1 Authentifizierung modernisieren
- Session-basierte Web-Auth beibehalten.
- Für mobile Clients Token-Auth ergänzen (z. B. kurzlebiges Access Token + Refresh Token).
- Login-Endpunkt liefern:
  - User-Basisdaten
  - erlaubte Features / Rollen
  - Serverzeit / Tick-Info

#### 1.2 Kernendpunkte bereitstellen
Mindestens für MVP:
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `GET /api/v1/player/overview`
- `GET /api/v1/player/resources`
- `GET /api/v1/player/fleets`
- `POST /api/v1/fleet/move`
- `GET /api/v1/research/tree`
- `POST /api/v1/research/start`
- `GET /api/v1/alliance/overview`
- `GET /api/v1/messages`
- `POST /api/v1/messages`

#### 1.3 Sicherheitsanforderungen
- Prepared Statements überall (`mysqli_execute_query` o. ä.).
- Striktes Input-Validation-Layer (numeric ranges, enum checks, string length).
- Output-Escaping + konsistente JSON-Errors.
- Rate Limiting auf Login, Nachrichten, Flottenaktionen.

### Phase 2 – iOS-App (SwiftUI)

#### Modulstruktur
- `Auth` (Login, Session-Refresh)
- `Dashboard` (Ressourcen, Tick-Countdown, News)
- `Fleet` (Flottenstatus, Bewegungen)
- `Research` (Tech-Baum, Start)
- `Alliance` (Übersicht, Mitglieder, Diplomatie)
- `Messaging` (Ingame-Nachrichten, optional Chat)

#### iOS-Technik
- SwiftUI + Combine/async-await
- Token im Keychain speichern
- Offline-Caching nur für Read-Modelle
- Push-Benachrichtigungen über APNs (z. B. Kampfbericht, Flottenankunft)

### Phase 3 – Web-Client

- Eigenständiger Frontend-Client gegen dieselbe `api/v1`.
- Responsive UI, damit Desktop und Mobile Browser funktionieren.
- Session- oder Token-Login abhängig vom Deployment.

### Phase 4 – Betrieb & Qualität

- API-Metriken (Latenz, Error Rate, Endpoint-Usage)
- Strukturierte Logs mit Correlation-ID
- Lasttests für Tick-nahe Endpunkte (vor allem Kampf/Fleet)
- Feature Flags, um neue App-Funktionen schrittweise auszurollen

## Technische Leitplanken

1. **Keine Spiellogik im Client**
   - Nur Darstellung + Benutzerinteraktion
   - Entscheidungen/Berechnungen immer im Backend

2. **Tick-Synchronität beachten**
   - API-Responses sollten Tick-ID/Timestamp enthalten
   - Client zeigt „Stand von Tick X“

3. **Kompatibilität sichern**
   - Alte Weboberfläche kann parallel weiterlaufen
   - Neue API ist additive Erweiterung

## Grobe Aufwandsschätzung

- Phase 1 (API-Härtung + MVP-Endpunkte): 3–6 Wochen
- Phase 2 (iOS MVP): 4–8 Wochen
- Phase 3 (Web MVP): 3–6 Wochen
- Phase 4 (Stabilisierung): 2–4 Wochen

> Gesamt für ein sauberes MVP: etwa 3–5 Monate, abhängig von Teamgröße und Altlasten im betroffenen Code.

## Konkreter nächster Schritt

1. API-Vertrag als OpenAPI-Spezifikation definieren.
2. Danach `Auth + Overview + Resources + Fleets` als erste vertikale Scheibe umsetzen.
3. Parallel SwiftUI-App-Skeleton mit Login + Dashboard anbinden.

---

Wenn gewünscht, kann darauf aufbauend als nächstes direkt ein lauffähiges Grundgerüst liefern:
- `api/v1/auth/*` + `api/v1/player/overview`
- SwiftUI-App mit Login, Token-Refresh und Dashboard
- Web-Client-Seite mit identischem Overview-Endpunkt
