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

$format_status = static function ($value): string {
	$value = trim((string) $value);
	return match ($value) {
		'pending' => __('En attente', 'discoops-ai-orchestrator'),
		'queued' => __('En file', 'discoops-ai-orchestrator'),
		'running' => __('En cours', 'discoops-ai-orchestrator'),
		'in_review' => __('En review', 'discoops-ai-orchestrator'),
		'approved' => __('Approuvé', 'discoops-ai-orchestrator'),
		'rejected' => __('Rejeté', 'discoops-ai-orchestrator'),
		'failed' => __('Échoué', 'discoops-ai-orchestrator'),
		default => $value,
	};
};

$page = max(1, absint($_GET['paged_jobs'] ?? 1));
$per_page = 20;
$total_items = count($jobs);
$total_pages = max(1, (int) ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$rows = array_slice($jobs, $offset, $per_page);

$queued = count(array_filter($jobs, static fn(array $job): bool => in_array((string) ($job['status'] ?? ''), ['pending', 'queued'], true)));
$running = count(array_filter($jobs, static fn(array $job): bool => (string) ($job['status'] ?? '') === 'running'));
$review = count(array_filter($jobs, static fn(array $job): bool => (string) ($job['status'] ?? '') === 'in_review'));
?>
<div class="wrap discoops-ai-admin discoops-ai-surface">
	<div class="discoops-ai-surface__hero">
		<div>
			<div class="discoops-ai-surface__eyebrow"><?php echo esc_html__('Discoops AI', 'discoops-ai-orchestrator'); ?></div>
			<h1><?php echo esc_html__('Jobs', 'discoops-ai-orchestrator'); ?></h1>
			<p><?php echo esc_html__('Suivez la file locale WordPress, ajustez les priorités et gardez une vue claire sur les workflows actifs.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-surface__stats">
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('En file', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $queued); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('En cours', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $running); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('En review', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) $review); ?></strong></div>
		</div>
	</div>

	<div class="discoops-ai-table-card">
		<div class="discoops-ai-table-card__head">
			<div>
				<h2><?php echo esc_html__('Liste des jobs', 'discoops-ai-orchestrator'); ?></h2>
				<p><?php echo esc_html(sprintf(__('%d job(s) au total.', 'discoops-ai-orchestrator'), $total_items)); ?></p>
			</div>
			<div class="discoops-ai-table-card__meta"><?php echo esc_html(sprintf(__('Page %1$d / %2$d', 'discoops-ai-orchestrator'), $page, $total_pages)); ?></div>
		</div>
		<div class="discoops-ai-table-wrap">
			<table class="discoops-ai-table">
				<thead>
					<tr><th>ID</th><th><?php echo esc_html__('Post', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Type', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Signal', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Statut', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Review', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Priorité', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Actions', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Créé le', 'discoops-ai-orchestrator'); ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $job) : ?>
						<?php
						$job_id = (int) ($job['id'] ?? 0);
						$current_priority = (int) ($job['priority'] ?? 5);
						$raise_priority = max(1, $current_priority - 1);
						$lower_priority = min(9, $current_priority + 1);
						$raise_url = wp_nonce_url(admin_url('admin-post.php?action=discoops_ai_set_job_priority&job_id=' . $job_id . '&priority=' . $raise_priority), 'discoops_ai_set_job_priority_' . $job_id . '_' . $raise_priority);
						$lower_url = wp_nonce_url(admin_url('admin-post.php?action=discoops_ai_set_job_priority&job_id=' . $job_id . '&priority=' . $lower_priority), 'discoops_ai_set_job_priority_' . $job_id . '_' . $lower_priority);
						?>
						<tr>
							<td>#<?php echo esc_html((string) $job['id']); ?></td>
							<td><?php echo esc_html((string) $job['post_id']); ?></td>
							<td><span class="discoops-ai-table__pill"><?php echo esc_html($format_job_type($job['job_type'] ?? '')); ?></span></td>
							<td><?php echo esc_html((string) $job['source_signal']); ?></td>
							<td><?php echo esc_html($format_status($job['status'] ?? '')); ?></td>
							<td><?php echo esc_html((string) ($job['review_status'] ?? '')); ?></td>
							<td><?php echo esc_html((string) $job['priority']); ?></td>
							<td class="discoops-ai-table__actions">
								<?php if (! empty($can_prioritize_jobs)) : ?>
									<a class="button button-small" href="<?php echo esc_url($raise_url); ?>"><?php echo esc_html__('Monter', 'discoops-ai-orchestrator'); ?></a>
									<a class="button button-small" href="<?php echo esc_url($lower_url); ?>"><?php echo esc_html__('Baisser', 'discoops-ai-orchestrator'); ?></a>
								<?php else : ?>
									<span class="discoops-ai-table__pill">Growth+</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html((string) $job['created_at']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($total_pages > 1) : ?>
			<div class="discoops-ai-pagination">
				<?php for ($i = 1; $i <= $total_pages; $i++) : ?>
					<a class="discoops-ai-pagination__link <?php echo $i === $page ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged_jobs', (string) $i)); ?>"><?php echo esc_html((string) $i); ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
