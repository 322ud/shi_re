# Änderungsprotokoll

## 0.5.2

- Administrativ erlaubte INValidator-Updates können beim Öffnen der Rechnungsansicht durch jeden angemeldeten Benutzer ausgeführt werden; Releaseprüfung nur bei eingeschalteter XML-Validierung.
- Updates sperren neue Rechnungsvorgänge und prüfen anschließend aktive Entwürfe erneut. Bei aktiver Rechnung wird das Update zurückgestellt.
- Nach unterbrochenen Updates verhindert eine bleibende Sperrmarkierung neue Rechnungen bis zur administrativen Prüfung; Wiederherstellung siehe Administrationsanleitung.
- Bestehende Routen werden weiterverwendet. Reguläres App-Update durchführen und die Rechnungsansicht neu laden, damit das aktualisierte JavaScript geladen wird.

## 0.5.1

- App-Store-Beschreibung überarbeitet und Dokumentationslinks aktualisiert.

## 0.5.0 – 2026-11-24

- Unterstützung für Nextcloud 34 und 35; Nextcloud-35-Kompatibilitätsprüfung vom Maintainer bestätigt.
- Administrationsseite mit Tabellen für Einstellungen und Komponenten neu strukturiert.
- PDF-Erzeugung, XML-Einbindung, PDF-Prüfung und Meldungsbereich separat konfigurierbar.
- Bei Rechnung und Storno zeigt eine Liste unter dem Erstellen-Button die aktiven Verarbeitungsschritte.
- Archivliste linksbündig unter dem Archiv-Button ausgerichtet.
- Fensterkennungen werden auch auf lokalen HTTP-Testhosts ohne `crypto.randomUUID` kryptografisch zufällig erzeugt; bestehende Kennungen bleiben beim Neuladen erhalten.
- PDF-Metadaten und Administrationsdokumentation aktualisiert.

### Aktualisierung

Das reguläre Nextcloud-App-Update verwenden und die App-Seite anschließend neu laden.
Gespeicherte Einstellungen bleiben erhalten. Neue Optionen und Serverpfade sind
in [der Administrationsanleitung](docs/admin.md) beschrieben.
