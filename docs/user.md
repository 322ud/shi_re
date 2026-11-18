# ShiRE – Benutzeranleitung

## Rechnung erstellen

Beim Start einer neuen Rechnung bestimmt ShiRE Rechnungsnummer und Rechnungsdatum automatisch. Rechnungssteller und Rechnungsempfänger werden aus der Vorlage übernommen. Die Empfängerdaten kannst du bearbeiten.

Der Cursor steht zunächst im **Aktenzeichen** des Empfängers. Mit **Tab** wechselst du zur **Beschreibung** der Leistung.

Stundenangaben in der Beschreibung, etwa `Besprechung (2 h), Ausarbeitung (1,5 h)`, werden addiert und in **Stunden** übernommen. Du kannst die Stundenanzahl auch direkt bearbeiten. Erneute Texteingaben mit Stundenangaben berechnen sie wieder aus der Beschreibung.

ShiRE berechnet automatisch:

- **Nettosumme:** Stunden × Stundensatz.
- **Umsatzsteuer:** Nettosumme × Steuersatz; der Steuersatz ist editierbar.
- **Bruttosumme:** Nettosumme + Umsatzsteuer.

Mit **Rechnung erstellen** wird zunächst die XML-Rechnung erzeugt. Ist das PDF-Modul eingerichtet und aktiviert, kann daraus eine PDF-Rechnung mit eingebetteter XML entstehen. Je nach Einstellung startet die PDF-Erstellung direkt oder über **PDF-Rechnung erstellen** beziehungsweise **in PDF einbinden**.

- **PDF-Rechnung speichern** lädt die fertige PDF herunter; sie wird auch angezeigt und archiviert.
- **speichern und ShiRE schließen** bzw. **speichern und neue Rechnung starten** archiviert die XML und lädt sie herunter. Die Einträge können mit **XML speichern und …** beschriftet sein und stehen gegebenenfalls unter **XML annehmen**.
- **neue Rechnung starten** beginnt die nächste Rechnung; **ShiRE schließen** beendet die Ansicht.
- **abbrechen** verwirft den laufenden Vorgang und bietet den Wechsel zu einer neuen Rechnung oder das Schließen von ShiRE an.

## Rechnungsarchiv

Über **Archiv** wählst du eine gespeicherte Rechnung aus. Die Ansicht zeigt ausschließlich den Inhalt dieser archivierten Rechnung; die Felder sind nicht editierbar. **download** lädt die PDF herunter, falls vorhanden, sonst die XML. **zurück** führt zum vorherigen Entwurf.

Mit **als Vorlage verwenden… → für neue Rechnung** übernimmst du die editierbaren Rechnungsdaten in eine neue Rechnung mit neuer Rechnungsnummer und aktuellem Datum. Weiter geht es wie unter [Rechnung erstellen](#rechnung-erstellen).

Mit **als Vorlage verwenden… → für Storno-Rechnung** öffnest du die [Storno-Rechnung](#storno-rechnung).

## Storno-Rechnung

Die Storno-Rechnung erhält automatisch eine neue Rechnungsnummer und das aktuelle Datum. Sie übernimmt den Empfänger und die Beträge der archivierten Rechnung mit umgekehrtem Vorzeichen sowie einen Verweis auf deren Rechnungsnummer. Die Daten sind nicht editierbar; die ursprüngliche Rechnung bleibt unverändert.

**Storno-Rechnung erstellen** startet die XML- und gegebenenfalls PDF-Erstellung wie bei einer [neuen Rechnung](#rechnung-erstellen). Über **abbrechen** kannst du den Storno-Entwurf verwerfen und eine neue Rechnung beginnen oder ShiRE schließen.
