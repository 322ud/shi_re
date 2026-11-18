<?php
/** @var array $_ */
$status = $_['status'];
$adminHelp = static function (string $section, string $label) use ($_): void {
    $base = $_['readmeUrl'];
    $url = $base . (str_contains($base, '?') ? '&' : '?') . 'document=admin#' . rawurlencode($section);
    $description = 'Hilfe zu „' . $label . '“ (öffnet ein neues Fenster)';
    ?>
    <a class="shire-admin-help" href="<?php p($url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php p($description); ?>" title="<?php p($description); ?>"><span aria-hidden="true">i</span></a>
    <?php
};
?>
<div id="myx-admin" class="section">
    <h2>ShiRE</h2>
    <section aria-labelledby="shire-settings-title">
        <h3 id="shire-settings-title">Einstellungen</h3>
        <div class="shire-settings-group" role="group" aria-labelledby="shire-validation-title">
        <h4 id="shire-validation-title">Validierung</h4>
        <p id="myx-validator-install-help"<?php if (!empty($status['installed'])) { print_unescaped(' hidden'); } ?>>INValidator bitte durch die Nextcloud-Administration installieren und aktivieren. Hinweise stehen in der README.</p>
        <?php foreach ([
            'myx-validation-enabled' => ['XML-Rechnung mit INValidator prüfen', $status['validation_enabled']],
            'myx-auto-update' => ['beim App-Start INValidator automatisch updaten', $status['auto_update']],
            'myx-hide-validator' => ['INValidator ausblenden', $status['hide_navigation']],
        ] as $id => [$label, $checked]): ?>
        <div class="shire-switch"<?php if (($id === 'myx-validation-enabled' && empty($status['installed']))
            || ($id === 'myx-auto-update' && (empty($status['installed']) || !$status['enabled'] || !$status['validation_enabled']))) { print_unescaped(' hidden'); } ?>>
            <input id="<?php p($id); ?>" type="checkbox" role="switch" aria-labelledby="<?php p($id); ?>-label" <?php if ($checked) { print_unescaped('checked'); } ?>>
            <span id="<?php p($id); ?>-label"><?php p($label); ?></span>
            <?php $adminHelp('xml-validierung-mit-invalidator', $label); ?>
        </div>
        <?php endforeach; ?>
        </div>
        <div class="shire-settings-group" role="group" aria-labelledby="shire-templates-title">
        <h4 id="shire-templates-title">Vorlagen</h4>
        <div class="shire-switch">
            <input id="myx-custom-templates" type="checkbox" role="switch" aria-labelledby="myx-custom-templates-label" <?php if ($_['customTemplates']) { print_unescaped('checked'); } ?>>
            <span id="myx-custom-templates-label">eigene Vorlagen</span>
            <?php $adminHelp('eigene-vorlagen', 'eigene Vorlagen'); ?>
        </div>
    <form id="myx-pdf-templates"<?php if (!$_['customTemplates']) { print_unescaped(' hidden'); } ?>>
        <input type="hidden" name="templatesCheck" value="yes">
        <?php $savedTemplates = $_['pdfStatus']['checks']['templates']; ?>
        <ul id="myx-template-files">
        <?php if (!empty($savedTemplates['version'])): ?>
            <?php foreach (['odt' => 'XRechnung (ODT)', 'xml' => 'XRechnung (XML)', 'mapping' => 'XRechnung (Mapping)', 'storno_odt' => 'Storno (ODT)', 'storno_xml' => 'Storno (XML)', 'storno_mapping' => 'Storno (Mapping)'] as $key => $label):
                $file = $savedTemplates['files'][$key] ?? [];
                if (!$file || ($file['source'] ?? '') === 'default') continue; ?>
            <li><?php
                $file = $savedTemplates['files'][$key] ?? [];
                p($label . ': ' . ($file['name'] ?? 'Dateiname nicht erfasst')
                    . (($file['source'] ?? '') === 'default' ? ' — mitgelieferte Standardvorlage' : ' — Upload-Datum: ' . ($file['date'] ?? 'nicht erfasst')));
            ?></li>
            <?php endforeach; ?>
        <?php endif; ?>
        </ul>
        <details id="myx-replace-templates">
        <summary>Vorlagen ersetzen</summary>
        <div class="shire-table-scroll">
        <table class="shire-template-uploads">
            <thead><tr><th scope="col">Format</th><th scope="col">XRechnung</th><th scope="col">Storno</th></tr></thead>
            <tbody>
            <?php foreach (['odt' => ['ODT', '.odt', '10 MiB'], 'xml' => ['XML', '.xml', '2 MiB'], 'mapping' => ['Mapping', '.tsv,.txt', '256 KiB']] as $key => [$label, $accept, $limit]): ?>
            <tr><th scope="row"><?php p($label . ' (max ' . $limit . ')'); ?></th>
                <?php foreach (['' => 'XRechnung', 'storno_' => 'Storno'] as $prefix => $kind): ?>
                <td><input id="myx-pdf-<?php p($prefix . $key); ?>" name="<?php p($prefix . $key); ?>" type="file" accept="<?php p($accept); ?>" aria-label="<?php p($kind . ' ' . $label . ' (max ' . $limit . ')'); ?>"></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p>Nicht ausgewählte Dateien bleiben erhalten. Die Vorlagensätze für XRechnung und Storno werden jeweils auf vollständige
            Zuordnung zwischen ODT, Mapping und XML geprüft. Bei inkonsistenten Vorlagen werden für XML- und PDF-Rechnungen die Standardvorlagen verwendet; eigene Vorlagen sind dann inaktiv.</p>
        <button id="myx-discard-templates" type="button">eigene Vorlagen verwerfen</button>
        <button id="myx-pdf-upload" type="submit">neue Vorlagen prüfen</button>
    <?php $adminHelp('struktur-und-übernahme', 'eigene Vorlagen verwerfen / neue Vorlagen prüfen'); ?>
        </details>
    </form>
        </div>
        <div class="shire-settings-group" role="group" aria-labelledby="shire-other-title">
        <h4 id="shire-other-title">andere</h4>
        <div class="shire-switch">
            <input id="myx-allow-draft-deletion" type="checkbox" role="switch" aria-labelledby="myx-allow-draft-deletion-label" <?php if ($_['allowDraftDeletion']) { print_unescaped('checked'); } ?>>
            <span id="myx-allow-draft-deletion-label">zeige Button „aktiven Rechnungsentwurf löschen“ bei Entwurfskonflikt</span>
            <?php $adminHelp('optionen-für-die-nutzung', 'zeige Button „aktiven Rechnungsentwurf löschen“ bei Entwurfskonflikt'); ?>
        </div>
        <div class="shire-switch">
            <input id="myx-detailed-progress" type="checkbox" role="switch" aria-labelledby="myx-detailed-progress-label" <?php if ($_['detailedProgress'] ?? false) { print_unescaped('checked'); } ?>>
            <span id="myx-detailed-progress-label">ausführliche Ablaufdokumentation</span>
            <?php $adminHelp('optionen-für-die-nutzung', 'ausführliche Ablaufdokumentation'); ?>
        </div>
        <button id="myx-clear-archive" type="button">Archiv leeren</button>
    <?php $adminHelp('optionen-für-die-nutzung', 'Archiv leeren'); ?>
        <p id="myx-clear-archive-result" role="status" aria-live="polite"></p>
        </div>
        <p id="myx-admin-result" role="status" aria-live="polite"></p>
    </section>
    <section aria-labelledby="shire-system-title">
    <h3 id="shire-system-title">System</h3>
    <button id="myx-check-system" type="button">Systemprüfung wiederholen</button>
    <?php $adminHelp('systemvoraussetzungen', 'Systemprüfung wiederholen'); ?>
    <?php
    $validatorReady = !empty($status['installed']) && $status['enabled'] && $status['validation_enabled'] && empty($status['check_error']);
    $checks = ['validator' => ['ok' => $validatorReady,
        'version' => $status['installed'] ?? '', 'detail' => $status['check_error'] ?? ($status['enabled'] ? '' : 'INValidator fehlt oder ist deaktiviert.')]]
        + $_['pdfStatus']['checks'];
    foreach ([
        'xml' => ['XML-Erzeugung', ['templates' => 'eigene Vorlagen', 'validator' => 'INValidator']],
        'pdf' => ['für PDF-Erzeugung', ['libreoffice' => 'LibreOffice', 'mustang' => 'MustangCLI', 'verapdf' => 'veraPDF']],
    ] as $group => [$heading, $components]): ?>
    <h4><?php p($heading); ?></h4>
    <div class="shire-table-scroll" role="region" aria-label="<?php p($heading); ?>" tabindex="0">
        <table class="shire-system">
            <thead><tr><th scope="col">Ergebnis</th><th scope="col">Komponente</th><th scope="col">Version</th><th scope="col">Pfad/Details</th><th scope="col">Aktion/Hilfe</th></tr></thead>
            <tbody>
            <?php foreach ($components as $key => $label):
                $check = $checks[$key]; ?>
                <tr data-check="<?php p($key); ?>">
                    <td><span class="shire-state shire-state--<?php p($check['ok'] ? 'available' : 'missing'); ?>" role="img" aria-label="<?php p($check['ok'] ? 'Bereit' : 'Nicht bereit'); ?>"><?php p($check['ok'] ? '✓' : '✕'); ?></span></td>
                    <th scope="row"><?php p($label); ?></th>
                    <td data-version><?php p($key === 'templates' ? '—' : ($check['version'] ?: '—')); ?></td>
                    <td data-detail><?php p($check['detail']); ?></td>
                    <td class="shire-system-actions">
                        <a href="#shire-admin-readme-title" data-readme-help="<?php p($key === 'templates' ? 'Vorlagen und Mapping' : ($key === 'validator' ? 'Einstellungen' : 'PDF-Modul einrichten')); ?>">Info ↗</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($group === 'xml'): ?>
    <p id="myx-xml-status" data-ready="<?php p($validatorReady ? 'true' : 'false'); ?>" role="status" aria-live="polite"><?php p($validatorReady ? 'XML-Rechnung wird erzeugt und validiert.' : 'XML-Rechnung wird erzeugt, aber nicht validiert.'); ?></p>
    <?php else: ?>
    <p id="myx-pdf-status" data-status="<?php p(json_encode($_['pdfStatus'])); ?>" role="status" aria-live="polite"><?php
        p($_['pdfStatus']['enabled']
            ? 'PDF-Rechnung wird erzeugt und mit MustangCLI geprüft. ' . (!empty($checks['verapdf']['ok']) ? 'Zusätzlich wird PDF/A-3b mit veraPDF geprüft.' : 'PDF/A-3b wird nicht mit veraPDF geprüft.')
            : 'PDF-Rechnung wird nicht erzeugt und nicht geprüft. ' . implode(' ', $_['pdfStatus']['errors']));
    ?></p>
    <?php endif; ?>
    <?php endforeach; ?>
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
