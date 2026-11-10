<div class="shire-readme-viewer" data-shire-readme>
    <div class="shire-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
    <pre class="shire-readme-fallback" tabindex="0" aria-label="Inhalt der README"><?php
        $readme = file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.';
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $heading)) {
                print_unescaped('<span data-readme-heading="');
                p($heading[1]);
                print_unescaped('">');
                p($line);
                print_unescaped('</span>');
            } else { p($line); }
            print_unescaped("\n");
        }
    ?></pre>
</div>
