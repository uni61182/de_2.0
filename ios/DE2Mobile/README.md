# DE2Mobile (iOS SwiftUI)

Dieses Verzeichnis enthält ein startfähiges SwiftUI-App-Grundgerüst für die neue DE2-App.

## Enthalten
- Auth-Flow mit `login` und `refresh`
- API-Client gegen `/api/v1`
- Dashboard mit:
  - Player Overview
  - Ressourcen
  - Flotten
  - Flottenbewegung

## Verwendung
1. In Xcode ein neues iOS App Projekt `DE2Mobile` anlegen.
2. Dateien aus `DE2Mobile/` in das Projekt übernehmen.
3. `baseURL` in `APIClient.swift` auf deinen Server setzen.
4. Build & Run auf iPhone/Simulator.
