<div class="shire-readme-viewer" data-shire-readme>
    <div class="shire-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
    <pre class="shire-readme-fallback" tabindex="0" aria-label="Inhalt der README"><?php
        p(file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.');
    ?></pre>
</div>
