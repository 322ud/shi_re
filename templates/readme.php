<div class="shire-readme-page">
    <a href="<?php p($_['appUrl']); ?>">Zurück zu ShiRE</a>
    <h2><?php p(($_['document'] ?? 'readme') === 'admin' ? 'Administrationsanleitung' : 'README'); ?></h2>
    <?php require __DIR__ . '/readme-content.php'; ?>
</div>
