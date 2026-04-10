<?php
declare(strict_types=1);

$pending_reviews = 0;
$drafts_waiting = 0;
$published_refreshes = 0;
global $wpdb;

$recent_drops = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM " . \DiscoopsAI\table_name('signals') . " WHERE signal_type = 'drop' AND observed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);

foreach ($jobs as $job) {
	if (($job['review_status'] ?? '') === 'pending') {
		$pending_reviews++;
	}

	if (in_array((string) ($job['status'] ?? ''), ['pending', 'queued', 'running', 'in_review'], true)) {
		$drafts_waiting++;
	}
}

$published_refreshes_query = get_posts([
	'post_type' => 'post',
	'post_status' => 'any',
	'numberposts' => 20,
	'fields' => 'ids',
	'meta_key' => '_discoops_ai_status',
	'meta_value' => 'refreshed_from_review',
]);
$published_refreshes = is_array($published_refreshes_query) ? count($published_refreshes_query) : 0;

$snapshot = get_option('discoops_ai_dashboard_snapshot', []);
$snapshot = is_array($snapshot) ? $snapshot : [];
$scan = get_option('discoops_ai_dashboard_scan_state', []);
$scan = is_array($scan) ? $scan : [];

$average_audit = (int) ($snapshot['average_score'] ?? 0);
$policy_risk_count = (int) ($snapshot['policy_risk_count'] ?? 0);
$clickbait_count = (int) ($snapshot['clickbait_count'] ?? 0);
$clickbait_posts = array_values(array_filter((array) ($snapshot['clickbait_posts'] ?? []), 'is_array'));
$analyzed_count = (int) ($snapshot['analyzed_count'] ?? 0);
$scan_total = (int) ($scan['total'] ?? ($snapshot['total'] ?? 500));
$scan_processed = (int) ($scan['cursor'] ?? 0);
$scan_running = ! empty($scan['running']);
$scan_percent = $scan_total > 0 ? (int) floor(($scan_processed / $scan_total) * 100) : 0;
$snapshot_updated_at = (string) ($snapshot['updated_at'] ?? '');
?>
<div class="wrap discoops-ai-admin discoops-ai-dashboard" data-discoops-dashboard>
	<div class="discoops-ai-dashboard__hero">
		<div class="discoops-ai-dashboard__hero-copy">
			<div class="discoops-ai-dashboard__eyebrow"><?php echo esc_html__('Discoops AI', 'discoops-ai-orchestrator'); ?></div>
			<h1><?php echo esc_html__('Editorial Discover dashboard', 'discoops-ai-orchestrator'); ?></h1>
			<p><?php echo esc_html__('Track the Discover workflow, policy risk level, and launch an asynchronous analysis across up to 500 contents without blocking the WordPress admin.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__hero-actions">
			<?php if (! empty($can_bulk_scan)) : ?>
				<button type="button" class="button button-primary discoops-ai-dashboard__scan-btn" data-discoops-dashboard-scan>
					<?php echo esc_html__('Launch content analysis', 'discoops-ai-orchestrator'); ?>
				</button>
			<?php else : ?>
				<a href="https://www.discoops.com/?r=billing" class="button button-secondary discoops-ai-dashboard__scan-btn" target="_blank" rel="noreferrer noopener">
					<?php echo esc_html__('Unlock 500-content scan (Growth)', 'discoops-ai-orchestrator'); ?>
				</a>
			<?php endif; ?>
			<p class="discoops-ai-dashboard__hero-hint">
				<?php
				echo esc_html(
					$snapshot_updated_at !== ''
						? sprintf(__('Latest snapshot: %s', 'discoops-ai-orchestrator'), $snapshot_updated_at)
						: __('No snapshot has been calculated yet.', 'discoops-ai-orchestrator')
				);
				?>
			</p>
		</div>
	</div>

	<section class="discoops-ai-dashboard__progress" data-discoops-dashboard-progress>
		<div class="discoops-ai-dashboard__progress-head">
			<div>
				<h2><?php echo esc_html__('Asynchronous content analysis', 'discoops-ai-orchestrator'); ?></h2>
				<p data-discoops-dashboard-progress-label>
					<?php
					echo esc_html(
						$scan_running
							? sprintf(__('Progress: %1$d / %2$d contents', 'discoops-ai-orchestrator'), $scan_processed, $scan_total)
							: ($analyzed_count > 0
								? sprintf(__('%d contents already analyzed in the last snapshot.', 'discoops-ai-orchestrator'), $analyzed_count)
								: __('Launch a scan to calculate scores on up to 500 posts.', 'discoops-ai-orchestrator'))
					);
					?>
				</p>
				<?php if (empty($can_bulk_scan)) : ?>
					<p class="discoops-ai-dashboard__hero-hint"><?php echo esc_html__('The asynchronous 500-content scan is available starting with the Growth plan.', 'discoops-ai-orchestrator'); ?></p>
				<?php endif; ?>
			</div>
			<div class="discoops-ai-dashboard__progress-badge" data-discoops-dashboard-progress-badge>
				<?php echo esc_html($scan_running ? $scan_percent . '%' : ($analyzed_count > 0 ? __('Ready', 'discoops-ai-orchestrator') : __('Inactive', 'discoops-ai-orchestrator'))); ?>
			</div>
		</div>
		<div class="discoops-ai-dashboard__progress-bar">
			<div class="discoops-ai-dashboard__progress-fill" data-discoops-dashboard-progress-fill style="width: <?php echo esc_attr((string) ($scan_running ? $scan_percent : ($analyzed_count > 0 ? 100 : 0))); ?>%"></div>
		</div>
	</section>

	<div class="discoops-ai-dashboard__stats">
		<div class="discoops-ai-dashboard__card">
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Discover alerts', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $recent_drops); ?></div>
			<p><?php echo esc_html(sprintf(__('Drop signal%s during the last 7 days.', 'discoops-ai-orchestrator'), $recent_drops > 1 ? 's' : '')); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card">
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Drafts waiting', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $drafts_waiting); ?></div>
			<p><?php echo esc_html__('Contents still engaged in the Discoops workflow.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card">
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Reviews to validate', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $pending_reviews); ?></div>
			<p><?php echo esc_html__('Reviews waiting for an editorial decision.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card">
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Published refreshes', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $published_refreshes); ?></div>
			<p><?php echo esc_html__('Source posts already updated after review.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card discoops-ai-dashboard__card--accent" data-discoops-dashboard-average>
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Average score', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $average_audit); ?><span>/100</span></div>
			<p><?php echo esc_html(sprintf(__('Calculated on %d analyzed contents.', 'discoops-ai-orchestrator'), $analyzed_count)); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card discoops-ai-dashboard__card--accent" data-discoops-dashboard-policy-risk>
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Policy risk', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $policy_risk_count); ?></div>
			<p><?php echo esc_html__('Contents with a low policy score in the latest snapshot.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__card" data-discoops-dashboard-clickbait>
			<div class="discoops-ai-dashboard__card-label"><?php echo esc_html__('Clickbait titles', 'discoops-ai-orchestrator'); ?></div>
			<div class="discoops-ai-dashboard__card-value"><?php echo esc_html((string) $clickbait_count); ?></div>
			<p><?php echo esc_html__('Titles detected as too sensational or too generic.', 'discoops-ai-orchestrator'); ?></p>
			<?php if ($clickbait_count > 0) : ?>
				<button type="button" class="button discoops-ai-dashboard__card-action" data-discoops-dashboard-toggle-clickbait><?php echo esc_html__('View articles', 'discoops-ai-orchestrator'); ?></button>
			<?php endif; ?>
		</div>
	</div>

	<div class="discoops-ai-dashboard__list-card" data-discoops-dashboard-clickbait-list-wrap hidden>
		<div class="discoops-ai-dashboard__list-head">
			<h2><?php echo esc_html__('Articles with clickbait / sensational titles', 'discoops-ai-orchestrator'); ?></h2>
			<p><?php echo esc_html__('List generated from the latest analysis snapshot.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-dashboard__list" data-discoops-dashboard-clickbait-list>
			<?php if ($clickbait_posts) : ?>
				<?php foreach ($clickbait_posts as $item) : ?>
					<div class="discoops-ai-dashboard__list-row">
						<div class="discoops-ai-dashboard__list-copy">
							<strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong>
							<span>#<?php echo esc_html((string) ($item['id'] ?? '')); ?></span>
						</div>
						<div class="discoops-ai-dashboard__list-actions">
							<?php if (! empty($item['edit_url'])) : ?>
								<a class="button button-secondary" href="<?php echo esc_url((string) $item['edit_url']); ?>"><?php echo esc_html__('Edit', 'discoops-ai-orchestrator'); ?></a>
							<?php endif; ?>
							<?php if (! empty($item['view_url'])) : ?>
								<a class="button" href="<?php echo esc_url((string) $item['view_url']); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html__('View', 'discoops-ai-orchestrator'); ?></a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p class="discoops-ai-dashboard__empty"><?php echo esc_html__('No clickbait article in the latest snapshot.', 'discoops-ai-orchestrator'); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
