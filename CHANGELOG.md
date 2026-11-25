# Änderungsprotokoll

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
