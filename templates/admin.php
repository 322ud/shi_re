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
        <div class="shire-switch">
            <input id="<?php p($id); ?>" type="checkbox" role="switch" aria-labelledby="<?php p($id); ?>-label" <?php if ($checked) { print_unescaped('checked'); } ?>>
            <span id="<?php p($id); ?>-label"><?php p($label); ?></span>
        </div>
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
    <button id="myx-check-system" type="button">Systemprüfung wiederholen</button>
    <div class="shire-table-scroll" role="region" aria-label="Ergebnisse der Systemprüfung" tabindex="0">
        <table class="shire-system">
            <thead><tr><th scope="col">Ergebnis</th><th scope="col">Komponente</th><th scope="col">Version</th><th scope="col">Pfad/Details</th><th scope="col">Aktion/Hilfe</th></tr></thead>
            <tbody>
            <?php
            $checks = ['validator' => ['ok' => !empty($status['installed']) && $status['enabled'],
                'version' => $status['installed'] ?? '', 'detail' => $status['enabled'] ? '' : 'INValidator fehlt oder ist deaktiviert.']]
                + $_['pdfStatus']['checks'];
            foreach (['validator' => 'INValidator', 'libreoffice' => 'LibreOffice', 'mustang' => 'MustangCLI', 'verapdf' => 'veraPDF', 'templates' => 'eigene Vorlagen'] as $key => $label):
                $check = $checks[$key]; ?>
                <tr data-check="<?php p($key); ?>">
                    <td><span class="shire-state shire-state--<?php p($check['ok'] ? 'available' : 'missing'); ?>" role="img" aria-label="<?php p($check['ok'] ? 'Bereit' : 'Nicht bereit'); ?>"><?php p($check['ok'] ? '✓' : '✕'); ?></span></td>
                    <th scope="row"><?php p($label); ?></th>
                    <td data-version><?php p($check['version'] ?: '—'); ?></td>
                    <td data-detail><?php p($check['detail']); ?></td>
                    <td class="shire-system-actions">
                        <?php if ($key === 'validator'): ?><button id="myx-check-validator" type="button">Release prüfen / installieren</button><?php endif; ?>
                        <a href="#shire-admin-readme-title" data-readme-help="<?php p($key === 'templates' ? 'Vorlagen und Mapping' : ($key === 'validator' ? 'Einstellungen' : 'PDF-Modul einrichten')); ?>">Info ↗</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p id="myx-pdf-status" role="status" aria-live="polite"><?php p($_['pdfStatus']['enabled'] ? 'PDF-Modul ist aktiv.' : implode(' ', $_['pdfStatus']['errors'])); ?></p>
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
