<?php
declare(strict_types=1);

use WP_REST_Request;

final class Phase1FlowTest extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		require_once dirname(__DIR__) . '/migrations/install.php';
		\DiscoopsAI\install_tables();
		do_action('rest_api_init');
	}

	public function test_signal_ingest_creates_signal_and_job(): void {
		$post_id = self::factory()->post->create(['post_status' => 'publish']);
		$request = new WP_REST_Request('POST', '/discoops-ai/v1/signals/ingest');
		$request->set_body_params([
			'post_id' => $post_id,
			'external_article_id' => 'article_1',
			'signal_type' => 'drop',
			'signal_score' => 0.91,
			'source' => 'discoops',
		]);

		$response = rest_do_request($request);

		$this->assertSame(202, $response->get_status());
		$data = $response->get_data();
		$this->assertArrayHasKey('signal_id', $data);
		$this->assertArrayHasKey('job_id', $data);
	}

	public function test_create_draft_endpoint_creates_linked_draft(): void {
		$source_post_id = self::factory()->post->create(['post_status' => 'publish']);
		$request = new WP_REST_Request('POST', '/discoops-ai/v1/posts/create-draft');
		$request->set_body_params([
			'sourcePostId' => $source_post_id,
			'jobId' => 123,
			'title' => 'Draft title',
			'content' => '<h2>Draft heading</h2><p>Draft body</p><ul><li>First</li><li>Second</li></ul>',
			'excerpt' => 'Draft excerpt',
			'meta' => [
				'_discoops_ai_status' => 'draft_created',
			],
		]);

		$response = rest_do_request($request);

		$this->assertSame(200, $response->get_status());
		$draft_id = (int) $response->get_data()['id'];
		$this->assertSame('draft', get_post_status($draft_id));
		$this->assertSame((string) $source_post_id, get_post_meta($draft_id, '_discoops_source_post_id', true));
		$stored = (string) get_post_field('post_content', $draft_id);
		$this->assertTrue(has_blocks($stored));
		$this->assertStringContainsString('<!-- wp:heading -->', $stored);
		$this->assertStringContainsString('<!-- wp:paragraph -->', $stored);
		$this->assertStringContainsString('<!-- wp:list -->', $stored);
		$this->assertStringNotContainsString('<!-- wp:classic -->', $stored);
	}

	public function test_approve_and_reject_change_review_and_job_statuses(): void {
		global $wpdb;

		$job_table = $wpdb->prefix . 'discoops_ai_jobs';
		$review_table = $wpdb->prefix . 'discoops_ai_reviews';

		$wpdb->insert($job_table, [
			'site_id' => '1',
			'post_id' => self::factory()->post->create(['post_status' => 'draft']),
			'job_type' => 'content_refresh',
			'source_signal' => 'drop',
			'priority' => 5,
			'status' => 'in_review',
			'payload_json' => wp_json_encode([]),
			'lock_token' => null,
			'locked_until' => null,
			'assigned_to' => get_current_user_id(),
			'created_at' => current_time('mysql', true),
			'updated_at' => current_time('mysql', true),
		]);
		$job_id = (int) $wpdb->insert_id;

		$wpdb->insert($review_table, [
			'job_id' => $job_id,
			'review_status' => 'pending',
			'reviewer_user_id' => null,
			'comment' => null,
			'reviewed_at' => null,
			'created_at' => current_time('mysql', true),
		]);
		$review_id = (int) $wpdb->insert_id;

		$approve_request = new WP_REST_Request('POST', "/discoops-ai/v1/reviews/{$review_id}/approve");
		$approve_response = rest_do_request($approve_request);
		$this->assertSame(200, $approve_response->get_status());
		$this->assertSame('approved', $wpdb->get_var($wpdb->prepare("SELECT review_status FROM {$review_table} WHERE id = %d", $review_id)));

		$wpdb->update($review_table, ['review_status' => 'pending'], ['id' => $review_id]);
		$wpdb->update($job_table, ['status' => 'in_review'], ['id' => $job_id]);

		$reject_request = new WP_REST_Request('POST', "/discoops-ai/v1/reviews/{$review_id}/reject");
		$reject_response = rest_do_request($reject_request);
		$this->assertSame(200, $reject_response->get_status());
		$this->assertSame('rejected', $wpdb->get_var($wpdb->prepare("SELECT review_status FROM {$review_table} WHERE id = %d", $review_id)));
	}
}
