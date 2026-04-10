<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class SubscriptionGate {
	private const CACHE_TTL = 90;
	private const FAILURE_CACHE_TTL = 60;
	public const CAP_PLUGIN = 'plugin';
	public const CAP_TRENDS = 'trends';
	public const CAP_VARIANTS = 'variants';
	public const CAP_REWORK = 'rework';
	public const CAP_AUTO_INTERNAL_LINKS = 'auto_internal_links';
	public const CAP_BULK_SCAN = 'bulk_scan';
	public const CAP_MULTI_SITE_PUSH = 'multi_site_push';
	public const CAP_SIMPLE_COLLABORATION = 'simple_collaboration';
	public const CAP_ADVANCED_COLLABORATION = 'advanced_collaboration';
	public const CAP_PRIORITY_MCP = 'priority_mcp';
	public const CAP_APPLY_TO_SOURCE = 'apply_to_source';
	public const CAP_PRIORITIZE_JOBS = 'prioritize_jobs';

	public function __construct(private readonly Settings $settings) {
	}

	public function is_allowed(bool $force = false): bool {
		$status = $this->get_status($force);
		return ! empty($status['allowed']);
	}

	public function access_level(bool $force = false): string {
		$status = $this->get_status($force);
		return (string) ($status['access_level'] ?? 'none');
	}

	public function has_capability(string $capability, bool $force = false): bool {
		$status = $this->get_status($force);
		$capabilities = is_array($status['capabilities'] ?? null) ? $status['capabilities'] : [];
		return ! empty($capabilities[$capability]);
	}

	public function required_plan_for_capability(string $capability): string {
		return match ($capability) {
			self::CAP_ADVANCED_COLLABORATION, self::CAP_PRIORITY_MCP => 'Agency',
			self::CAP_REWORK,
			self::CAP_AUTO_INTERNAL_LINKS,
			self::CAP_BULK_SCAN,
			self::CAP_MULTI_SITE_PUSH,
			self::CAP_SIMPLE_COLLABORATION,
			self::CAP_APPLY_TO_SOURCE,
			self::CAP_PRIORITIZE_JOBS,
			self::CAP_TRENDS,
			self::CAP_VARIANTS => 'Growth',
			default => 'Starter',
		};
	}

	public function get_status(bool $force = false): array {
		$settings = $this->settings->get();
		$secret = trim((string) ($settings['discoops_webhook_secret'] ?? ''));
		if ($secret === '') {
			return [
				'allowed' => false,
				'plan_id' => '',
				'access_level' => 'none',
				'subscription_status' => '',
				'matched_by' => '',
				'capabilities' => [],
				'message' => 'Configurez d abord le webhook secret Discoops dans les reglages du plugin.',
				'checked_at' => '',
			];
		}

		$cacheKey = 'discoops_ai_subscription_gate_' . md5($secret);
		if ($force) {
			delete_transient($cacheKey);
		}
		if (! $force) {
			$cached = get_transient($cacheKey);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$endpoint = apply_filters('discoops_ai_platform_api_url', 'https://www.discoops.com/api/v1/discoops.php');
		$endpoint = untrailingslashit((string) $endpoint) . '?action=plugin_access';

		$response = wp_remote_post($endpoint, [
			'timeout' => 12,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'body' => wp_json_encode([
				'webhook_secret' => $secret,
				'home_url' => home_url('/'),
			]),
		]);

		if (is_wp_error($response)) {
			$status = [
				'allowed' => false,
				'plan_id' => '',
				'access_level' => 'none',
				'subscription_status' => '',
				'matched_by' => '',
				'capabilities' => [],
				'message' => $response->get_error_message(),
				'checked_at' => current_time('mysql'),
			];
			set_transient($cacheKey, $status, self::FAILURE_CACHE_TTL);
			return $status;
		}

		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);
		$code = (int) wp_remote_retrieve_response_code($response);

		$status = [
			'allowed' => false,
			'plan_id' => '',
			'access_level' => 'none',
			'subscription_status' => '',
			'matched_by' => '',
			'capabilities' => [],
			'message' => 'Subscription verification failed.',
			'checked_at' => current_time('mysql'),
		];

		if ($code >= 200 && $code < 300 && is_array($json) && ! empty($json['ok'])) {
			$data = is_array($json['data'] ?? null) ? $json['data'] : $json;
			$status = [
				'allowed' => ! empty($data['allowed']),
				'plan_id' => (string) ($data['plan_id'] ?? ''),
				'access_level' => (string) ($data['access_level'] ?? 'none'),
				'subscription_status' => (string) ($data['subscription_status'] ?? ''),
				'matched_by' => (string) ($data['matched_by'] ?? ''),
				'capabilities' => is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
				'message' => (string) (($data['message'] ?? '') ?: (! empty($data['allowed']) ? 'Plugin access granted.' : 'Subscription verification failed.')),
				'checked_at' => current_time('mysql'),
			];
		} elseif (is_array($json) && is_array($json['error'] ?? null)) {
			$status['message'] = (string) (($json['error']['message'] ?? '') ?: $status['message']);
		} elseif ($code > 0) {
			$status['message'] = sprintf('Discoops plugin access endpoint returned HTTP %d.', $code);
		}

		$ttl = ! empty($status['allowed']) ? self::CACHE_TTL : self::FAILURE_CACHE_TTL;
		set_transient($cacheKey, $status, $ttl);
		return $status;
	}
}
