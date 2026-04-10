<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Settings {
	public const OPTION_KEY = 'discoops_ai_orchestrator_settings';

	public function register(): void {
		register_setting(
			'discoops_ai_orchestrator',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize'],
				'default'           => [
					'wordpress_username' => '',
					'wordpress_app_password' => '',
					'mcp_base_url' => '',
					'mcp_auth_token' => '',
					'discoops_webhook_secret' => '',
				],
			]
		);
	}

	public function sanitize(array $input): array {
		return [
			'wordpress_username'     => sanitize_text_field((string) ($input['wordpress_username'] ?? '')),
			'wordpress_app_password' => sanitize_text_field((string) ($input['wordpress_app_password'] ?? '')),
			'mcp_base_url'           => esc_url_raw((string) ($input['mcp_base_url'] ?? '')),
			'mcp_auth_token'         => sanitize_text_field((string) ($input['mcp_auth_token'] ?? '')),
			'discoops_webhook_secret' => sanitize_text_field((string) ($input['discoops_webhook_secret'] ?? '')),
		];
	}

	public function get(): array {
		$value = get_option(self::OPTION_KEY, []);
		return is_array($value) ? $value : [];
	}
}
