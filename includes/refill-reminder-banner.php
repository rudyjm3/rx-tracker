<?php
/**
 * Refill reminder cards partial.
 * Expects $lowSupplyMeds (array) from the calling context (built once in pages-data.php).
 *
 * @var array $lowSupplyMeds
 */
?>
<?php if ($lowSupplyMeds !== []): ?>
<div class="refill-reminders" data-refill-reminders>
  <?php foreach ($lowSupplyMeds as $lowMed): ?>
    <?php
      $lowMedId      = (int) $lowMed['id'];
      $lowCurQty     = (float) ($lowMed['current_quantity'] ?? $lowMed['pill_count'] ?? 0);
      $lowUnit       = (string) ($lowMed['inventory_unit'] ?? 'tablets');
      $lowCurDisplay = $lowCurQty == (int) $lowCurQty ? (string) (int) $lowCurQty : rtrim(number_format($lowCurQty, 3), '0');
      $lowDose       = formattedDose($lowMed);
    ?>
    <div class="refill-reminder-card" role="alert" data-refill-reminder-card data-medication-id="<?= $lowMedId ?>">
      <span class="refill-reminder-swipe-hint">Swipe to dismiss</span>
      <span class="refill-reminder-icon" aria-hidden="true"><i class="fa-solid fa-prescription-bottle-medical"></i></span>
      <div class="refill-reminder-body">
        <span class="refill-reminder-eyebrow">Reminder</span>
        <strong class="refill-reminder-name">Refill: <?= e((string) $lowMed['name']) ?><?= $lowDose !== '' ? ' ' . e($lowDose) : '' ?></strong>
        <span class="refill-reminder-desc">Pills Left: <?= e($lowCurDisplay) ?> <?= e($lowUnit) ?></span>
      </div>
      <button type="button"
              class="refill-reminder-action"
              data-open-refill-modal
              data-medication-id="<?= $lowMedId ?>"
              data-medication-name="<?= e((string) $lowMed['name']) ?>"
              data-medication-dose="<?= e($lowDose) ?>">
        Refill <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
      <button type="button" class="refill-reminder-close" data-refill-reminder-dismiss
              aria-label="Dismiss reminder for <?= e((string) $lowMed['name']) ?>">&#10005;</button>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
