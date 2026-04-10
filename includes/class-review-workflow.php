<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class ReviewWorkflow {
	public function __construct(private readonly Audit $audit) {
	}

	public function register_hooks(): void {
		add_action('discoops_ai_prepare_review', [$this, 'prepare_review'], 10, 1);
		add_action('discoops_ai_publish_after_review', [$this, 'publish_after_review'], 10, 1);
	}

	public function list_reviews(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT r.*, j.post_id, j.job_type, j.source_signal, j.status AS job_status, j.created_at AS job_created_at
			FROM ' . table_name('reviews') . ' r
			INNER JOIN ' . table_name('jobs') . ' j ON j.id = r.job_id
			ORDER BY r.created_at DESC LIMIT 50',
			ARRAY_A
		) ?: [];

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

	public function create(int $job_id): int {
		global $wpdb;

		$existing_review = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . table_name('reviews') . ' WHERE job_id = %d ORDER BY id DESC LIMIT 1', $job_id));
		if ($existing_review > 0) {
			return $existing_review;
		}

		$post_id = (int) $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . table_name('jobs') . ' WHERE id = %d', $job_id));

		$wpdb->insert(
			table_name('reviews'),
			[
				'job_id'           => $job_id,
				'review_status'    => 'pending',
				'reviewer_user_id' => null,
				'comment'          => null,
				'reviewed_at'      => null,
				'created_at'       => now_mysql(),
			]
		);

		if ($post_id > 0) {
			update_post_meta($post_id, '_discoops_ai_review_status', 'pending');
		}
		$wpdb->update(table_name('jobs'), ['status' => 'in_review', 'updated_at' => now_mysql()], ['id' => $job_id]);

		return (int) $wpdb->insert_id;
	}

	public function approve(int $review_id, string $comment = ''): array {
		global $wpdb;
		$job_id = (int) $wpdb->get_var($wpdb->prepare('SELECT job_id FROM ' . table_name('reviews') . ' WHERE id = %d', $review_id));
		$post_id = $job_id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . table_name('jobs') . ' WHERE id = %d', $job_id)) : 0;

		$wpdb->update(
			table_name('reviews'),
			[
				'review_status'    => 'approved',
				'reviewer_user_id' => get_current_user_id(),
				'comment'          => sanitize_textarea_field($comment),
				'reviewed_at'      => now_mysql(),
			],
			['id' => $review_id]
		);

		$this->audit->record('review_approved', ['review_id' => $review_id]);
		if ($post_id > 0) {
			update_post_meta($post_id, '_discoops_ai_review_status', 'approved');
			update_post_meta($post_id, '_discoops_ai_status', 'approved_for_publish');
		}
		if ($job_id > 0) {
			$wpdb->update(table_name('jobs'), ['status' => 'approved', 'updated_at' => now_mysql()], ['id' => $job_id]);
		}

		if ($job_id > 0) {
			$this->publish_after_review([
				'job_id' => $job_id,
				'review_id' => $review_id,
				'post_id' => $post_id,
			]);
		}

		return ['id' => $review_id, 'status' => 'approved'];
	}

	public function reject(int $review_id, string $comment = ''): array {
		global $wpdb;
		$job_id = (int) $wpdb->get_var($wpdb->prepare('SELECT job_id FROM ' . table_name('reviews') . ' WHERE id = %d', $review_id));
		$post_id = $job_id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT post_id FROM ' . table_name('jobs') . ' WHERE id = %d', $job_id)) : 0;

		$wpdb->update(
			table_name('reviews'),
			[
				'review_status'    => 'rejected',
				'reviewer_user_id' => get_current_user_id(),
				'comment'          => sanitize_textarea_field($comment),
				'reviewed_at'      => now_mysql(),
			],
			['id' => $review_id]
		);

		$this->audit->record('review_rejected', ['review_id' => $review_id]);
		if ($post_id > 0) {
			update_post_meta($post_id, '_discoops_ai_review_status', 'rejected');
			update_post_meta($post_id, '_discoops_ai_status', 'changes_requested');
		}
		if ($job_id > 0) {
			$wpdb->update(table_name('jobs'), ['status' => 'rejected', 'updated_at' => now_mysql()], ['id' => $job_id]);
		}

		return ['id' => $review_id, 'status' => 'rejected'];
	}

	public function prepare_review(array $args): void {
		$job_id = absint($args['job_id'] ?? 0);
		if ($job_id > 0) {
			$this->create($job_id);
		}
	}

	public function publish_after_review(array $args): void {
		$draft_id = absint($args['post_id'] ?? 0);
		if ($draft_id < 1) {
			$this->audit->record(
				'publish_after_review_skipped',
				[
					'job_id' => absint($args['job_id'] ?? 0),
					'reason' => 'missing_draft_post_id',
				]
			);
			return;
		}

		$source_post_id = absint((string) get_post_meta($draft_id, '_discoops_source_post_id', true));
		if ($source_post_id < 1) {
			$this->audit->record(
				'publish_after_review_skipped',
				[
					'job_id' => absint($args['job_id'] ?? 0),
					'post_id' => $draft_id,
					'reason' => 'missing_source_post_id',
				]
			);
			return;
		}

		$draft = get_post($draft_id);
		$source = get_post($source_post_id);
		if (! $draft || ! $source) {
			$this->audit->record(
				'publish_after_review_skipped',
				[
					'job_id' => absint($args['job_id'] ?? 0),
					'post_id' => $draft_id,
					'source_post_id' => $source_post_id,
					'reason' => 'draft_or_source_not_found',
				]
			);
			return;
		}

		$result = wp_update_post([
			'ID' => $source_post_id,
			'post_title' => $draft->post_title,
			'post_content' => $draft->post_content,
			'post_excerpt' => $draft->post_excerpt,
		], true);

		if ($result instanceof \WP_Error) {
			$this->audit->record(
				'publish_after_review_failed',
				[
					'job_id' => absint($args['job_id'] ?? 0),
					'post_id' => $draft_id,
					'source_post_id' => $source_post_id,
					'message' => $result->get_error_message(),
				]
			);
			return;
		}

		foreach ([
			'_discoops_seo_title',
			'_discoops_seo_description',
			'_discoops_focus_keyword',
			'_discoops_canonical_url',
			'_discoops_generated_summary',
			'_discoops_revision_summary',
			'_discoops_audit_score',
			'_discoops_issue_codes',
			'_discoops_quality_issues',
			'_discoops_quality_recommendations',
			'_discoops_quality_steps',
			'_discoops_opportunity_type',
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_canonical',
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
			'rank_math_canonical_url',
			'_discoops_internal_links',
			'_discoops_ai_revision_plan',
		] as $meta_key) {
			$value = get_post_meta($draft_id, $meta_key, true);
			if ($value !== '' && $value !== null) {
				update_post_meta($source_post_id, $meta_key, $value);
			}
		}

		update_post_meta($source_post_id, '_discoops_ai_status', 'refreshed_from_review');
		update_post_meta($source_post_id, '_discoops_ai_last_applied_draft_id', $draft_id);
		update_post_meta($draft_id, '_discoops_ai_status', 'applied_to_source');

		$this->audit->record(
			'publish_after_review_applied',
			[
				'job_id' => absint($args['job_id'] ?? 0),
				'post_id' => $draft_id,
				'source_post_id' => $source_post_id,
			]
		);
	}
}
