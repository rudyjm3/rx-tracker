<?php
/**
 * Nav bell button + notification panel partial.
 * Expects $navNotifications (array) and $navUnreadCount (int) from the calling context.
 *
 * @var array $navNotifications
 * @var int   $navUnreadCount
 */
?>
<button class="nav-bell-btn" aria-label="Notifications" aria-expanded="false" data-bell-btn>
  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
  <span class="nav-bell-badge"
        aria-label="<?= e((string) $navUnreadCount) ?> notification<?= $navUnreadCount !== 1 ? 's' : '' ?>"
        <?= $navUnreadCount === 0 ? 'hidden' : '' ?>><?= e((string) $navUnreadCount) ?></span>
</button>

<div class="notif-panel" data-notif-panel hidden aria-label="Notifications panel" role="dialog">
  <div class="notif-panel-header">
    <span class="notif-panel-title">Notifications</span>
    <?php if ($navNotifications !== []): ?>
      <button type="button" class="notif-mark-all-btn" data-notif-mark-all>Mark all read</button>
    <?php endif; ?>
  </div>
  <div class="notif-panel-body" data-notif-panel-body>
    <?php if ($navNotifications === []): ?>
      <p class="notif-empty">You're all set! No notifications.</p>
    <?php else: ?>
      <?php foreach ($navNotifications as $notif): ?>
        <?php
          $notifQty      = (float) ($notif['current_quantity'] ?? 0);
          $notifUnit     = (string) ($notif['inventory_unit'] ?? 'units');
          $notifQtyInt   = $notifQty == (int) $notifQty;
          $notifQtyLabel = $notifQtyInt
            ? (string) (int) $notifQty
            : rtrim(rtrim(number_format($notifQty, 2, '.', ''), '0'), '.');
          $notifType     = (string) $notif['type'];
          $isUnread      = !(bool) $notif['is_read'];
        ?>
        <div class="notif-item notif-item--<?= e($notifType) ?><?= $isUnread ? ' notif-item--unread' : '' ?>"
             data-notif-id="<?= e((string) $notif['id']) ?>"
             data-notif-item>
          <div class="notif-item-body">
            <span class="notif-item-icon" aria-hidden="true">
              <?php if ($notifType === 'out_of_stock'): ?>&#9888;<?php
              elseif ($notifType === 'critical_stock'): ?>&#9888;<?php
              else: ?>&#8505;<?php endif; ?>
            </span>
            <div class="notif-item-text">
              <strong class="notif-item-name"><?= e((string) $notif['medication_name']) ?></strong>
              <span class="notif-item-desc">
                <?php if ($notifType === 'out_of_stock'): ?>
                  Out of stock &mdash; refill needed
                <?php elseif ($notifType === 'critical_stock'): ?>
                  <?= e($notifQtyLabel) ?> <?= e($notifUnit) ?> remaining &mdash; running out soon
                <?php else: ?>
                  Low supply: <?= e($notifQtyLabel) ?> <?= e($notifUnit) ?> remaining
                <?php endif; ?>
              </span>
            </div>
          </div>
          <div class="notif-item-actions">
            <?php if ($navShowRefillBtn ?? true): ?>
            <button type="button"
                    class="notif-refill-btn"
                    data-open-refill-modal
                    data-medication-id="<?= e((string) $notif['medication_id']) ?>"
                    data-medication-name="<?= e((string) $notif['medication_name']) ?>"
                    title="Log a refill for <?= e((string) $notif['medication_name']) ?>">
              Refill
            </button>
            <?php endif; ?>
            <button type="button"
                    class="notif-dismiss-btn"
                    data-notif-dismiss="<?= e((string) $notif['id']) ?>"
                    aria-label="Dismiss notification for <?= e((string) $notif['medication_name']) ?>">&#10005;</button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
