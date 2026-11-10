<?php
/** @var array $_ */
$status = $_['status'];
?>
<div id="myx-admin" class="section">
    <h2>Rechnungsentwürfe</h2>
    <p>
        <input id="myx-allow-draft-deletion" type="checkbox" <?php if ($_['allowDraftDeletion']) { print_unescaped('checked'); } ?>>
        <label for="myx-allow-draft-deletion">Button „aktiven Rechnungsentwurf löschen“ bei einem Entwurfskonflikt anzeigen</label>
    </p>
    <p>Standard: Nein. Bei aktivierter Option können Nutzer den aktiven Entwurf nach Bestätigung mit „ja“ löschen.</p>
    <button id="myx-save-draft-settings" type="button">Entwurfseinstellung speichern</button>

    <h2>KoSIT-Validierung</h2>
    <p>Die Rechnungsprüfung verwendet die separate App „inv_validator“.</p>
    <p>Installierte Version: <strong><?php p($status['installed'] ?? 'nicht installiert'); ?></strong></p>
    <p>Beim Öffnen von „ShiRE“ wird das aktuelle GitHub-Release geprüft.
       Fehlt die App, wird sie beim Start durch einen Administrator automatisch installiert.
       Vorhandene Installationen werden nicht automatisch überschrieben.</p>
    <p>Solange das Repository privat ist, benötigt Nextcloud den Konfigurationseintrag
       <code>my_xrechnung_github_token</code> in <code>config.php</code> mit lesendem Zugriff auf
       <code>322ud/inv_validator</code>. Nach Veröffentlichung ist kein Token nötig.</p>
    <p>Das GitHub-Release muss ein Paket <code>inv_validator-VERSION.tar.gz</code> mit SHA-256-Digest enthalten.</p>
    <p>
        <input id="myx-hide-validator" type="checkbox" <?php if ($status['hide_navigation']) { print_unescaped('checked'); } ?>>
        <label for="myx-hide-validator">„XRechnung prüfen“ für normale Nutzer im App-Menü ausblenden</label>
    </p>
    <p>Administratoren sehen den Menüeintrag weiterhin. Die Validierung und direkte App-Aufrufe bleiben verfügbar.</p>
    <?php if (!$status['navigation_supported']): ?>
        <p>Die installierte Validator-Version unterstützt das Ausblenden noch nicht. Die Einstellung wirkt erst mit einem entsprechend aktualisierten Release.</p>
    <?php endif; ?>
    <button id="myx-save-validator" type="button">Einstellung speichern</button>
    <button id="myx-check-validator" type="button">Release prüfen / fehlende App installieren</button>
    <p id="myx-admin-result" role="status" aria-live="polite"></p>

    <h2>PDF-Rechnung</h2>
    <p>PDF/A-3b mit eingebetteter, validierter Rechnungs-XML.</p>
    <p id="myx-pdf-status" role="status" aria-live="polite"><?php
        p($_['pdfStatus']['enabled'] ? 'PDF-Modul ist aktiv.' : 'PDF-Modul ist deaktiviert. ' . implode(' ', $_['pdfStatus']['errors']));
    ?></p>
    <p id="myx-verapdf-status"<?php if (empty($_['pdfStatus']['versions']['verapdf'])) { print_unescaped(' hidden'); } ?>>Kompatibilität mit PDF/A-3b wird mit veraPDF geprüft. Installierte Version: <strong><?php p($_['pdfStatus']['versions']['verapdf'] ?? ''); ?></strong></p>
    <p>Serverpfade in config.php: <code>my_xrechnung_libreoffice</code> (Standard /usr/bin/libreoffice),
        <code>my_xrechnung_java</code> (Standard /usr/bin/java), <code>my_xrechnung_mustang_jar</code> (erforderlich).
        Optional: <code>my_xrechnung_verapdf</code> für eine zusätzliche PDF/A-3b-Prüfung.</p>
    <form id="myx-pdf-templates">
        <input type="hidden" name="templatesCheck" value="yes">
        <p><label for="myx-pdf-odt">ODT-Vorlage (max. 10 MiB)</label>
            <input id="myx-pdf-odt" name="odt" type="file" accept=".odt"></p>
        <p><label for="myx-pdf-xml">XML-Vorlage (max. 2 MiB)</label>
            <input id="myx-pdf-xml" name="xml" type="file" accept=".xml"></p>
        <p><label for="myx-pdf-mapping">Mapping-Tabelle (max. 256 KiB)</label>
            <input id="myx-pdf-mapping" name="mapping" type="file" accept=".tsv,.txt"></p>
        <p>Nicht ausgewählte Dateien bleiben erhalten. Der gesamte Vorlagensatz wird auf vollständige
            Zuordnung zwischen ODT, Mapping und XML geprüft. Fehler deaktivieren das PDF-Modul.
            Ein erneutes Prüfen ohne Dateiauswahl aktiviert den letzten gültigen Satz wieder.</p>
        <button id="myx-pdf-upload" type="submit">Vorlagensatz prüfen und übernehmen</button>
        <button id="myx-pdf-check" type="button">Verfügbarkeit prüfen</button>
    </form>
</div>
