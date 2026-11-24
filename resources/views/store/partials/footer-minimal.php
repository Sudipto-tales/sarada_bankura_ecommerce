<?php $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y'); ?>
<footer class="mt-16 border-t border-ink/10"><div class="wrap py-6 flex flex-col sm:flex-row gap-2 justify-between text-[12.5px] text-ink-500">
  <span>© <?= e($year) ?> <?= e(business('name')) ?></span>
  <span class="flex gap-4"><a href="<?= e(store_url('/policy')) ?>#returns" class="hover:text-ink">Returns</a><a href="<?= e(store_url('/policy')) ?>#delivery" class="hover:text-ink">Delivery</a><a href="<?= e(store_url('/policy')) ?>#privacy" class="hover:text-ink">Privacy</a></span>
</div></footer>
