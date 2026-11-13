# ShiRE

Nextcloud-App zur Erstellung und Validierung einer EN16931-CII-Rechnung mit einer Rechnungsposition. Nach erfolgreicher KoSIT-Validierung und ausdrücklicher XML-Annahme erzeugt das PDF-Modul auf Wunsch eine PDF/A-3b-Rechnung aus einer ODT-Vorlage und bettet dieselbe XML mit Mustangproject ein.

Die Dokumentation ist auf den Rechnungsseiten über das Info-Symbol rechts oben (Mouseover „Readme“) in einem überlagernden Fenster erreichbar.

## Einstellungen

Die Schalter in der Administration speichern Änderungen sofort. Standardwerte: automatisches INValidator-Update beim App-Start **nein**, INValidator für normale Nutzer ausblenden **ja**, Button zum Löschen bei Entwurfskonflikt **nein**, eigene Vorlagen **nein**. Automatische Updates erfolgen nur beim Start durch einen Administrator. Fehlt INValidator, wird die App weiterhin beim Start durch einen Administrator installiert.

Bei „eigene Vorlagen: nein“ verwendet ShiRE die mitgelieferten Standardvorlagen. Gespeicherte eigene Vorlagen bleiben erhalten und werden beim Einschalten wieder verwendet. Nur bei aktivierter Option erscheinen Dateiauswahl und „Vorlagen prüfen“.

Unter der README kann die Administration mit „Archiv leeren“ alle archivierten XML-Rechnungen und PDFs appweit löschen. Die Eingabe `ja` bestätigt das Löschen. Entwürfe und Vorlagen bleiben erhalten. Ohne Archiv richtet sich die nächste Rechnungsnummer wieder nach der aktiven Vorlage; ein bestehender Entwurf behält seine Nummer.

## PDF-Modul einrichten

Auf dem Nextcloud-Server werden LibreOffice Writer, Java ab Version 11, die Mustang-CLI sowie PHP DOM, ZIP, mbstring und `proc_open` benötigt. Getestet wurde Mustang-CLI **2.26.0**. Die JAR muss aus einem vertrauenswürdigen Release installiert und für den Webserver lesbar sein; die App installiert keine ausführbaren Programme automatisch. Der mitgelieferte Metadaten-Helfer verwendet PDFBox 3 aus dieser JAR. Die Systemprüfung prüft auch seine Verfügbarkeit. Auf dem Server ist kein Java-Compiler erforderlich.

In Nextclouds `config.php` konfigurieren (absolute Pfade):

```php
'shi_re_libreoffice' => '/usr/bin/libreoffice',
'shi_re_java' => '/usr/bin/java',
'shi_re_mustang_jar' => '/opt/mustang/Mustang-CLI-2.26.0.jar',
// Optional: zusätzliche unabhängige PDF/A-3b-Prüfung des fertigen Dokuments.
'shi_re_verapdf' => '/opt/verapdf/verapdf',
```

Die Einträge `shi_re_libreoffice` und `shi_re_java` sind nur bei abweichenden Programmpfaden nötig. Ohne Konfiguration gelten die oben gezeigten Standardpfade. Für Mustang gibt es keinen Standardpfad. In **Administration → ShiRE → System** zeigt „Systemprüfung wiederholen“ fehlende Abhängigkeiten und Vorlagenfehler. Dieselbe Prüfung erfolgt beim App-Start und vor jeder PDF-Erzeugung. Bei deaktiviertem PDF-Modul wir nur eine XML-Rechnung erstellt und zum Download bereitgestellt.

Der Webserver-Benutzer benötigt ein beschreibbares Nextcloud-Tempverzeichnis und Zugriff auf die Programme und die verwendeten Schriften. HTTP-/Proxy-Zeitlimits müssen die Konvertierung und die Validierung zulassen. Jeder externe Aufruf hat ein Zeitlimit von 60 Sekunden (Verfügbarkeitsprüfungen 10–15 Sekunden). LibreOffice verwendet ein eigenes Profil pro Vorgang. Dateien liegen in privaten, von Nextcloud verwalteten temporären Verzeichnissen; sie werden bei Erfolg und Fehler sofort gelöscht. Bei einem harten Prozessabbruch greift Nextclouds Tempbereinigung.

## Vorlagen und Mapping

Standarddateien:

- `config/X-Rechnung.odt`
- `config/en16931.xml`
- `config/mapping.tsv`

In der Administration lassen sich einzelne oder alle drei Dateien zusammen hochladen. Nicht ausgewählte Dateien stammen aus dem bisher gültigen Satz. Die App prüft den vollständigen Satz und übernimmt ihn erst nach erfolgreicher Prüfung. Die eigenen Vorlagen liegen unveränderlich und versioniert in AppData; ein Konfigurationszeiger bestimmt den aktiven Satz. Die Dateien im App-Verzeichnis bleiben unverändert.

Die Prüfung verlangt:

1. Jeder ODT-Platzhalter besitzt genau einen Mapping-Eintrag.
2. Jeder Mapping-Platzhalter kommt im ODT vor (mehrfaches Vorkommen ist erlaubt).
3. Jeder XPath findet genau ein XML-Blattelement. Die Vorlage enthält genau eine Rechnungsposition.

Bei inkonsistenten eigenen Vorlagen verwenden XML- und PDF-Erzeugung wieder die Standardvorlagen. Die Systemprüfung markiert eigene Vorlagen mit einem roten Kreuz und „inaktiv“. Auch ein inkonsistenter Upload bleibt mit Dateinamen und Upload-Datum gespeichert, wird aber nicht für Rechnungen verwendet. Weitere Uploads ersetzen nur die ausgewählten Dateien dieses gespeicherten Satzes. Erst wenn der gesamte Satz erfolgreich geprüft wurde, wird er wieder aktiv. „eigene Vorlagen verwerfen“ entfernt die Auswahl des gespeicherten Satzes und schaltet auf Standardvorlagen zurück. Prüfen ohne Dateiauswahl reaktiviert keinen alten Satz.

TSV ohne Kopfzeile, UTF-8, zwei oder drei Spalten:

```text
{{ID}}	/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID	26 200
```

Die Trennzeichen sind echte Tabulatoren. Spalte 3 kann Beispiele enthalten, dient aber niemals als Ersatz für fehlende Rechnungsdaten. CSV-konforme Anführungszeichen innerhalb einer TSV-Zelle werden unterstützt. Die Namespace-Präfixe `rsm`, `ram`, `qdt` und `udt` sind fest den CII-Namespaces zugeordnet. `{{ID}}` ist erforderlich.

Platzhalter werden in `content.xml` und `styles.xml` ersetzt, einschließlich Kopf-/Fußzeilen und über mehrere Formatierungs-Textknoten verteilter Platzhalter innerhalb eines Absatzes. Absatzübergreifende Platzhalter werden nicht unterstützt. ODT-Makros, externe eingebundene Ressourcen, verschlüsselte und signierte Vorlagen werden zurückgewiesen. Normale Text-Hyperlinks sind erlaubt. ODT maximal 10 MiB, XML maximal 2 MiB, Mapping maximal 256 KiB; entpackter ODT-Inhalt maximal 50 MiB.

Für die Darstellung gelten:

- `{{Currency}}`: `EUR` → `€`; andere Währungen bleiben erhalten.
- `{{TaxRate}}`: `19.00` → `19`. Gebrochene Steuersätze werden mit Fehler abgewiesen, um keine falsche Prozentzahl darzustellen.
- `{{IBAN}}`: Vierergruppen von links, getrennt durch geschützte Leerzeichen U+00A0.
- `{{UnitPrice}}`, `{{BasisTotal}}`, `{{TaxTotal}}`, `{{GrandTotal}}`: zwei Nachkommastellen mit Komma, ohne Gleitkommarundung.
- Mengen und weitere XML-Felder mit Namen `…Amount`, `…Quantity`, `…Percent`: Dezimalpunkt wird zum Komma.
- XML-Datumsfelder im Format `102`: `YYYYMMDD` → `DD.MM.YYYY`.
- Namen, Kennungen, Adressen und Freitexte bleiben inhaltlich erhalten; XML-Sonderzeichen werden sicher eingesetzt. Zeilenumbrüche und Tabulatoren werden in ODF-Elemente umgewandelt.

Ein neuer Rechnungsentwurf lädt die aktive XML-Vorlage. PDF-Erzeugung verwendet den bei ihrer Anforderung aktuellen vollständigen Vorlagensatz; eine Änderung während einer Anforderung wird anhand der Vorlagenversion erkannt.

Bei der XML-Erstellung wird `{{UserID}}` (Notiz mit SubjectCode `ABN`) mit den kleingeschriebenen Initialen des angemeldeten Nextcloud-Nutzers befüllt: „Frank Busse“ wird zu `fb`. Grundlage ist der Anzeigename, bei leerem Anzeigenamen der Benutzername. Leerzeichen und Bindestriche trennen Namensbestandteile. Eigene Vorlagen ohne diese Notiz bleiben verwendbar.

Die nächste Rechnungsnummer wird aus der höchsten Nummer der aktiven XML-Vorlage (`ExchangedDocument/ram:ID`) und der archivierten Dateinamen im Format `JJ NNN.xml` bzw. `JJ NNN.pdf` im AppData-Hauptverzeichnis berechnet. Die Vorlagennummer gilt als vorherige Nummer: Ohne Archiv ergibt beispielsweise `26 200` im Jahr 2026 die erste Rechnungsnummer `26 201`. Liegt das Jahr der höchsten Nummer vor dem aktuellen Serverjahr, beginnt die Nummerierung bei `JJ 000` des aktuellen Jahres. Auch bei vorhandenem Archiv bleibt die Vorlagennummer als Untergrenze berücksichtigt.

## PDF-Erzeugung und Download

Nach der KoSIT-Prüfung erscheinen: links bei valider XML „XML annehmen“, daneben „abbrechen“, rechts „Bericht als PDF speichern“. Der Bericht-Button öffnet den Browser-Druckdialog; dort „Als PDF speichern“ auswählen.

Nur bei Annahme wird die validierte XML-Datei dauerhaft als `<Rechnungsnummer>.xml` archiviert. „speichern und …“ lädt diese Datei herunter und schließt die App oder beginnt eine neue Rechnung. „in PDF einbinden“ verlässt das Menü und erzeugt aus dieser Archivdatei die PDF-Rechnung. Ein PDF-Fehler lässt die bereits angenommene XML unverändert im Archiv. Abbrechen verwirft die Rechnung ohne Archivierung. Nach Annahme oder Abbruch werden Arbeits-XML und Entwurfsdaten entfernt; der Entwurf bleibt bis dahin gesperrt und per Heartbeat aktiv.

Nach erfolgreicher KoSIT-Prüfung speichert die App den SHA-256-Hash der XML, den Benutzer, die Fensterkennung und die verwendeten Validator-Versionen in `validation-proof.json` im AppData-Hauptverzeichnis. Eine zufällige Vorgangskennung bleibt ausschließlich im Browser-Arbeitsspeicher; auf dem Server wird nur ihr Hash abgelegt. XML-Annahme und direkter Arbeits-XML-Download verlangen diesen Nachweis und identische XML-Bytes, ohne INValidator erneut aufzurufen.

Die PDF-Route akzeptiert ausschließlich die für diesen Vorgang angenommene Archiv-XML. Sie prüft den Nachweis, den XML-Hash und die aktuelle Vorlagenversion. Genau die gelesenen XML-Bytes liefern die ODT-Felder und den PDF-Anhang. Bei „in PDF einbinden“ bleibt der Nachweis über die XML-Annahme hinaus bestehen und wird vor dem PDF-Erzeugungsversuch einmalig verbraucht, auch wenn dieser fehlschlägt. Bei Abschluss ohne PDF, Verwerfen, bestätigtem Löschen des Entwurfs und erfolgreichem Start einer neuen Rechnung wird er gelöscht. Neuladen oder Verlassen der Seite verwirft die Vorgangskennung; eine Fortsetzung allein mit der Archivdatei ist damit nicht möglich. Eine bereits laufende PDF-Erzeugung kann beim Schließen der Seite noch fertig werden.

LibreOffice exportiert mit `SelectPdfVersion=3` (PDF/A-3b). Mustang kombiniert PDF und XML im Factur-X-Format, Version 1, mit Profil EN16931 oder XRECHNUNG entsprechend der XML-Guideline. Andere Guidelines und mehrere Rechnungspositionen werden abgewiesen. Anschließend ergänzt der Java-Helfer die Metadaten. Erst danach extrahiert Mustang den XML-Anhang; sein SHA-256-Hash muss mit der validierten XML übereinstimmen. Außerdem muss Mustangs Validierung des fertigen PDFs erfolgreich sein. Falls konfiguriert, muss auch veraPDF die PDF/A-3b-Konformität bestätigen. Ohne veraPDF gibt es keinen zusätzlichen unabhängigen PDF/A-Konformitätsnachweis.

Die Standardfelder stehen synchron im PDF-Info-Dictionary und in XMP: Titel (`dc:title`) ist „Rechnung“, abgeleitet aus dem XML-TypeCode 380; Creator (`xmp:CreatorTool`) ist App-Name und Version aus `appinfo/info.xml`; Producer (`pdf:Producer`) ist `MustangCLI <JAR-Version>`. Die JAR-Version stammt aus den JAR-Metadaten, nicht aus Formatversionen im Hilfetext oder aus dem Dateinamen.

Zusätzliche XMP-Felder sind `shi_re:libreOfficeVersion`, `shi_re:INValidatorVersion`, `inv_validator:kositValidatorVersion` und `inv_validator:xrechnungSzenarioVersion`. Bei Verwendung von veraPDF kommt `shi_re:veraPDFVersion` hinzu. INValidator liefert die installierten Versionen über seine lokale Status-API ohne Update-Abfrage; die Szenarioversion ist die konkrete Releasekennung, nicht die XRechnung-Standardversion der XML. Nicht ermittelbare Versionen werden als `unbekannt` ausgewiesen. Die Versionsangaben sind kein eigenständiger Validierungsnachweis.

Die Namespace-URIs sind `https://github.com/322ud/shi_re/ns/metadata/1.0/` und `https://github.com/322ud/inv_validator/ns/metadata/1.0/`. Sie sind stabile Bezeichner und werden nicht abgerufen. Beide Namensräume erhalten eingebettete PDF/A-Erweiterungsschemas mit Text-Eigenschaften. Bestehende Factur-X-Metadaten und deren Schema bleiben erhalten. Nach der abschließenden Validierung wird die PDF nicht mehr verändert.

Erst dann liefert die authentifizierte, CSRF-geschützte POST-Route `/en16931/downloadPdfInvoice` das PDF mit `Cache-Control: no-store`. Die geprüfte PDF wird dauerhaft im AppData-Hauptverzeichnis neben der zuvor angenommenen XML als `<Rechnungsnummer>.pdf` gespeichert (mit bereinigten Dateinamenzeichen); eine bestehende Datei gleichen Namens wird niemals ersetzt. Ein erneuter Speicherversuch wird mit HTTP 409 abgelehnt; die vorhandene PDF bleibt unverändert. Prüfung und Neuanlage verwenden gemeinsam eine exklusive Nextcloud-Sperre. Der Browser öffnet die Antwort als Blob zur Ansicht. Im Eingabeformular bietet „Archiv“ neben „Rechnung erstellen“ die gespeicherten PDFs und XML-Dateien zum Download an, nach letztem Speicherzeitpunkt absteigend sortiert. Liste und Download verwenden authentifizierte, CSRF-geschützte POST-Routen (`/pdf/invoices`, `/pdf/saved`). Die Ablage ist wie die Arbeits-XML appweit gemeinsam. Es gibt keinen öffentlichen Download-Link. Beim Wechsel zur nächsten Rechnung oder Verlassen der Seite wird die Blob-URL freigegeben.

## Tests

GitHub Actions führt bei jedem Push und Pull Request sowie bei manuellem Start den Workflow
`.github/workflows/ci.yml` aus: PHP-Syntaxprüfung und Backend-Tests mit PHP 8.3,
Frontend-Build mit Node.js 22 sowie JavaScript- und Browsertests mit Playwright-Chromium.
Der Navigationstest verwendet einen festgelegten Commit des öffentlichen Repositorys
`322ud/inv_validator`. Echte LibreOffice-/Mustang-/veraPDF-Prüfungen bleiben separate
Integrationstests mit den unten angegebenen Werkzeugen.

```bash
for test in tests/*.php; do php "$test" || exit; done
for test in tests/*.js; do node "$test" || exit; done
php tests/pdf-module.php --libreoffice
php tests/pdf-module.php --libreoffice --mustang=/absolute/path/to/Mustang-CLI-2.26.0.jar
php tests/pdf-module.php --mustang=/absolute/path/to/Mustang-CLI-2.26.0.jar --verapdf=/absolute/path/to/verapdf
```

Die ersten beiden Befehle benötigen keinen Nextcloud-Server oder installierte PDF-Werkzeuge. Der NcActions-Browsertest benötigt zusätzlich `npm ci` und Chromium (Standard `/usr/bin/chromium`, alternativ `CHROMIUM_PATH`). Der Navigationstest erwartet die Validator-App im Nachbarverzeichnis `../inv_validator`. Die Integrationstests verwenden die echte ODT-Vorlage, erzeugen private temporäre Verzeichnisse und löschen alle Rechnungs- und Profildateien wieder.

Der Mustang-Integrationstest prüft mit `/usr/bin/pdfinfo` (Poppler) auch die Standard- und XMP-Metadaten sowie die Erweiterungsschemas. Mit `--verapdf` wird zusätzlich die fertige PDF/A-3b-Datei unabhängig validiert.

Der Java-Quellcode und die für Java 11 kompilierte Klasse liegen in `tools/pdf-metadata/` und werden beide ausgeliefert. Nach Änderungen am Java-Helfer die Klasse mit einem JDK neu bauen: `sh tools/build-pdf-metadata.sh /absolute/path/to/Mustang-CLI-2.26.0.jar`. Es werden dabei keine Abhängigkeiten heruntergeladen.

Während der KoSIT-Prüfung und PDF-Erzeugung wird der Nextcloud-Ladeindikator mit Statustext angezeigt. Die Entscheidungsmenüs sind während der Annahme bzw. des Verwerfens gesperrt; während der PDF-Erzeugung zeigt die Folgeseite den Ladeindikator. Historische PDFs ohne archivierte XML bleiben einzeln abrufbar.

## Frontend bauen

Die Nextcloud-Menükomponenten werden lokal gebündelt; es gibt keine CDN-Abhängigkeit. Mit Node.js 20 und npm: `npm ci && npm run build`. Die erzeugten Dateien `js/validation-actions.js` und `css/validation-actions.css` werden mit ausgeliefert. `node_modules` gehört nicht ins Deployment. Quellcode: `frontend/actions.js`.
