# Arbeitsregeln für ShiRE

## Nextcloud-Routen und JSON-Endpunkte

- Bei neuen oder geänderten Controller-Routen immer berücksichtigen, dass eine bestehende Nextcloud-Installation noch einen alten Routencache verwenden kann. Eine Route im Quellcode ist kein Nachweis ihrer Erreichbarkeit in der laufenden Installation.
- Vor Abschluss einer solchen Änderung den Registrierungs- und Updateweg prüfen und dokumentieren, wie bestehende Installationen die neuen Routen erhalten. Eine gegebenenfalls erforderliche Versionsänderung oder Cache-Aktualisierung nicht stillschweigend voraussetzen.
- Neue Endpunkte möglichst über den tatsächlich verwendeten Nextcloud-URL-Pfad mit den erforderlichen Authentifizierungs- und CSRF-Daten prüfen. Neben dem HTTP-Status auch Inhaltstyp und JSON-Antwort prüfen. Tests mit simulierten Serverantworten ersetzen diese Prüfung nicht.
- Ist keine laufende Testinstallation zugänglich, diese Prüfung ausdrücklich als offen benennen; die Erreichbarkeit nicht als getestet darstellen.
- Bei „keine gültige JSON-Antwort“ und HTTP 404 zuerst URL-Erzeugung (Installationsunterverzeichnis, index.php/URL-Rewriting), Routenregistrierung und Routencache untersuchen. Den Routencache nicht allein aufgrund dieser Fehlermeldung als bewiesene Ursache darstellen.
- Bei erforderlicher Kompatibilität mit bestehenden Routencaches prüfen, ob eine passende bereits registrierte Route genutzt werden kann. Berechtigungen, CSRF-Schutz und Eingabeprüfung dabei beibehalten.

## GitHub Actions

- Vor Verwendung eines Ausdrucks wie `${{ context.property }}` prüfen, ob der Kontext am jeweiligen Workflow-Schlüssel verfügbar ist. Insbesondere ist `runner` in `jobs.<job_id>.env` nicht verfügbar.
- Runnerabhängige Pfade für mehrere Schritte zur Laufzeit aus Umgebungsvariablen wie `$RUNNER_TEMP` ableiten und über `$GITHUB_ENV` bereitstellen. `${{ runner.temp }}` nur an unterstützten Workflow-Schlüsseln verwenden.
- Zusätzliche Kommandozeilenprogramme nicht als vorinstalliert voraussetzen. Wenn ausreichend, Basiswerkzeuge wie `grep` verwenden; benötigte Zusatzprogramme wie `rg` explizit installieren und mit `command -v` prüfen.
- `ubuntu-latest` als veränderliches Runner-Image behandeln. Abhängigkeiten explizit halten und bei CI-Änderungen sowohl Ausdruckskontexte als auch die Verfügbarkeit der verwendeten Befehle prüfen.
