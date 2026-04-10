<?php
declare(strict_types=1);

$format_job_type = static function ($value): string {
	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}

	return match ($value) {
		'refresh_declining' => __('Refresh déclinant', 'discoops-ai-orchestrator'),
		'variant_from_winner' => __('Variante winner', 'discoops-ai-orchestrator'),
		'review_submission' => __('Review submission', 'discoops-ai-orchestrator'),
		'trend_topic' => __('Tendance du jour', 'discoops-ai-orchestrator'),
		default => $value,
	};
};

$page = max(1, absint($_GET['paged_reviews'] ?? 1));
$per_page = 12;
$total_items = count($reviews);
$total_pages = max(1, (int) ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$rows = array_slice($reviews, $offset, $per_page);

$pending = count(array_filter($reviews, static fn(array $review): bool => (string) ($review['review_status'] ?? '') === 'pending'));
$approved = count(array_filter($reviews, static fn(array $review): bool => (string) ($review['review_status'] ?? '') === 'approved'));
$rejected = count(array_filter($reviews, static fn(array $review): bool => (string) ($review['review_status'] ?? '') === 'rejected'));
?>
<div class="wrap discoops-ai-admin discoops-ai-surface">
	<div class="discoops-ai-surface__hero">
		<div>
			<div class="discoops-ai-surface__eyebrow"><?php echo esc_html__('Discoops AI', 'discoops-ai-orchestrator'); ?></div>
			<h1><?php echo esc_html__('Reviews', 'discoops-ai-orchestrator'); ?></h1>
			<p><?php echo esc_html__('Pilotez les validations éditoriales, suivez les refreshs et gardez une trace claire des décisions prises.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-surface__stats">
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Pending', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $pending); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Approved', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $approved); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Rejected', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $rejected); ?></strong></div>
		</div>
	</div>

	<div class="discoops-ai-table-card">
		<div class="discoops-ai-table-card__head">
			<div>
				<h2><?php echo esc_html__('Liste des reviews', 'discoops-ai-orchestrator'); ?></h2>
				<p><?php echo esc_html(sprintf(__('%d review(s) au total.', 'discoops-ai-orchestrator'), $total_items)); ?></p>
			</div>
			<div class="discoops-ai-table-card__meta"><?php echo esc_html(sprintf(__('Page %1$d / %2$d', 'discoops-ai-orchestrator'), $page, $total_pages)); ?></div>
		</div>
		<div class="discoops-ai-table-wrap">
			<table class="discoops-ai-table">
				<thead>
					<tr><th><?php echo esc_html__('Review', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Job', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Post', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Source Post', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Type', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Signal', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Status', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Date', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Actions', 'discoops-ai-orchestrator'); ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $review) : ?>
						<?php
						$post_id = (int) ($review['post_id'] ?? 0);
						$job_type = (string) ($review['job_type'] ?? '');
						$source_post_id = $post_id > 0 ? (int) get_post_meta($post_id, '_discoops_source_post_id', true) : 0;
						?>
						<tr>
							<td>#<?php echo esc_html((string) $review['id']); ?></td>
							<td>#<?php echo esc_html((string) $review['job_id']); ?></td>
							<td><?php echo esc_html((string) $review['post_id']); ?></td>
							<td><?php echo $job_type === 'refresh_declining' && $source_post_id > 0 ? esc_html((string) $source_post_id) : '&mdash;'; ?></td>
							<td><span class="discoops-ai-table__pill"><?php echo esc_html($format_job_type($review['job_type'] ?? '')); ?></span></td>
							<td><?php echo esc_html((string) $review['source_signal']); ?></td>
							<td><?php echo esc_html((string) $review['review_status']); ?></td>
							<td><?php echo esc_html((string) ($review['reviewed_at'] ?: $review['job_created_at'])); ?></td>
							<td class="discoops-ai-table__actions">
								<?php if (($review['review_status'] ?? '') === 'pending') : ?>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="discoops-ai-inline-form">
										<?php wp_nonce_field('discoops_ai_review_action'); ?>
										<input type="hidden" name="action" value="discoops_ai_approve_review">
										<input type="hidden" name="review_id" value="<?php echo esc_attr((string) $review['id']); ?>">
										<input type="text" name="comment" placeholder="<?php echo esc_attr__('Note interne (optionnelle)', 'discoops-ai-orchestrator'); ?>">
										<button type="submit" class="button button-primary"><?php echo esc_html__('Approve', 'discoops-ai-orchestrator'); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="discoops-ai-inline-form">
										<?php wp_nonce_field('discoops_ai_review_action'); ?>
										<input type="hidden" name="action" value="discoops_ai_reject_review">
										<input type="hidden" name="review_id" value="<?php echo esc_attr((string) $review['id']); ?>">
										<input type="text" name="comment" placeholder="<?php echo esc_attr__('Note interne (optionnelle)', 'discoops-ai-orchestrator'); ?>">
										<button type="submit" class="button"><?php echo esc_html__('Reject', 'discoops-ai-orchestrator'); ?></button>
									</form>
								<?php else : ?>
									<span><?php echo esc_html((string) $review['review_status']); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($total_pages > 1) : ?>
			<div class="discoops-ai-pagination">
				<?php for ($i = 1; $i <= $total_pages; $i++) : ?>
					<a class="discoops-ai-pagination__link <?php echo $i === $page ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged_reviews', (string) $i)); ?>"><?php echo esc_html((string) $i); ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
