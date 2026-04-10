<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Activator {
	public static function activate(): void {
		require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'migrations/install.php';

		install_tables();

		if (function_exists('as_has_scheduled_action') && ! as_has_scheduled_action('discoops_ai_cleanup_logs')) {
			as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'discoops_ai_cleanup_logs');
		}
	}
}
