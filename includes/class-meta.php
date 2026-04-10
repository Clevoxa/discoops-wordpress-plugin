<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Meta {
	/**
	 * @return array<string, string>
	 */
	public function keys(): array {
		return [
			'_discoops_last_signal_type' => 'string',
			'_discoops_last_signal_score' => 'number',
			'_discoops_last_signal_at' => 'string',
			'_discoops_ai_status' => 'string',
			'_discoops_ai_last_job_id' => 'integer',
			'_discoops_ai_review_status' => 'string',
			'_discoops_ai_cluster_id' => 'string',
			'_discoops_ai_cannibalization_group' => 'string',
			'_discoops_generated_summary' => 'string',
			'_discoops_revision_summary' => 'string',
			'_discoops_audit_score' => 'number',
			'_discoops_policy_score' => 'number',
			'_discoops_policy_flags' => 'string',
			'_discoops_issue_codes' => 'string',
			'_discoops_quality_issues' => 'string',
			'_discoops_quality_recommendations' => 'string',
			'_discoops_quality_steps' => 'string',
			'_discoops_opportunity_type' => 'string',
			'_discoops_source_post_id' => 'integer',
			'_discoops_editor_notes' => 'string',
			'_discoops_review_assignee' => 'integer',
			'_discoops_editor_comments' => 'string',
		];
	}

	public function register(): void {
		foreach ($this->keys() as $key => $type) {
			register_post_meta(
				'post',
				$key,
				[
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => $type,
					'auth_callback'     => static fn() => current_user_can('edit_posts'),
					'sanitize_callback' => static fn($value) => is_scalar($value) ? $value : '',
				]
			);
		}
	}
}
