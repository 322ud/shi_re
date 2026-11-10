<?php
/** @var array $_ */
$status = $_['status'];
?>
<?php require __DIR__ . '/readme-link.php'; ?>
<div id="myx-admin" class="section">
    <h2>ShiRE</h2>
    <section aria-labelledby="shire-drafts-title">
    <h3 id="shire-drafts-title">Rechnungsentwürfe</h3>
    <p>
        <input id="myx-allow-draft-deletion" type="checkbox" <?php if ($_['allowDraftDeletion']) { print_unescaped('checked'); } ?>>
        <label for="myx-allow-draft-deletion">Button „aktiven Rechnungsentwurf löschen“ bei einem Entwurfskonflikt anzeigen</label>
    </p>
    <p>Standard: Nein. Bei aktivierter Option können Nutzer den aktiven Entwurf nach Bestätigung mit „ja“ löschen.</p>
    <button id="myx-save-draft-settings" type="button">Entwurfseinstellung speichern</button>

    </section>
    <section aria-labelledby="shire-validator-title">
    <h3 id="shire-validator-title">KoSIT-Validierung</h3>
    <p>Die Rechnungsprüfung verwendet die separate App „inv_validator“.</p>
    <p>Installierte Version: <strong><?php p($status['installed'] ?? 'nicht installiert'); ?></strong></p>
    <p>Beim Öffnen von „ShiRE“ wird das aktuelle GitHub-Release geprüft.
       Fehlt die App, wird sie beim Start durch einen Administrator automatisch installiert.
       Vorhandene Installationen werden nicht automatisch überschrieben.</p>
    <p>Solange das Repository privat ist, benötigt Nextcloud den Konfigurationseintrag
       <code>shi_re_github_token</code> in <code>config.php</code> mit lesendem Zugriff auf
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

    </section>
    <section aria-labelledby="shire-pdf-title">
    <h3 id="shire-pdf-title">PDF-Rechnung</h3>
    <p>PDF/A-3b mit eingebetteter, validierter Rechnungs-XML.</p>
    <p id="myx-pdf-status" role="status" aria-live="polite"><?php
        p($_['pdfStatus']['enabled'] ? 'PDF-Modul ist aktiv.' : 'PDF-Modul ist deaktiviert. ' . implode(' ', $_['pdfStatus']['errors']));
    ?></p>
    <p id="myx-verapdf-status"<?php if (empty($_['pdfStatus']['versions']['verapdf'])) { print_unescaped(' hidden'); } ?>>Kompatibilität mit PDF/A-3b wird mit veraPDF geprüft. Installierte Version: <strong><?php p($_['pdfStatus']['versions']['verapdf'] ?? ''); ?></strong></p>
    <div class="shire-table-scroll" role="region" aria-label="PDF-Werkzeuge" tabindex="0">
        <table class="shire-system">
            <thead><tr><th scope="col">Komponente</th><th scope="col">Version / Prüfstatus</th></tr></thead>
            <tbody>
            <?php foreach (['libreoffice' => 'LibreOffice', 'java' => 'Java', 'mustang' => 'Mustang-CLI', 'verapdf' => 'veraPDF (optional)'] as $key => $label): ?>
                <tr><th scope="row"><?php p($label); ?></th>
                    <td data-pdf-version="<?php p($key); ?>"><?php p($_['pdfStatus']['versions'][$key] ?? 'Nicht ermittelt'); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <details><summary>Programmpfade in config.php</summary>
    <p>Serverpfade in config.php: <code>shi_re_libreoffice</code> (Standard /usr/bin/libreoffice),
        <code>shi_re_java</code> (Standard /usr/bin/java), <code>shi_re_mustang_jar</code> (erforderlich).
        Optional: <code>shi_re_verapdf</code> für eine zusätzliche PDF/A-3b-Prüfung.</p>
    </details>
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
    </section>
    <section aria-labelledby="shire-admin-readme-title">
        <h3 id="shire-admin-readme-title">README</h3>
        <?php require __DIR__ . '/readme-content.php'; ?>
    </section>
</div>
