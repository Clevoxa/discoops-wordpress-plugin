<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

require_once DISCOOPS_AI_ORCHESTRATOR_PATH . 'migrations/install.php';

function upgrade_plugin(): void {
	install_tables();
}
