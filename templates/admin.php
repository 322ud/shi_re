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
$templatesInstalled = !empty($checks['templates']['version']);
$validationActive = !empty($status['installed']) && !empty($status['enabled']) && !empty($status['validation_enabled']);
$rows = [
    ['myx-custom-templates', 'eigene Vorlagen', $templatesInstalled, $_['customTemplates'], false, false],
    ['myx-validation-enabled', 'XML-Validierung', !empty($status['installed']), $status['validation_enabled'], false, false],
    ['myx-pdf-enabled', 'PDF-Erzeugung', !empty($checks['libreoffice']['ok']) && !empty($checks['mustang']['ok']), true, false, true],
    ['myx-pdf-validation', 'PDF-Prüfung', !empty($checks['verapdf']['ok']), true, false, true],
    ['myx-hide-validator', 'INValidator', true, $status['hide_navigation'], true, false],
    ['myx-message-area', 'Meldungsbereich', true, true, true, true],
    ['myx-allow-draft-deletion', 'Button „Entwurf löschen“', true, $_['allowDraftDeletion'], true, false],
];
?>
<div id="myx-admin" class="section" data-validator-enabled="<?php p(!empty($status['enabled']) ? 'true' : 'false'); ?>">
    <h2>ShiRE</h2>
    <section aria-labelledby="shire-settings-title">
        <h3 id="shire-settings-title">Einstellungen</h3>
        <div class="shire-table-scroll">
        <table class="shire-system" aria-labelledby="shire-settings-title"><tbody>
        <?php foreach ($rows as [$id, $label, $installed, $checked, $visibility, $pending]):
            $visible = $id === 'myx-hide-validator' ? !$checked : $checked;
            $state = !$installed ? 'nicht installiert' : ($id === 'myx-custom-templates' ? 'installiert' : ($visibility ? ($visible ? 'eingeblendet' : 'ausgeblendet') : ($checked ? 'aktiv' : 'inaktiv')));
            $action = $visibility ? ($visible ? 'ausblenden' : 'einblenden') : ($checked ? 'deaktivieren' : 'aktivieren'); ?>
            <tr data-setting="<?php p($id); ?>"<?php if ($id === 'myx-hide-validator' && !$validationActive) { print_unescaped(' hidden'); } ?>>
                <th scope="row" id="<?php p($id); ?>-name"><?php p($label); ?></th>
                <td data-setting-state><?php p($state); ?></td>
                <td>
                    <?php if ($id === 'myx-custom-templates'): ?><a id="myx-install-templates" class="button" href="#shire-components-title"<?php if ($installed) { print_unescaped(' hidden'); } ?>>installieren</a><?php endif; ?>
                    <label class="shire-switch"<?php if (!$installed) { print_unescaped(' hidden'); } ?>>
                        <input id="<?php p($id); ?>" type="checkbox" role="switch" aria-labelledby="<?php p($id); ?>-name <?php p($id); ?>-label" <?php if ($checked) { print_unescaped('checked '); } if ($pending) { print_unescaped('disabled aria-describedby="shire-pending-settings"'); } ?>>
                        <span id="<?php p($id); ?>-label"><?php p($action); ?></span>
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <p id="shire-pending-settings">Die Schalter für PDF-Erzeugung, PDF-Prüfung und Meldungsbereich sind vorbereitet; ihre Funktion wird im nächsten Schritt ergänzt.</p>
        <p id="myx-admin-result" role="status" aria-live="polite"></p>
    </section>
    <section aria-labelledby="shire-components-title">
        <h3 id="shire-components-title" tabindex="-1">Komponenten</h3>
        <div class="shire-table-scroll">
        <table class="shire-system" aria-labelledby="shire-components-title"><tbody>
            <tr data-check="templates"<?php if (!$templatesInstalled) { print_unescaped(' hidden'); } ?>>
                <th scope="row">eigene Vorlagen</th><td>installiert</td><td id="myx-template-menu-slot"></td>
            </tr>
            <?php foreach (['libreoffice' => 'LibreOffice', 'mustang' => 'MustangCLI', 'verapdf' => 'veraPDF'] as $key => $label): ?>
            <tr data-check="<?php p($key); ?>">
                <th scope="row"><?php p($label); ?></th>
                <td data-version><?php p($checks[$key]['version'] ?: 'nicht installiert'); ?></td><td></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table>
        </div>
        <div id="myx-template-install-slot">
    <form id="myx-pdf-templates">
        <input type="hidden" name="templatesCheck" value="yes">
        <?php $savedTemplates = $_['pdfStatus']['checks']['templates']; ?>
        <details id="myx-replace-templates">
        <summary><?php p($templatesInstalled ? 'ersetzen' : 'Vorlagen installieren'); ?></summary>
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
    <?php $adminHelp('struktur-und-ubernahme', 'eigene Vorlagen verwerfen / neue Vorlagen prüfen'); ?>
        </details>
    </form>
        </div>
        <button id="myx-check-system" type="button">Systemprüfung wiederholen</button>
        <?php $adminHelp('systemvoraussetzungen', 'Systemprüfung wiederholen'); ?>
        <p id="myx-pdf-status" data-status="<?php p(json_encode($_['pdfStatus'])); ?>" role="status" aria-live="polite"></p>
        <details><summary>Weitere Einstellungen</summary>
            <label class="shire-switch"><input id="myx-auto-update" type="checkbox" role="switch" <?php if ($status['auto_update']) { print_unescaped('checked'); } ?>>beim App-Start INValidator automatisch updaten</label>
            <label class="shire-switch"><input id="myx-detailed-progress" type="checkbox" role="switch" <?php if ($_['detailedProgress'] ?? false) { print_unescaped('checked'); } ?>>ausführliche Ablaufdokumentation</label>
            <button id="myx-clear-archive" type="button">Archiv leeren</button>
            <?php $adminHelp('optionen-fur-die-nutzung', 'Archiv leeren'); ?>
            <p id="myx-clear-archive-result" role="status" aria-live="polite"></p>
        </details>
    </section>
    <section aria-labelledby="shire-admin-readme-title">
        <h3 id="shire-admin-readme-title">README</h3>
        <?php require __DIR__ . '/readme-content.php'; ?>
    </section>
</div>
