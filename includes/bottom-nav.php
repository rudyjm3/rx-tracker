<nav class="bottom-nav" aria-label="Main navigation">
  <a href="index.php" class="bottom-nav-item<?= !in_array($page, ['settings', 'calendar', 'export', 'medications', 'help', 'pain-tracking'], true) ? ' is-active' : '' ?>" aria-label="Dashboard">
    <i class="fa-solid fa-house" aria-hidden="true"></i>
    Dashboard
  </a>
  <a href="index.php?page=medications" class="bottom-nav-item<?= $page === 'medications' ? ' is-active' : '' ?>" aria-label="Medications">
    <i class="fa-solid fa-pills" aria-hidden="true"></i>
    Medications
  </a>
  <a href="index.php?page=calendar" class="bottom-nav-item<?= $page === 'calendar' ? ' is-active' : '' ?>" aria-label="Calendar">
    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
    Calendar
  </a>
  <a href="index.php?page=export" class="bottom-nav-item<?= $page === 'export' ? ' is-active' : '' ?>" aria-label="Export">
    <i class="fa-solid fa-file-export" aria-hidden="true"></i>
    Export
  </a>
  <button type="button" class="bottom-nav-item<?= in_array($page, ['settings', 'help'], true) ? ' is-active' : '' ?>" aria-label="More" onclick="document.getElementById('more-menu').classList.add('is-open')">
    <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
    More
  </button>
</nav>
<div id="more-menu" class="more-menu">
  <div class="more-menu__backdrop" onclick="document.getElementById('more-menu').classList.remove('is-open')"></div>
  <div class="more-menu__sheet">
    <a href="index.php?page=settings" class="more-menu__item<?= $page === 'settings' ? ' is-active' : '' ?>">
      <i class="fa-solid fa-gear" aria-hidden="true"></i>
      Settings
    </a>
    <a href="index.php?page=help" class="more-menu__item<?= $page === 'help' ? ' is-active' : '' ?>">
      <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
      Help
    </a>
    <a href="index.php?page=terms" class="more-menu__item">
      <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
      Terms of Use
    </a>
    <a href="index.php?page=privacy" class="more-menu__item">
      <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
      Privacy Policy
    </a>
  </div>
</div>
