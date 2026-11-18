# ShiRE – Benutzeranleitung

## Rechnung erstellen

Beim Start einer neuen Rechnung bestimmt ShiRE Rechnungsnummer und Rechnungsdatum automatisch. Rechnungssteller und Rechnungsempfänger werden aus der Vorlage übernommen. Die Empfängerdaten kannst du bearbeiten.

Der Cursor steht zunächst im **Aktenzeichen** des Empfängers. Mit **Tab** wechselst du zur **Beschreibung** der Leistung.

Stundenangaben in der Beschreibung, etwa `Besprechung (2 h), Ausarbeitung (1,5 h)`, werden addiert und in **Stunden** übernommen. Du kannst die Stundenanzahl auch direkt bearbeiten. Erneute Texteingaben mit Stundenangaben berechnen sie wieder aus der Beschreibung.

ShiRE berechnet automatisch:

- **Nettosumme:** Stunden × Stundensatz.
- **Umsatzsteuer:** Nettosumme × Steuersatz; der Steuersatz ist editierbar.
- **Bruttosumme:** Nettosumme + Umsatzsteuer.

Mit **Rechnung erstellen** wird die Rechnung erzeugt. Die fertige Rechnung wird automatisch im Archiv gespeichert und heruntergeladen.

- **erstelle neue Rechnung** beginnt die nächste Rechnung.
- **ShiRE schließen** beendet ShiRE.

## Rechnungsarchiv

Über **Archiv** wählst du eine gespeicherte Rechnung aus. Die Ansicht zeigt ausschließlich den Inhalt dieser archivierten Rechnung; die Felder sind nicht editierbar. **download** lädt die gespeicherte Rechnung herunter. **zurück** führt zum aktuellen Entwurf.

Mit **als Vorlage verwenden… → für neue Rechnung** übernimmst du die editierbaren Rechnungsdaten in eine neue Rechnung mit neuer Rechnungsnummer und aktuellem Datum. Weiter geht es wie unter [Rechnung erstellen](#rechnung-erstellen).

Mit **als Vorlage verwenden… → für Storno-Rechnung** öffnest du die [Storno-Rechnung](#storno-rechnung).

## Storno-Rechnung

Die Storno-Rechnung erhält automatisch eine neue Rechnungsnummer und das aktuelle Datum. Sie übernimmt den Empfänger und die Beträge der archivierten Rechnung mit umgekehrtem Vorzeichen sowie einen Verweis auf deren Rechnungsnummer. Die Daten sind nicht editierbar; die ursprüngliche Rechnung bleibt unverändert.

**Storno-Rechnung erstellen** startet die Erstellung wie bei einer [neuen Rechnung](#rechnung-erstellen). Über **abbrechen** kannst du den Storno-Entwurf verwerfen und eine neue Rechnung beginnen oder ShiRE schließen.
