<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}

		$audit = new Audit();
		$policy = new PolicyCompliance();
		$meta = new Meta();
		$settings = new Settings();
		$gate = new SubscriptionGate($settings);
		$permissions = new Permissions($gate);
		$reviews = new ReviewWorkflow($audit);
		$jobs = new Jobs($settings, $audit, $reviews, $gate);
		$signals = new Signals($jobs, $audit, $settings);
		$updater = new Updater();
		$admin = new Admin($jobs, $reviews, $settings, $policy, $gate);
		$rest = new Rest($permissions, $jobs, $signals, $reviews, $audit, $policy, $gate);

		add_action('init', [$meta, 'register']);
		add_action('admin_init', [$settings, 'register']);
		add_action('rest_api_init', [$rest, 'register_routes']);

		$jobs->register_hooks();
		$signals->register_hooks();
		$reviews->register_hooks();
		$updater->register();
		$admin->register();

		$this->booted = true;
	}
}
