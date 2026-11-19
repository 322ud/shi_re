<?php
$title = ['admin' => 'Administrationsanleitung', 'user' => 'Benutzeranleitung', 'developer' => 'Entwicklerdokumentation'][$_['document'] ?? 'readme'] ?? 'README';
?>
<div class="shire-readme-page" data-document-title="<?php p($title); ?>">
    <?php require __DIR__ . '/readme-content.php'; ?>
</div>
