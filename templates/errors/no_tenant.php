<div class="card empty">
  <?= icon('building', 40) ?>
  <h2>Δεν ανήκεις ακόμη σε κάποιον οργανισμό</h2>
  <p>Ζήτησε από τον διαχειριστή να σου στείλει πρόσκληση.</p>
  <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Αποσύνδεση</button></form>
</div>
