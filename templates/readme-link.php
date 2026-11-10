<a class="shire-readme-link" aria-haspopup="dialog" aria-controls="shire-readme-dialog" href="<?php p($_['readmeUrl']); ?>" title="Readme" aria-label="Readme">
    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M12 11v6" stroke="currentColor" stroke-width="2"/>
        <circle cx="12" cy="7.5" r="1.25" fill="currentColor"/>
    </svg>
</a>
<div id="shire-readme-dialog" class="shire-readme-modal" role="dialog" aria-modal="true" aria-labelledby="shire-readme-title" hidden>
    <div class="shire-readme-dialog-panel" role="document">
    <div class="shire-readme-dialog-header">
        <h3 id="shire-readme-title">README</h3>
        <button id="shire-readme-close" type="button" aria-label="README schließen">Schließen</button>
    </div>
    <?php require __DIR__ . '/readme-content.php'; ?>
    </div>
</div>
