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
    <p>Die Rechnungsprüfung verwendet die separate App „kosit_validator“.</p>
    <p>Installierte Version: <strong><?php p($status['installed'] ?? 'nicht installiert'); ?></strong></p>
    <p>Beim Öffnen von „meine X-Rechnung“ wird das aktuelle GitHub-Release geprüft.
       Fehlt die App, wird sie beim Start durch einen Administrator automatisch installiert.
       Vorhandene Installationen werden nicht automatisch überschrieben.</p>
    <p>Solange das Repository privat ist, benötigt Nextcloud den Konfigurationseintrag
       <code>my_xrechnung_github_token</code> in <code>config.php</code> mit lesendem Zugriff auf
       <code>322ud/kosit_validator</code>. Nach Veröffentlichung ist kein Token nötig.</p>
    <p>Das GitHub-Release muss ein Paket <code>kosit_validator-VERSION.tar.gz</code> mit SHA-256-Digest enthalten.</p>
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
</div>
