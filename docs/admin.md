# ShiRE administrieren

Alle genannten Einstellungen stehen unter **Administration → ShiRE**. Schalter speichern Änderungen sofort. Diese Anleitung beschreibt den aktuellen Quellstand mit App-Version **0.4.1**. Vorhandene gespeicherte Einstellungen bleiben erhalten; die genannten Standardwerte gelten ohne gespeicherte Auswahl.

Die Seite gliedert sich in **Einstellungen** mit den Gruppen **Rechnungserzeugung** und **Nutzeroberfläche**, anschließend **Komponenten** und **README**. Die Informationssymbole öffnen den passenden Abschnitt dieser Anleitung in einem eigenen Fenster.

## Systemvoraussetzungen

ShiRE benötigt Nextcloud **34** sowie PHP mit **DOM und mbstring**. Für PDF-Erzeugung und die Prüfung von ODT-Vorlagen wird zusätzlich **ZIP** benötigt. Bei inaktiver PDF-Erzeugung werden eigene XML-Vorlagen ohne ODT-/TSV-Prüfung geprüft. Für die übrige Serverumgebung gelten die [Systemvoraussetzungen von Nextcloud](https://docs.nextcloud.com/server/stable/admin_manual/installation/system_requirements.html).

Unter **Komponenten** stehen die installierten Versionen beziehungsweise „nicht installiert“. **Systemprüfung wiederholen** aktualisiert die Komponentenanzeigen und die davon abhängigen Einstellungszeilen. Programmpfade werden in `config.php` hinterlegt, siehe unten.

| Anzeige | Voraussetzung und Bedeutung |
| --- | --- |
| eigene Vorlagen | Ein konsistenter Vorlagensatz; ohne eigene Vorlagen werden die mitgelieferten verwendet. |
| INValidator | Optional für XML-Validierung; muss installiert, aktiviert und zur Prüfung eingeschaltet sein. |
| LibreOffice | Writer auf dem Server für die PDF-Konvertierung. |
| MustangCLI | Optional für XML-Einbettung, zusätzliche Metadaten und Mustang-Prüfung. Lesbare CLI-JAR und Java ab Version 11; ShiRE prüft auch benötigte CLI-Funktionen und den mitgelieferten Metadaten-Helfer. Getestet: Mustang-CLI 2.26.0. |
| veraPDF | Optional für die zusätzliche PDF/A-3b-Prüfung. |

Für PDF muss PHP außerdem `proc_open` ausführen dürfen. Der Webserver-Benutzer benötigt Zugriff auf Programme und Schriften sowie ein beschreibbares Nextcloud-Tempverzeichnis. Bei Containern müssen die Werkzeuge in der Umgebung des PHP-Prozesses erreichbar sein. HTTP-/Proxy-Zeitlimits müssen Konvertierung und Prüfung zulassen; einzelne Werkzeugaufrufe sind auf 60 Sekunden begrenzt.

Die Einstellungszeilen zeigen „aktiv“/„inaktiv“ beziehungsweise „eingeblendet“/„ausgeblendet“. Bei eigenen Vorlagen bezeichnet „installiert“ den gespeicherten Satz; die Schalterstellung zeigt dessen Auswahl an. Es gibt keine Ergebnis-Kreuze oder dauerhaften zusammenfassenden XML-/PDF-Statuszeilen mehr.

Fehlende optionale Werkzeuge verhindern weder die XML-Erzeugung noch den reinen PDF-Export mit LibreOffice. Die Komponentenprüfung untersucht die Erreichbarkeit und benötigte Funktionen der Werkzeuge. Sie ersetzt keine Prüfung einer konkreten Rechnung.

## Installation aus dem Nextcloud-Store

1. Als Administrator in Nextcloud **Apps** öffnen und nach **ShiRE** suchen.
2. Die für die Nextcloud-Version angebotene Ausgabe herunterladen und aktivieren.
3. **Administration → ShiRE** öffnen und die Systemprüfung ausführen.
4. Bei Bedarf die unten beschriebenen Komponenten auf dem Server installieren und erneut prüfen.

Dieser Installationsweg setzt eine veröffentlichte, kompatible Store-Version voraus. Wird ShiRE nicht angeboten, ist die Store-Verfügbarkeit beziehungsweise Versionskompatibilität zu prüfen. Siehe [Nextcloud: Apps verwalten](https://docs.nextcloud.com/server/stable/admin_manual/apps_management.html).

### Programmpfade in config.php

Für reine XML-Erzeugung sind keine ShiRE-Programmpfade erforderlich. Je nach gewünschter PDF-Funktion folgende Einträge in das vorhandene `$CONFIG`-Array von Nextclouds `config/config.php` aufnehmen; alle Pfade sind absolute **Serverpfade**:

```php
// PDF-Export: nur bei Abweichung vom Standardpfad erforderlich:
'shi_re_libreoffice' => '/usr/bin/libreoffice',

// Optional: Java und MustangCLI für XML-Einbindung in die PDF:
// Java-Standardpfad; MustangCLI hat keinen Standardpfad.
'shi_re_java' => '/usr/bin/java',
'shi_re_mustang_jar' => '/opt/mustang/Mustang-CLI-2.26.0.jar',

// Optional: zusätzliche PDF/A-3b-Prüfung:
'shi_re_verapdf' => '/opt/verapdf/verapdf',
```

Die Programme müssen für den Webserver-Benutzer lesbar und ausführbar sein, die JAR lesbar. ShiRE installiert diese Programme nicht selbst. Nach Änderungen **Systemprüfung wiederholen** wählen. Java und MustangCLI werden nur für die XML-Einbindung samt Mustang-Prüfung und Metadaten-Ergänzung benötigt. veraPDF dient ausschließlich der zusätzlichen PDF/A-Prüfung.

## Einstellungen

### Eigene Vorlagen

Eigene Vorlagen werden unter **Einstellungen → Rechnungserzeugung → eigene Vorlagen** ausgewählt (Standard im aktuellen Code: aus). Ohne gespeicherten Satz führt **installieren** zum Upload-Bereich unter **Komponenten**. Bei gespeichertem Satz öffnet **Komponenten → eigene Vorlagen → ersetzen** die Dateiauswahl. Dort stehen auch die gespeicherten Dateinamen und Upload-Daten. Dateien auswählen und **neue Vorlagen prüfen** wählen.

Nach einem erfolgreichen Upload steht bei **eigene Vorlagen** „installiert“; eine zuvor aktive Vorlagenoption bleibt aktiv (Schalter blau und rechts, Aktion **deaktivieren**). Der Upload schaltet sie nicht aus.

**Einschränkung bei zuvor ausgeschalteter Vorlagenoption:** Der aktuelle Servercode verlangt bereits vor dem Upload `custom_templates = yes`. Andernfalls antwortet er mit „Eigene Vorlagen sind deaktiviert“. Der Button **installieren** öffnet das Formular, setzt diese Option aber nicht selbst. Bei vorhandenem Satz lässt sie sich über den Schalter aktivieren. Ohne gespeicherten Satz und bei ausgeschalteter Option fehlt dieser Schritt in der aktuellen Oberfläche. Diese Einschränkung betrifft den Ausgangszustand vor dem Upload, nicht die Aktivierung nach einer erfolgreichen Installation.

#### Struktur und Übernahme

Für **XRechnung** und **Storno** gibt es jeweils:

| Datei | Zweck | Größenlimit |
| --- | --- | --- |
| XML | Rechnungsstruktur, feste Ausstellerdaten und Ausgangswerte | 2 MiB |
| ODT | Layout und Platzhalter für PDF | 10 MiB |
| TSV (Mapping) | Zuordnung der ODT-Platzhalter zu XML-Feldern | 256 KiB |

Ausgangspunkt sind die mitgelieferten Dateien [XRechnung.xml](../config/XRechnung.xml), [XRechnung.odt](../config/XRechnung.odt), [XRechnung.tsv](../config/XRechnung.tsv) sowie [Storno.xml](../config/Storno.xml), [Storno.odt](../config/Storno.odt) und [Storno.tsv](../config/Storno.tsv).

Die Vorlagenverwaltung richtet sich nach **PDF-Erzeugung**:

| PDF-Erzeugung | Dateiauswahl und Anzeige | Vorlagenprüfung |
| --- | --- | --- |
| inaktiv, auch bei fehlendem LibreOffice | Nur eigene XML-Dateien für XRechnung und Storno. ODT- und TSV-Auswahl sowie deren gespeicherte Dateinamen sind ausgeblendet. | Die Rechnungs-XML wird gegen die Felder und Berechnungen der Eingabemaske geprüft; eine eigene Storno-XML gegen die Anforderungen der Storno-Erzeugung. ODT und Mapping werden nicht auf Konsistenz geprüft. |
| aktiv | XML, ODT und TSV für XRechnung und Storno. | Vollständige Zuordnung zwischen XML, Mapping und ODT. Ein eigener Storno-Satz wird ebenfalls geprüft. |

Beim Einschalten von PDF wird ein vorhandener eigener Satz erneut vollständig geprüft. Passt er nicht zu ODT oder Mapping, bleiben seine Dateien gespeichert; ShiRE verwendet die Standardvorlagen und zeigt einen Hinweis mit dem Prüffehler. Beim Ausschalten von PDF wird die XML erneut separat geprüft und kann dadurch wieder verwendbar werden. Eine ausdrücklich ausgeschaltete Auswahl **eigene Vorlagen** bleibt dabei ausgeschaltet.

Gespeicherte ODT- und TSV-Dateien werden durch das Ausschalten von PDF weder gelöscht noch verändert. Bereits im Browser ausgewählte, aber noch nicht hochgeladene ODT-/TSV-Dateien werden beim Ausschalten aus der Auswahl entfernt; bei Bedarf nach dem Einschalten erneut auswählen. Im XML-Betrieb verarbeitet der Upload nur XML-Dateien. Nicht hochgeladene Dateien bleiben im gespeicherten Satz erhalten beziehungsweise werden bei dessen Anlage aus den Standardvorlagen ergänzt. Die Prüfung verändert nicht den Inhalt der hochgeladenen XML und ersetzt keine fachliche XML-Validierung mit INValidator.

Ein übernommener Vorlagensatz, der die Konsistenzprüfung nicht besteht, bleibt zur Korrektur gespeichert; ShiRE verwendet dann die Standardvorlagen. Die Upload-Antwort beziehungsweise der Hinweis unter den Komponenten meldet den Fehler. Die Anzeige „installiert“ und der Auswahl-Schalter allein belegen keine erfolgreiche Vorlagenprüfung. Weitere Uploads können den gespeicherten Satz korrigieren. Ausschalten von **eigene Vorlagen** erhält die Dateien; **eigene Vorlagen verwerfen** entfernt deren Auswahl. Die mitgelieferten Dateien bleiben unverändert.

Ein neuer Rechnungsentwurf lädt die aktive XML-Vorlage. PDF-Erzeugung verwendet den bei ihrer Anforderung aktuellen vollständigen Vorlagensatz; eine Änderung während einer Anforderung wird anhand der Vorlagenversion erkannt.

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

**PDF/A:** Ziel ist PDF/A-3b nach [ISO 19005-3](https://www.iso.org/standard/57229.html). Dazu müssen unter anderem Schriften eingebettet, Metadaten und Farbinformationen passend sowie Anhänge korrekt eingebunden sein. Auf dem Server verfügbare, einbettbare Schriften verwenden und nach Änderungen an Layout oder Schriften das fertige PDF mit veraPDF prüfen. Ein erfolgreich verarbeitbares ODT allein garantiert keine PDF/A-Konformität. Bei aktivierter XML-Einbindung zusätzlich die sichtbaren Rechnungsangaben mit der eingebetteten XML abgleichen.

### XML-Validierung mit INValidator

INValidator separat installieren und aktivieren; ShiRE übernimmt keine Erstinstallation. Installationshinweise und Releases stehen im [INValidator-Projekt](https://github.com/322ud/inv_validator). Bei manueller Bereitstellung eines Release-Pakets im App-Verzeichnis die App unter dem Webserver-Benutzer mit `php occ app:enable inv_validator` registrieren und aktivieren. Danach die ShiRE-Administration neu laden.

Wenn INValidator installiert ist, zeigt **Komponenten → INValidator** die Version und den Schalter **automatisch aktualisieren** beziehungsweise **nicht automatisch aktualisieren**. Standard ist **aus**. Der Schalter ist auch bei ausgeschalteter XML-Validierung sichtbar; das automatische Update beim ShiRE-Start setzt zusätzlich eine aktive XML-Prüfung und einen angemeldeten Administrator voraus.

Automatische Updates benötigen Zugriff auf die GitHub-Releases und ein beschreibbares App-Verzeichnis. Bei einem privaten Repository kann in `config.php` der serverseitige Eintrag `shi_re_github_token` erforderlich sein. Der Update-Schalter betrifft die Validator-App; die verwendeten Prüfszenarien und ihre Voraussetzungen sind in deren Dokumentation beschrieben.

Wenn INValidator installiert ist, stehen unter **Einstellungen** zwei weitere Optionen zur Verfügung:

**→ Rechnungserzeugung → XML-Validierung** schaltet die XML-Prüfung ein oder aus (Standard: aktiv). Ohne Prüfung wird weiterhin XML erzeugt. Der Schalter allein installiert oder aktiviert die Nextcloud-App nicht.

**→ Nutzeroberfläche → INValidator** steuert den Navigationseintrag (Standard: ausgeblendet). Die Prüfung aus ShiRE bleibt beim Ausblenden verfügbar. Die installierte INValidator-Version muss das Ausblenden unterstützen.

### PDF-Erzeugung mit LibreOffice

Wenn LibreOffice Writer installiert ist, ist standardmäßig unter **Einstellungen → Rechnungserzeugung** die Option **→ PDF-Erzeugung** aktiv, lässt sich aber mittels des Schalters in dieser Zeile deaktivieren. Der gespeicherte Status bleibt nach einem Neustart erhalten. Erforderlich bleiben konsistente Vorlagen, PHP ZIP und DOM, `proc_open` und ein beschreibbares temporäres Arbeitsverzeichnis.

LibreOffice Writer füllt die ODT-Vorlage mit den Rechnungsdaten der XML-Rechnung und erzeugt eine PDF-Rechnung. Bei deaktivierter oder nicht betriebsbereiter PDF-Erzeugung bleibt die XML-Erzeugung einschließlich einer gegebenenfalls aktivierten XML-Prüfung verfügbar.

Installation und Verwendung: [LibreOffice im Kommandozeilenbetrieb](https://help.libreoffice.org/latest/de/text/shared/guide/start_parameters.html)

### XML-Einbindung mit MustangCLI

Wenn MustangCLI und Java installiert sind, ist standardmäßig unter **→ Rechnungserzeugung** die Option **→ XML-Einbindung** aktiv, lässt sich aber mittels des Schalters in dieser Zeile deaktivieren. Der gespeicherte Status bleibt nach einem Neustart erhalten.

MustangCLI bettet die Rechnungs-XML in die PDF-Rechnung ein und prüft das Ergebnis; ShiRE ergänzt die Metadaten und vergleicht noch einmal die eingebettete XML mit der gespeicherten XML-Rechnung. Schlägt die XML-Einbettung fehl, wird der Vorgang mit einem Fehler beendet.

Installation und Verwendung: [Mustangproject mit CLI und Releases](https://github.com/ZUGFeRD/mustangproject).

#### PDF-Metadaten

MustangCLI setzt den PDF-Autor (Author und XMP `dc:creator`) als `initialen@domain` vollständig in Kleinbuchstaben. Die Initialen stammen aus dem Anzeigenamen des angemeldeten Nextcloud-Nutzers (ersatzweise der Benutzerkennung), die Domain aus den letzten beiden Bestandteilen des von Nextcloud ermittelten Anfragehosts: Second-Level-Domain plus Top-Level-Domain, ohne Port und vorgelagerte Subdomains (z. B. `nextcloud.intern.my_domain.de` → `my_domain.de`). Es erfolgt keine Ermittlung der registrierbaren Domain anhand einer Public-Suffix-Liste: `cloud.example.co.uk` ergibt daher `co.uk`. IP-Adressen und einteilige Hostnamen bleiben unverändert.

Das PDF enthält die Versionen von ShiRE, MustangCLI und LibreOffice sowie bei aktiver Prüfung die verfügbaren Versionen von INValidator, KoSIT-Validator, XRechnung-Prüfszenario und veraPDF. Nicht ermittelbare Versionen erscheinen als `unbekannt`. Die Szenarioversion bezeichnet das konkrete Prüfrelease; die Versionsangaben sind kein eigenständiger Validierungsnachweis.

### PDF-Prüfung mit veraPDF

**Komponenten → veraPDF** zeigt die Verfügbarkeit. Wenn [veraPDF installiert](https://docs.verapdf.org/install/) ist und `shi_re_verapdf` (in `config.php`) auf das ausführbare CLI-Programm zeigt, ist standardmäßig unter **Einstellungen → Rechnungserzeugung** die Option **→ PDF-Prüfung** aktiv, lässt sich aber mittels des Schalters in dieser Zeile deaktivieren. Der gespeicherte Status bleibt nach einem Neustart erhalten. Schlägt die Prüfung fehl, wird der Vorgang mit einem Fehler beendet.

Details: [veraPDF-Prüfprofile und CLI](https://docs.verapdf.org/cli/validation/).


### Nutzeroberfläche

Unter **Einstellungen → Nutzeroberfläche**:

| Einstellung | Wirkung und Sichtbarkeit | Standard |
| --- | --- | --- |
| INValidator | Blendet den Navigationseintrag der Validator-App ein oder aus. Die Zeile erscheint nur bei installiertem, aktiviertem INValidator und eingeschalteter XML-Validierung. | ausgeblendet |
| Meldungsbereich | Blendet den grauen Meldungsbereich ein oder aus. Ohne ihn füllt der weiße Bereich die gesamte nutzbare Fläche. | eingeblendet |
| Button „Entwurf löschen“ | Zeigt bei einem Entwurfskonflikt die Möglichkeit, den blockierenden Entwurf nach Bestätigung zu löschen. Dabei gehen dessen Daten verloren. Bei mehreren gleichzeitig arbeitenden Nutzern ausgeschaltet lassen. | ausgeblendet |

## Komponenten

Unter **Komponenten** wird der mit einer automatischen Systemprüfung ermittelte Status der für die Rechnungserzeugung genutzten Komponenten dargestellt.

LibreOffice verwendet ein eigenes Profil pro Vorgang. Dateien liegen in privaten, von Nextcloud verwalteten temporären Verzeichnissen; sie werden bei Erfolg und Fehler sofort gelöscht. Bei einem harten Prozessabbruch greift Nextclouds Tempbereinigung.

Die Arbeitsdatei im AppData-Hauptverzeichnis heißt `tmp.xml`; sie wird für Erzeugung, Validierung und Arbeits-XML-Download verwendet und nach Annahme oder Abbruch zusammen mit den Entwurfsdaten entfernt.

Angenommene XML-Rechnungen und erfolgreich erzeugte PDFs liegen dauerhaft als `<Rechnungsnummer>.xml` beziehungsweise `<Rechnungsnummer>.pdf` im AppData-Hauptverzeichnis. Das Archiv ist appweit gemeinsam. Vorhandene PDFs werden nicht überschrieben; ein erneuter Speicherversuch unter demselben Namen wird abgelehnt. Bei einem PDF-Fehler bleibt die bereits angenommene XML im Archiv erhalten. Downloads erfordern eine Anmeldung; öffentliche Download-Links gibt es nicht.

Bei nicht leerem Archiv oderzeigt die Zeile **Komponenten → Rechnungsarchiv** `bis JJ NNN` für die höchste archivierte Rechnungsnummer, oder „nicht ermittelbar“ für den Archivstatus. **Archiv leeren** löscht nach Bestätigung durch Eingabe von `ja` alle archivierten XML-Rechnungen und PDFs appweit. Entwürfe und Vorlagen bleiben erhalten. Die nächste Rechnungsnummer richtet sich dann wieder nach der aktiven Vorlage; ein bestehender Entwurf behält seine Nummer.

## Einstellungen speichern und bestehende Installationen

Die Oberfläche speichert boolesche Einstellungen über vorhandene, auf Administratoren beschränkte und CSRF-geschützte Endpunkte:

| Einstellungen | Verwendeter Endpunkt |
| --- | --- |
| PDF-Erzeugung (`pdf_enabled`), XML-Einbindung (`xml_embedding`), PDF-Prüfung (`pdf_validation`), eigene Vorlagen (`custom_templates`) | `/admin/pdf/settings` |
| XML-Validierung, INValidator-Navigation, automatische Validator-Updates, Meldungsbereich (`message_area`) | `/admin/validator/settings` |
| Button „Entwurf löschen“ | `/admin/draft/settings` |

Die Pfade sind relativ zur ShiRE-App-URL; die Oberfläche erzeugt sie über Nextcloud und berücksichtigt dabei Installationsunterverzeichnis und URL-Rewriting. Für die neuen PDF-Schalter werden keine neuen Routen eingeführt. Bestehende Installationen benötigen dafür keine zusätzliche Routenregistrierung oder gezielte Routencache-Aktualisierung; nach Bereitstellung der aktualisierten App-Dateien die Admin-Seite neu laden.

Bei HTTP 404 oder „keine gültige JSON-Antwort“ zuerst die tatsächlich angeforderte URL, die Routenregistrierung und den Routencache untersuchen. Der Routencache ist durch diese Meldung allein nicht als Ursache bewiesen. Ein erfolgreicher lokaler Test ersetzt keine Prüfung des Endpunkts in der laufenden Nextcloud-Installation mit Anmeldung und CSRF-Token.
