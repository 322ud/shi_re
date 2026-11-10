<?php
/** @var array $_ */
$status = $_['status'];
?>
<div id="myx-admin" class="section">
    <h2>ShiRE</h2>
    <section aria-labelledby="shire-settings-title">
        <h3 id="shire-settings-title">Einstellungen</h3>
        <?php foreach ([
            'myx-auto-update' => ['automatisches Update für INValidator beim Start der App', $status['auto_update']],
            'myx-hide-validator' => ['INValidator ausblenden', $status['hide_navigation']],
            'myx-allow-draft-deletion' => ['Button „aktiven Rechnungsentwurf löschen“ bei Entwurfskonflikt', $_['allowDraftDeletion']],
            'myx-custom-templates' => ['eigene Vorlagen', $_['customTemplates']],
        ] as $id => [$label, $checked]): ?>
        <label class="shire-switch" for="<?php p($id); ?>">
            <input id="<?php p($id); ?>" type="checkbox" role="switch" <?php if ($checked) { print_unescaped('checked'); } ?>>
            <span><?php p($label); ?></span><span data-switch-value><?php p($checked ? 'ja' : 'nein'); ?></span>
        </label>
        <?php endforeach; ?>
        <p id="myx-admin-result" role="status" aria-live="polite"></p>
    <form id="myx-pdf-templates"<?php if (!$_['customTemplates']) { print_unescaped(' hidden'); } ?>>
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
        <button id="myx-pdf-upload" type="submit">Vorlagen prüfen</button>
    </form>
    </section>
    <section aria-labelledby="shire-system-title">
    <h3 id="shire-system-title">System</h3>
    <p>PDF/A-3b mit eingebetteter, validierter Rechnungs-XML.</p>
    <p id="myx-pdf-status" role="status" aria-live="polite"><?php
        p($_['pdfStatus']['enabled'] ? 'PDF-Modul ist aktiv.' : 'PDF-Modul ist deaktiviert. ' . implode(' ', $_['pdfStatus']['errors']));
    ?></p>
    <p id="myx-verapdf-status"<?php if (empty($_['pdfStatus']['versions']['verapdf'])) { print_unescaped(' hidden'); } ?>>Kompatibilität mit PDF/A-3b wird mit veraPDF geprüft. Installierte Version: <strong><?php p($_['pdfStatus']['versions']['verapdf'] ?? ''); ?></strong></p>
    <div class="shire-table-scroll" role="region" aria-label="PDF-Werkzeuge" tabindex="0">
        <table class="shire-system">
            <thead><tr><th scope="col">Komponente</th><th scope="col">Version / Prüfstatus</th></tr></thead>
            <tbody>
                <tr><th scope="row">INValidator</th><td id="myx-validator-version"><?php p(($status['installed'] ?? 'Nicht installiert') . ($status['enabled'] ? '' : ' – deaktiviert')); ?></td></tr>
            <?php foreach (['libreoffice' => 'LibreOffice', 'java' => 'Java', 'mustang' => 'Mustang-CLI', 'verapdf' => 'veraPDF'] as $key => $label): ?>
                <tr><th scope="row"><?php p($label); ?></th>
                    <td data-pdf-version="<?php p($key); ?>"><?php p($_['pdfStatus']['versions'][$key] ?? 'Nicht ermittelt'); ?></td></tr>
            <?php endforeach; ?>
                <tr><th scope="row">eigene Vorlagen</th><td id="myx-template-status"><?php p($_['pdfStatus']['customTemplatesStatus']); ?></td></tr>
            </tbody>
        </table>
    </div>
    <button id="myx-pdf-check" type="button">Verfügbarkeit prüfen</button>
    <button id="myx-check-validator" type="button">INValidator-Release prüfen / fehlende App installieren</button>
    <p>INValidator ausblenden gilt für normale Nutzer. Administratoren sehen den Menüeintrag weiterhin.</p>
    <?php if (!$status['navigation_supported']): ?><p>Die installierte INValidator-Version unterstützt das Ausblenden noch nicht.</p><?php endif; ?>
    <details><summary>Programmpfade in config.php</summary>
    <p>Serverpfade in config.php: <code>shi_re_libreoffice</code> (Standard /usr/bin/libreoffice),
        <code>shi_re_java</code> (Standard /usr/bin/java), <code>shi_re_mustang_jar</code> (erforderlich).
        Optional: <code>shi_re_verapdf</code> für eine zusätzliche PDF/A-3b-Prüfung.</p>
    </details>

    </section>
    <section aria-labelledby="shire-admin-readme-title">
        <h3 id="shire-admin-readme-title">README</h3>
        <?php require __DIR__ . '/readme-content.php'; ?>
    </section>
</div>
