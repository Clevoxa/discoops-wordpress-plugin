<?php
declare(strict_types=1);

namespace DiscoopsAI;

use WP_REST_Request;

if (! defined('ABSPATH')) {
	exit;
}

final class Permissions {
	public function __construct(private readonly SubscriptionGate $gate) {
	}

	public function can_read(WP_REST_Request $request): bool {
		unset($request);
		return current_user_can('edit_posts') && $this->gate->is_allowed();
	}

	public function can_write(WP_REST_Request $request): bool {
		unset($request);
		return current_user_can('edit_posts') && $this->gate->is_allowed();
	}

	public function can_manage(WP_REST_Request $request): bool {
		unset($request);
		return current_user_can('manage_options') && $this->gate->is_allowed();
	}
}
