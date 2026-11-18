# ShiRE

Nextcloud-App zur Erstellung und Validierung einer EN16931-CII-Rechnung mit einer Rechnungsposition. Nach XML-Annahme erzeugt das PDF-Modul auf Wunsch eine PDF/A-3b-Rechnung aus einer ODT-Vorlage und bettet dieselbe XML mit Mustangproject ein.

Die [Benutzeranleitung](./docs/user.md) beschreibt das [Erstellen einer Rechnung](./docs/user.md#rechnung-erstellen), das [Rechnungsarchiv](./docs/user.md#rechnungsarchiv) und [Storno-Rechnungen](./docs/user.md#storno-rechnung). Das Info-Symbol rechts oben in den Rechnungsansichten öffnet den passenden Abschnitt in einem separaten Browserfenster.

Die [Administrationsanleitung](./docs/admin.md) beschreibt Installation, Systemvoraussetzungen, Einstellungen und die Nutzung eigener Vorlagen.

## Einstellungen

Während der Verarbeitung fragt der Browser den Serverfortschritt alle 500 ms ab. Der Schalter speichert über die bestehende Admin-Route `/admin/validator/settings`; die Fortschrittsabfrage verwendet `/validator/status` mit `action: progress`. Die neueren separaten Routen bleiben als kompatible Aliase erhalten. Der Server gibt die PHP-Sitzungssperre frei, damit die Abfragen während laufender Konvertierungen beantwortet werden können. Ein begrenzter Fortschrittsstand pro Benutzer wird in den Nextcloud-Benutzereinstellungen gespeichert, beim nächsten Vorgang ersetzt und nach einer Stunde nicht mehr ausgeliefert. Abfragen sind zusätzlich an Fenster und zufällige Vorgangskennung gebunden. Es entsteht kein dauerhaft wachsendes Protokoll; bei ausgeschalteter Option werden weder Fortschrittsstände geschrieben noch abgefragt.

## PDF-Erzeugung und Download

Der KoSIT-Prüfbericht wird im Ablauf nicht angezeigt. Wenn ein Bericht vorliegt, öffnet „Bericht als PDF speichern“ den Browser-Druckdialog. Ohne verfügbaren und aktivierten INValidator entfällt der XML-Prüfbericht; die PDF-Prüfung durch Mustang und gegebenenfalls veraPDF bleibt aktiv.

Angenommene XML-Dateien werden dauerhaft als `<Rechnungsnummer>.xml` archiviert. Nach Annahme oder Abbruch werden Arbeits-XML und Entwurfsdaten entfernt; der Entwurf bleibt bis dahin gesperrt und per Heartbeat aktiv.

Nach erfolgreicher KoSIT-Prüfung speichert die App den SHA-256-Hash der XML, den Benutzer, die Fensterkennung und die verwendeten Validator-Versionen in `validation-proof.json` im AppData-Hauptverzeichnis. Eine zufällige Vorgangskennung bleibt ausschließlich im Browser-Arbeitsspeicher; auf dem Server wird nur ihr Hash abgelegt. XML-Annahme und direkter Arbeits-XML-Download verlangen diesen Nachweis und identische XML-Bytes, ohne INValidator erneut aufzurufen. Bei inaktivem INValidator wird stattdessen eine ausdrücklich als ungeprüft markierte Vorgangsfreigabe mit denselben Bindungen und ohne Validator-Versionen gespeichert. Wird die XML-Prüfung vor Annahme oder PDF-Erzeugung aktiviert, reicht diese Freigabe nicht mehr aus.

Die PDF-Route akzeptiert ausschließlich die für diesen Vorgang angenommene Archiv-XML. Sie prüft den Nachweis, den XML-Hash und die aktuelle Vorlagenversion. Genau die gelesenen XML-Bytes liefern die ODT-Felder und den PDF-Anhang. Bei „in PDF einbinden“ bleibt der Nachweis über die XML-Annahme hinaus bestehen und wird vor dem PDF-Erzeugungsversuch einmalig verbraucht, auch wenn dieser fehlschlägt. Bei Abschluss ohne PDF, Verwerfen, bestätigtem Löschen des Entwurfs und erfolgreichem Start einer neuen Rechnung wird er gelöscht. Neuladen oder Verlassen der Seite verwirft die Vorgangskennung; eine Fortsetzung allein mit der Archivdatei ist damit nicht möglich. Eine bereits laufende PDF-Erzeugung kann beim Schließen der Seite noch fertig werden.

LibreOffice exportiert mit `SelectPdfVersion=3` (PDF/A-3b). Mustang kombiniert PDF und XML im Factur-X-Format, Version 1, mit Profil EN16931 oder XRECHNUNG entsprechend der XML-Guideline. Andere Guidelines und mehrere Rechnungspositionen werden abgewiesen. Anschließend ergänzt der Java-Helfer die Metadaten. Erst danach extrahiert Mustang den XML-Anhang; sein SHA-256-Hash muss mit der validierten XML übereinstimmen. Außerdem muss Mustangs Validierung des fertigen PDFs erfolgreich sein. Falls konfiguriert, muss auch veraPDF die PDF/A-3b-Konformität bestätigen. Ohne veraPDF gibt es keinen zusätzlichen unabhängigen PDF/A-Konformitätsnachweis.

Die Standardfelder stehen synchron im PDF-Info-Dictionary und in XMP: Titel (`dc:title`) ist „Rechnung“, abgeleitet aus dem XML-TypeCode 380; Creator (`xmp:CreatorTool`) ist App-Name und Version aus `appinfo/info.xml`; Producer (`pdf:Producer`) ist `MustangCLI <JAR-Version>`. Die JAR-Version stammt aus den JAR-Metadaten, nicht aus Formatversionen im Hilfetext oder aus dem Dateinamen.

Das zusätzliche XMP-Feld `shi_re:libreOfficeVersion` dokumentiert den PDF-Export. Nur bei aktiver XML-Prüfung und vorliegenden INValidator-Prüfdaten kommen `shi_re:INValidatorVersion`, `inv_validator:kositValidatorVersion` und `inv_validator:xrechnungSzenarioVersion` hinzu. Bei Verwendung von veraPDF kommt `shi_re:veraPDFVersion` hinzu. INValidator liefert die installierten Versionen über seine lokale Status-API ohne Update-Abfrage; die Szenarioversion ist die konkrete Releasekennung, nicht die XRechnung-Standardversion der XML. Nicht ermittelbare Versionen werden als `unbekannt` ausgewiesen. Die Versionsangaben sind kein eigenständiger Validierungsnachweis.

Die Namespace-URIs sind `https://github.com/322ud/shi_re/ns/metadata/1.0/` und `https://github.com/322ud/inv_validator/ns/metadata/1.0/`. Sie sind stabile Bezeichner und werden nicht abgerufen. Nur verwendete Namensräume erhalten eingebettete PDF/A-Erweiterungsschemas mit Text-Eigenschaften. Beim erneuten Schreiben werden Angaben und Schemas inaktiver Prüfungen entfernt. Bestehende Factur-X-Metadaten und deren Schema bleiben erhalten. Nach der abschließenden Validierung wird die PDF nicht mehr verändert.

Erst dann liefert die authentifizierte, CSRF-geschützte POST-Route `/en16931/downloadPdfInvoice` das PDF mit `Cache-Control: no-store`. Die Dateinamenzeichen werden für die PDF-Ablage bereinigt. Ein erneuter Speicherversuch wird mit HTTP 409 abgelehnt; die vorhandene PDF bleibt unverändert. Prüfung und Neuanlage verwenden gemeinsam eine exklusive Nextcloud-Sperre. Der Browser öffnet die Antwort als Blob zur Ansicht. Die Archivliste ist nach letztem Speicherzeitpunkt absteigend sortiert. Liste und Download verwenden authentifizierte, CSRF-geschützte POST-Routen (`/pdf/invoices`, `/pdf/saved`). Beim Wechsel zur nächsten Rechnung oder Verlassen der Seite wird die Blob-URL freigegeben.

## Storno-Rechnungen

Für Storno-PDFs werden Storno-ODT und Storno-Mapping verwendet. Die ABN-Notiz und der PDF-Platzhalter `{{UserID}}` enthalten die Initialen des aktuell angemeldeten Nutzers. Unterstützt werden archivierte Rechnungen vom Typ 380 mit einer Position und einem Steuersatz.

Die Storno-Vorschau verwendet die bereits registrierte POST-Route `/en16931/draft` mit `action=cancellation`, die Erstellung die bestehende Route `/en16931/createXmlInvoice`. Authentifizierung, CSRF-Prüfung und Entwurfsbesitz bleiben erforderlich. Der authentifizierte HTTP-Test in einer laufenden Nextcloud und die vollständige Storno-PDF-Prüfung mit LibreOffice/Mustang stehen noch aus.

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

## Release-Paket und Signierung

`tools/build-release.sh` baut ausschließlich lokale Artefakte. Es lädt nichts zu GitHub oder zum App Store hoch. Benötigt werden Bash, Git, GNU tar/coreutils, gzip, PHP mit SimpleXML und OpenSSL, OpenSSL CLI sowie Node.js 22/npm. `npm ci` installiert die festgeschriebenen Frontend-Abhängigkeiten in einem temporären Verzeichnis und benötigt Zugriff auf die npm-Registry oder einen vollständigen npm-Cache. Der Quellcheckout bleibt dabei unverändert.

Für ein signiertes Release die Pfade zum von Nextcloud ausgestellten **ShiRE-Zertifikat (CN `shi_re`)**, zum zugehörigen unverschlüsselten RSA-Schlüssel (mindestens 4096 Bit) und zu einer eingerichteten Nextcloud-Installation angeben. Schlüssel außerhalb des Repositorys aufbewahren. Die Signierbefehle müssen unter einem Benutzer laufen, der diese Nextcloud-Installation verwenden darf; eine isolierte Build-Installation ist dafür geeignet.

```bash
export APP_SIGNING_KEY_FILE="$HOME/shi-re-signing/private.key"
export APP_SIGNING_CERTIFICATE_FILE="$HOME/shi-re-signing/shi_re.crt"
export APP_REQUIRE_SIGNING=1
export NEXTCLOUD_OCC=/pfad/nextcloud/occ
bash tools/build-release.sh --signed
```

`shi_re.crt` ist der vorgesehene Ablageort für das noch zu beantragende Zertifikat. Ein erzeugtes Schlüsselpaar allein reicht für den signierten Build nicht aus. `APP_REQUIRE_SIGNING=1` verbietet ausdrücklich auch den Aufruf mit `--unsigned`; andere Werte als `0` und `1` werden abgewiesen.

Das Skript prüft Schlüssel/Zertifikat, App-ID und Gültigkeitszeitraum. Nextcloud erzeugt `appinfo/signature.json` und prüft die App anschließend mit `integrity:check-app`. Erst danach wird das Archiv gebaut, separat mit SHA-512 signiert und diese Archivsignatur überprüft. Fehler brechen den Build ab; es gibt keinen automatischen Rückfall auf ein unsigniertes Paket.

Ausgabe bei Version 0.3.0: `dist/shi_re-0.3.0-signed/` mit:

- `shi_re-0.3.0.tar.gz`: genau ein Hauptordner `shi_re/`, Laufzeitdateien einschließlich Vorlagen, frisch gebautem Frontend und Java-Helfer samt Quellcode.
- `.tar.gz.sig`: binäre SHA-512-Archivsignatur.
- `.tar.gz.sig.b64`: Base64-Archivsignatur für die spätere Store-Einreichung.
- `.tar.gz.crt`: öffentliches Zertifikat.
- `.tar.gz.sha256`: Prüfsummen der vier Dateien.

Version und App-ID kommen aus `appinfo/info.xml`. Laufzeitdateien werden anhand einer Positivliste aus den von Git erfassten Pfaden kopiert; ihr Inhalt stammt aus dem aktuellen Arbeitsverzeichnis. Neue Laufzeitdateien müssen daher zunächst zu Git hinzugefügt werden. Entwicklungsdateien, private Schlüssel und `node_modules` gehören nicht ins Archiv. Vorhandene Lizenzdateien und Änderungsprotokolle werden mitgenommen. Ein vorhandenes Ausgabeverzeichnis wird nicht überschrieben; als zweites Argument kann ein anderes Ziel angegeben werden. Das Skript verändert weder Version noch Git-Tags.

Ein ausdrücklich **unsigniertes Testpaket** lässt sich mit `APP_REQUIRE_SIGNING=0 bash tools/build-release.sh --unsigned` erstellen. Es liegt getrennt unter `dist/shi_re-VERSION-unsigned/` und ist nicht für die Store-Einreichung bestimmt.

Prüfsummen prüfen: im Ausgabeverzeichnis `sha256sum --check --strict shi_re-0.3.0.tar.gz.sha256` ausführen. Automatisierte Pakettests: `bash tests/release-package.sh`. Diese verwenden einen temporären Testschlüssel und einen simulierten `occ`-Signierablauf; sie ersetzen keine Prüfung der echten Nextcloud-Zertifikatskette. Die tatsächliche Vertrauensprüfung erfolgt beim signierten Build durch das angegebene Nextcloud-`occ`.

## Lizenz

ShiRE ist unter **GNU AGPL Version 3 oder später** (`AGPL-3.0-or-later`)
lizenziert. Der vollständige Text steht in [LICENSE](LICENSE).
Die gebündelten Fremdkomponenten behalten ihre jeweiligen Lizenzen;
Versionen, Copyright-Hinweise und Lizenztexte sind in
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) und [LICENSES/](LICENSES/) dokumentiert.
