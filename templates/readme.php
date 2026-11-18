<div class="shire-readme-page">
    <a href="<?php p($_['appUrl']); ?>">Zurück zu ShiRE</a>
    <h2><?php p(['admin' => 'Administrationsanleitung', 'user' => 'Benutzeranleitung', 'developer' => 'Entwicklerdokumentation'][$_['document'] ?? 'readme'] ?? 'README'); ?></h2>
    <?php require __DIR__ . '/readme-content.php'; ?>
</div>
