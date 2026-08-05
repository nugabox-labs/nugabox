      </main>
    </div>
  </div>
</div>

<script src="/admin/assets/js/bootstrap.bundle.min.js"></script>
<script src="/admin/assets/js/admin.js"></script>
<?php foreach (($PAGE_SCRIPTS ?? []) as $src): ?>
<script src="<?= h($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
