<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Updater {
	private const CACHE_KEY = 'discoops_ai_orchestrator_release_manifest';
	private const CACHE_TTL = 1800;

	public function register(): void {
		add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
		add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
		add_action('upgrader_process_complete', [$this, 'clear_cache']);
	}

	public function inject_update(mixed $transient): mixed {
		if (! is_object($transient)) {
			return $transient;
		}

		$manifest = $this->fetch_manifest();
		$plugin = $manifest['plugin'] ?? null;
		if (! is_array($plugin)) {
			return $transient;
		}

		$newVersion = (string) ($plugin['version'] ?? '');
		$downloadUrl = (string) ($plugin['download_url'] ?? '');
		if ($newVersion === '' || $downloadUrl === '') {
			return $transient;
		}

		if (version_compare($newVersion, DISCOOPS_AI_ORCHESTRATOR_VERSION, '<=')) {
			return $transient;
		}

		$pluginFile = plugin_basename(DISCOOPS_AI_ORCHESTRATOR_FILE);
		$transient->response[$pluginFile] = (object) [
			'id' => 'discoops-ai-orchestrator',
			'slug' => DISCOOPS_AI_ORCHESTRATOR_SLUG,
			'plugin' => $pluginFile,
			'new_version' => $newVersion,
			'url' => (string) ($plugin['homepage'] ?? 'https://www.discoops.com/?r=wp_connect'),
			'package' => $downloadUrl,
			'tested' => (string) ($plugin['tested'] ?? ''),
			'requires' => (string) ($plugin['requires_wp'] ?? ''),
			'requires_php' => (string) ($plugin['requires_php'] ?? ''),
		];

		return $transient;
	}

	public function plugins_api(mixed $result, string $action, object $args): mixed {
		if ($action !== 'plugin_information' || (($args->slug ?? '') !== DISCOOPS_AI_ORCHESTRATOR_SLUG)) {
			return $result;
		}

		$manifest = $this->fetch_manifest();
		$plugin = $manifest['plugin'] ?? null;
		if (! is_array($plugin)) {
			return $result;
		}

		return (object) [
			'name' => (string) ($plugin['name'] ?? 'Discoops - AI Discover Tools to Dominate Discover Rankings'),
			'slug' => (string) ($plugin['slug'] ?? DISCOOPS_AI_ORCHESTRATOR_SLUG),
			'version' => (string) ($plugin['version'] ?? DISCOOPS_AI_ORCHESTRATOR_VERSION),
			'author' => '<a href="https://www.discoops.com">Discoops</a>',
			'homepage' => (string) ($plugin['homepage'] ?? 'https://www.discoops.com/?r=wp_connect'),
			'requires' => (string) ($plugin['requires_wp'] ?? ''),
			'requires_php' => (string) ($plugin['requires_php'] ?? ''),
			'tested' => (string) ($plugin['tested'] ?? ''),
			'last_updated' => (string) ($plugin['last_updated'] ?? ''),
			'download_link' => (string) ($plugin['download_url'] ?? ''),
			'sections' => (array) ($plugin['sections'] ?? []),
			'banners' => (array) ($plugin['banners'] ?? []),
		];
	}

	public function clear_cache(): void {
		delete_site_transient(self::CACHE_KEY);
	}

	private function fetch_manifest(): array {
		$cached = get_site_transient(self::CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$response = wp_remote_get(DISCOOPS_AI_ORCHESTRATOR_UPDATE_API, [
			'timeout' => 8,
			'headers' => [
				'Accept' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			return [];
		}

		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($decoded) || ! is_array($decoded['plugin'] ?? null)) {
			return [];
		}

		set_site_transient(self::CACHE_KEY, $decoded, self::CACHE_TTL);
		return $decoded;
	}
}
