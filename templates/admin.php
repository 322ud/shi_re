<?php
/** @var array $_ */
$status = $_['status'];
$adminHelp = static function (string $section, string $label) use ($_): void {
    $base = $_['readmeUrl'];
    $url = $base . (str_contains($base, '?') ? '&' : '?') . 'document=admin#h-' . rawurlencode($section);
    $description = 'Hilfe zu „' . $label . '“ (öffnet ein neues Fenster)';
    ?>
    <a class="shire-admin-help" data-shire-help href="<?php p($url); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php p($description); ?>" title="<?php p($description); ?>"><span aria-hidden="true">i</span></a>
    <?php
};
?>
<?php
$checks = $_['pdfStatus']['checks'];
$pdfActive = !empty($checks['libreoffice']['ok']) && ($_['pdfStatus']['pdf_enabled'] ?? true);
$embeddingAvailable = $pdfActive && !empty($checks['mustang']['ok']) && !empty($checks['java']['ok']);
$pdfValidationAvailable = $pdfActive && !empty($checks['verapdf']['ok']);
$templatesInstalled = !empty($checks['templates']['version']);
$validationActive = !empty($status['installed']) && !empty($status['enabled']) && !empty($status['validation_enabled']);
$helpSections = [
    'myx-custom-templates' => 'eigene-vorlagen',
    'myx-validation-enabled' => 'xml-validierung-mit-invalidator',
    'myx-pdf-enabled' => 'pdf-erzeugung-mit-libreoffice-und-mustangcli',
    'myx-xml-embedding' => 'pdf-erzeugung-mit-libreoffice-und-mustangcli',
    'myx-pdf-validation' => 'pdf-validierung-mit-verapdf',
    'myx-hide-validator' => 'xml-validierung-mit-invalidator',
    'myx-message-area' => 'optionen-fur-die-nutzung',
    'myx-allow-draft-deletion' => 'optionen-fur-die-nutzung',
];
$rows = [
    ['myx-custom-templates', 'eigene Vorlagen', $templatesInstalled, $_['customTemplates'], false, false],
    ['myx-validation-enabled', 'XML-Validierung', !empty($status['installed']), $status['validation_enabled'], false, false],
    ['myx-pdf-enabled', 'PDF-Erzeugung', !empty($checks['libreoffice']['ok']), $_['pdfStatus']['pdf_enabled'] ?? !empty($checks['libreoffice']['ok']), false, false],
    ['myx-xml-embedding', 'XML-Einbindung', $embeddingAvailable, $embeddingAvailable && ($_['pdfStatus']['xmlEmbedding'] ?? true), false, false],
    ['myx-pdf-validation', 'PDF-Prüfung', $pdfValidationAvailable, $pdfValidationAvailable && ($_['pdfStatus']['pdfaVerification'] ?? true), false, false],
    ['myx-hide-validator', 'INValidator', true, !$status['hide_navigation'], true, false],
    ['myx-message-area', 'Meldungsbereich', true, $_['messageArea'] ?? true, true, false],
    ['myx-allow-draft-deletion', 'Button „Entwurf löschen“', true, $_['allowDraftDeletion'], true, false],
];
?>
<div id="myx-admin" class="section" data-validator-enabled="<?php p(!empty($status['enabled']) ? 'true' : 'false'); ?>">
    <h2>ShiRE</h2>
    <section aria-labelledby="shire-settings-title">
        <h3 id="shire-settings-title">Einstellungen</h3>
        <?php foreach ([
            ['shire-generation-title', 'Rechnungserzeugung', array_slice($rows, 0, 5)],
            ['shire-interface-title', 'Nutzeroberfläche', array_slice($rows, 5)],
        ] as [$groupId, $groupTitle, $groupRows]): ?>
        <h4 id="<?php p($groupId); ?>"><?php p($groupTitle); ?></h4>
        <div class="shire-table-scroll">
        <table class="shire-system" aria-labelledby="<?php p($groupId); ?>"><tbody>
        <?php foreach ($groupRows as [$id, $label, $installed, $checked, $visibility, $pending]):
            $visible = $checked;
            $state = !$installed ? 'nicht installiert' : ($id === 'myx-custom-templates' ? 'installiert' : ($visibility ? ($visible ? 'eingeblendet' : 'ausgeblendet') : ($checked ? 'aktiv' : 'inaktiv')));
            $action = $visibility ? ($visible ? 'ausblenden' : 'einblenden') : ($checked ? 'deaktivieren' : 'aktivieren'); ?>
            <tr data-setting="<?php p($id); ?>"<?php if (($id === 'myx-hide-validator' && !$validationActive) || (in_array($id, ['myx-pdf-enabled', 'myx-xml-embedding', 'myx-pdf-validation'], true) && !$installed)) { print_unescaped(' hidden'); } ?>>
                <th scope="row" id="<?php p($id); ?>-name"><?php p($label); ?></th>
                <td data-setting-state><?php p($state); ?></td>
                <td>
                    <?php if ($id === 'myx-custom-templates'): ?><a id="myx-install-templates" class="button" href="#shire-components-title"<?php if ($installed) { print_unescaped(' hidden'); } ?>>installieren</a><?php endif; ?>
                    <label class="shire-switch"<?php if (!$installed) { print_unescaped(' hidden'); } ?>>
                        <input id="<?php p($id); ?>" type="checkbox" role="switch" aria-labelledby="<?php p($id); ?>-name <?php p($id); ?>-label" <?php if ($checked) { print_unescaped('checked '); } if ($pending) { print_unescaped('disabled aria-describedby="shire-pending-settings"'); } ?>>
                        <span id="<?php p($id); ?>-label"><?php p($action); ?></span>
                    </label>
                </td>
                <td class="shire-help-cell"><?php $adminHelp($helpSections[$id], $label); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php endforeach; ?>
        <p id="myx-admin-result" role="status" aria-live="polite"></p>
    </section>
    <section aria-labelledby="shire-components-title">
        <h3 id="shire-components-title" tabindex="-1">Komponenten</h3>
        <div class="shire-table-scroll">
        <table class="shire-system" aria-labelledby="shire-components-title"><tbody>
            <tr data-check="templates"<?php if (!$templatesInstalled) { print_unescaped(' hidden'); } ?>>
                <th scope="row">eigene Vorlagen</th><td id="myx-template-install-state"><?php p($templatesInstalled ? 'installiert' : 'nicht installiert'); ?></td>
                <td><details id="myx-replace-templates"><summary aria-controls="myx-template-panel"><?php p($templatesInstalled ? 'ersetzen' : 'Vorlagen installieren'); ?></summary></details></td>
                <td class="shire-help-cell"><?php $adminHelp('eigene-vorlagen', 'eigene Vorlagen'); ?></td>
            </tr>
            <tr id="myx-template-panel" hidden><td colspan="4">
    <form id="myx-pdf-templates">
        <input type="hidden" name="templatesCheck" value="yes">
        <?php $savedTemplates = $_['pdfStatus']['checks']['templates']; ?>
        <ul id="myx-template-files">
        <?php if (!empty($savedTemplates['version'])): ?>
            <?php foreach (['odt' => 'XRechnung (ODT)', 'xml' => 'XRechnung (XML)', 'mapping' => 'XRechnung (Mapping)', 'storno_odt' => 'Storno (ODT)', 'storno_xml' => 'Storno (XML)', 'storno_mapping' => 'Storno (Mapping)'] as $key => $label):
                $file = $savedTemplates['files'][$key] ?? [];
                if (!$file || ($file['source'] ?? '') === 'default' || (!$pdfActive && !in_array($key, ['xml', 'storno_xml'], true))) continue; ?>
            <li><?php
                $file = $savedTemplates['files'][$key] ?? [];
                p($label . ': ' . ($file['name'] ?? 'Dateiname nicht erfasst')
                    . (($file['source'] ?? '') === 'default' ? ' — mitgelieferte Standardvorlage' : ' — Upload-Datum: ' . ($file['date'] ?? 'nicht erfasst')));
            ?></li>
            <?php endforeach; ?>
        <?php endif; ?>
        </ul>
        <div class="shire-table-scroll">
        <table class="shire-template-uploads">
            <thead><tr><th scope="col">Format</th><th scope="col">XRechnung</th><th scope="col">Storno</th></tr></thead>
            <tbody>
            <?php foreach (['odt' => ['ODT', '.odt', '10 MiB'], 'xml' => ['XML', '.xml', '2 MiB'], 'mapping' => ['Mapping', '.tsv,.txt', '256 KiB']] as $key => [$label, $accept, $limit]): ?>
            <tr<?php if ($key !== 'xml') { print_unescaped(' data-pdf-template' . (!$pdfActive ? ' hidden' : '')); } ?>><th scope="row"><?php p($label . ' (max ' . $limit . ')'); ?></th>
                <?php foreach (['' => 'XRechnung', 'storno_' => 'Storno'] as $prefix => $kind): ?>
                <td><input id="myx-pdf-<?php p($prefix . $key); ?>" name="<?php p($prefix . $key); ?>" type="file"<?php if (!$pdfActive && $key !== 'xml') { print_unescaped(' disabled'); } ?> accept="<?php p($accept); ?>" aria-label="<?php p($kind . ' ' . $label . ' (max ' . $limit . ')'); ?>"></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p id="myx-template-pdf-help"<?php if (!$pdfActive) { print_unescaped(' hidden'); } ?>>Nicht ausgewählte Dateien bleiben erhalten. Die Vorlagensätze für XRechnung und Storno werden jeweils auf vollständige
            Zuordnung zwischen ODT, Mapping und XML geprüft. Bei inkonsistenten Vorlagen werden für XML- und PDF-Rechnungen die Standardvorlagen verwendet; eigene Vorlagen sind dann inaktiv.</p>
        <p id="myx-template-xml-help"<?php if ($pdfActive) { print_unescaped(' hidden'); } ?>>Bei inaktiver PDF-Erzeugung werden nur XML-Vorlagen gegen die Anforderungen der Eingabemaske beziehungsweise der Storno-Erzeugung geprüft. Gespeicherte ODT- und Mapping-Dateien bleiben erhalten und werden beim Aktivieren von PDF wieder mitgeprüft.</p>
        <button id="myx-discard-templates" type="button">eigene Vorlagen verwerfen</button>
        <button id="myx-pdf-upload" type="submit">neue Vorlagen prüfen</button>
    <?php $adminHelp('struktur-und-ubernahme', 'eigene Vorlagen verwerfen / neue Vorlagen prüfen'); ?>
    </form>
            </td></tr>
            <tr data-check="validator">
                <th scope="row" id="myx-validator-component">INValidator</th>
                <td data-version><?php p($status['installed'] ?: 'nicht installiert'); ?></td>
                <td><label class="shire-switch"<?php if (empty($status['installed'])) { print_unescaped(' hidden'); } ?>>
                    <input id="myx-auto-update" type="checkbox" role="switch" aria-labelledby="myx-validator-component myx-auto-update-label" <?php if ($status['auto_update']) { print_unescaped('checked'); } ?>>
                    <span id="myx-auto-update-label"><?php p($status['auto_update'] ? 'nicht automatisch aktualisieren' : 'automatisch aktualisieren'); ?></span>
                </label></td>
                <td class="shire-help-cell"><?php $adminHelp('xml-validierung-mit-invalidator', 'INValidator'); ?></td>
            </tr>
            <?php foreach (['libreoffice' => 'LibreOffice', 'mustang' => 'MustangCLI', 'verapdf' => 'veraPDF'] as $key => $label): ?>
            <tr data-check="<?php p($key); ?>">
                <th scope="row"><?php p($label); ?></th>
                <td data-version><?php p($checks[$key]['version'] ?: 'nicht installiert'); ?></td><td></td>
                <td class="shire-help-cell"><?php $adminHelp($key === 'verapdf' ? 'pdf-validierung-mit-verapdf' : 'pdf-erzeugung-mit-libreoffice-und-mustangcli', $label); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr data-check="archive"<?php if (($_['archiveStatus'] ?? 'leer') === 'leer') { print_unescaped(' hidden'); } ?>>
                <th scope="row">Rechnungsarchiv</th>
                <td id="myx-archive-status"><?php p($_['archiveStatus'] ?? 'leer'); ?></td>
                <td><button id="myx-clear-archive" type="button">Archiv leeren</button></td>
                <td class="shire-help-cell"><?php $adminHelp('betrieb-und-archiv', 'Rechnungsarchiv'); ?></td>
            </tr>
        </tbody></table>
        </div>
        <p id="myx-clear-archive-result" role="status" aria-live="polite"></p>
        <button id="myx-check-system" type="button">Systemprüfung wiederholen</button>
        <?php $adminHelp('systemvoraussetzungen', 'Systemprüfung wiederholen'); ?>
        <p id="myx-pdf-status" data-status="<?php p(json_encode($_['pdfStatus'])); ?>" role="status" aria-live="polite"><?php
            if ($_['customTemplates'] && !empty($checks['templates']['error'])) {
                p('Eigene Vorlagen sind für die aktuelle Konfiguration nicht verwendbar. Standardvorlagen werden verwendet. ' . $checks['templates']['error']);
            }
        ?></p>

    </section>
    <section aria-labelledby="shire-admin-readme-title">
        <h3 id="shire-admin-readme-title">README</h3>
        <?php require __DIR__ . '/readme-content.php'; ?>
    </section>
</div>
