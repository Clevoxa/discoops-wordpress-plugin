<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Signals {
	public function __construct(
		private readonly Jobs $jobs,
		private readonly Audit $audit,
		private readonly Settings $settings
	) {
	}

	public function register_hooks(): void {
		add_action('discoops_ai_ingest_signal', [$this, 'process_async_signal'], 10, 1);
	}

	public function ingest(array $payload): array {
		global $wpdb;

		$row = [
			'post_id'             => isset($payload['post_id']) ? absint($payload['post_id']) : null,
			'external_article_id' => sanitize_text_field((string) ($payload['external_article_id'] ?? '')),
			'signal_type'         => sanitize_key((string) ($payload['signal_type'] ?? 'unknown')),
			'signal_score'        => isset($payload['signal_score']) ? (float) $payload['signal_score'] : null,
			'source'              => sanitize_key((string) ($payload['source'] ?? 'unknown')),
			'payload_json'        => wp_json_encode($payload),
			'observed_at'         => sanitize_text_field((string) ($payload['observed_at'] ?? now_mysql())),
			'created_at'          => now_mysql(),
		];

		$wpdb->insert(table_name('signals'), $row);
		$signal_id = (int) $wpdb->insert_id;

		if (! empty($row['post_id'])) {
			update_post_meta((int) $row['post_id'], '_discoops_last_signal_type', $row['signal_type']);
			update_post_meta((int) $row['post_id'], '_discoops_last_signal_score', (string) $row['signal_score']);
			update_post_meta((int) $row['post_id'], '_discoops_last_signal_at', $row['observed_at']);
			update_post_meta((int) $row['post_id'], '_discoops_ai_status', 'signal_received');
		}

		$job = $this->jobs->enqueue(
			[
				'post_id'       => $row['post_id'],
				'job_type'      => 'content_refresh',
				'source_signal' => $row['signal_type'],
				'site_id'       => (string) get_current_blog_id(),
				'payload'       => $payload,
				'external_article_id' => $row['external_article_id'],
			]
		);

		$this->audit->record(
			'signal_ingested',
			[
				'signal_id' => $signal_id,
				'job_id'    => $job['id'],
				'source'    => $row['source'],
			]
		);

		return [
			'signal_id' => $signal_id,
			'job_id'    => $job['id'],
			'status'    => 'accepted',
		];
	}

	public function process_async_signal(array $args): void {
		$this->audit->record('signal_async_received', $args);
	}

	public function has_valid_signature(string $raw_body, string $signature): bool {
		$secret = (string) ($this->settings->get()['discoops_webhook_secret'] ?? '');
		if ($secret === '') {
			return true;
		}

		$expected = hash_hmac('sha256', $raw_body, $secret);
		$normalized = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

		return $normalized !== '' && hash_equals($expected, $normalized);
	}
}
