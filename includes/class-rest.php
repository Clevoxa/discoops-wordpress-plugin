<?php
declare(strict_types=1);

namespace DiscoopsAI;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
	exit;
}

final class Rest {
	private const ROUTE_NAMESPACE = 'discoops-ai/v1';
	private const DASHBOARD_SNAPSHOT_OPTION = 'discoops_ai_dashboard_snapshot';
	private const DASHBOARD_SCAN_STATE_OPTION = 'discoops_ai_dashboard_scan_state';

	public function __construct(
		private readonly Permissions $permissions,
		private readonly Jobs $jobs,
		private readonly Signals $signals,
		private readonly ReviewWorkflow $reviews,
		private readonly Audit $audit,
		private readonly PolicyCompliance $policy,
		private readonly SubscriptionGate $gate
	) {
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/health',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => fn() => new WP_REST_Response([
					'ok' => true,
					'service' => 'discoops-ai-orchestrator',
					'version' => DISCOOPS_AI_ORCHESTRATOR_VERSION,
					'review_required' => true,
					'action_scheduler' => function_exists('as_enqueue_async_action'),
				]),
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/posts/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => [$this, 'get_post'],
				'args'                => ['id' => ['type' => 'integer', 'required' => true]],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/posts/by-url',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => [$this, 'get_post_by_url'],
				'args'                => ['url' => ['type' => 'string', 'required' => true]],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/posts/search',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => [$this, 'search_posts'],
				'args'                => [
					'query'    => ['type' => 'string', 'required' => true],
					'per_page' => ['type' => 'integer', 'default' => 10],
					'status'   => ['type' => 'string', 'required' => false],
				],
			]
		);

		$this->register_post_route('/posts/create-draft', 'create_draft', [
			'sourcePostId' => ['type' => 'integer', 'required' => false],
			'jobId'    => ['type' => 'integer', 'required' => false],
			'postType' => ['type' => 'string', 'required' => false],
			'title'   => ['type' => 'string', 'required' => true],
			'content' => ['type' => 'string', 'required' => true],
			'excerpt' => ['type' => 'string', 'required' => false],
			'slug'    => ['type' => 'string', 'required' => false],
			'author'  => ['type' => 'integer', 'required' => false],
			'taxonomies' => ['type' => 'object', 'required' => false],
			'meta'    => ['type' => 'object', 'required' => false],
		]);

		$this->register_post_route('/posts/update-draft', 'update_draft', [
			'id'      => ['type' => 'integer', 'required' => true],
			'jobId'   => ['type' => 'integer', 'required' => false],
			'title'   => ['type' => 'string', 'required' => false],
			'content' => ['type' => 'string', 'required' => false],
			'excerpt' => ['type' => 'string', 'required' => false],
			'meta'    => ['type' => 'object', 'required' => false],
		]);

		$this->register_post_route('/posts/update-seo', 'update_seo', [
			'id'             => ['type' => 'integer', 'required' => true],
			'seoTitle'       => ['type' => 'string', 'required' => false],
			'seoDescription' => ['type' => 'string', 'required' => false],
			'focusKeyword'   => ['type' => 'string', 'required' => false],
			'canonicalUrl'   => ['type' => 'string', 'required' => false],
		]);

		$this->register_post_route('/posts/add-internal-links', 'add_internal_links', [
			'id'      => ['type' => 'integer', 'required' => true],
			'links'   => ['type' => 'array', 'required' => true],
			'content' => ['type' => 'string', 'required' => false],
		]);

		$this->register_post_route('/posts/attach-revision-plan', 'attach_revision_plan', [
			'id'   => ['type' => 'integer', 'required' => true],
			'jobId' => ['type' => 'integer', 'required' => false],
			'plan' => ['type' => 'object', 'required' => true],
		]);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/signals/ingest',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [$this, 'ingest_signal'],
				'args'                => [
					'post_id'             => ['type' => 'integer', 'required' => false],
					'external_article_id' => ['type' => 'string', 'required' => false],
					'signal_type'         => ['type' => 'string', 'required' => true],
					'signal_score'        => ['type' => 'number', 'required' => false],
					'source'              => ['type' => 'string', 'required' => true],
					'observed_at'         => ['type' => 'string', 'required' => false],
				],
			]
		);

		$this->register_post_route('/jobs/enqueue', 'enqueue_job', [
			'post_id'       => ['type' => 'integer', 'required' => false],
			'job_type'      => ['type' => 'string', 'required' => true],
			'source_signal' => ['type' => 'string', 'required' => true],
			'priority'      => ['type' => 'integer', 'required' => false],
			'site_id'       => ['type' => 'string', 'required' => false],
			'payload'       => ['type' => 'object', 'required' => false],
		]);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/jobs',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => fn(WP_REST_Request $request) => new WP_REST_Response($this->jobs->list($request->get_params())),
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/jobs/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => function (WP_REST_Request $request): WP_REST_Response|WP_Error {
					$job = $this->jobs->get(absint($request['id']));
					if (! is_array($job)) {
						return new WP_Error('discoops_ai_job_not_found', 'Job not found', ['status' => 404]);
					}

					return new WP_REST_Response($job);
				},
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/dashboard/scan-status',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => [$this, 'dashboard_scan_status'],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/dashboard/scan-run',
			[
				'methods'             => 'POST',
				'permission_callback' => [$this->permissions, 'can_manage'],
				'callback'            => [$this, 'dashboard_scan_run'],
				'args'                => [
					'limit' => ['type' => 'integer', 'required' => false],
					'reset' => ['type' => 'boolean', 'required' => false],
				],
			]
		);

		$this->register_post_route('/editor/rewrite-block', 'rewrite_editor_block', [
			'post_id'    => ['type' => 'integer', 'required' => true],
			'client_id'  => ['type' => 'string', 'required' => false],
			'block_name' => ['type' => 'string', 'required' => false],
			'action'     => ['type' => 'string', 'required' => true],
			'content'    => ['type' => 'string', 'required' => true],
		]);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/reviews',
			[
				'methods'             => 'GET',
				'permission_callback' => [$this->permissions, 'can_read'],
				'callback'            => fn() => new WP_REST_Response($this->reviews->list_reviews()),
			]
		);

		$this->register_manage_post_route('/reviews/(?P<id>\d+)/approve', 'approve_review', [
			'id'      => ['type' => 'integer', 'required' => true],
			'comment' => ['type' => 'string', 'required' => false],
		]);

		$this->register_manage_post_route('/reviews/(?P<id>\d+)/reject', 'reject_review', [
			'id'      => ['type' => 'integer', 'required' => true],
			'comment' => ['type' => 'string', 'required' => false],
		]);
	}

	private function register_post_route(string $route, string $callback, array $args): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			$route,
			[
				'methods'             => 'POST',
				'permission_callback' => [$this->permissions, 'can_write'],
				'callback'            => [$this, $callback],
				'args'                => $args,
			]
		);
	}

	private function register_manage_post_route(string $route, string $callback, array $args): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			$route,
			[
				'methods'             => 'POST',
				'permission_callback' => [$this->permissions, 'can_manage'],
				'callback'            => [$this, $callback],
				'args'                => $args,
			]
		);
	}

	public function get_post(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$post = get_post(absint($request['id']));

		if (! $post instanceof WP_Post) {
			return new WP_Error('discoops_ai_post_not_found', 'Post not found', ['status' => 404]);
		}

		return new WP_REST_Response($this->serialize_post($post));
	}

	public function get_post_by_url(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$url = esc_url_raw((string) $request['url']);
		$post_id = url_to_postid($url);

		if ($post_id < 1) {
			$path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
			$slug = $path !== '' ? basename($path) : '';
			if ($slug !== '') {
				$query = new WP_Query([
					'name' => sanitize_title($slug),
					'post_type' => 'post',
					'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
					'posts_per_page' => 1,
					'fields' => 'ids',
				]);
				$post_id = isset($query->posts[0]) ? (int) $query->posts[0] : 0;
			}
		}

		if ($post_id < 1) {
			return new WP_Error('discoops_ai_post_not_found', 'Post not found for URL', ['status' => 404]);
		}

		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return new WP_Error('discoops_ai_post_not_found', 'Post not found for URL', ['status' => 404]);
		}

		return new WP_REST_Response($this->serialize_post($post));
	}

	public function search_posts(WP_REST_Request $request): WP_REST_Response {
		$query = new WP_Query([
			's'              => sanitize_text_field((string) $request['query']),
			'post_type'      => 'post',
			'post_status'    => $request['status'] ? sanitize_key((string) $request['status']) : ['draft', 'pending', 'publish'],
			'posts_per_page' => min(20, max(1, absint($request['per_page'] ?? 10))),
		]);

		$items = array_map(
			static fn(WP_Post $post): array => [
				'id'     => $post->ID,
				'title'  => get_the_title($post),
				'status' => $post->post_status,
				'slug'   => $post->post_name,
				'link'   => get_permalink($post) ?: '',
			],
			$query->posts
		);

		return new WP_REST_Response(['items' => $items]);
	}

	public function create_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$source_post_id = absint($request['sourcePostId'] ?? 0);
		$source_post = $source_post_id > 0 ? get_post($source_post_id) : null;
		$post_type = $source_post instanceof WP_Post ? $source_post->post_type : sanitize_key((string) ($request['postType'] ?? 'post'));
		$content = $this->prepare_editor_content((string) $request['content']);

		$post_id = wp_insert_post([
			'post_type'    => $post_type ?: 'post',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field((string) $request['title']),
			'post_content' => $content,
			'post_excerpt' => sanitize_text_field((string) ($request['excerpt'] ?? '')),
			'post_name'    => sanitize_title((string) ($request['slug'] ?? '')),
			'post_author'  => absint($request['author'] ?? get_current_user_id()),
		], true);

		if ($post_id instanceof WP_Error) {
			return $post_id;
		}

		$this->persist_meta((int) $post_id, (array) ($request['meta'] ?? []));
		if ($source_post_id > 0) {
			update_post_meta((int) $post_id, '_discoops_source_post_id', $source_post_id);
		}
		if (! empty($request['jobId'])) {
			update_post_meta((int) $post_id, '_discoops_ai_last_job_id', absint($request['jobId']));
		}
		$this->assign_taxonomies((int) $post_id, (array) ($request['taxonomies'] ?? []), $source_post);
		update_post_meta((int) $post_id, '_discoops_ai_status', 'draft_created');
		$this->audit->record('draft_created', ['post_id' => (int) $post_id]);

		return new WP_REST_Response(['id' => (int) $post_id, 'status' => 'draft']);
	}

	public function update_draft(WP_REST_Request $request): WP_REST_Response|WP_Error {
		$post_data = ['ID' => absint($request['id'])];

		if ($request['title']) {
			$post_data['post_title'] = sanitize_text_field((string) $request['title']);
		}

		if ($request['content']) {
			$post_data['post_content'] = $this->prepare_editor_content((string) $request['content']);
		}

		if ($request['excerpt']) {
			$post_data['post_excerpt'] = sanitize_text_field((string) $request['excerpt']);
		}

		$post_id = wp_update_post($post_data, true);
		if ($post_id instanceof WP_Error) {
			return $post_id;
		}

		$this->persist_meta(absint($request['id']), (array) ($request['meta'] ?? []));
		if (! empty($request['jobId'])) {
			update_post_meta(absint($request['id']), '_discoops_ai_last_job_id', absint($request['jobId']));
		}
		$this->audit->record('draft_updated', ['post_id' => absint($request['id'])]);

		return new WP_REST_Response(['id' => absint($request['id']), 'status' => 'updated']);
	}

	public function update_seo(WP_REST_Request $request): WP_REST_Response {
		$post_id = absint($request['id']);
		$seo_title = sanitize_text_field((string) ($request['seoTitle'] ?? ''));
		$seo_description = sanitize_text_field((string) ($request['seoDescription'] ?? ''));
		$focus_keyword = sanitize_text_field((string) ($request['focusKeyword'] ?? ''));
		$canonical_url = esc_url_raw((string) ($request['canonicalUrl'] ?? ''));

		update_post_meta($post_id, '_discoops_seo_title', $seo_title);
		update_post_meta($post_id, '_discoops_seo_description', $seo_description);
		update_post_meta($post_id, '_discoops_focus_keyword', $focus_keyword);
		update_post_meta($post_id, '_discoops_canonical_url', $canonical_url);

		if ($seo_title !== '') {
			update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
			update_post_meta($post_id, 'rank_math_title', $seo_title);
		}
		if ($seo_description !== '') {
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);
			update_post_meta($post_id, 'rank_math_description', $seo_description);
		}
		if ($focus_keyword !== '') {
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
			update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
		}
		if ($canonical_url !== '') {
			update_post_meta($post_id, '_yoast_wpseo_canonical', $canonical_url);
			update_post_meta($post_id, 'rank_math_canonical_url', $canonical_url);
		}

		return new WP_REST_Response(['id' => $post_id, 'status' => 'seo_updated']);
	}

	public function add_internal_links(WP_REST_Request $request): WP_REST_Response {
		if (! $this->gate->has_capability(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS)) {
			return new WP_REST_Response([
				'error' => 'plan_restricted',
				'message' => sprintf(
					/* translators: %s is a plan name */
					__('Cette fonctionnalite est disponible a partir du plan %s.', 'discoops-ai-orchestrator'),
					$this->gate->required_plan_for_capability(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS)
				),
			], 403);
		}

		$post_id = absint($request['id']);
		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return new WP_REST_Response(['error' => 'post_not_found'], 404);
		}

		$links = is_array($request['links']) ? $request['links'] : [];
		update_post_meta($post_id, '_discoops_internal_links', wp_json_encode($links));

		$content = (string) ($request['content'] ?? $post->post_content);
		$updated_content = $this->inject_internal_links_into_content($content, $links);
		$inserted_count = max(0, count($links) - count($this->collect_unlinked_internal_link_items($updated_content, $links)));

		return new WP_REST_Response([
			'id' => $post_id,
			'status' => 'links_attached',
			'content' => $updated_content,
			'inserted_count' => $inserted_count,
			'changed' => $updated_content !== $content,
		]);
	}

	public function attach_revision_plan(WP_REST_Request $request): WP_REST_Response {
		$post_id = absint($request['id']);
		update_post_meta($post_id, '_discoops_ai_revision_plan', wp_json_encode($request['plan']));
		update_post_meta($post_id, '_discoops_ai_status', 'revision_planned');
		if (! empty($request['jobId'])) {
			update_post_meta($post_id, '_discoops_ai_last_job_id', absint($request['jobId']));
		}

		return new WP_REST_Response(['id' => $post_id, 'status' => 'revision_plan_attached']);
	}

	public function ingest_signal(WP_REST_Request $request): WP_REST_Response {
		$signature = (string) ($request->get_header('x-discoops-signature') ?: '');
		if (! $this->signals->has_valid_signature($request->get_body(), $signature)) {
			return new WP_REST_Response(['ok' => false, 'error' => 'Invalid signature'], 401);
		}

		$payload = $request->get_json_params();
		if (! is_array($payload)) {
			$payload = $request->get_params();
		}

		return new WP_REST_Response($this->signals->ingest($payload), 202);
	}

	public function enqueue_job(WP_REST_Request $request): WP_REST_Response {
		$payload = $request->get_json_params();
		if (! is_array($payload)) {
			$payload = $request->get_params();
		}

		if (($payload['job_type'] ?? '') === 'review_submission') {
			$post_id = isset($payload['post_id']) ? absint($payload['post_id']) : 0;
			$payloadMeta = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
			$opportunity_type = sanitize_key(
				(string) (
					$payloadMeta['opportunity_type']
					?? $payloadMeta['origin_job_type']
					?? ($post_id > 0 ? get_post_meta($post_id, '_discoops_opportunity_type', true) : '')
				)
			);
			$job_type = in_array($opportunity_type, ['refresh_declining', 'variant_from_winner', 'cluster_expansion'], true)
				? $opportunity_type
				: 'review_submission';
			$source_signal = $payload['source_signal'] ?? ($job_type === 'review_submission' ? 'manual_review' : 'discover_opportunity');

			$local_job = $this->jobs->enqueue_local(
				[
					'site_id'       => $payload['site_id'] ?? get_current_blog_id(),
					'post_id'       => $post_id,
					'job_type'      => $job_type,
					'source_signal' => $source_signal,
					'priority'      => $payload['priority'] ?? 5,
					'payload'       => $payloadMeta,
				],
				'in_review'
			);
			$review_id = $this->reviews->create((int) $local_job['id']);
			return new WP_REST_Response(
				[
					'id' => (int) $local_job['id'],
					'review_id' => $review_id,
					'status' => 'in_review',
				],
				202
			);
		}

		$jobType = sanitize_key((string) ($payload['job_type'] ?? ''));
		if (str_starts_with($jobType, 'rework_') && ! $this->gate->has_capability(SubscriptionGate::CAP_REWORK)) {
			return new WP_REST_Response([
				'error' => 'plan_restricted',
				'message' => sprintf(
					__('Cette fonctionnalite est disponible a partir du plan %s.', 'discoops-ai-orchestrator'),
					$this->gate->required_plan_for_capability(SubscriptionGate::CAP_REWORK)
				),
			], 403);
		}

		return new WP_REST_Response($this->jobs->enqueue($payload), 202);
	}

	public function approve_review(WP_REST_Request $request): WP_REST_Response {
		return new WP_REST_Response($this->reviews->approve(absint($request['id']), (string) ($request['comment'] ?? '')));
	}

	public function reject_review(WP_REST_Request $request): WP_REST_Response {
		return new WP_REST_Response($this->reviews->reject(absint($request['id']), (string) ($request['comment'] ?? '')));
	}

	public function rewrite_editor_block(WP_REST_Request $request): WP_REST_Response {
		if (! $this->gate->has_capability(SubscriptionGate::CAP_REWORK)) {
			return new WP_REST_Response([
				'error' => 'plan_restricted',
				'message' => sprintf(
					__('Cette fonctionnalite est disponible a partir du plan %s.', 'discoops-ai-orchestrator'),
					$this->gate->required_plan_for_capability(SubscriptionGate::CAP_REWORK)
				),
			], 403);
		}

		$post_id = absint($request['post_id']);
		$post = get_post($post_id);
		if (! $post instanceof WP_Post) {
			return new WP_REST_Response(['error' => 'post_not_found'], 404);
		}

		$action = sanitize_text_field((string) ($request['action'] ?? ''));
		$block_name = sanitize_key((string) ($request['block_name'] ?? ''));
		$content = (string) ($request['content'] ?? '');
		$rewritten = $this->build_inline_rewrite($post, $block_name, $action, $content);

		return new WP_REST_Response([
			'rewritten' => $rewritten,
			'note' => $this->build_inline_rewrite_note($action),
		]);
	}

	public function dashboard_scan_status(WP_REST_Request $request): WP_REST_Response {
		unset($request);
		return new WP_REST_Response($this->get_dashboard_scan_payload());
	}

	public function dashboard_scan_run(WP_REST_Request $request): WP_REST_Response {
		if (! $this->gate->has_capability(SubscriptionGate::CAP_BULK_SCAN)) {
			return new WP_REST_Response([
				'error' => 'plan_restricted',
				'message' => sprintf(
					__('Cette fonctionnalite est disponible a partir du plan %s.', 'discoops-ai-orchestrator'),
					$this->gate->required_plan_for_capability(SubscriptionGate::CAP_BULK_SCAN)
				),
			], 403);
		}

		$limit = max(10, min(50, absint($request['limit'] ?? 25)));
		$reset = ! empty($request['reset']);
		$state = get_option(self::DASHBOARD_SCAN_STATE_OPTION, []);
		$state = is_array($state) ? $state : [];

		if ($reset || empty($state['ids']) || ! is_array($state['ids'])) {
			$ids = get_posts([
				'post_type' => 'post',
				'post_status' => 'any',
				'numberposts' => 500,
				'fields' => 'ids',
				'orderby' => 'date',
				'order' => 'DESC',
			]);

			$state = [
				'ids' => array_values(array_map('intval', is_array($ids) ? $ids : [])),
				'cursor' => 0,
				'total' => is_array($ids) ? count($ids) : 0,
				'sum_score' => 0,
				'scored_count' => 0,
				'policy_risk_count' => 0,
				'clickbait_count' => 0,
				'clickbait_posts' => [],
				'running' => true,
				'started_at' => current_time('mysql'),
				'updated_at' => current_time('mysql'),
			];
		}

		$ids = array_values(array_map('intval', (array) ($state['ids'] ?? [])));
		$total = count($ids);
		$cursor = (int) ($state['cursor'] ?? 0);
		$batch = array_slice($ids, $cursor, $limit);

		foreach ($batch as $post_id) {
			$post = get_post($post_id);
			if (! $post instanceof WP_Post) {
				continue;
			}

			$report = $this->policy->evaluate($post);
			$policy_score = (int) round((float) ($report['score'] ?? 0));
			update_post_meta($post_id, '_discoops_policy_score', $policy_score);
			update_post_meta($post_id, '_discoops_policy_flags', wp_json_encode(array_values(array_map('strval', (array) ($report['flags'] ?? [])))));

			$effective_score = (int) round((float) get_post_meta($post_id, '_discoops_audit_score', true));
			if ($effective_score <= 0) {
				$effective_score = $policy_score;
			}

			if ($effective_score > 0) {
				$state['sum_score'] = (int) ($state['sum_score'] ?? 0) + $effective_score;
				$state['scored_count'] = (int) ($state['scored_count'] ?? 0) + 1;
			}

			if ($policy_score > 0 && $policy_score < 65) {
				$state['policy_risk_count'] = (int) ($state['policy_risk_count'] ?? 0) + 1;
			}
			if (! empty($report['title_clickbait'])) {
				$state['clickbait_count'] = (int) ($state['clickbait_count'] ?? 0) + 1;
				if (count((array) ($state['clickbait_posts'] ?? [])) < 50) {
					$state['clickbait_posts'][] = [
						'id' => (int) $post_id,
						'title' => get_the_title($post) ?: sprintf('Post #%d', $post_id),
						'edit_url' => get_edit_post_link($post_id, 'raw') ?: '',
						'view_url' => get_permalink($post) ?: '',
					];
				}
			}
		}

		$cursor += count($batch);
		$done = $cursor >= $total;
		$state['cursor'] = $cursor;
		$state['updated_at'] = current_time('mysql');
		$state['running'] = ! $done;

		if ($done) {
			$snapshot = [
				'updated_at' => current_time('mysql'),
				'analyzed_count' => (int) ($state['scored_count'] ?? 0),
				'total' => $total,
				'average_score' => (int) round(((int) ($state['sum_score'] ?? 0)) / max(1, (int) ($state['scored_count'] ?? 0))),
				'policy_risk_count' => (int) ($state['policy_risk_count'] ?? 0),
				'clickbait_count' => (int) ($state['clickbait_count'] ?? 0),
				'clickbait_posts' => array_values(array_filter((array) ($state['clickbait_posts'] ?? []), 'is_array')),
			];
			update_option(self::DASHBOARD_SNAPSHOT_OPTION, $snapshot, false);
			delete_option(self::DASHBOARD_SCAN_STATE_OPTION);
		} else {
			update_option(self::DASHBOARD_SCAN_STATE_OPTION, $state, false);
		}

		return new WP_REST_Response($this->get_dashboard_scan_payload());
	}

	private function get_dashboard_scan_payload(): array {
		$snapshot = get_option(self::DASHBOARD_SNAPSHOT_OPTION, []);
		$snapshot = is_array($snapshot) ? $snapshot : [];
		$state = get_option(self::DASHBOARD_SCAN_STATE_OPTION, []);
		$state = is_array($state) ? $state : [];
		$total = (int) ($state['total'] ?? ($snapshot['total'] ?? 0));
		$processed = (int) ($state['cursor'] ?? ($snapshot['analyzed_count'] ?? 0));

		return [
			'snapshot' => [
				'updated_at' => (string) ($snapshot['updated_at'] ?? ''),
				'analyzed_count' => (int) ($snapshot['analyzed_count'] ?? 0),
				'total' => (int) ($snapshot['total'] ?? 0),
				'average_score' => (int) ($snapshot['average_score'] ?? 0),
				'policy_risk_count' => (int) ($snapshot['policy_risk_count'] ?? 0),
				'clickbait_count' => (int) ($snapshot['clickbait_count'] ?? 0),
				'clickbait_posts' => array_values(array_filter((array) ($snapshot['clickbait_posts'] ?? []), 'is_array')),
			],
			'scan' => [
				'running' => ! empty($state['running']),
				'processed' => $processed,
				'total' => $total,
				'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
				'started_at' => (string) ($state['started_at'] ?? ''),
				'updated_at' => (string) ($state['updated_at'] ?? ''),
			],
		];
	}

	private function persist_meta(int $post_id, array $meta): void {
		foreach ($meta as $key => $value) {
			if (is_scalar($value) || $value === null) {
				update_post_meta($post_id, sanitize_key((string) $key), (string) $value);
			}
		}
	}

	private function prepare_editor_content(string $content): string {
		$content = trim(wp_kses_post($content));
		if ($content === '') {
			return '';
		}

		if (function_exists('has_blocks') && has_blocks($content)) {
			return $content;
		}

		$converted = $this->convert_html_to_blocks($content);

		return $converted !== '' ? $converted : $content;
	}

	private function convert_html_to_blocks(string $html): string {
		if (! class_exists('\DOMDocument')) {
			return '';
		}

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML(
			'<!DOCTYPE html><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (! $loaded) {
			return '';
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		if (! $body instanceof \DOMElement) {
			return '';
		}

		$blocks = [];
		foreach ($body->childNodes as $node) {
			$block = $this->convert_dom_node_to_block_markup($node);
			if ($block !== '') {
				$blocks[] = $block;
			}
		}

		return trim(implode("\n\n", $blocks));
	}

	private function convert_dom_node_to_block_markup(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			$text = trim($node->textContent ?? '');
			if ($text === '') {
				return '';
			}

			return "<!-- wp:paragraph -->\n<p>" . esc_html($text) . "</p>\n<!-- /wp:paragraph -->";
		}

		if (! $node instanceof \DOMElement) {
			return '';
		}

		$tag = strtolower($node->tagName);
		$outer = trim($node->ownerDocument?->saveHTML($node) ?: '');
		if ($outer === '') {
			return '';
		}

		return match ($tag) {
			'p' => "<!-- wp:paragraph -->\n{$outer}\n<!-- /wp:paragraph -->",
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->wrap_heading_block($outer, (int) substr($tag, 1)),
			'ul' => "<!-- wp:list -->\n{$outer}\n<!-- /wp:list -->",
			'ol' => "<!-- wp:list {\"ordered\":true} -->\n{$outer}\n<!-- /wp:list -->",
			'blockquote' => $this->wrap_quote_block($node),
			'figure' => $this->wrap_figure_block($node, $outer),
			'img' => $this->wrap_image_block($node),
			'hr' => "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
			'pre' => "<!-- wp:preformatted -->\n{$outer}\n<!-- /wp:preformatted -->",
			'table' => "<!-- wp:table -->\n{$outer}\n<!-- /wp:table -->",
			default => "<!-- wp:html -->\n{$outer}\n<!-- /wp:html -->",
		};
	}

	private function wrap_heading_block(string $outer, int $level): string {
		$attrs = $level !== 2 ? ' {"level":' . max(1, min(6, $level)) . '}' : '';
		return "<!-- wp:heading{$attrs} -->\n{$outer}\n<!-- /wp:heading -->";
	}

	private function wrap_quote_block(\DOMElement $node): string {
		$inner = $this->dom_inner_html($node);
		$html = '<blockquote class="wp-block-quote">' . $inner . '</blockquote>';
		return "<!-- wp:quote -->\n{$html}\n<!-- /wp:quote -->";
	}

	private function wrap_figure_block(\DOMElement $node, string $outer): string {
		if ($node->getElementsByTagName('img')->length > 0) {
			return "<!-- wp:image -->\n{$outer}\n<!-- /wp:image -->";
		}

		return "<!-- wp:html -->\n{$outer}\n<!-- /wp:html -->";
	}

	private function wrap_image_block(\DOMElement $node): string {
		$img = trim($node->ownerDocument?->saveHTML($node) ?: '');
		if ($img === '') {
			return '';
		}

		$html = '<figure class="wp-block-image">' . $img . '</figure>';
		return "<!-- wp:image -->\n{$html}\n<!-- /wp:image -->";
	}

	private function dom_inner_html(\DOMElement $element): string {
		$html = '';
		foreach ($element->childNodes as $child) {
			$html .= $element->ownerDocument?->saveHTML($child) ?? '';
		}

		return trim($html);
	}

	private function build_inline_rewrite(WP_Post $post, string $block_name, string $action, string $content): string {
		$plain = trim(wp_strip_all_tags($content));
		if ($plain === '') {
			return $content;
		}

		$topic = trim(wp_strip_all_tags((string) $post->post_title));
		$sentences = preg_split('/(?<=[\.\!\?])\s+/u', $plain) ?: [];
		$sentences = array_values(array_filter(array_map('trim', $sentences)));
		$first = $sentences[0] ?? $plain;
		$second = $sentences[1] ?? '';

		if ($action === 'Humaniser') {
			$first = preg_replace('/\b(Par ailleurs|De plus|En conclusion|Le vrai point)\b/u', 'En pratique', $first) ?: $first;
			$first = preg_replace('/\bImaginez\b/u', 'Quand on le pose sur la table', $first) ?: $first;
			$extra = 'Au moment de servir, c est surtout la texture, la tenue et le petit detail de geste qui font la difference.';
			$plain = trim($first . ' ' . ($second !== '' ? $second . ' ' : '') . $extra);
		} elseif ($action === 'Ajouter un exemple') {
			$plain = trim($plain . ' En pratique, par exemple, si la preparation semble correcte mais manque de nettete au dressage, il suffit souvent d ajuster un seul detail concret plutot que de tout recommencer, parce que ce geste change tout de suite le resultat au service.');
		} else {
			$plain = trim($plain . ' En pratique, ce point change vraiment le resultat sur ' . mb_strtolower($topic) . ' : si l on prend le temps de le regler correctement, on gagne en regularite, en tenue et en lisibilite des gestes au moment de servir.');
		}

		if ($block_name === 'core/heading') {
			$plain = preg_replace('/[\.\!\?]+$/u', '', $plain) ?: $plain;
			return sanitize_text_field($plain);
		}

		if ($block_name === 'core/quote') {
			return wp_kses_post($plain);
		}

		return esc_html($plain);
	}

	private function build_inline_rewrite_note(string $action): string {
		return match ($action) {
			'Humaniser' => __('Bloc reecrit pour casser les tournures trop mecaniques et le rendre plus naturel.', 'discoops-ai-orchestrator'),
			'Ajouter un exemple' => __('Bloc reecrit avec un exemple concret supplementaire.', 'discoops-ai-orchestrator'),
			default => __('Bloc reecrit pour apporter plus de profondeur editoriale.', 'discoops-ai-orchestrator'),
		};
	}

	private function inject_internal_links_into_content(string $content, array $links): string {
		if ($content === '' || ! $links) {
			return $content;
		}

		$blocks = parse_blocks($content);
		if (! is_array($blocks) || ! $blocks) {
			return $content;
		}

		$link_items = [];
		foreach ($links as $link) {
			if (! is_array($link)) {
				continue;
			}
			$title = trim((string) ($link['title'] ?? ''));
			$url = esc_url_raw((string) ($link['url'] ?? ''));
			if ($title === '' || $url === '') {
				continue;
			}
			$target_post_id = url_to_postid($url);
			if ($target_post_id <= 0 || get_post_status($target_post_id) !== 'publish') {
				continue;
			}
			$link_items[] = [
				'title' => $title,
				'url' => get_permalink($target_post_id) ?: $url,
			];
		}

		if (! $link_items) {
			return $content;
		}

		$used_urls = [];
		$blocks = $this->inject_internal_links_into_blocks($blocks, $link_items, $used_urls);

		return serialize_blocks($blocks);
	}

	private function inject_internal_links_into_blocks(array $blocks, array &$link_items, array &$used_urls): array {
		foreach ($blocks as &$block) {
			if (! $link_items) {
				break;
			}

			if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$block['innerBlocks'] = $this->inject_internal_links_into_blocks($block['innerBlocks'], $link_items, $used_urls);
			}

			if (($block['blockName'] ?? '') !== 'core/paragraph') {
				continue;
			}

			$html = (string) ($block['innerHTML'] ?? '');
			if ($html === '') {
				continue;
			}

			$text = trim(wp_strip_all_tags($html));
			if (mb_strlen($text) < 70) {
				continue;
			}

			$selected_index = null;
			$rewritten_html = $html;

			foreach ($link_items as $index => $item) {
				if (in_array($item['url'], $used_urls, true)) {
					continue;
				}

				$candidate = $this->inject_single_internal_link($html, $item['title'], $item['url']);
				if ($candidate !== $html) {
					$rewritten_html = $candidate;
					$selected_index = $index;
					break;
				}
			}

			if ($selected_index === null) {
				continue;
			}

			$block['innerHTML'] = $rewritten_html;
			if (isset($block['attrs']) && is_array($block['attrs'])) {
				$block['attrs']['content'] = $rewritten_html;
			}
			$used_urls[] = $link_items[$selected_index]['url'];
			array_splice($link_items, $selected_index, 1);
		}
		unset($block);

		return $blocks;
	}

	private function collect_unlinked_internal_link_items(string $content, array $links): array {
		$remaining = [];
		foreach ($links as $link) {
			if (! is_array($link)) {
				continue;
			}
			$url = esc_url_raw((string) ($link['url'] ?? ''));
			if ($url === '') {
				continue;
			}
			if (stripos($content, 'href="' . $url . '"') === false && stripos($content, "href='" . $url . "'") === false) {
				$remaining[] = $link;
			}
		}

		return $remaining;
	}

	private function build_internal_link_anchor_text(string $title): string {
		$title = wp_strip_all_tags($title);
		$parts = preg_split('/[:\-\(\)\,\|]+/u', $title) ?: [$title];
		$anchor = trim((string) ($parts[0] ?? $title));
		if ($anchor === '') {
			$anchor = trim($title);
		}

		return $anchor;
	}

	private function inject_single_internal_link(string $html, string $title, string $url): string {
		$plain = wp_strip_all_tags($html);
		if ($plain === '' || $title === '' || $url === '') {
			return $html;
		}

		if (stripos($html, 'href="' . esc_url($url) . '"') !== false || stripos($html, "href='" . esc_url($url) . "'") !== false) {
			return $html;
		}

		$anchorText = $this->find_contextual_anchor_in_text($plain, $title);
		if ($anchorText === null || $anchorText === '') {
			$anchorText = $this->build_internal_link_anchor_text($title);
		}

		$needle = preg_quote($anchorText, '/');
		if ($anchorText !== '' && stripos($html, '<a ') === false && preg_match('/(?<![\pL\pN])' . $needle . '(?![\pL\pN])/iu', $plain)) {
			$link = '<a href="' . esc_url($url) . '">' . esc_html($anchorText) . '</a>';
			$updated = preg_replace('/(?<![\pL\pN])' . $needle . '(?![\pL\pN])/iu', $link, $html, 1);
			if (is_string($updated) && $updated !== '') {
				return $updated;
			}
		}

		$sentence = ' Dans le même esprit, voir aussi <a href="' . esc_url($url) . '">' . esc_html($anchorText) . '</a>.';
		return preg_replace('/<\/p>\s*$/i', $sentence . '</p>', $html, 1) ?: ($html . $sentence);
	}

	private function find_contextual_anchor_in_text(string $text, string $title): ?string {
		$text = trim(wp_strip_all_tags($text));
		$title = trim(wp_strip_all_tags($title));
		if ($text === '' || $title === '') {
			return null;
		}

		foreach ($this->build_internal_link_anchor_candidates($title) as $candidate) {
			$needle = preg_quote($candidate, '/');
			if (preg_match('/(?<![\pL\pN])(' . $needle . ')(?![\pL\pN])/iu', $text, $matches)) {
				$match = trim((string) ($matches[1] ?? ''));
				if ($match !== '') {
					return $match;
				}
			}
		}

		$title_tokens = $this->extract_internal_link_significant_tokens($title);
		if (! $title_tokens) {
			return null;
		}

		$sentences = preg_split('/(?<=[\.\!\?\:;])\s+/u', $text) ?: [$text];
		$sentences = array_values(array_filter(array_map('trim', $sentences)));
		$best_sentence = '';
		$best_score = 0;

		foreach ($sentences as $sentence) {
			$sentence_tokens = $this->extract_internal_link_significant_tokens($sentence);
			if (! $sentence_tokens) {
				continue;
			}

			$overlap = array_values(array_intersect($sentence_tokens, $title_tokens));
			$score = count($overlap);
			if ($score > $best_score) {
				$best_score = $score;
				$best_sentence = $sentence;
			}
		}

		if ($best_sentence !== '' && $best_score > 0) {
			$semantic_anchor = $this->build_semantic_anchor_from_sentence($best_sentence, $title_tokens);
			if ($semantic_anchor !== null && $semantic_anchor !== '') {
				return $semantic_anchor;
			}
		}

		return null;
	}

	private function build_internal_link_anchor_candidates(string $title): array {
		$title = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($title)) ?? '');
		if ($title === '') {
			return [];
		}

		$stopwords = [
			'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'et', 'ou', 'au', 'aux',
			'pour', 'avec', 'sans', 'sur', 'dans', 'en', 'a', 'à', 'qui', 'que', 'qu', 'ce',
			'cette', 'ces', 'son', 'sa', 'ses', 'vos', 'votre', 'nos', 'notre'
		];
		$candidates = [];
		$parts = preg_split('/[:\-\(\)\,\|]+/u', $title) ?: [$title];

		foreach ($parts as $part) {
			$part = trim(preg_replace('/\s+/u', ' ', (string) $part) ?? '');
			if ($part === '') {
				continue;
			}

			if (mb_strlen($part, 'UTF-8') >= 10) {
				$candidates[] = $part;
			}

			$words = preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
			$count = count($words);
			for ($size = min(5, $count); $size >= 2; $size--) {
				for ($offset = 0; $offset <= ($count - $size); $offset++) {
					$slice = array_slice($words, $offset, $size);
					$normalized = array_map(
						static fn(string $word): string => mb_strtolower(trim($word, " \t\n\r\0\x0B'’.,;:!?()[]{}"), 'UTF-8'),
						$slice
					);
					$significant = array_filter($normalized, static function (string $word) use ($stopwords): bool {
						return $word !== '' && mb_strlen($word, 'UTF-8') >= 4 && ! in_array($word, $stopwords, true);
					});
					if (count($significant) < ($size >= 4 ? 2 : 1)) {
						continue;
					}

					$candidate = trim(implode(' ', $slice));
					if (mb_strlen($candidate, 'UTF-8') >= 8) {
						$candidates[] = $candidate;
					}
				}
			}
		}

		$candidates = array_values(array_unique($candidates));
		usort($candidates, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
		return $candidates;
	}

	private function extract_internal_link_significant_tokens(string $text): array {
		$text = $this->normalize_internal_link_text($text);
		if ($text === '') {
			return [];
		}

		$stopwords = [
			'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'et', 'ou', 'au', 'aux',
			'pour', 'avec', 'sans', 'sur', 'dans', 'en', 'a', 'qui', 'que', 'qu', 'ce',
			'cette', 'ces', 'son', 'sa', 'ses', 'vos', 'votre', 'nos', 'notre', 'plus',
			'tres', 'tout', 'toute', 'tous', 'faire', 'fait', 'comme', 'mais', 'bien',
			'par', 'vers', 'entre', 'avant', 'apres', 'pendant', 'chez', 'leur', 'leurs'
		];

		$words = preg_split('/[^a-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$tokens = [];
		foreach ($words as $word) {
			if (mb_strlen($word, 'UTF-8') < 4) {
				continue;
			}
			if (in_array($word, $stopwords, true)) {
				continue;
			}
			$tokens[] = $word;
		}

		return array_values(array_unique($tokens));
	}

	private function normalize_internal_link_text(string $text): string {
		$text = remove_accents(wp_strip_all_tags($text));
		$text = mb_strtolower($text, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text) ?? '';
		return trim($text);
	}

	private function build_semantic_anchor_from_sentence(string $sentence, array $title_tokens): ?string {
		$sentence = trim($sentence);
		if ($sentence === '') {
			return null;
		}

		$words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$count = count($words);
		if ($count < 2) {
			return null;
		}

		$best_candidate = '';
		$best_score = 0;

		for ($size = min(6, $count); $size >= 2; $size--) {
			for ($offset = 0; $offset <= ($count - $size); $offset++) {
				$slice = array_slice($words, $offset, $size);
				$candidate = trim(implode(' ', $slice), " \t\n\r\0\x0B,;:!?()[]{}\"'");
				if ($candidate === '' || mb_strlen($candidate, 'UTF-8') < 10) {
					continue;
				}

				$candidate_tokens = $this->extract_internal_link_significant_tokens($candidate);
				if (! $candidate_tokens) {
					continue;
				}

				$overlap = array_values(array_intersect($candidate_tokens, $title_tokens));
				if (! $overlap) {
					continue;
				}

				$score = (count($overlap) * 10) + min(mb_strlen($candidate, 'UTF-8'), 60);
				if ($score > $best_score) {
					$best_score = $score;
					$best_candidate = $candidate;
				}
			}
		}

		if ($best_candidate === '') {
			return null;
		}

		return trim($best_candidate, " \t\n\r\0\x0B,;:!?()[]{}\"'");
	}

	private function serialize_post(WP_Post $post): array {
		return [
			'id'      => $post->ID,
			'type'    => $post->post_type,
			'status'  => $post->post_status,
			'title'   => get_the_title($post),
			'slug'    => $post->post_name,
			'link'    => get_permalink($post) ?: '',
			'excerpt' => $post->post_excerpt,
			'content' => $post->post_content,
			'featured_media' => (int) get_post_thumbnail_id($post->ID),
			'featured_image' => get_the_post_thumbnail_url($post->ID, 'full') ?: null,
			'taxonomies' => $this->get_taxonomies_payload($post),
			'meta'    => get_post_meta($post->ID),
			'existing_draft' => $this->find_existing_draft($post->ID),
		];
	}

	private function get_taxonomies_payload(WP_Post $post): array {
		$payload = [];
		$taxonomies = get_object_taxonomies($post->post_type, 'names');

		foreach ($taxonomies as $taxonomy) {
			$payload[$taxonomy] = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);
		}

		return $payload;
	}

	private function find_existing_draft(int $source_post_id): ?array {
		$drafts = get_posts([
			'post_type'   => 'any',
			'post_status' => ['draft', 'pending'],
			'meta_key'    => '_discoops_source_post_id',
			'meta_value'  => $source_post_id,
			'numberposts' => 1,
		]);

		if (! isset($drafts[0]) || ! $drafts[0] instanceof WP_Post) {
			return null;
		}

		return [
			'id' => $drafts[0]->ID,
			'status' => $drafts[0]->post_status,
			'title' => get_the_title($drafts[0]),
		];
	}

	private function assign_taxonomies(int $post_id, array $taxonomies, ?WP_Post $source_post): void {
		$taxonomies_to_apply = $taxonomies;
		if ($source_post instanceof WP_Post && $taxonomies_to_apply === []) {
			$taxonomies_to_apply = $this->get_taxonomies_payload($source_post);
		}

		foreach ($taxonomies_to_apply as $taxonomy => $term_ids) {
			if (taxonomy_exists((string) $taxonomy) && is_array($term_ids)) {
				wp_set_object_terms($post_id, array_map('absint', $term_ids), (string) $taxonomy, false);
			}
		}
	}
}
