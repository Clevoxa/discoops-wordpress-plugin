<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Audit {
	public function record(string $action, array $context = []): void {
		$payload = redact_array($context);
		do_action('discoops_ai_audit_recorded', $action, $payload);
	}
}
