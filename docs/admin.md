# ShiRE administrieren

Alle genannten Einstellungen stehen unter **Administration → ShiRE**. Schalter speichern Änderungen sofort. Diese Anleitung beschreibt ShiRE 0.3.0.

## Systemvoraussetzungen

ShiRE benötigt Nextcloud **34** sowie PHP mit **DOM und mbstring**. Die Vorlagenprüfung benötigt zusätzlich **ZIP**, auch bei reiner XML-Nutzung. Für die übrige Serverumgebung gelten die [Systemvoraussetzungen von Nextcloud](https://docs.nextcloud.com/server/stable/admin_manual/installation/system_requirements.html).

Unter **System → Systemprüfung wiederholen** erscheinen Verfügbarkeit, Version und Pfad beziehungsweise Fehler der Komponenten:

| Anzeige | Voraussetzung und Bedeutung |
| --- | --- |
| eigene Vorlagen | Ein konsistenter Vorlagensatz; ohne eigene Vorlagen werden die mitgelieferten verwendet. |
| INValidator | Optional für XML-Validierung; muss installiert, aktiviert und zur Prüfung eingeschaltet sein. |
| LibreOffice | Writer auf dem Server für die PDF-Konvertierung. |
| MustangCLI | Lesbare CLI-JAR und Java ab Version 11; ShiRE prüft auch benötigte CLI-Funktionen und den mitgelieferten Metadaten-Helfer. Getestet: Mustang-CLI 2.26.0. |
| veraPDF | Optional für die zusätzliche PDF/A-3b-Prüfung. |

Für PDF muss PHP außerdem `proc_open` ausführen dürfen. Der Webserver-Benutzer benötigt Zugriff auf Programme und Schriften sowie ein beschreibbares Nextcloud-Tempverzeichnis. Bei Containern müssen die Werkzeuge in der Umgebung des PHP-Prozesses erreichbar sein. HTTP-/Proxy-Zeitlimits müssen Konvertierung und Prüfung zulassen; einzelne Werkzeugaufrufe sind auf 60 Sekunden begrenzt.

Die Statuszeilen unter den Tabellen zeigen, ob XML validiert und PDF erzeugt beziehungsweise geprüft wird. Ein Kreuz bei einer nicht eingerichteten optionalen Komponente verhindert nicht die XML-Erzeugung. Die Systemprüfung bestätigt die Betriebsbereitschaft; die fertige Rechnung wird gesondert geprüft.

## Installation aus dem Nextcloud-Store

1. Als Administrator in Nextcloud **Apps** öffnen und nach **ShiRE** suchen.
2. Die für die Nextcloud-Version angebotene Ausgabe herunterladen und aktivieren.
3. **Administration → ShiRE** öffnen und die Systemprüfung ausführen.
4. Bei Bedarf die unten beschriebenen Komponenten auf dem Server installieren und erneut prüfen.

Dieser Installationsweg setzt eine veröffentlichte, kompatible Store-Version voraus. Wird ShiRE nicht angeboten, ist die Store-Verfügbarkeit beziehungsweise Versionskompatibilität zu prüfen. Siehe [Nextcloud: Apps verwalten](https://docs.nextcloud.com/server/stable/admin_manual/apps_management.html).

### Programmpfade in config.php

Für reine XML-Erzeugung sind keine ShiRE-Programmpfade erforderlich. Für PDF folgende Einträge in das vorhandene `$CONFIG`-Array von Nextclouds `config/config.php` aufnehmen; alle Pfade sind absolute **Serverpfade**:

```php
// Nur bei Abweichung von diesen Standardpfaden erforderlich:
'shi_re_libreoffice' => '/usr/bin/libreoffice',
'shi_re_java' => '/usr/bin/java',

// Für PDF erforderlich; kein Standardpfad. An die installierte JAR anpassen:
'shi_re_mustang_jar' => '/opt/mustang/Mustang-CLI-2.26.0.jar',

// Optional: zusätzliche PDF/A-3b-Prüfung:
'shi_re_verapdf' => '/opt/verapdf/verapdf',
```

Die Programme müssen für den Webserver-Benutzer lesbar und ausführbar sein, die JAR lesbar. ShiRE installiert diese Programme nicht selbst. Nach Änderungen **Systemprüfung wiederholen** wählen. Die Pfade werden auch unter **System → Programmpfade in config.php** erläutert.

## Optionen für die Nutzung

Unter **Einstellungen** (Archivverwaltung unter **Komponenten**):

| Auswahl | Wirkung | Standard |
| --- | --- | --- |
| Meldungsbereich | Blendet den grauen Meldungsbereich in der Nutzeroberfläche ein oder aus. Ohne Meldungsbereich füllt der weiße Bereich die gesamte nutzbare Fläche. Die Ablaufdokumentation ist immer ausführlich, mit Uhrzeit (Europe/Berlin) und Hinweisen auf übersprungene Prüfungen. | eingeblendet |
| zeige Button „aktiven Rechnungsentwurf löschen“ bei Entwurfskonflikt | Nutzer können den blockierenden aktiven Entwurf nach Bestätigung löschen und neu beginnen. Dabei gehen die bisherigen Entwurfsdaten verloren. Diese Option darf bei mehreren (gleichzeitigen) Nutzern nicht gesetzt sein.| aus |
| Archiv leeren (Button) | Löscht nach Bestätigung durch Eingabe von `ja` alle archivierten XML-Rechnungen und PDFs appweit. Entwürfe und Vorlagen bleiben erhalten. Die nächste Rechnungsnummer richtet sich anschließend wieder nach der aktiven Vorlage; ein bestehender Entwurf behält seine Nummer. | — |

Die Einstellung „Meldungsbereich“ gilt nach dem nächsten Laden der Nutzeroberfläche. Die frühere Auswahl einer kurzen oder ausführlichen Ablaufdokumentation und das Menü „Weitere Einstellungen“ entfallen. Gespeicherte Werte von `detailed_progress` werden nicht mehr verwendet.

Die Einstellung wird über die bestehende, auf Administratoren beschränkte und CSRF-geschützte Route `/admin/validator/settings` gespeichert (`message_area`, boolesch). Es wird keine neue Route benötigt; bestehende Installationen benötigen dafür keine Routencache-Aktualisierung.

## Optionale Komponenten

### XML-Validierung mit INValidator

INValidator separat installieren und aktivieren; ShiRE übernimmt keine Erstinstallation. Installationshinweise und Releases stehen im [INValidator-Projekt](https://github.com/322ud/inv_validator). Bei manueller Bereitstellung eines Release-Pakets im App-Verzeichnis die App unter dem Webserver-Benutzer mit `php occ app:enable inv_validator` registrieren und aktivieren. Danach die ShiRE-Administration neu laden.

Unter **Einstellungen → Validierung**:

| Auswahl | Wirkung | Standard |
| --- | --- | --- |
| XML-Rechnung mit INValidator prüfen | Aktiviert die XML-Prüfung; erscheint nach Installation von INValidator. Ohne Prüfung wird weiterhin XML erzeugt. | ein |
| beim App-Start INValidator automatisch updaten | Aktualisiert die installierte Validator-App beim ShiRE-Start durch einen Administrator. Erscheint nur bei aktivierter App und eingeschalteter XML-Prüfung. | ein |
| INValidator ausblenden | Blendet den Navigationseintrag von INValidator für normale Nutzer aus; die Prüfung aus ShiRE bleibt verfügbar. | ein |

Automatische Updates benötigen Zugriff auf die GitHub-Releases und ein beschreibbares App-Verzeichnis. Bei einem privaten Repository kann in `config.php` der serverseitige Eintrag `shi_re_github_token` erforderlich sein. Der Update-Schalter betrifft die Validator-App; die verwendeten Prüfszenarien und ihre Voraussetzungen sind in deren Dokumentation beschrieben.

### PDF-Erzeugung mit LibreOffice und MustangCLI

LibreOffice Writer konvertiert die ausgefüllte ODT-Vorlage. MustangCLI bettet die Rechnungs-XML ein und prüft das Ergebnis; ShiRE ergänzt die Metadaten und vergleicht die eingebettete XML mit der angenommenen Rechnung.

Es gibt hierfür **keinen eigenen Schalter**: Sind Werkzeuge und Vorlagen bereit, steht PDF-Erzeugung zur Verfügung. Maßgeblich sind die Einträge **System → für PDF-Erzeugung** und die Statuszeile darunter. Ohne betriebsbereites PDF-Modul bleibt der XML-Download verfügbar.

Installation und Verwendung: [LibreOffice im Kommandozeilenbetrieb](https://help.libreoffice.org/latest/de/text/shared/guide/start_parameters.html), [Mustangproject mit CLI und Releases](https://github.com/ZUGFeRD/mustangproject).

#### PDF-Metadaten

Der PDF-Autor (Author und XMP `dc:creator`) wird als `initialen@domain` vollständig in Kleinbuchstaben gesetzt. Die Initialen stammen aus dem Anzeigenamen des angemeldeten Nextcloud-Nutzers (ersatzweise der Benutzerkennung), die Domain aus dem von Nextcloud ermittelten Anfragehost ohne Port und ohne führendes `cloud.` (z. B. `cloud.my_domain.de` → `my_domain.de`).

Das PDF enthält die Versionen von ShiRE, MustangCLI und LibreOffice sowie bei aktiver Prüfung die verfügbaren Versionen von INValidator, KoSIT-Validator, XRechnung-Prüfszenario und veraPDF. Nicht ermittelbare Versionen erscheinen als `unbekannt`. Die Szenarioversion bezeichnet das konkrete Prüfrelease; die Versionsangaben sind kein eigenständiger Validierungsnachweis.

### PDF-Validierung mit veraPDF

[veraPDF installieren](https://docs.verapdf.org/install/) und `shi_re_verapdf` auf das ausführbare CLI-Programm setzen. Damit wird die zusätzliche Prüfung des fertigen Dokuments auf **PDF/A-3b** aktiviert; einen Admin-Schalter gibt es nicht. **System → veraPDF** zeigt die Verfügbarkeit. Ohne Pfadeintrag entfällt diese Zusatzprüfung; ein eingetragener, aber ungültiger Pfad verhindert die PDF-Bereitschaft.

Details: [veraPDF-Prüfprofile und CLI](https://docs.verapdf.org/cli/validation/). Die PDF/A-Prüfung ersetzt keine fachliche XML-Validierung.

### Eigene Vorlagen

Unter **Einstellungen → Vorlagen → eigene Vorlagen** einschalten (Standard: aus). Unter **Vorlagen ersetzen** Dateien auswählen und **neue Vorlagen prüfen** wählen.

#### Struktur und Übernahme

Für **XRechnung** und **Storno** gibt es jeweils:

| Datei | Zweck | Größenlimit |
| --- | --- | --- |
| XML | Rechnungsstruktur, feste Ausstellerdaten und Ausgangswerte | 2 MiB |
| ODT | Layout und Platzhalter für PDF | 10 MiB |
| TSV (Mapping) | Zuordnung der ODT-Platzhalter zu XML-Feldern | 256 KiB |

Ausgangspunkt sind die mitgelieferten Dateien [XRechnung.xml](../config/XRechnung.xml), [XRechnung.odt](../config/XRechnung.odt), [XRechnung.tsv](../config/XRechnung.tsv) sowie [Storno.xml](../config/Storno.xml), [Storno.odt](../config/Storno.odt) und [Storno.tsv](../config/Storno.tsv).

ODT und TSV dienen der optionalen PDF-Ausgabe. **Die aktuelle Vorlagenprüfung verlangt dennoch einen konsistenten vollständigen Satz**, auch ohne PDF-Nutzung. Ein alleiniger XML-Upload ist möglich, wenn er zu den vorhandenen ODT-/TSV-Dateien passt. Nicht hochgeladene Dateien werden aus dem gespeicherten Satz beziehungsweise den Standardvorlagen ergänzt. Sobald eine eigene Storno-Datei vorliegt, wird auch der Storno-Satz geprüft.

Fehlerhafte Uploads bleiben gespeichert, werden aber als **inaktiv** angezeigt; ShiRE verwendet dann die Standardvorlagen. Weitere Uploads können den gespeicherten Satz korrigieren. Ausschalten von **eigene Vorlagen** erhält die Dateien; **eigene Vorlagen verwerfen** entfernt deren Auswahl. Die mitgelieferten Dateien bleiben unverändert.

Ein neuer Rechnungsentwurflädt die aktive XML-Vorlage. PDF-Erzeugung verwendet den bei ihrer Anforderung aktuellen vollständigen Vorlagensatz; eine Änderung während einer Anforderung wird anhand der Vorlagenversion erkannt.

#### Formale Voraussetzungen für die Verarbeitung

Die XML-Struktur der mitgelieferten Vorlagen beibehalten und feste Angaben wie Aussteller, Anschrift, Steuerdaten und Bankverbindung anpassen. ShiRE befüllt vorhandene Felder; fehlende oder mehrdeutige Elemente können die Erstellung abbrechen lassen.

| Bereich | Voraussetzung |
| --- | --- |
| XML-Struktur | Wohlgeformtes CII-XML mit den Namespaces der Standardvorlage; keine DTD oder externen Entitäten. Genau eine Rechnungsposition. |
| Rechnung | Dokumenttyp `380`; Rechnungsnummer im Format `JJ NNN`, etwa `26 000`. Sie dient ohne Archiv als Ausgangswert für die nächste Nummer. |
| Pflichtstruktur | Rechnungsnummer/-typ, Ausstellungs-, Leistungs- und Fälligkeitsdatum; Käufername, Anschrift und Referenz; Positionsnummer, Produktbeschreibung, Nettopreis, Basismenge, Rechnungsmenge, Positionssteuer und Positionssumme; Währung, Kopfsteuer und Summenfelder der Standardvorlage. Diese Elemente nicht entfernen. |
| Berechnung | Eine Stundenposition (`HUR`), EUR und positive Umsatzsteuer der Kategorie `S`; Stundensatz mit höchstens zwei wirksamen Nachkommastellen. Nutzer geben Stunden in Zehntelschritten an. Weitere Positionen und Auslagen sind nicht unterstützt. |
| Zahlen und Daten | XML-Dezimalzahlen mit Punkt, ohne Tausendertrennzeichen; Datumswerte `JJJJMMTT` mit `format="102"`. Länderkennungen zweistellig, etwa `DE`. |
| Storno | Typ `384`, eine Position, Beschreibung und Verweis `InvoiceReferencedDocument/IssuerAssignedID` auf die Ursprungsrechnung. Die mitgelieferte Storno-Struktur erhalten. Unterstützt wird die vollständige Stornierung archivierter Rechnungen vom Typ `380` mit einer Position und einem Steuersatz. |

Für ODT und TSV gelten zusätzlich:

- TSV: UTF-8 ohne Kopfzeile, zwei oder drei durch echte Tabulatoren getrennte Spalten: `{{Platzhalter}}`, absoluter XPath, optional ein Beispielwert. Beispielwerte ersetzen keine fehlenden XML-Inhalte.
- Platzhalternamen beginnen mit einem ASCII-Buchstaben und enthalten nur Buchstaben, Ziffern und Unterstriche. Jeder Platzhalter hat genau einen Mapping-Eintrag; jeder Eintrag kommt im ODT vor. Mehrfache Verwendung im ODT ist erlaubt.
- Jeder XPath muss genau ein XML-Blattelement finden. Verfügbare Präfixe: `rsm`, `ram`, `qdt`, `udt` entsprechend den Standardvorlagen. `{{ID}}` mit nicht leerer Rechnungsnummer ist erforderlich.
- Platzhalter dürfen innerhalb eines Absatzes formatiert sein und in Kopf-/Fußzeilen stehen, aber nicht über Absatzgrenzen reichen. Makros, extern eingebundene Ressourcen, verschlüsselte und signierte ODT-Dateien werden abgewiesen; entpackt sind höchstens 50 MiB zulässig.
- `{{UnitPrice}}`, `{{BasisTotal}}`, `{{TaxTotal}}` und `{{GrandTotal}}` müssen ohne Rundung mit zwei Nachkommastellen darstellbar sein. `{{TaxRate}}` muss ganzzahlig darstellbar sein, etwa `19.00`. `{{DocumentType}}` unterstützt `380` und `384`.

#### Darstellung und Nutzerinitialen

Für die Darstellung gelten:

- `{{Currency}}`: `EUR` → `€`; andere Währungen bleiben erhalten.
- `{{TaxRate}}`: `19.00` → `19`. Gebrochene Steuersätze werden mit Fehler abgewiesen, um keine falsche Prozentzahl darzustellen.
- `{{IBAN}}`: Vierergruppen von links, getrennt durch geschützte Leerzeichen U+00A0.
- `{{UnitPrice}}`, `{{BasisTotal}}`, `{{TaxTotal}}`, `{{GrandTotal}}`: zwei Nachkommastellen mit Komma, ohne Gleitkommarundung.
- Mengen und weitere XML-Felder mit Namen `…Amount`, `…Quantity`, `…Percent`: Dezimalpunkt wird zum Komma.
- XML-Datumsfelder im Format `102`: `YYYYMMDD` → `DD.MM.YYYY`.
- Namen, Kennungen, Adressen und Freitexte bleiben inhaltlich erhalten; XML-Sonderzeichen werden sicher eingesetzt. Zeilenumbrüche und Tabulatoren werden in ODF-Elemente umgewandelt.

Bei der XML-Erstellung wird `{{UserID}}` (Notiz mit SubjectCode `ABN`) mit den kleingeschriebenen Initialen des angemeldeten Nextcloud-Nutzers befüllt: „Frank Busse“ wird zu `fb`. Grundlage ist der Anzeigename, bei leerem Anzeigenamen der Benutzername. Leerzeichen und Bindestriche trennen Namensbestandteile. Eigene Vorlagen ohne diese Notiz bleiben verwendbar.

#### Rechnungsnummerierung

Die nächste Rechnungsnummer wird aus der höchsten Nummer der aktiven XML-Vorlage (`ExchangedDocument/ram:ID`) und der archivierten Dateinamen im Format `JJ NNN.xml` bzw. `JJ NNN.pdf` im AppData-Hauptverzeichnis berechnet. Die Vorlagennummer gilt als vorherige Nummer: Ohne Archiv ergibt beispielsweise `26 200` im Jahr 2026 die erste Rechnungsnummer `26 201`. Liegt das Jahr der höchsten Nummer vor dem aktuellen Serverjahr, beginnt die Nummerierung bei `JJ 000` des aktuellen Jahres. Auch bei vorhandenem Archiv bleibt die Vorlagennummer als Untergrenze berücksichtigt.

#### Voraussetzungen für standardkonforme Rechnungen

**XRechnung:** Die Vorlagenprüfung kontrolliert die Verarbeitbarkeit und Zuordnung der Dateien, nicht sämtliche Regeln des Standards. Auch ein erfolgreicher Upload ist deshalb kein Nachweis einer gültigen XRechnung. Maßgeblich sind die [XRechnung-Spezifikation und technischen Prüfarbeitsmittel der KoSIT](https://xeinkauf.de/xrechnung/).

Die mitgelieferte XML bezeichnet ihr Profil derzeit mit `urn:cen.eu:en16931:2017`. Der Dateiname „XRechnung“ allein bestätigt daher keine XRechnung-Konformität. Für XRechnung müssen unter anderem Profil-/Prozesskennungen, Verkäufer- und Käuferdaten, elektronische Adressen, Steuer-/Zahlungsangaben und die geforderte Käuferreferenz zum gewählten Standard und Empfänger passen. Die vollständigen Pflichtfelder und Geschäftsregeln stehen in den [Versionen und Bundles](https://xeinkauf.de/xrechnung/versionen-und-bundles/). Die fertig befüllte Rechnung mit dem passenden INValidator-Prüfszenario prüfen.

**PDF/A:** Ziel ist PDF/A-3b nach [ISO 19005-3](https://www.iso.org/standard/57229.html). Dazu müssen unter anderem Schriften eingebettet, Metadaten und Farbinformationen passend sowie Anhänge korrekt eingebunden sein. Auf dem Server verfügbare, einbettbare Schriften verwenden und nach Änderungen an Layout oder Schriften das fertige PDF mit veraPDF prüfen. Ein erfolgreich verarbeitbares ODT allein garantiert keine PDF/A-Konformität. Zusätzlich die sichtbaren Rechnungsangaben mit der eingebetteten XML abgleichen.

## Betrieb und Archiv

LibreOffice verwendet ein eigenes Profil pro Vorgang. Dateien liegen in privaten, von Nextcloud verwalteten temporären Verzeichnissen; sie werden bei Erfolg und Fehler sofort gelöscht. Bei einem harten Prozessabbruch greift Nextclouds Tempbereinigung.

Die Arbeitsdatei im AppData-Hauptverzeichnis heißt `tmp.xml`; sie wird für Erzeugung, Validierung und Arbeits-XML-Download verwendet und nach Annahme oder Abbruch zusammen mit den Entwurfsdaten entfernt.

Angenommene XML-Rechnungen und erfolgreich geprüfte PDFs liegen dauerhaft als `<Rechnungsnummer>.xml` beziehungsweise `<Rechnungsnummer>.pdf` im AppData-Hauptverzeichnis. Das Archiv ist appweit gemeinsam. Vorhandene PDFs werden nicht überschrieben; ein erneuter Speicherversuch unter demselben Namen wird abgelehnt. Bei einem PDF-Fehler bleibt die bereits angenommene XML im Archiv erhalten. Downloads erfordern eine Anmeldung; öffentliche Download-Links gibt es nicht.
