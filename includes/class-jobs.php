<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Jobs {
	public function __construct(
		private readonly Settings $settings,
		private readonly Audit $audit,
		private readonly ReviewWorkflow $reviews,
		private readonly SubscriptionGate $gate
	) {
	}

	public function register_hooks(): void {
		add_action('discoops_ai_run_job', [$this, 'run_job'], 10, 1);
		add_action('discoops_ai_cleanup_logs', [$this, 'cleanup_logs']);
	}

	public function enqueue(array $payload): array {
		global $wpdb;

		$job_id = $this->insert_job($payload, 'pending');

		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('discoops_ai_run_job', ['job_id' => $job_id], 'discoops-ai');
		}

		return [
			'id'     => $job_id,
			'status' => 'pending',
		];
	}

	public function enqueue_local(array $payload, string $status = 'pending'): array {
		$job_id = $this->insert_job($payload, $status);

		return [
			'id'     => $job_id,
			'status' => $status,
		];
	}

	public function get(int $job_id): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM ' . table_name('jobs') . ' WHERE id = %d', $job_id),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function list(array $filters = []): array {
		global $wpdb;

		$status = isset($filters['status']) ? sanitize_key((string) $filters['status']) : '';
		$sql = 'SELECT j.*, r.review_status, r.id AS review_id FROM ' . table_name('jobs') . ' j LEFT JOIN ' . table_name('reviews') . ' r ON r.job_id = j.id';

		if ($status !== '') {
			$sql .= $wpdb->prepare(' WHERE j.status = %s', $status);
		}

		$sql .= ' ORDER BY j.priority ASC, j.created_at DESC LIMIT 50';

		$rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

		return array_map(function (array $row): array {
			$post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
			$derived_type = $post_id > 0 ? sanitize_key((string) get_post_meta($post_id, '_discoops_opportunity_type', true)) : '';
			if ($derived_type !== '' && in_array($derived_type, ['refresh_declining', 'variant_from_winner', 'cluster_expansion'], true)) {
				$row['job_type'] = $derived_type;
				if (($row['source_signal'] ?? '') === 'manual_review') {
					$row['source_signal'] = 'discover_opportunity';
				}
			}

			return $row;
		}, $rows);
	}

	public function run_job(array $args): void {
		global $wpdb;
		$job_id = absint($args['job_id'] ?? 0);

		if ($job_id < 1) {
			return;
		}

		$wpdb->update(
			table_name('jobs'),
			[
				'status'       => 'running',
				'updated_at'   => now_mysql(),
				'locked_until' => gmdate('Y-m-d H:i:s', time() + 300),
			],
			['id' => $job_id]
		);

		$post_id = (int) $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . table_name('jobs') . ' WHERE id = %d', $job_id));
		if ($post_id > 0) {
			update_post_meta($post_id, '_discoops_ai_status', 'running');
		}

		$job = $this->get($job_id);
		if (! is_array($job)) {
			return;
		}

		if (! $this->gate->is_allowed()) {
			$this->mark_job_failed($job_id, 0, 'Discoops subscription inactive or not eligible for plugin access.');
			return;
		}

		if (str_starts_with((string) ($job['job_type'] ?? ''), 'rework_') && ! $this->gate->has_capability(SubscriptionGate::CAP_REWORK)) {
			$this->mark_job_failed($job_id, 0, 'Current plan does not allow targeted rework jobs.');
			return;
		}

		$payload = decode_json_array((string) ($job['payload_json'] ?? ''));
		$workflow = $this->resolve_workflow_name($job, $payload);
		$run_id = $this->create_run($job_id, $workflow, $payload);

		if (! in_array($workflow, ['optimizeDecliningArticle', 'draftDiscoverOpportunity'], true)) {
			$this->mark_job_failed($job_id, $run_id, 'Unsupported workflow for phase 1.');
			return;
		}

		$mcp_base_url = untrailingslashit((string) ($this->settings->get()['mcp_base_url'] ?? ''));
		$mcp_auth_token = (string) ($this->settings->get()['mcp_auth_token'] ?? '');

		if ($mcp_base_url === '' || $mcp_auth_token === '') {
			$this->mark_job_failed($job_id, $run_id, 'MCP settings are incomplete.');
			return;
		}

		$request_body = $workflow === 'draftDiscoverOpportunity'
			? [
				'siteId' => (string) $job['site_id'],
				'opportunityId' => isset($payload['opportunity_id']) ? absint($payload['opportunity_id']) : 0,
				'limit' => 1,
			]
			: [
				'siteId'    => (string) $job['site_id'],
				'postId'    => (int) $job['post_id'],
				'articleId' => (string) ($payload['external_article_id'] ?? ($payload['articleId'] ?? ('wp-' . $job['post_id']))),
				'jobId'     => $job_id,
				'scope'     => (string) (($payload['payload']['scope'] ?? $payload['scope']) ?: ''),
			];

		$workflowPath = $workflow === 'draftDiscoverOpportunity'
			? '/workflows/draft-discover-opportunity/run'
			: '/workflows/optimize-declining-article/run';

		$response = wp_remote_post(
			$mcp_base_url . $workflowPath,
			[
				'timeout' => 30,
				'headers' => [
					'Content-Type' => 'application/json',
					'x-mcp-token'  => $mcp_auth_token,
				],
				'body'    => wp_json_encode($request_body),
			]
		);

		if (is_wp_error($response)) {
			$this->mark_job_failed($job_id, $run_id, $response->get_error_message());
			return;
		}

		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if (wp_remote_retrieve_response_code($response) >= 400 || ! is_array($decoded)) {
			$this->mark_job_failed($job_id, $run_id, 'Invalid MCP workflow response.');
			return;
		}

		$workflow_result = is_array($decoded['result'] ?? null) ? $decoded['result'] : $decoded;
		$this->complete_job($job_id, $run_id, $workflow_result);
	}

	public function cleanup_logs(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . table_name('runs') . ' WHERE ended_at IS NOT NULL AND ended_at < %s',
				gmdate('Y-m-d H:i:s', strtotime('-30 days'))
			)
		);
	}

	private function resolve_workflow_name(array $job, array $payload): string {
		$signal = (string) ($job['source_signal'] ?? '');
		$hint = (string) ($payload['workflow'] ?? '');
		$job_type = (string) ($job['job_type'] ?? '');

		if ($hint !== '') {
			return $hint;
		}

		if (str_starts_with($job_type, 'rework_')) {
			return 'optimizeDecliningArticle';
		}

		if ($signal === 'drop') {
			return 'optimizeDecliningArticle';
		}

		if ($signal === 'discover_opportunity') {
			return 'draftDiscoverOpportunity';
		}

		return 'unsupported';
	}

	private function insert_job(array $payload, string $status): int {
		global $wpdb;

		$job = [
			'site_id'       => sanitize_text_field((string) ($payload['site_id'] ?? get_current_blog_id())),
			'post_id'       => isset($payload['post_id']) ? absint($payload['post_id']) : null,
			'job_type'      => sanitize_key((string) ($payload['job_type'] ?? 'generic')),
			'source_signal' => sanitize_key((string) ($payload['source_signal'] ?? 'manual')),
			'priority'      => min(9, max(1, absint($payload['priority'] ?? 5))),
			'status'        => sanitize_key($status),
			'payload_json'  => wp_json_encode($payload),
			'lock_token'    => null,
			'locked_until'  => null,
			'assigned_to'   => get_current_user_id() ?: null,
			'created_at'    => now_mysql(),
			'updated_at'    => now_mysql(),
		];

		$wpdb->insert(table_name('jobs'), $job);
		$job_id = (int) $wpdb->insert_id;

		if (! empty($job['post_id'])) {
			update_post_meta((int) $job['post_id'], '_discoops_ai_last_job_id', $job_id);
			update_post_meta((int) $job['post_id'], '_discoops_ai_status', $status === 'in_review' ? 'in_review' : 'queued');
		}

		return $job_id;
	}

	private function create_run(int $job_id, string $workflow, array $payload): int {
		global $wpdb;

		$wpdb->insert(
			table_name('runs'),
			[
				'job_id'         => $job_id,
				'workflow'       => $workflow,
				'model'          => 'deterministic-phase-1',
				'input_summary'  => wp_json_encode($payload),
				'output_summary' => null,
				'tool_calls_json' => null,
				'decision_json'  => null,
				'status'         => 'running',
				'started_at'     => now_mysql(),
				'ended_at'       => null,
			]
		);

		return (int) $wpdb->insert_id;
	}

	private function complete_job(int $job_id, int $run_id, array $workflow_result): void {
		global $wpdb;

		$wpdb->update(
			table_name('jobs'),
			[
				'status'     => 'in_review',
				'updated_at' => now_mysql(),
			],
			['id' => $job_id]
		);

		$wpdb->update(
			table_name('runs'),
			[
				'output_summary' => sanitize_text_field((string) ($workflow_result['reason'] ?? $workflow_result['summary'] ?? 'Completed')),
				'decision_json'  => wp_json_encode($workflow_result),
				'status'         => 'completed',
				'ended_at'       => now_mysql(),
			],
			['id' => $run_id]
		);

		$post_id = (int) ($workflow_result['draft_post_id'] ?? $workflow_result['draftPostId'] ?? 0);
		if ($post_id > 0) {
			update_post_meta($post_id, '_discoops_ai_status', 'in_review');
			update_post_meta($post_id, '_discoops_ai_review_status', 'pending');
		}

		$this->audit->record('job_completed', [
			'job_id'   => $job_id,
			'run_id'   => $run_id,
			'decision' => (string) ($workflow_result['decision'] ?? ''),
		]);
	}

	private function mark_job_failed(int $job_id, int $run_id, string $message): void {
		global $wpdb;

		$wpdb->update(
			table_name('jobs'),
			[
				'status'     => 'failed',
				'updated_at' => now_mysql(),
			],
			['id' => $job_id]
		);

		$wpdb->update(
			table_name('runs'),
			[
				'output_summary' => sanitize_text_field($message),
				'status'         => 'failed',
				'ended_at'       => now_mysql(),
			],
			['id' => $run_id]
		);

		$this->audit->record('job_failed', [
			'job_id'  => $job_id,
			'run_id'  => $run_id,
			'message' => $message,
		]);
	}
}
