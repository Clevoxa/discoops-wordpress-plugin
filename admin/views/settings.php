<?php
declare(strict_types=1);
?>
<div class="wrap discoops-ai-admin discoops-ai-surface">
	<div class="discoops-ai-surface__hero">
		<div>
			<div class="discoops-ai-surface__eyebrow"><?php echo esc_html__('Discoops AI', 'discoops-ai-orchestrator'); ?></div>
			<h1><?php echo esc_html__('Settings', 'discoops-ai-orchestrator'); ?></h1>
			<p><?php echo esc_html__('Configure the WordPress connection, the MCP, and the secrets used by the plugin for Discoops workflows.', 'discoops-ai-orchestrator'); ?></p>
		</div>
	</div>

	<div class="discoops-ai-table-card">
		<div class="discoops-ai-table-card__head">
			<div>
				<h2><?php echo esc_html__('Discoops subscription status', 'discoops-ai-orchestrator'); ?></h2>
				<p><?php echo esc_html__('The plugin works only with an active Starter, Growth, or Agency subscription.', 'discoops-ai-orchestrator'); ?></p>
			</div>
		</div>
		<p><strong><?php echo esc_html__('Plugin access:', 'discoops-ai-orchestrator'); ?></strong> <?php echo ! empty($subscription_gate_status['allowed']) ? esc_html__('Active', 'discoops-ai-orchestrator') : esc_html__('Blocked', 'discoops-ai-orchestrator'); ?></p>
		<p><strong><?php echo esc_html__('Plan:', 'discoops-ai-orchestrator'); ?></strong> <?php echo esc_html((string) ($subscription_gate_status['plan_id'] ?? __('unknown', 'discoops-ai-orchestrator'))); ?></p>
		<p><strong><?php echo esc_html__('Plugin level:', 'discoops-ai-orchestrator'); ?></strong> <?php echo esc_html((string) ($subscription_gate_status['access_level'] ?? 'none')); ?></p>
		<p><strong><?php echo esc_html__('Subscription status:', 'discoops-ai-orchestrator'); ?></strong> <?php echo esc_html((string) ($subscription_gate_status['subscription_status'] ?? __('unknown', 'discoops-ai-orchestrator'))); ?></p>
		<p><?php echo esc_html((string) ($subscription_gate_status['message'] ?? '')); ?></p>
		<?php if (! empty($subscription_gate_capabilities)) : ?>
			<?php
			$enabled_caps = [];
			foreach ($subscription_gate_capabilities as $capability => $enabled) {
				if (! empty($enabled)) {
					$enabled_caps[] = (string) $capability;
				}
			}
			?>
			<p><strong><?php echo esc_html__('Enabled capabilities:', 'discoops-ai-orchestrator'); ?></strong> <?php echo esc_html($enabled_caps ? implode(', ', $enabled_caps) : __('none', 'discoops-ai-orchestrator')); ?></p>
		<?php endif; ?>
	</div>

	<div class="discoops-ai-table-card">
		<div class="discoops-ai-table-card__head">
			<div>
				<h2><?php echo esc_html__('Plugin configuration', 'discoops-ai-orchestrator'); ?></h2>
				<p><?php echo esc_html__('Main settings used by Discoops AI.', 'discoops-ai-orchestrator'); ?></p>
			</div>
		</div>
		<form method="post" action="options.php" class="discoops-ai-settings-form">
			<?php settings_fields('discoops_ai_orchestrator'); ?>
			<div class="discoops-ai-settings-grid">
				<label class="discoops-ai-settings-field">
					<span><?php echo esc_html__('MCP base URL', 'discoops-ai-orchestrator'); ?></span>
					<input id="mcp_base_url" name="<?php echo esc_attr(\DiscoopsAI\Settings::OPTION_KEY); ?>[mcp_base_url]" value="<?php echo esc_attr((string) ($settings['mcp_base_url'] ?? '')); ?>">
				</label>
				<label class="discoops-ai-settings-field">
					<span><?php echo esc_html__('WP username', 'discoops-ai-orchestrator'); ?></span>
					<input id="wordpress_username" name="<?php echo esc_attr(\DiscoopsAI\Settings::OPTION_KEY); ?>[wordpress_username]" value="<?php echo esc_attr((string) ($settings['wordpress_username'] ?? '')); ?>">
				</label>
				<label class="discoops-ai-settings-field">
					<span><?php echo esc_html__('Application Password', 'discoops-ai-orchestrator'); ?></span>
					<input id="wordpress_app_password" name="<?php echo esc_attr(\DiscoopsAI\Settings::OPTION_KEY); ?>[wordpress_app_password]" value="<?php echo esc_attr((string) ($settings['wordpress_app_password'] ?? '')); ?>">
				</label>
				<label class="discoops-ai-settings-field">
					<span><?php echo esc_html__('MCP auth token', 'discoops-ai-orchestrator'); ?></span>
					<input id="mcp_auth_token" name="<?php echo esc_attr(\DiscoopsAI\Settings::OPTION_KEY); ?>[mcp_auth_token]" value="<?php echo esc_attr((string) ($settings['mcp_auth_token'] ?? '')); ?>">
				</label>
				<label class="discoops-ai-settings-field discoops-ai-settings-field--full">
					<span><?php echo esc_html__('Discoops webhook secret', 'discoops-ai-orchestrator'); ?></span>
					<input id="discoops_webhook_secret" name="<?php echo esc_attr(\DiscoopsAI\Settings::OPTION_KEY); ?>[discoops_webhook_secret]" value="<?php echo esc_attr((string) ($settings['discoops_webhook_secret'] ?? '')); ?>">
				</label>
			</div>
			<div class="discoops-ai-settings-actions">
				<?php submit_button(__('Save settings', 'discoops-ai-orchestrator'), 'primary', 'submit', false); ?>
			</div>
		</form>
	</div>
</div>
