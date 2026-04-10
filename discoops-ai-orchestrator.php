<?php
/**
 * Plugin Name: Discoops - AI Discover Tools to Dominate Discover Rankings
 * Description: Editorial AI orchestration scaffolding for Discoops signals and WordPress workflows.
 * Version: 0.6.2
 * Requires PHP: 8.1
 * Author: Discoops
 * Update URI: https://www.discoops.com/?r=wp_connect
 * Text Domain: discoops-ai-orchestrator
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('DISCOOPS_AI_ORCHESTRATOR_VERSION', '0.6.2');
define('DISCOOPS_AI_ORCHESTRATOR_FILE', __FILE__);
define('DISCOOPS_AI_ORCHESTRATOR_PATH', plugin_dir_path(__FILE__));
define('DISCOOPS_AI_ORCHESTRATOR_URL', plugin_dir_url(__FILE__));
define('DISCOOPS_AI_ORCHESTRATOR_SLUG', 'discoops-ai-orchestrator');
define('DISCOOPS_AI_ORCHESTRATOR_UPDATE_API', 'https://www.discoops.com/api/v1/discoops.php?action=plugin_release');

if (file_exists(DISCOOPS_AI_ORCHESTRATOR_PATH . 'libraries/action-scheduler/action-scheduler.php')) {
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'libraries/action-scheduler/action-scheduler.php';
}

require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-i18n.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/helpers.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-activator.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-permissions.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-audit.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-policy-compliance.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-meta.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-jobs.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-signals.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-settings.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-subscription-gate.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-review-workflow.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-updater.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-admin.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-rest.php';
require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, ['DiscoopsAI\\Activator', 'activate']);

add_action('plugins_loaded', static function (): void {
	DiscoopsAI\I18n::register();
	DiscoopsAI\Plugin::instance()->boot();
});
