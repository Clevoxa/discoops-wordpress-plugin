<?php
declare(strict_types=1);

$drops = array_values(array_filter($signals, static fn(array $signal): bool => (string) ($signal['signal_type'] ?? '') === 'drop'));
$page = max(1, absint($_GET['paged_signals'] ?? 1));
$per_page = 20;
$total_items = count($signals);
$total_pages = max(1, (int) ceil($total_items / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$rows = array_slice($signals, $offset, $per_page);
?>
<div class="wrap discoops-ai-admin discoops-ai-surface">
	<div class="discoops-ai-surface__hero">
		<div>
			<div class="discoops-ai-surface__eyebrow"><?php echo esc_html__('Discoops AI', 'discoops-ai-orchestrator'); ?></div>
			<h1><?php echo esc_html__('Signals', 'discoops-ai-orchestrator'); ?></h1>
			<p><?php echo esc_html__('Surveillez les signaux Discover reçus par le plugin et gardez une vision propre des derniers événements critiques.', 'discoops-ai-orchestrator'); ?></p>
		</div>
		<div class="discoops-ai-surface__stats">
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Chutes', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) count($drops)); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Signals', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html((string) count($signals)); ?></strong></div>
			<div class="discoops-ai-surface__stat"><span><?php echo esc_html__('Dernier type', 'discoops-ai-orchestrator'); ?></span><strong><?php echo esc_html(isset($signals[0]) ? (string) $signals[0]['signal_type'] : 'n/a'); ?></strong></div>
		</div>
	</div>

	<div class="discoops-ai-table-card">
		<div class="discoops-ai-table-card__head">
			<div>
				<h2><?php echo esc_html__('Historique des signals', 'discoops-ai-orchestrator'); ?></h2>
				<p><?php echo esc_html(sprintf(__('%d signal(s) chargés.', 'discoops-ai-orchestrator'), $total_items)); ?></p>
			</div>
			<div class="discoops-ai-table-card__meta"><?php echo esc_html(sprintf(__('Page %1$d / %2$d', 'discoops-ai-orchestrator'), $page, $total_pages)); ?></div>
		</div>
		<div class="discoops-ai-table-wrap">
			<table class="discoops-ai-table">
				<thead><tr><th>ID</th><th><?php echo esc_html__('Post', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Type', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Score', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Observé le', 'discoops-ai-orchestrator'); ?></th><th><?php echo esc_html__('Source', 'discoops-ai-orchestrator'); ?></th></tr></thead>
				<tbody>
					<?php foreach ($rows as $signal) : ?>
						<tr>
							<td>#<?php echo esc_html((string) $signal['id']); ?></td>
							<td><?php echo esc_html((string) $signal['post_id']); ?></td>
							<td><span class="discoops-ai-table__pill"><?php echo esc_html((string) $signal['signal_type']); ?></span></td>
							<td><?php echo esc_html((string) $signal['signal_score']); ?></td>
							<td><?php echo esc_html((string) $signal['observed_at']); ?></td>
							<td><?php echo esc_html((string) $signal['source']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ($total_pages > 1) : ?>
			<div class="discoops-ai-pagination">
				<?php for ($i = 1; $i <= $total_pages; $i++) : ?>
					<a class="discoops-ai-pagination__link <?php echo $i === $page ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged_signals', (string) $i)); ?>"><?php echo esc_html((string) $i); ?></a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
