<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class Admin {
	private function ui(string $french, string $english): string {
		return I18n::ui($french, $english);
	}

	private function locked_feature_message(string $capability): string {
		$plan = $this->gate->required_plan_for_capability($capability);
		return $this->ui(
			sprintf('Disponible à partir du plan %s.', $plan),
			sprintf('Available from the %s plan.', $plan)
		);
	}

	private function upgrade_cta_label(string $capability): string {
		$plan = $this->gate->required_plan_for_capability($capability);
		return $this->ui(
			sprintf('Passer au plan %s', $plan),
			sprintf('Upgrade to %s', $plan)
		);
	}

	private function upgrade_badge(string $capability): string {
		return $this->gate->required_plan_for_capability($capability);
	}

	public function __construct(
		private readonly Jobs $jobs,
		private readonly ReviewWorkflow $reviews,
		private readonly Settings $settings,
		private readonly PolicyCompliance $policy,
		private readonly SubscriptionGate $gate
	) {
	}

	public function register(): void {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('add_meta_boxes', [$this, 'register_editor_metabox']);
		add_action('save_post_post', [$this, 'save_editor_meta'], 10, 2);
		add_action('admin_notices', [$this, 'render_admin_notices']);
		add_action('admin_post_discoops_ai_approve_review', [$this, 'handle_approve_review']);
		add_action('admin_post_discoops_ai_reject_review', [$this, 'handle_reject_review']);
		add_action('admin_post_discoops_ai_enqueue_post', [$this, 'handle_enqueue_post']);
		add_action('admin_post_discoops_ai_rework_post', [$this, 'handle_rework_post']);
		add_action('admin_post_discoops_ai_apply_source', [$this, 'handle_apply_source']);
		add_action('admin_post_discoops_ai_set_job_priority', [$this, 'handle_set_job_priority']);
		add_filter('post_row_actions', [$this, 'add_post_row_actions'], 10, 2);
		add_filter('get_user_option_meta-box-order_post', [$this, 'prioritize_editor_metabox'], 10, 3);
		add_filter('manage_post_posts_columns', [$this, 'add_post_columns']);
		add_action('manage_post_posts_custom_column', [$this, 'render_post_column'], 10, 2);
		add_action('restrict_manage_posts', [$this, 'render_post_filters']);
		add_action('pre_get_posts', [$this, 'apply_post_filters']);
	}

	public function register_menu(): void {
		add_menu_page('Discoops – AI Discover Tools to Dominate Discover Rankings', 'Discoops AI', 'manage_options', 'discoops-ai', [$this, 'render_dashboard']);
		add_submenu_page('discoops-ai', 'Dashboard', 'Dashboard', 'manage_options', 'discoops-ai', [$this, 'render_dashboard']);
		add_submenu_page('discoops-ai', 'Jobs', 'Jobs', 'manage_options', 'discoops-ai-jobs', [$this, 'render_jobs']);
		add_submenu_page('discoops-ai', 'Reviews', 'Reviews', 'manage_options', 'discoops-ai-reviews', [$this, 'render_reviews']);
		add_submenu_page('discoops-ai', 'Signals', 'Signals', 'manage_options', 'discoops-ai-signals', [$this, 'render_signals']);
		add_submenu_page('discoops-ai', 'Settings', 'Settings', 'manage_options', 'discoops-ai-settings', [$this, 'render_settings']);
	}

	public function enqueue_assets(string $hook): void {
		$is_discoops_screen = strpos($hook, 'discoops-ai') !== false;
		$is_post_editor = in_array($hook, ['post.php', 'post-new.php'], true);

		if (! $is_discoops_screen && ! $is_post_editor) {
			return;
		}

		$admin_css_version = file_exists(DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/assets/admin.css') ? (string) filemtime(DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/assets/admin.css') : DISCOOPS_AI_ORCHESTRATOR_VERSION;
		$admin_js_version = file_exists(DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/assets/admin.js') ? (string) filemtime(DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/assets/admin.js') : DISCOOPS_AI_ORCHESTRATOR_VERSION;
		wp_enqueue_style('discoops-ai-admin', DISCOOPS_AI_ORCHESTRATOR_URL . 'admin/assets/admin.css', [], $admin_css_version);
		wp_enqueue_script('discoops-ai-admin', DISCOOPS_AI_ORCHESTRATOR_URL . 'admin/assets/admin.js', ['jquery'], $admin_js_version, true);

		wp_localize_script(
			'discoops-ai-admin',
			'discoopsAiAdmin',
			[
				'restUrl' => esc_url_raw(rest_url('discoops-ai/v1')),
				'restNonce' => wp_create_nonce('wp_rest'),
				'screen' => $hook,
				'labels' => [
					'dashboardScanStart' => __('Lancer l analyse des contenus', 'discoops-ai-orchestrator'),
					'dashboardScanRunning' => __('Analyse en cours...', 'discoops-ai-orchestrator'),
					'dashboardScanDone' => __('Analyse terminee', 'discoops-ai-orchestrator'),
					'dashboardScanIdle' => __('Aucun scan en cours', 'discoops-ai-orchestrator'),
					'dashboardScanProgress' => __('Progression', 'discoops-ai-orchestrator'),
					'contents' => __('contenus', 'discoops-ai-orchestrator'),
					'viewArticles' => __('Voir les articles', 'discoops-ai-orchestrator'),
					'hideArticles' => __('Masquer les articles', 'discoops-ai-orchestrator'),
					'noClickbaitArticles' => __('Aucun article clickbait dans le dernier snapshot.', 'discoops-ai-orchestrator'),
					'edit' => __('Modifier', 'discoops-ai-orchestrator'),
					'view' => __('Voir', 'discoops-ai-orchestrator'),
				],
			]
		);

		if ($is_post_editor) {
			wp_localize_script(
				'discoops-ai-admin',
				'discoopsAiEditor',
				[
					'restUrl' => esc_url_raw(rest_url('discoops-ai/v1')),
					'restNonce' => wp_create_nonce('wp_rest'),
					'labels' => [
						'scoreLabel' => __("Score d'audit live", 'discoops-ai-orchestrator'),
						'scoreExcellent' => __('Excellent', 'discoops-ai-orchestrator'),
						'scoreStrong' => __('Solide', 'discoops-ai-orchestrator'),
						'scoreAverage' => __('A renforcer', 'discoops-ai-orchestrator'),
						'scoreWeak' => __('Fragile', 'discoops-ai-orchestrator'),
						'contentLength' => __('Longueur de contenu', 'discoops-ai-orchestrator'),
						'headings' => __('Intertitres', 'discoops-ai-orchestrator'),
						'excerpt' => __('Extrait', 'discoops-ai-orchestrator'),
						'focusKeyword' => __('Mot-cle principal', 'discoops-ai-orchestrator'),
						'seoTitle' => __('Titre SEO', 'discoops-ai-orchestrator'),
						'seoDescription' => __('Meta description', 'discoops-ai-orchestrator'),
						'internalLinks' => __('Liens internes', 'discoops-ai-orchestrator'),
						'googlePolicies' => __('Règles Google', 'discoops-ai-orchestrator'),
						'lists' => __('Listes / FAQ', 'discoops-ai-orchestrator'),
						'missing' => __('A travailler', 'discoops-ai-orchestrator'),
						'good' => __('Bien en place', 'discoops-ai-orchestrator'),
						'great' => __('Tres bon niveau', 'discoops-ai-orchestrator'),
						'wordUnit' => __('mots', 'discoops-ai-orchestrator'),
						'charUnit' => __('caracteres', 'discoops-ai-orchestrator'),
						'sectionsHint' => __('Ajoutez plus de sections claires', 'discoops-ai-orchestrator'),
						'excerptHint' => __('Visez un extrait court mais complet', 'discoops-ai-orchestrator'),
						'focusHint' => __('Ajoutez un mot-cle principal', 'discoops-ai-orchestrator'),
						'seoTitleHint' => __('Ajoutez un titre SEO', 'discoops-ai-orchestrator'),
						'seoDescriptionHint' => __('Ajoutez une meta description', 'discoops-ai-orchestrator'),
						'linksHint' => __('Ajoutez quelques liens internes', 'discoops-ai-orchestrator'),
						'listsHint' => __('Ajoutez une liste ou une FAQ', 'discoops-ai-orchestrator'),
						'structureReady' => __('Structure enrichie', 'discoops-ai-orchestrator'),
						'linksFound' => __('liens trouves', 'discoops-ai-orchestrator'),
						'sectionsFound' => __('intertitres', 'discoops-ai-orchestrator'),
						'usefulParagraphs' => __('paragraphes utiles', 'discoops-ai-orchestrator'),
						'jobRunning' => __('En cours', 'discoops-ai-orchestrator'),
						'jobFailed' => __('Echoue', 'discoops-ai-orchestrator'),
						'jobDone' => __('Termine', 'discoops-ai-orchestrator'),
						'jobQueued' => __('En file', 'discoops-ai-orchestrator'),
						'inlineImprove' => __('Approfondir', 'discoops-ai-orchestrator'),
						'inlineExample' => __('Ajouter un exemple', 'discoops-ai-orchestrator'),
						'inlineHumanize' => __('Humaniser', 'discoops-ai-orchestrator'),
						'inlineOk' => __('RAS', 'discoops-ai-orchestrator'),
						'insertInternalLinks' => __('Ajouter automatiquement les liens', 'discoops-ai-orchestrator'),
						'internalLinksInserted' => __('Liens internes ajoutes automatiquement dans le contenu.', 'discoops-ai-orchestrator'),
						'internalLinksInsertFailed' => __('Impossible d ajouter automatiquement les liens internes.', 'discoops-ai-orchestrator'),
						'paragraphs' => __('Paragraphes', 'discoops-ai-orchestrator'),
						'editorialRhythm' => __('Rythme éditorial', 'discoops-ai-orchestrator'),
						'discoverTitle' => __('Titre Discover', 'discoops-ai-orchestrator'),
						'needsMoreMaterial' => __('Le texte a besoin de plus de matiere', 'discoops-ai-orchestrator'),
						'variedOpenings' => __('Le texte varie bien ses ouvertures', 'discoops-ai-orchestrator'),
						'varyOpenings' => __('Variez davantage les ouvertures et transitions', 'discoops-ai-orchestrator'),
						'clearDiscoverTitle' => __('Titre clair et assez distinctif', 'discoops-ai-orchestrator'),
						'avoidClickbaitTitle' => __('Évitez le titre trop générique ou trop sensationnaliste', 'discoops-ai-orchestrator'),
						'policyGoodHint' => __('Le contenu reste globalement cohérent avec les signaux helpful et anti-spam', 'discoops-ai-orchestrator'),
						'policyWarnHint' => __('Renforcez les signaux people-first et retirez tout marqueur trop template ou trop clickbait', 'discoops-ai-orchestrator'),
						'blockSolidDetailed' => __('Bloc solide et assez detaille', 'discoops-ai-orchestrator'),
						'blockTooShort' => __('Bloc trop court : ajoutez une consequence pratique ou un exemple', 'discoops-ai-orchestrator'),
						'blockTooAbstract' => __('Bloc encore un peu abstrait : ajoutez un exemple ou un geste concret', 'discoops-ai-orchestrator'),
						'blockTooAi' => __('Bloc lisible, mais trop proche de formulations IA recurrentes', 'discoops-ai-orchestrator'),
						'headingTooShort' => __('Intertitre un peu court : rendez-le plus specifique ou plus informatif', 'discoops-ai-orchestrator'),
						'headingCouldPromiseMore' => __('Intertitre correct, mais il peut annoncer plus clairement la promesse de la section', 'discoops-ai-orchestrator'),
						'headingGood' => __('Intertitre clair, utile et bien calibre pour introduire la section', 'discoops-ai-orchestrator'),
						'blockLabel' => __('Bloc', 'discoops-ai-orchestrator'),
						'headingLabel' => __('Intertitre', 'discoops-ai-orchestrator'),
						'textLabel' => __('Texte', 'discoops-ai-orchestrator'),
						'alreadyDetailed' => __('Les paragraphes principaux ont deja un niveau de detail correct', 'discoops-ai-orchestrator'),
						'richEnough' => __('Bien en place', 'discoops-ai-orchestrator'),
						'enrichBlock' => __('A enrichir', 'discoops-ai-orchestrator'),
						'makeMoreConcrete' => __('Plus concret', 'discoops-ai-orchestrator'),
						'tooShortParagraph' => __('Trop court : ajoutez un exemple concret ou une consequence pratique', 'discoops-ai-orchestrator'),
						'makeParagraphConcrete' => __('Ajoutez un pourquoi, un geste concret ou une consequence visible', 'discoops-ai-orchestrator'),
						'viewArticles' => __('Voir les articles', 'discoops-ai-orchestrator'),
						'hideArticles' => __('Masquer les articles', 'discoops-ai-orchestrator'),
						'noClickbaitArticles' => __('Aucun article clickbait dans le dernier snapshot.', 'discoops-ai-orchestrator'),
						'edit' => __('Modifier', 'discoops-ai-orchestrator'),
						'view' => __('Voir', 'discoops-ai-orchestrator'),
						'ready' => __('Pret', 'discoops-ai-orchestrator'),
						'inactive' => __('Inactif', 'discoops-ai-orchestrator'),
						'contents' => __('contenus', 'discoops-ai-orchestrator'),
						'jobType' => __('Type', 'discoops-ai-orchestrator'),
						'priority' => __('Priorite', 'discoops-ai-orchestrator'),
						'updatedAt' => __('Mis a jour', 'discoops-ai-orchestrator'),
					],
				]
			);

			wp_add_inline_script(
				'discoops-ai-admin',
				'window.discoopsAiEditor = Object.assign({}, window.discoopsAiEditor || {}, ' . wp_json_encode($this->editor_ui_payload()) . ');',
				'after'
			);
		}
	}

	private function editor_ui_payload(): array {
		return [
			'uiLang' => I18n::language(),
			'accessLevel' => $this->gate->access_level(),
			'canRework' => $this->gate->has_capability(SubscriptionGate::CAP_REWORK),
			'canAutoInternalLinks' => $this->gate->has_capability(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS),
			'canSimpleCollaboration' => $this->gate->has_capability(SubscriptionGate::CAP_SIMPLE_COLLABORATION),
			'canApplyToSource' => $this->gate->has_capability(SubscriptionGate::CAP_APPLY_TO_SOURCE),
			'canAdvancedCollaboration' => $this->gate->has_capability(SubscriptionGate::CAP_ADVANCED_COLLABORATION),
			'billingUrl' => 'https://www.discoops.com/?r=billing',
			'labels' => [
				'scoreLabel' => $this->ui("Score d'audit live", 'Live audit score'),
				'scoreExcellent' => $this->ui('Excellent', 'Excellent'),
				'scoreStrong' => $this->ui('Solide', 'Strong'),
				'scoreAverage' => $this->ui('À renforcer', 'Needs work'),
				'scoreWeak' => $this->ui('Fragile', 'Weak'),
				'contentLength' => $this->ui('Longueur de contenu', 'Content length'),
				'headings' => $this->ui('Intertitres', 'Headings'),
				'excerpt' => $this->ui('Extrait', 'Excerpt'),
				'focusKeyword' => $this->ui('Mot-clé principal', 'Primary keyword'),
				'seoTitle' => $this->ui('Titre SEO', 'SEO title'),
				'seoDescription' => $this->ui('Meta description', 'Meta description'),
				'internalLinks' => $this->ui('Liens internes', 'Internal links'),
				'googlePolicies' => $this->ui('Google policies', 'Google policies'),
				'lists' => $this->ui('Listes / FAQ', 'Lists / FAQ'),
				'missing' => $this->ui('À travailler', 'Needs work'),
				'good' => $this->ui('Bien en place', 'In place'),
				'great' => $this->ui('Très bon niveau', 'Very good'),
				'wordUnit' => $this->ui('mots', 'words'),
				'charUnit' => $this->ui('caractères', 'characters'),
				'sectionsHint' => $this->ui('Ajoutez plus de sections claires', 'Add more clear sections'),
				'excerptHint' => $this->ui('Visez un extrait court mais complet', 'Aim for a short but complete excerpt'),
				'focusHint' => $this->ui('Ajoutez un mot-clé principal', 'Add a primary keyword'),
				'seoTitleHint' => $this->ui('Ajoutez un titre SEO', 'Add an SEO title'),
				'seoDescriptionHint' => $this->ui('Ajoutez une meta description', 'Add a meta description'),
				'linksHint' => $this->ui('Ajoutez quelques liens internes', 'Add a few internal links'),
				'listsHint' => $this->ui('Ajoutez une liste ou une FAQ', 'Add a list or FAQ'),
				'structureReady' => $this->ui('Structure enrichie', 'Enhanced structure'),
				'linksFound' => $this->ui('liens trouvés', 'links found'),
				'sectionsFound' => $this->ui('intertitres', 'headings'),
				'usefulParagraphs' => $this->ui('paragraphes utiles', 'useful paragraphs'),
				'jobRunning' => $this->ui('En cours', 'Running'),
				'jobFailed' => $this->ui('Échoué', 'Failed'),
				'jobDone' => $this->ui('Terminé', 'Completed'),
				'jobQueued' => $this->ui('En file', 'Queued'),
				'inlineImprove' => $this->ui('Approfondir', 'Deepen'),
				'inlineExample' => $this->ui('Ajouter un exemple', 'Add an example'),
				'inlineHumanize' => $this->ui('Humaniser', 'Humanize'),
				'inlineOk' => $this->ui('RAS', 'OK'),
				'insertInternalLinks' => $this->ui('Ajouter automatiquement les liens', 'Add links automatically'),
				'internalLinksInserted' => $this->ui('Liens internes ajoutés automatiquement dans le contenu.', 'Internal links were added automatically to the content.'),
				'internalLinksInsertFailed' => $this->ui("Impossible d'ajouter automatiquement les liens internes.", 'Unable to add internal links automatically.'),
				'paragraphs' => $this->ui('Paragraphes', 'Paragraphs'),
				'editorialRhythm' => $this->ui('Rythme éditorial', 'Editorial rhythm'),
				'discoverTitle' => $this->ui('Titre Discover', 'Discover title'),
				'needsMoreMaterial' => $this->ui('Le texte a besoin de plus de matière', 'The text needs more material'),
				'variedOpenings' => $this->ui('Le texte varie bien ses ouvertures', 'The text varies its openings well'),
				'varyOpenings' => $this->ui('Variez davantage les ouvertures et transitions', 'Vary openings and transitions more'),
				'clearDiscoverTitle' => $this->ui('Titre clair et assez distinctif', 'Clear and distinctive title'),
				'avoidClickbaitTitle' => $this->ui('Évitez le titre trop générique ou trop sensationnaliste', 'Avoid titles that are too generic or sensationalist'),
				'policyGoodHint' => $this->ui('Le contenu reste globalement cohérent avec les signaux helpful et anti-spam', 'The content remains aligned with helpful and anti-spam signals'),
				'policyWarnHint' => $this->ui('Renforcez les signaux people-first et retirez tout marqueur trop template ou trop clickbait', 'Strengthen people-first signals and remove templated or clickbait markers'),
				'blockSolidDetailed' => $this->ui('Bloc solide et assez détaillé', 'Solid and detailed block'),
				'blockTooShort' => $this->ui('Bloc trop court : ajoutez une conséquence pratique ou un exemple', 'Block too short: add a practical consequence or an example'),
				'blockTooAbstract' => $this->ui('Bloc encore un peu abstrait : ajoutez un exemple ou un geste concret', 'Block still feels abstract: add an example or a concrete action'),
				'blockTooAi' => $this->ui('Bloc lisible, mais trop proche de formulations IA récurrentes', 'Readable block, but still too close to recurring AI phrasing'),
				'headingTooShort' => $this->ui('Intertitre un peu court : rendez-le plus spécifique ou plus informatif', 'Heading is a bit short: make it more specific or informative'),
				'headingCouldPromiseMore' => $this->ui("Intertitre correct, mais il peut annoncer plus clairement la promesse de la section", 'Decent heading, but it could signal the section promise more clearly'),
				'headingGood' => $this->ui('Intertitre clair, utile et bien calibré pour introduire la section', 'Clear, useful heading that introduces the section well'),
				'blockLabel' => $this->ui('Bloc', 'Block'),
				'headingLabel' => $this->ui('Intertitre', 'Heading'),
				'textLabel' => $this->ui('Texte', 'Text'),
				'alreadyDetailed' => $this->ui('Les paragraphes principaux ont déjà un niveau de détail correct', 'Main paragraphs already have a good level of detail'),
				'richEnough' => $this->ui('Bien en place', 'In place'),
				'enrichBlock' => $this->ui('À enrichir', 'Needs enrichment'),
				'makeMoreConcrete' => $this->ui('Plus concret', 'More concrete'),
				'tooShortParagraph' => $this->ui('Trop court : ajoutez un exemple concret ou une conséquence pratique', 'Too short: add a concrete example or a practical outcome'),
				'makeParagraphConcrete' => $this->ui('Ajoutez un pourquoi, un geste concret ou une conséquence visible', 'Add a why, a concrete action or a visible outcome'),
				'viewArticles' => $this->ui('Voir les articles', 'View articles'),
				'hideArticles' => $this->ui('Masquer les articles', 'Hide articles'),
				'noClickbaitArticles' => $this->ui('Aucun article clickbait dans le dernier snapshot.', 'No clickbait article in the latest snapshot.'),
				'edit' => $this->ui('Modifier', 'Edit'),
				'view' => $this->ui('Voir', 'View'),
				'ready' => $this->ui('Prêt', 'Ready'),
				'inactive' => $this->ui('Inactif', 'Inactive'),
				'contents' => $this->ui('contenus', 'contents'),
				'jobType' => $this->ui('Type', 'Type'),
				'priority' => $this->ui('Priorité', 'Priority'),
				'updatedAt' => $this->ui('Mis à jour', 'Updated at'),
			],
		];
	}

	public function register_editor_metabox(string $post_type): void {
		if ($post_type !== 'post' || ! $this->gate->is_allowed()) {
			return;
		}

		add_meta_box(
			'discoops-ai-editor-guidance',
			__('Discoops – AI Discover Tools to Dominate Discover Rankings', 'discoops-ai-orchestrator'),
			[$this, 'render_editor_metabox'],
			'post',
			'normal',
			'high'
		);
	}

	public function prioritize_editor_metabox(mixed $result, string $option, mixed $user): mixed {
		unset($option, $user);

		$order = is_array($result) ? $result : [];
		$normal = isset($order['normal']) ? explode(',', (string) $order['normal']) : [];
		$normal = array_values(array_filter(array_map('trim', $normal)));
		$normal = array_values(array_unique(array_merge(['discoops-ai-editor-guidance'], $normal)));
		$order['normal'] = implode(',', $normal);

		return $order;
	}

	public function render_editor_metabox(\WP_Post $post): void {
		$can_rework = $this->gate->has_capability(SubscriptionGate::CAP_REWORK);
		$can_auto_internal_links = $this->gate->has_capability(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS);
		$can_apply_to_source = $this->gate->has_capability(SubscriptionGate::CAP_APPLY_TO_SOURCE);
		$can_simple_collaboration = $this->gate->has_capability(SubscriptionGate::CAP_SIMPLE_COLLABORATION);
		$growth_label = $this->gate->required_plan_for_capability(SubscriptionGate::CAP_REWORK);
		$agency_label = $this->gate->required_plan_for_capability(SubscriptionGate::CAP_ADVANCED_COLLABORATION);

		$audit_score = get_post_meta($post->ID, '_discoops_audit_score', true);
		$signal_score = get_post_meta($post->ID, '_discoops_last_signal_score', true);
		$summary = (string) get_post_meta($post->ID, '_discoops_revision_summary', true);
		if ($summary === '') {
			$summary = (string) get_post_meta($post->ID, '_discoops_generated_summary', true);
		}
		$summary = $this->normalize_display_text($summary);

		$review_plan = json_decode((string) get_post_meta($post->ID, '_discoops_ai_revision_plan', true), true);
		$issue_codes = json_decode((string) get_post_meta($post->ID, '_discoops_issue_codes', true), true);
		$full_issues = json_decode((string) get_post_meta($post->ID, '_discoops_quality_issues', true), true);
		$recommendations = json_decode((string) get_post_meta($post->ID, '_discoops_quality_recommendations', true), true);
		$full_steps = json_decode((string) get_post_meta($post->ID, '_discoops_quality_steps', true), true);
		$opportunity_type = (string) get_post_meta($post->ID, '_discoops_opportunity_type', true);
		$source_post_id = (int) get_post_meta($post->ID, '_discoops_source_post_id', true);
		$focus_keyword = (string) get_post_meta($post->ID, '_discoops_focus_keyword', true);
		$seo_title = (string) get_post_meta($post->ID, '_discoops_seo_title', true);
		$seo_description = (string) get_post_meta($post->ID, '_discoops_seo_description', true);
		$policy_score = (int) round((float) get_post_meta($post->ID, '_discoops_policy_score', true));
		$policy_flags = decode_json_array((string) get_post_meta($post->ID, '_discoops_policy_flags', true));
		$status = (string) get_post_meta($post->ID, '_discoops_ai_status', true);
		$review_status = (string) get_post_meta($post->ID, '_discoops_ai_review_status', true);
		$last_job_id = (int) get_post_meta($post->ID, '_discoops_ai_last_job_id', true);

		$issues = [];
		if (is_array($full_issues)) {
			$issues = array_values(array_filter(array_map([$this, 'normalize_display_text'], array_map('strval', $full_issues))));
		}
		if (! $issues && is_array($issue_codes)) {
			$issues = array_values(array_filter(array_map([$this, 'describe_issue_code'], $issue_codes)));
		}
		if (! $issues && is_array($review_plan['issues'] ?? null)) {
			$issues = array_values(array_filter(array_map([$this, 'normalize_display_text'], array_map('strval', $review_plan['issues']))));
		}

		$actions = [];
		if (is_array($recommendations)) {
			$actions = array_values(array_filter(array_map([$this, 'normalize_display_text'], array_map('strval', $recommendations))));
		}
		if (! $actions && is_array($review_plan['recommendedActions'] ?? null)) {
			$actions = array_values(array_filter(array_map([$this, 'normalize_display_text'], array_map('strval', $review_plan['recommendedActions']))));
		}

		$steps = [];
		if (is_array($full_steps)) {
			$steps = array_values(array_filter(array_map([$this, 'localize_ui_text'], array_map('strval', $full_steps))));
		}
		if (! $steps && is_array($review_plan['steps'] ?? null)) {
			$steps = array_values(array_filter(array_map([$this, 'translate_review_step'], array_map('strval', $review_plan['steps']))));
		}

		$initial_audit = $audit_score !== '' ? (int) round((float) $audit_score) : 0;
		$initial_signal = $signal_score !== '' ? (float) $signal_score : null;
		$focus_keyword = $this->normalize_display_text($focus_keyword);
		$seo_title = $this->normalize_display_text($seo_title);
		$seo_description = $this->normalize_display_text($seo_description);
		$post_text = $this->normalize_display_text(wp_strip_all_tags((string) $post->post_content));
		$source_post = $source_post_id > 0 ? get_post($source_post_id) : null;
		$source_excerpt = $source_post instanceof \WP_Post ? $this->normalize_display_text((string) $source_post->post_excerpt) : '';
		$source_title = $source_post instanceof \WP_Post ? $this->normalize_display_text((string) $source_post->post_title) : '';
		$editor_notes = (string) get_post_meta($post->ID, '_discoops_editor_notes', true);
		$editor_comments = decode_json_array((string) get_post_meta($post->ID, '_discoops_editor_comments', true));
		$review_assignee = (int) get_post_meta($post->ID, '_discoops_review_assignee', true);
		$intro_variants = $this->build_intro_variants($post);
		$faq_suggestions = $this->build_faq_suggestions($post);
		$internal_link_suggestions = $this->build_internal_link_suggestions($post);
		$internal_link_suggestion_items = $this->build_internal_link_suggestion_items($post);
		$missing_sections = $this->build_missing_sections($post);
		$concrete_proofs = $this->build_concrete_proof_ideas($post);
		$ai_flags = $this->detect_ai_phrases($post_text);
		$insight_scores = $this->build_editor_scores($post, $post_text, $focus_keyword, $seo_title, $seo_description);
		$discover_preview = $this->build_discover_preview($post, $seo_title, $seo_description);
		$media_audit = $this->build_media_audit($post);
		$eeat_items = $this->build_eeat_items($post, $post_text);
		$cannibalization = $this->build_cannibalization_findings($post);
		$policy_report = $this->policy->evaluate($post);
		if ($policy_score <= 0) {
			$policy_score = (int) ($policy_report['score'] ?? 0);
		}
		if (! $policy_flags) {
			$policy_flags = array_values(array_map('strval', (array) ($policy_report['flags'] ?? [])));
		}
		$history = $this->load_refresh_history($post->ID, $source_post_id);
		$job_timeline = $this->load_job_timeline($last_job_id);
		$intro_variants = array_values(array_filter(array_map([$this, 'normalize_display_text'], $intro_variants)));
		$faq_suggestions = array_values(array_filter(array_map([$this, 'normalize_display_text'], $faq_suggestions)));
		$internal_link_suggestions = array_values(array_filter(array_map([$this, 'normalize_display_text'], $internal_link_suggestions)));
		$missing_sections = array_values(array_filter(array_map([$this, 'normalize_display_text'], $missing_sections)));
		$concrete_proofs = array_values(array_filter(array_map([$this, 'normalize_display_text'], $concrete_proofs)));
		$ai_flags = array_values(array_filter(array_map([$this, 'normalize_display_text'], $ai_flags)));
		$job_timeline = array_values(array_filter(array_map([$this, 'localize_ui_text'], $job_timeline)));
		$insight_scores = $this->localize_ui_rows($insight_scores);
		$media_audit['items'] = $this->localize_ui_rows((array) ($media_audit['items'] ?? []));
		$media_audit['alt'] = $this->localize_ui_text((string) ($media_audit['alt'] ?? ''));
		$media_audit['caption'] = $this->localize_ui_text((string) ($media_audit['caption'] ?? ''));
		$eeat_items = $this->localize_ui_rows($eeat_items);
		$cannibalization['items'] = $this->localize_ui_rows((array) ($cannibalization['items'] ?? []));
		$cannibalization['posts'] = array_values(array_filter(array_map([$this, 'localize_ui_text'], (array) ($cannibalization['posts'] ?? []))));
		$review_assignees = get_users([
			'orderby' => 'display_name',
			'order' => 'ASC',
			'fields' => ['ID', 'display_name', 'user_email'],
		]);

		echo '<div class="discoops-ai-editor" data-discoops-editor data-post-id="' . esc_attr((string) $post->ID) . '" data-initial-audit="' . esc_attr((string) $initial_audit) . '" data-last-job-id="' . esc_attr((string) $last_job_id) . '" data-internal-link-suggestions="' . esc_attr(wp_json_encode($internal_link_suggestion_items)) . '">';
		wp_nonce_field('discoops_ai_editor_meta_' . $post->ID, 'discoops_ai_editor_meta_nonce');
		echo '<div class="discoops-ai-editor__hero">';
		echo '<div class="discoops-ai-editor__eyebrow">' . esc_html($this->ui('Cockpit éditorial Discoops', 'Discoops editorial cockpit')) . '</div>';
		echo '<div class="discoops-ai-editor__hero-row">';
		echo '<div class="discoops-ai-editor__hero-copy">';
		echo '<div class="discoops-ai-editor__brand">';
		echo '<img class="discoops-ai-editor__brand-icon" src="' . esc_url('https://www.discoops.com/assets/favicon/favicon-96x96.png') . '" alt="' . esc_attr__('Discoops', 'discoops-ai-orchestrator') . '" />';
		echo '<div class="discoops-ai-editor__brand-copy">';
		echo '<h3 class="discoops-ai-editor__title">' . esc_html__('Discoops AI', 'discoops-ai-orchestrator') . '</h3>';
		echo '<p class="discoops-ai-editor__subtitle">' . esc_html($this->ui('Recommandations, signaux qualité et score live pendant que vous éditez.', 'Recommendations, quality signals and live score while you edit.')) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div class="discoops-ai-editor__chips">';
		if ($opportunity_type !== '') {
				printf('<span class="discoops-ai-chip">%s</span>', esc_html($this->localize_ui_text($this->humanize_opportunity_type($opportunity_type))));
		}
		if ($initial_signal !== null) {
				printf('<span class="discoops-ai-chip discoops-ai-chip--blue">%s %s</span>', esc_html($this->ui('Signal', 'Signal')), esc_html(number_format($initial_signal, 2, '.', '')));
		}
		if ($source_post_id > 0) {
				printf('<span class="discoops-ai-chip discoops-ai-chip--slate">%s #%d</span>', esc_html($this->ui('Article source', 'Source post')), $source_post_id);
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="discoops-ai-layout">';
		echo '<div class="discoops-ai-main">';
		echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Synthèse', 'Summary')) . '</span></div>';
			if ($status !== '' || $review_status !== '') {
				echo '<div class="discoops-ai-kpis">';
			if ($status !== '') {
				printf('<div class="discoops-ai-kpi"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('Statut', 'Status')), esc_html($this->localize_ui_text($this->humanize_ai_status($status))));
			}
			if ($review_status !== '') {
			printf('<div class="discoops-ai-kpi"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('Review', 'Review')), esc_html($this->localize_ui_text($this->humanize_review_status($review_status))));
			}
			echo '</div>';
		}
		if ($summary !== '') {
			printf('<p class="discoops-ai-summary">%s</p>', esc_html($summary));
		}
		echo '<div class="discoops-ai-editor__chips">';
		foreach ([
			'intro' => ['label' => 'Relancer intro', 'icon' => 'dashicons-edit-page'],
			'title' => ['label' => 'Relancer titre', 'icon' => 'dashicons-editor-textcolor'],
			'faq' => ['label' => 'Relancer FAQ', 'icon' => 'dashicons-editor-help'],
			'internal_links' => ['label' => 'Relancer maillage', 'icon' => 'dashicons-admin-links'],
		] as $scope => $button) {
			if ($can_rework) {
				$url = wp_nonce_url(admin_url('admin-post.php?action=discoops_ai_rework_post&post_id=' . $post->ID . '&scope=' . rawurlencode($scope)), 'discoops_ai_rework_post_' . $post->ID . '_' . $scope);
				printf(
					'<a class="discoops-ai-chip discoops-ai-chip--slate" href="%s"><span class="dashicons %s discoops-ai-chip__icon" aria-hidden="true"></span><span>%s</span></a>',
					esc_url($url),
					esc_attr($button['icon']),
					esc_html__($button['label'], 'discoops-ai-orchestrator')
				);
			} else {
				printf(
					'<span class="discoops-ai-chip discoops-ai-chip--slate discoops-ai-chip--locked"><span class="dashicons %s discoops-ai-chip__icon" aria-hidden="true"></span><span>%s</span><small>%s</small></span>',
					esc_attr($button['icon']),
					esc_html__($button['label'], 'discoops-ai-orchestrator'),
					esc_html($growth_label)
				);
			}
		}
		if ($source_post_id > 0 && $can_apply_to_source) {
			$url = wp_nonce_url(admin_url('admin-post.php?action=discoops_ai_apply_source&post_id=' . $post->ID), 'discoops_ai_apply_source_' . $post->ID);
			printf(
				'<a class="discoops-ai-chip" href="%s"><span class="dashicons dashicons-yes-alt discoops-ai-chip__icon" aria-hidden="true"></span><span>%s</span></a>',
				esc_url($url),
				esc_html__('Appliquer au post source', 'discoops-ai-orchestrator')
			);
		} elseif ($source_post_id > 0) {
			printf(
				'<span class="discoops-ai-chip discoops-ai-chip--locked"><span class="dashicons dashicons-yes-alt discoops-ai-chip__icon" aria-hidden="true"></span><span>%s</span><small>%s</small></span>',
				esc_html__('Appliquer au post source', 'discoops-ai-orchestrator'),
				esc_html($this->gate->required_plan_for_capability(SubscriptionGate::CAP_APPLY_TO_SOURCE))
			);
		}
		echo '</div>';
		if (! $can_rework) {
			echo '<div class="discoops-ai-upgrade-note">';
			echo '<span class="discoops-ai-upgrade-note__badge">' . esc_html($growth_label) . '</span>';
			echo '<span>' . esc_html($this->locked_feature_message(SubscriptionGate::CAP_REWORK)) . '</span>';
			echo '<a class="discoops-ai-upgrade-note__link" href="https://www.discoops.com/?r=billing" target="_blank" rel="noreferrer noopener">' . esc_html($this->upgrade_cta_label(SubscriptionGate::CAP_REWORK)) . '</a>';
			echo '</div>';
		}
		if ($audit_score === '' && $signal_score === '' && $summary === '' && ! $issues && ! $actions && ! $steps) {
			echo '<p class="discoops-ai-empty">' . esc_html__('Aucune recommandation Discoops disponible pour cet article pour le moment.', 'discoops-ai-orchestrator') . '</p>';
		}
		echo '</section>';
		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Scores éditoriaux', 'Editorial scores')) . '</span></div>';
		echo '<div class="discoops-ai-seo-grid">';
		foreach ($insight_scores as $card) {
			printf(
				'<div class="discoops-ai-seo-item"><span>%s</span><strong>%s / 100</strong><div class="discoops-ai-live-check__hint">%s</div></div>',
				esc_html($card['label']),
				esc_html((string) $card['score']),
				esc_html($card['hint'])
			);
		}
		echo '</div></section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Score par bloc', 'Block score')) . '</span><small>' . esc_html($this->ui('Analyse bloc par bloc du contenu en cours.', 'Block-by-block analysis of the current content.')) . '</small></div>';
		echo '<div class="discoops-ai-live-checks" data-discoops-block-scores></div>';
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Comparaison avant / après', 'Before / after comparison')) . '</span></div>';
		echo '<div class="discoops-ai-seo-grid">';
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong><div class="discoops-ai-live-check__hint">%s</div></div>', esc_html($this->ui('Source', 'Source')), esc_html($source_title !== '' ? $source_title : $post->post_title), esc_html($source_excerpt !== '' ? $source_excerpt : $this->ui('Aucun extrait source disponible.', 'No source excerpt available.')));
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong><div class="discoops-ai-live-check__hint">%s</div></div>', esc_html($this->ui('Draft actuel', 'Current draft')), esc_html($post->post_title), esc_html($summary !== '' ? $summary : $this->normalize_display_text((string) $post->post_excerpt)));
		echo '</div></section>';

		echo '<div class="discoops-ai-grid discoops-ai-grid--stack">';
		if ($issues) {
			echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Points à corriger', 'Points to fix')) . '</span></div>';
			echo '<ul class="discoops-ai-list">';
			foreach ($issues as $issue) {
				printf('<li>%s</li>', esc_html($issue));
			}
			echo '</ul></section>';
		}

		if ($actions) {
			echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Recommandations', 'Recommendations')) . '</span></div>';
			echo '<ul class="discoops-ai-list discoops-ai-list--accent">';
			foreach ($actions as $action) {
				printf('<li>%s</li>', esc_html($action));
			}
			echo '</ul></section>';
		}

		if ($steps) {
			echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Plan de vérification', 'Verification plan')) . '</span></div>';
			echo '<ol class="discoops-ai-list discoops-ai-list--ordered">';
			foreach ($steps as $step) {
				printf('<li>%s</li>', esc_html($step));
			}
			echo '</ol></section>';
		}

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Suggestions inline par paragraphe', 'Inline suggestions by paragraph')) . '</span><small>' . esc_html($this->ui('Zones du texte à enrichir ou à rendre plus concrètes.', 'Areas of the text to enrich or make more concrete.')) . '</small></div>';
		echo '<div class="discoops-ai-live-checks" data-discoops-inline-suggestions></div>';
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Détecteur de ton IA', 'AI tone detector')) . '</span></div>';
		if ($ai_flags) {
			echo '<ul class="discoops-ai-list">';
			foreach ($ai_flags as $flag) {
				printf('<li>%s</li>', esc_html($flag));
			}
			echo '</ul>';
		} else {
			echo '<p class="discoops-ai-empty">' . esc_html($this->ui('Aucune tournure fortement mécanique détectée pour le moment.', 'No strongly mechanical phrasing detected right now.')) . '</p>';
		}
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Idées et enrichissements', 'Ideas and enrichments')) . '</span></div>';
		echo '<div class="discoops-ai-seo-grid">';
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('Variantes d’intro', 'Intro variants')), esc_html(implode(' | ', $intro_variants)));
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('FAQ naturelles', 'Natural FAQs')), esc_html(implode(' | ', $faq_suggestions)));
		echo '<div class="discoops-ai-seo-item">';
		echo '<span>' . esc_html($this->ui('Liens internes suggérés', 'Suggested internal links')) . '</span>';
		echo '<strong>' . esc_html(implode(' | ', $internal_link_suggestions)) . '</strong>';
		if ($internal_link_suggestion_items) {
			if ($can_auto_internal_links) {
				echo '<button type="button" class="button button-secondary discoops-ai-action-button" data-discoops-auto-links>' . esc_html__('Ajouter automatiquement les liens', 'discoops-ai-orchestrator') . '</button>';
			} else {
				echo '<button type="button" class="button button-secondary discoops-ai-action-button" disabled>' . esc_html__('Ajouter automatiquement les liens', 'discoops-ai-orchestrator') . '</button>';
				echo '<div class="discoops-ai-upgrade-note discoops-ai-upgrade-note--compact">';
				echo '<span class="discoops-ai-upgrade-note__badge">' . esc_html($this->upgrade_badge(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS)) . '</span>';
				echo '<span>' . esc_html($this->locked_feature_message(SubscriptionGate::CAP_AUTO_INTERNAL_LINKS)) . '</span>';
				echo '</div>';
			}
		}
		echo '</div>';
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('Sections manquantes', 'Missing sections')), esc_html(implode(' | ', $missing_sections)));
		printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html($this->ui('Preuves concrètes', 'Concrete proof ideas')), esc_html(implode(' | ', $concrete_proofs)));
		echo '</div></section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Collaboration', 'Collaboration')) . '</span></div>';
		if ($can_simple_collaboration) {
			if (! $this->gate->has_capability(SubscriptionGate::CAP_ADVANCED_COLLABORATION)) {
				echo '<div class="discoops-ai-upgrade-note discoops-ai-upgrade-note--compact">';
				echo '<span class="discoops-ai-upgrade-note__badge">' . esc_html($agency_label) . '</span>';
				echo '<span>' . esc_html($this->ui('Le plan Agency débloque la collaboration avancée et les workflows premium.', 'The Agency plan unlocks advanced collaboration and premium workflows.')) . '</span>';
				echo '</div>';
			}
			echo '<div class="discoops-ai-seo-grid">';
			echo '<div class="discoops-ai-seo-item"><span>' . esc_html($this->ui('Commentaires éditoriaux', 'Editorial notes')) . '</span><textarea name="discoops_editor_notes" rows="5" style="width:100%;margin-top:8px;">' . esc_textarea($editor_notes) . '</textarea></div>';
			echo '<div class="discoops-ai-seo-item"><span>' . esc_html($this->ui('Assignation review', 'Review assignment')) . '</span><select name="discoops_review_assignee" style="width:100%;margin-top:8px;"><option value="0">' . esc_html($this->ui('Non assigné', 'Unassigned')) . '</option>';
			foreach ($review_assignees as $user) {
				$label = trim((string) ($user->display_name ?: $user->user_email));
				printf('<option value="%d"%s>%s</option>', (int) $user->ID, selected($review_assignee, (int) $user->ID, false), esc_html($label));
			}
			echo '</select></div>';
			echo '</div>';
			echo '<div class="discoops-ai-seo-grid" style="margin-top:12px;">';
			echo '<div class="discoops-ai-seo-item"><span>' . esc_html($this->ui('Ajouter un commentaire', 'Add a comment')) . '</span><textarea name="discoops_editor_comment_message" rows="4" placeholder="' . esc_attr($this->ui('Ajouter une note de contexte, une demande de rework ou une décision éditoriale...', 'Add context, a rework request or an editorial decision...')) . '"></textarea></div>';
			echo '<div class="discoops-ai-seo-item"><span>' . esc_html($this->ui('Visibilité du commentaire', 'Comment visibility')) . '</span><select name="discoops_editor_comment_visibility"><option value="internal">' . esc_html($this->ui('Interne', 'Internal')) . '</option><option value="editorial">' . esc_html($this->ui('Éditorial', 'Editorial')) . '</option><option value="review">' . esc_html($this->ui('Review', 'Review')) . '</option></select></div>';
			echo '</div>';
		} else {
			echo '<div class="discoops-ai-upgrade-note">';
			echo '<span class="discoops-ai-upgrade-note__badge">' . esc_html($this->upgrade_badge(SubscriptionGate::CAP_SIMPLE_COLLABORATION)) . '</span>';
			echo '<span>' . esc_html($this->locked_feature_message(SubscriptionGate::CAP_SIMPLE_COLLABORATION)) . '</span>';
			echo '<a class="discoops-ai-upgrade-note__link" href="https://www.discoops.com/?r=billing" target="_blank" rel="noreferrer noopener">' . esc_html($this->upgrade_cta_label(SubscriptionGate::CAP_SIMPLE_COLLABORATION)) . '</a>';
			echo '</div>';
		}
		if ($editor_comments) {
			echo '<div class="discoops-ai-panel__header" style="margin-top:16px;"><span>' . esc_html($this->ui('Fil éditorial', 'Editorial thread')) . '</span></div>';
			echo '<ul class="discoops-ai-list">';
			foreach ($editor_comments as $comment_row) {
				$author_name = $this->normalize_display_text((string) ($comment_row['author'] ?? 'Discoops'));
				$visibility = $this->normalize_display_text((string) ($comment_row['visibility'] ?? 'internal'));
				$message = $this->normalize_display_text((string) ($comment_row['message'] ?? ''));
				$created_at = $this->normalize_display_text((string) ($comment_row['created_at'] ?? ''));
				if ($message === '') {
					continue;
				}
				printf('<li><strong>%s</strong> [%s] - %s<br><span style="color:#64748b;">%s</span></li>', esc_html($author_name), esc_html($visibility), esc_html($message), esc_html($created_at));
			}
			echo '</ul>';
		}
		if ($history) {
			echo '<div class="discoops-ai-panel__header" style="margin-top:16px;"><span>' . esc_html($this->ui('Historique des refreshs & décisions', 'Refresh and decision history')) . '</span></div>';
			echo '<ul class="discoops-ai-list">';
			foreach ($history as $row) {
				printf('<li>%s</li>', esc_html($row));
			}
			echo '</ul>';
		}
		echo '</section>';
		echo '</div>';
		echo '</div>';

		echo '<aside class="discoops-ai-sidebar">';
		echo '<section class="discoops-ai-score-card" data-discoops-score-card>';
		echo '<div class="discoops-ai-score-card__label">' . esc_html($this->ui("Score d'audit live", 'Live audit score')) . '</div>';
		echo '<div class="discoops-ai-score-card__ring" data-discoops-score-ring>';
		echo '<div class="discoops-ai-score-card__value-wrap">';
		echo '<div class="discoops-ai-score-card__value" data-discoops-score-value>' . esc_html((string) $initial_audit) . '</div>';
		echo '<div class="discoops-ai-score-card__unit">/ 100</div>';
		echo '</div>';
		echo '</div>';
		echo '<div class="discoops-ai-score-card__status" data-discoops-score-status>' . esc_html($this->score_label($initial_audit)) . '</div>';
		echo '</section>';
		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Checklist live', 'Checklist live')) . '</span><small>' . esc_html($this->ui("Mise à jour pendant l'édition", 'Updated while editing')) . '</small></div>';
		echo '<div class="discoops-ai-live-checks" data-discoops-live-checks></div>';
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Statut temps réel du job MCP', 'Live MCP job status')) . '</span></div>';
		echo '<div class="discoops-ai-live-checks" data-discoops-job-status>';
		if ($last_job_id > 0) {
			printf('<div class="discoops-ai-live-check is-good"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_html(sprintf(__('Job #%d', 'discoops-ai-orchestrator'), $last_job_id)), esc_html__('Le plugin va verifier automatiquement son evolution.', 'discoops-ai-orchestrator'), esc_html__('Suivi actif', 'discoops-ai-orchestrator'));
		} else {
			printf('<div class="discoops-ai-live-check is-warn"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_html__('Aucun job recent', 'discoops-ai-orchestrator'), esc_html__('Aucun workflow MCP recent n est rattache a ce contenu.', 'discoops-ai-orchestrator'), esc_html__('Inactif', 'discoops-ai-orchestrator'));
		}
		echo '</div></section>';
		if ($job_timeline) {
			echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Timeline du job', 'Job timeline')) . '</span></div>';
			echo '<ul class="discoops-ai-list">';
			foreach ($job_timeline as $timeline_row) {
				printf('<li>%s</li>', esc_html($timeline_row));
			}
			echo '</ul></section>';
		}

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Préparation Discover', 'Discover readiness')) . '</span></div>';
		echo '<div class="discoops-ai-live-checks">';
		foreach ($this->localize_ui_rows($this->build_discover_readiness_items($post, $insight_scores, $media_audit)) as $item) {
			printf('<div class="discoops-ai-live-check is-%s"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_attr($item['state']), esc_html($item['title']), esc_html($item['hint']), esc_html($item['badge']));
		}
		echo '</div></section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Règles Google Search & Discover', 'Google Search & Discover policies')) . '</span><small>' . esc_html(sprintf($this->ui('Score policy : %d / 100', 'Policy score: %d / 100'), $policy_score)) . '</small></div>';
		echo '<div class="discoops-ai-live-checks">';
		foreach ($this->localize_ui_rows((array) ($policy_report['items'] ?? [])) as $item) {
			printf(
				'<div class="discoops-ai-live-check is-%s"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>',
				esc_attr((string) ($item['state'] ?? 'warn')),
				esc_html((string) ($item['title'] ?? '')),
				esc_html((string) ($item['hint'] ?? '')),
				esc_html((string) ($item['badge'] ?? ''))
			);
		}
		echo '</div>';
		if ($policy_flags) {
			echo '<ul class="discoops-ai-list" style="margin-top:14px;">';
			foreach ($policy_flags as $flag) {
				printf('<li>%s</li>', esc_html($this->localize_ui_text((string) $flag)));
			}
			echo '</ul>';
		}
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Score E-E-A-T visible', 'Visible E-E-A-T score')) . '</span></div>';
		echo '<div class="discoops-ai-live-checks">';
		foreach ($eeat_items as $item) {
			printf('<div class="discoops-ai-live-check is-%s"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_attr($item['state']), esc_html($item['title']), esc_html($item['hint']), esc_html($item['badge']));
		}
		echo '</div></section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html__('Apercu mobile Discover', 'discoops-ai-orchestrator') . '</span></div>';
		echo '<div class="discoops-ai-discover-preview">';
		if ($discover_preview['image'] !== '') {
			printf('<img src="%s" alt="" class="discoops-ai-discover-preview__image" />', esc_url($discover_preview['image']));
		}
		printf('<div class="discoops-ai-discover-preview__title">%s</div>', esc_html($discover_preview['title']));
		printf('<div class="discoops-ai-discover-preview__excerpt">%s</div>', esc_html($discover_preview['excerpt']));
		echo '</div></section>';

		if ($focus_keyword !== '' || $seo_title !== '' || $seo_description !== '') {
			echo '<section class="discoops-ai-panel">';
			echo '<div class="discoops-ai-panel__header"><span>' . esc_html__('Reperes SEO', 'discoops-ai-orchestrator') . '</span></div>';
			echo '<div class="discoops-ai-seo-grid">';
			if ($focus_keyword !== '') {
				printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html__('Mot-cle', 'discoops-ai-orchestrator'), esc_html($focus_keyword));
			}
			if ($seo_title !== '') {
				printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html__('Titre SEO', 'discoops-ai-orchestrator'), esc_html($seo_title));
			}
			if ($seo_description !== '') {
				printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html__('Meta description', 'discoops-ai-orchestrator'), esc_html($seo_description));
			}
			echo '</div></section>';
		}

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Media et image principale', 'Media and main image')) . '</span></div>';
		echo '<div class="discoops-ai-live-checks">';
		foreach ($media_audit['items'] as $item) {
			printf('<div class="discoops-ai-live-check is-%s"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_attr($item['state']), esc_html($item['title']), esc_html($item['hint']), esc_html($item['badge']));
		}
		if ($media_audit['caption'] !== '' || $media_audit['alt'] !== '') {
			echo '<div class="discoops-ai-seo-grid">';
			if ($media_audit['caption'] !== '') {
				printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html__('Suggestion de legende', 'discoops-ai-orchestrator'), esc_html($media_audit['caption']));
			}
			if ($media_audit['alt'] !== '') {
				printf('<div class="discoops-ai-seo-item"><span>%s</span><strong>%s</strong></div>', esc_html__('Suggestion de alt', 'discoops-ai-orchestrator'), esc_html($media_audit['alt']));
			}
			echo '</div>';
		}
		echo '</section>';

		echo '<section class="discoops-ai-panel">';
		echo '<div class="discoops-ai-panel__header"><span>' . esc_html($this->ui('Cannibalisation avant publication', 'Cannibalization before publication')) . '</span></div>';
		echo '<div class="discoops-ai-live-checks">';
		foreach ($cannibalization['items'] as $item) {
			printf('<div class="discoops-ai-live-check is-%s"><div class="discoops-ai-live-check__meta"><div class="discoops-ai-live-check__title">%s</div><div class="discoops-ai-live-check__hint">%s</div></div><div class="discoops-ai-live-check__badge">%s</div></div>', esc_attr($item['state']), esc_html($item['title']), esc_html($item['hint']), esc_html($item['badge']));
		}
		echo '</div>';
		if ($cannibalization['posts']) {
			echo '<ul class="discoops-ai-list" style="margin-top:14px;">';
			foreach ($cannibalization['posts'] as $related_post) {
				printf('<li>%s</li>', esc_html($related_post));
			}
			echo '</ul>';
		}
		echo '</section>';
		echo '</aside>';
		echo '</div>';

		echo '<div class="discoops-ai-grid discoops-ai-grid--stack">';
		echo '</div>';
		echo '</div>';
	}

	private function score_label(int $score): string {
		if ($score >= 85) {
			return __('Excellent', 'discoops-ai-orchestrator');
		}
		if ($score >= 70) {
			return __('Solide', 'discoops-ai-orchestrator');
		}
		if ($score >= 55) {
			return __('A renforcer', 'discoops-ai-orchestrator');
		}

		return __('Fragile', 'discoops-ai-orchestrator');
	}

	private function normalize_display_text(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace_callback(
			'/\\\\u([0-9a-fA-F]{4})|(?<!\\\\)u([0-9a-fA-F]{4})/',
			static function (array $matches): string {
				$hex = $matches[1] !== '' ? $matches[1] : $matches[2];
				return html_entity_decode('&#x' . $hex . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
			},
			$text
		) ?? $text;

		$text = str_replace(
			['â€™', 'â€˜', 'â€œ', 'â€', 'â€“', 'â€”', 'â€¦', "\r"],
			["'", "'", '"', '"', '-', '-', '...', ''],
			$text
		);

		$text = preg_replace('/\s+/u', ' ', $text) ?? $text;
		return trim($text);
	}

	private function localize_ui_text(string $text): string {
		$text = $this->normalize_display_text($text);
		if ($text === '') {
			return '';
		}

		if (I18n::language() === 'en') {
			if (preg_match('/^Image detectee \((\d+) x (\d+)\)\.$/i', $text, $matches) === 1) {
				return sprintf('Image detected (%d x %d).', (int) $matches[1], (int) $matches[2]);
			}
			if (preg_match('/^Cree le (.+), mis a jour le (.+)$/i', $text, $matches) === 1) {
				return sprintf('Created on %s, updated on %s', $matches[1], $matches[2]);
			}
		}

		return $this->normalize_display_text(I18n::map($text));
	}

	private function localize_ui_rows(array $rows): array {
		return array_map(function (array $row): array {
			foreach (['label', 'title', 'hint', 'badge', 'value'] as $key) {
				if (isset($row[$key]) && is_string($row[$key])) {
					$row[$key] = $this->localize_ui_text($row[$key]);
				}
			}
			return $row;
		}, $rows);
	}

	private function humanize_issue_code(string $code): string {
		$map = [
			'high_ai_risk' => __('Signal IA eleve', 'discoops-ai-orchestrator'),
			'templating_visible' => __('Traces de templating visibles', 'discoops-ai-orchestrator'),
			'image_preview_missing' => __('Grand apercu image absent', 'discoops-ai-orchestrator'),
			'image_missing' => __('Image OG manquante', 'discoops-ai-orchestrator'),
			'image_illustration' => __('Mention "Image Illustration" visible', 'discoops-ai-orchestrator'),
			'ai_tone_homogeneous' => __('Ton trop genere / homogene', 'discoops-ai-orchestrator'),
			'transparency_missing' => __('Auteur ou date incomplets', 'discoops-ai-orchestrator'),
			'expertise_missing' => __('Expertise insuffisante', 'discoops-ai-orchestrator'),
			'originality_missing' => __('Originalite insuffisante', 'discoops-ai-orchestrator'),
			'clickbait_risk' => __('Risque de titre trop sensationnaliste', 'discoops-ai-orchestrator'),
			'low_quality_signals' => __('Qualite editoriale fragile', 'discoops-ai-orchestrator'),
		];

		return $map[$code] ?? $code;
	}

	private function describe_issue_code(string $code): string {
		$map = [
			'high_ai_risk' => "Le texte garde encore des signaux IA trop visibles dans ses formulations et son rythme.",
			'templating_visible' => "La structure ou certaines tournures paraissent trop templatees et doivent etre davantage naturalisees.",
			'image_preview_missing' => "Le contenu manque d un grand apercu image fort pour mieux performer dans Discover.",
			'image_missing' => "L article doit afficher une image principale plus solide et mieux exploitee.",
			'image_illustration' => "La mention image illustration reste visible et doit disparaitre pour garder un rendu editorial plus credible.",
			'ai_tone_homogeneous' => "Le ton reste trop homogene et previsible, avec des formulations qui manquent de relief editorial.",
			'transparency_missing' => "Les signaux de transparence comme l auteur, la date ou le contexte de production doivent etre renforces.",
			'expertise_missing' => "Le contenu manque encore de preuves concretes d expertise, de test ou d observation utile.",
			'originality_missing' => "Le texte doit apporter plus d originalite visible, de detail concret et de valeur non interchangeable.",
			'clickbait_risk' => "Le titre ou certains passages s approchent trop d un angle sensationnaliste et doivent etre calmes.",
			'low_quality_signals' => "Plusieurs signaux editoriaux restent fragiles et necessitent un refresh qualitatif plus pousse.",
		];

		return $map[$code] ?? $this->humanize_issue_code($code);
	}

	private function translate_review_step(string $step): string {
		$normalized = strtolower($this->normalize_display_text($step));
		$map = [
			'validate the editorial angle against the source winner and current intent' => "Valider l'angle editorial par rapport a la source winner et a l'intention actuelle.",
			'review structure, freshness signals and practical examples' => "Verifier la structure, les signaux de fraicheur et les exemples pratiques.",
			'check seo title, meta description and internal links' => "Verifier le titre SEO, la meta description et les liens internes.",
			'approve or refine before publication' => "Approuver ou ajuster le draft avant publication.",
		];

		return $map[$normalized] ?? $this->normalize_display_text($step);
	}

	private function humanize_opportunity_type(string $type): string {
		$map = [
			'refresh_declining' => __('Refresh declinant', 'discoops-ai-orchestrator'),
			'variant_from_winner' => __('Variante depuis winner', 'discoops-ai-orchestrator'),
			'trend_topic' => __('Tendance du jour', 'discoops-ai-orchestrator'),
			'quality_refresh' => __('Refresh qualite', 'discoops-ai-orchestrator'),
			'manual_refresh_from_wordpress' => __('Refresh manuel WordPress', 'discoops-ai-orchestrator'),
			'site_recovery_refresh' => __('Refresh recovery site', 'discoops-ai-orchestrator'),
		];

		return $map[$type] ?? $type;
	}

	private function humanize_ai_status(string $status): string {
		$map = [
			'in_review' => __('En review', 'discoops-ai-orchestrator'),
			'approved' => __('Approuvé', 'discoops-ai-orchestrator'),
			'rejected' => __('Rejeté', 'discoops-ai-orchestrator'),
			'applied_to_source' => __('Appliqué au post source', 'discoops-ai-orchestrator'),
			'queued' => __('En file', 'discoops-ai-orchestrator'),
			'processing' => __('En cours', 'discoops-ai-orchestrator'),
			'completed' => __('Terminé', 'discoops-ai-orchestrator'),
			'failed' => __('Échoué', 'discoops-ai-orchestrator'),
			'ignored' => __('Ignoré', 'discoops-ai-orchestrator'),
			'queued_rework' => __('Rework en file', 'discoops-ai-orchestrator'),
		];

		return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
	}

	private function humanize_review_status(string $status): string {
		$map = [
			'pending' => __('En attente', 'discoops-ai-orchestrator'),
			'approved' => __('Approuvé', 'discoops-ai-orchestrator'),
			'rejected' => __('Rejeté', 'discoops-ai-orchestrator'),
			'in_review' => __('En review', 'discoops-ai-orchestrator'),
		];

		return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
	}

	public function save_editor_meta(int $post_id, \WP_Post $post): void {
		if ($post->post_type !== 'post') {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! isset($_POST['discoops_ai_editor_meta_nonce']) || ! wp_verify_nonce((string) $_POST['discoops_ai_editor_meta_nonce'], 'discoops_ai_editor_meta_' . $post_id)) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$policy_report = $this->policy->evaluate($post);
		update_post_meta($post_id, '_discoops_policy_score', (int) ($policy_report['score'] ?? 0));
		update_post_meta($post_id, '_discoops_policy_flags', wp_json_encode(array_values(array_map('strval', (array) ($policy_report['flags'] ?? [])))));

		if ($this->gate->has_capability(SubscriptionGate::CAP_SIMPLE_COLLABORATION)) {
			update_post_meta($post_id, '_discoops_editor_notes', sanitize_textarea_field((string) ($_POST['discoops_editor_notes'] ?? '')));
			update_post_meta($post_id, '_discoops_review_assignee', absint($_POST['discoops_review_assignee'] ?? 0));

			$new_comment = sanitize_textarea_field((string) ($_POST['discoops_editor_comment_message'] ?? ''));
			if ($new_comment !== '') {
				$visibility = sanitize_key((string) ($_POST['discoops_editor_comment_visibility'] ?? 'internal'));
				$existing_comments = decode_json_array((string) get_post_meta($post_id, '_discoops_editor_comments', true));
				$existing_comments[] = [
					'author' => wp_get_current_user()->display_name ?: 'Discoops',
					'author_id' => get_current_user_id(),
					'visibility' => $visibility !== '' ? $visibility : 'internal',
					'message' => $new_comment,
					'created_at' => current_time('mysql'),
				];
				update_post_meta($post_id, '_discoops_editor_comments', wp_json_encode(array_slice($existing_comments, -25)));
			}
		}
	}

	public function add_post_columns(array $columns): array {
		$insert = [];
		foreach ($columns as $key => $label) {
			$insert[$key] = $label;
			if ($key === 'title') {
				$insert['discoops_audit'] = __('Audit Discoops', 'discoops-ai-orchestrator');
				$insert['discoops_state'] = __('Etat Discoops', 'discoops-ai-orchestrator');
				$insert['discoops_review'] = __('Review', 'discoops-ai-orchestrator');
			}
		}
		return $insert;
	}

	public function render_post_column(string $column, int $post_id): void {
		if ($column === 'discoops_audit') {
			$score = (int) round((float) get_post_meta($post_id, '_discoops_audit_score', true));
			echo esc_html($score > 0 ? $score . ' / 100' : '—');
			return;
		}
		if ($column === 'discoops_state') {
			$status = (string) get_post_meta($post_id, '_discoops_ai_status', true);
			$signal = (string) get_post_meta($post_id, '_discoops_last_signal_type', true);
			echo esc_html($status !== '' ? ucfirst(str_replace('_', ' ', $status)) : '—');
			if ($signal !== '') {
				echo '<br><span style="color:#64748b;">' . esc_html($signal) . '</span>';
			}
			return;
		}
		if ($column === 'discoops_review') {
			$review = (string) get_post_meta($post_id, '_discoops_ai_review_status', true);
			$type = (string) get_post_meta($post_id, '_discoops_opportunity_type', true);
			echo esc_html($review !== '' ? ucfirst($review) : '—');
			if ($type !== '') {
				echo '<br><span style="color:#64748b;">' . esc_html($this->humanize_opportunity_type($type)) . '</span>';
			}
		}
	}

	public function render_post_filters(): void {
		global $typenow;
		if ($typenow !== 'post') {
			return;
		}

		$current = sanitize_key((string) ($_GET['discoops_filter'] ?? ''));
		echo '<select name="discoops_filter">';
		echo '<option value="">' . esc_html__('Tous les statuts Discoops', 'discoops-ai-orchestrator') . '</option>';
		foreach ([
			'needs_refresh' => __('A rafraichir', 'discoops-ai-orchestrator'),
			'winner' => __('Winner / source forte', 'discoops-ai-orchestrator'),
			'ai_risk' => __('Risque IA', 'discoops-ai-orchestrator'),
			'review_pending' => __('Review en attente', 'discoops-ai-orchestrator'),
		] as $value => $label) {
			printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
		}
		echo '</select>';
	}

	public function apply_post_filters(\WP_Query $query): void {
		if (! is_admin() || ! $query->is_main_query()) {
			return;
		}
		if (($query->get('post_type') ?: 'post') !== 'post') {
			return;
		}

		$filter = sanitize_key((string) ($_GET['discoops_filter'] ?? ''));
		if ($filter === '') {
			return;
		}

		$meta_query = (array) $query->get('meta_query');
		switch ($filter) {
			case 'needs_refresh':
				$meta_query[] = ['key' => '_discoops_last_signal_type', 'value' => 'drop'];
				break;
			case 'winner':
				$meta_query[] = ['key' => '_discoops_opportunity_type', 'value' => ['variant_from_winner', 'refresh_declining'], 'compare' => 'IN'];
				break;
			case 'ai_risk':
				$meta_query[] = ['key' => '_discoops_issue_codes', 'value' => 'high_ai_risk', 'compare' => 'LIKE'];
				break;
			case 'review_pending':
				$meta_query[] = ['key' => '_discoops_ai_review_status', 'value' => 'pending'];
				break;
		}
		$query->set('meta_query', $meta_query);
	}

	private function build_editor_scores(\WP_Post $post, string $text, string $focusKeyword, string $seoTitle, string $seoDescription): array {
		$linkCount = substr_count((string) $post->post_content, '<a ');
		$excerpt = $this->normalize_display_text((string) $post->post_excerpt);
		$title = $this->normalize_display_text((string) $post->post_title);
		$intro = mb_substr($text, 0, 260);
		$introScore = mb_strlen($intro) > 180 ? 82 : 58;
		$titleScore = mb_strlen($title) >= 45 && mb_strlen($title) <= 95 ? 84 : 61;
		$excerptScore = mb_strlen($excerpt) >= 120 && mb_strlen($excerpt) <= 220 ? 86 : 54;
		$meshScore = $linkCount >= 3 ? 88 : ($linkCount >= 1 ? 71 : 42);
		$originalityScore = (int) max(35, min(95, 92 - (count($this->detect_ai_phrases($text)) * 7)));
		$discoverScore = (int) round(($introScore + $titleScore + $excerptScore + $meshScore + $originalityScore) / 5);
		$eeatScore = (int) max(40, min(95, 60 + ($focusKeyword !== '' ? 8 : 0) + ($seoTitle !== '' ? 6 : 0) + ($seoDescription !== '' ? 6 : 0)));
		$cannibalizationScore = (int) max(38, min(90, 82 - ($linkCount === 0 ? 16 : 0)));

		return [
			['label' => __('Intro', 'discoops-ai-orchestrator'), 'score' => $introScore, 'hint' => __('Capte l intention et pose un angle clair.', 'discoops-ai-orchestrator')],
			['label' => __('Titre', 'discoops-ai-orchestrator'), 'score' => $titleScore, 'hint' => __('Clarte, precision et promesse editoriale.', 'discoops-ai-orchestrator')],
			['label' => __('Extrait', 'discoops-ai-orchestrator'), 'score' => $excerptScore, 'hint' => __('Resume court, complet et orienté clic qualifie.', 'discoops-ai-orchestrator')],
			['label' => __('Maillage', 'discoops-ai-orchestrator'), 'score' => $meshScore, 'hint' => __('Liens internes utiles et contextuels.', 'discoops-ai-orchestrator')],
			['label' => __('Originalite', 'discoops-ai-orchestrator'), 'score' => $originalityScore, 'hint' => __('Valeur concrete, preuve et ton non interchangeable.', 'discoops-ai-orchestrator')],
			['label' => __('Discover', 'discoops-ai-orchestrator'), 'score' => $discoverScore, 'hint' => __('Potentiel de diffusion global sur Discover.', 'discoops-ai-orchestrator')],
			['label' => __('E-E-A-T', 'discoops-ai-orchestrator'), 'score' => $eeatScore, 'hint' => __('Expertise, transparence et credibilite.', 'discoops-ai-orchestrator')],
			['label' => __('Cannibalisation', 'discoops-ai-orchestrator'), 'score' => $cannibalizationScore, 'hint' => __('Risque de collision avec les autres contenus du site.', 'discoops-ai-orchestrator')],
		];
	}

	private function build_discover_preview(\WP_Post $post, string $seoTitle, string $seoDescription): array {
		$image = get_the_post_thumbnail_url($post, 'large') ?: '';
		$title = $seoTitle !== '' ? $seoTitle : $this->normalize_display_text((string) $post->post_title);
		$excerpt = $seoDescription !== '' ? $seoDescription : $this->normalize_display_text((string) $post->post_excerpt);
		return ['image' => $image, 'title' => $title, 'excerpt' => $excerpt];
	}

	private function build_media_audit(\WP_Post $post): array {
		$thumb_id = get_post_thumbnail_id($post);
		$meta = $thumb_id ? wp_get_attachment_metadata($thumb_id) : [];
		$width = (int) ($meta['width'] ?? 0);
		$height = (int) ($meta['height'] ?? 0);
		$alt = $thumb_id ? $this->normalize_display_text((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) : '';
		$caption = $thumb_id ? $this->normalize_display_text((string) wp_get_attachment_caption($thumb_id)) : '';
		$image_title = $thumb_id ? $this->normalize_display_text((string) get_the_title($thumb_id)) : '';
		$ratio = $height > 0 ? round($width / $height, 2) : 0.0;
		$illustrationFlag = preg_match('/\b(illustration|illustre|illustratif)\b/ui', implode(' ', [$alt, $caption, $image_title])) === 1;

		$items = [
			[
				'title' => __('Image principale', 'discoops-ai-orchestrator'),
				'hint' => $thumb_id ? sprintf(__('Image detectee (%d x %d).', 'discoops-ai-orchestrator'), $width, $height) : __('Aucune image principale detectee.', 'discoops-ai-orchestrator'),
				'state' => $thumb_id ? 'good' : 'warn',
				'badge' => $thumb_id ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
			[
				'title' => __('Ratio Discover', 'discoops-ai-orchestrator'),
				'hint' => $ratio > 1.6 ? __('Grand format compatible Discover.', 'discoops-ai-orchestrator') : __('Visez un visuel plus large pour Discover.', 'discoops-ai-orchestrator'),
				'state' => $ratio > 1.6 ? 'good' : 'warn',
				'badge' => $ratio > 1.6 ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
			[
				'title' => __('Qualite image', 'discoops-ai-orchestrator'),
				'hint' => $width >= 1200 ? __('Definition suffisante pour les grands apercus.', 'discoops-ai-orchestrator') : __('Une image 1200px+ est recommandee.', 'discoops-ai-orchestrator'),
				'state' => $width >= 1200 ? 'good' : 'warn',
				'badge' => $width >= 1200 ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
			[
				'title' => __('Photo credible', 'discoops-ai-orchestrator'),
				'hint' => $illustrationFlag ? __('La media semble presentee comme une illustration plutot qu une photo editoriale.', 'discoops-ai-orchestrator') : __('Le visuel ne porte pas de signal d illustration problematique.', 'discoops-ai-orchestrator'),
				'state' => $illustrationFlag ? 'warn' : 'good',
				'badge' => $illustrationFlag ? __('A retravailler', 'discoops-ai-orchestrator') : __('Bien en place', 'discoops-ai-orchestrator'),
			],
		];

		return [
			'items' => $items,
			'alt' => $alt !== '' ? $alt : __('Decrire clairement le plat final avec son contexte de service.', 'discoops-ai-orchestrator'),
			'caption' => $caption !== '' ? $caption : __('Photo du resultat final, mise en scene simple et credible.', 'discoops-ai-orchestrator'),
		];
	}

	private function build_discover_readiness_items(\WP_Post $post, array $scores, array $mediaAudit): array {
		$scoreMap = [];
		foreach ($scores as $row) {
			$scoreMap[(string) $row['label']] = (int) $row['score'];
		}
		return [
			['title' => __('Image large', 'discoops-ai-orchestrator'), 'hint' => $mediaAudit['items'][1]['hint'], 'state' => $mediaAudit['items'][1]['state'], 'badge' => $mediaAudit['items'][1]['badge']],
			['title' => __('Fraicheur', 'discoops-ai-orchestrator'), 'hint' => __('Le contenu doit montrer un angle actuel ou une remise a jour visible.', 'discoops-ai-orchestrator'), 'state' => 'good', 'badge' => __('A suivre', 'discoops-ai-orchestrator')],
			['title' => __('Originalite', 'discoops-ai-orchestrator'), 'hint' => __('Le texte doit apporter des gestes, erreurs ou details non interchangeables.', 'discoops-ai-orchestrator'), 'state' => (($scoreMap['Originalite'] ?? 0) >= 70 ? 'good' : 'warn'), 'badge' => (($scoreMap['Originalite'] ?? 0) >= 70 ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'))],
			['title' => __('People-first', 'discoops-ai-orchestrator'), 'hint' => __('Verifier que le texte aide vraiment le lecteur avant de chercher la performance.', 'discoops-ai-orchestrator'), 'state' => 'good', 'badge' => __('A suivre', 'discoops-ai-orchestrator')],
		];
	}

	private function build_eeat_items(\WP_Post $post, string $text): array {
		$author = get_the_author_meta('display_name', (int) $post->post_author);
		$hasDate = $post->post_date_gmt !== '0000-00-00 00:00:00';
		$hasConcreteSignals = preg_match('/\b(erreur|astuce|compar|test|essai|texture|cuisson|service)\b/ui', $text) === 1;
		$hasExplanation = preg_match('/\b(parce que|afin de|pour eviter|pour obtenir)\b/ui', $text) === 1;

		return [
			[
				'title' => __('Auteur et transparence', 'discoops-ai-orchestrator'),
				'hint' => $author !== '' && $hasDate ? __('Auteur et date visibles dans WordPress.', 'discoops-ai-orchestrator') : __('Renforcer les signaux auteur/date et transparence.', 'discoops-ai-orchestrator'),
				'state' => $author !== '' && $hasDate ? 'good' : 'warn',
				'badge' => $author !== '' && $hasDate ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
			[
				'title' => __('Expertise visible', 'discoops-ai-orchestrator'),
				'hint' => $hasConcreteSignals ? __('Le texte montre deja des gestes, erreurs ou details concrets.', 'discoops-ai-orchestrator') : __('Ajouter plus de preuves de maitrise et d observation pratique.', 'discoops-ai-orchestrator'),
				'state' => $hasConcreteSignals ? 'good' : 'warn',
				'badge' => $hasConcreteSignals ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
			[
				'title' => __('Why / How explicites', 'discoops-ai-orchestrator'),
				'hint' => $hasExplanation ? __('Le texte explique deja pourquoi les gestes comptent.', 'discoops-ai-orchestrator') : __('Ajouter davantage de pourquoi / comment dans les conseils.', 'discoops-ai-orchestrator'),
				'state' => $hasExplanation ? 'good' : 'warn',
				'badge' => $hasExplanation ? __('Bien en place', 'discoops-ai-orchestrator') : __('A travailler', 'discoops-ai-orchestrator'),
			],
		];
	}

	private function build_cannibalization_findings(\WP_Post $post): array {
		$keywords = array_values(array_filter(array_map(
			static fn(string $word): string => trim($word),
			preg_split('/[\s:;,!\?\-]+/u', strtolower($this->normalize_display_text((string) $post->post_title))) ?: []
		), static fn(string $word): bool => mb_strlen($word) >= 4));

		$matched = [];
		if ($keywords) {
			$query = new \WP_Query([
				'post_type' => 'post',
				'post_status' => ['publish', 'draft', 'pending', 'future'],
				'posts_per_page' => 6,
				'post__not_in' => [$post->ID],
				's' => implode(' ', array_slice($keywords, 0, 4)),
				'fields' => 'ids',
			]);

			foreach ((array) $query->posts as $candidate_id) {
				$title = $this->normalize_display_text((string) get_the_title((int) $candidate_id));
				if ($title === '') {
					continue;
				}
				$overlap = 0;
				foreach (array_slice($keywords, 0, 6) as $keyword) {
					if (str_contains(mb_strtolower($title), $keyword)) {
						$overlap++;
					}
				}
				if ($overlap >= 2) {
					$matched[] = sprintf('#%d - %s', (int) $candidate_id, $title);
				}
			}
			wp_reset_postdata();
		}

		$riskState = count($matched) >= 3 ? 'warn' : (count($matched) >= 1 ? 'good' : 'good');
		$riskBadge = count($matched) >= 3 ? __('A surveiller', 'discoops-ai-orchestrator') : __('Sous controle', 'discoops-ai-orchestrator');

		return [
			'items' => [
				[
					'title' => __('Risque de collision', 'discoops-ai-orchestrator'),
					'hint' => count($matched) >= 3
						? __('Plusieurs contenus proches existent deja sur ce sujet.', 'discoops-ai-orchestrator')
						: __('Le titre semble assez distinct pour eviter une collision forte.', 'discoops-ai-orchestrator'),
					'state' => $riskState,
					'badge' => $riskBadge,
				],
			],
			'posts' => array_slice($matched, 0, 4),
		];
	}

	private function detect_ai_phrases(string $text): array {
		$patterns = [
			'/\bimaginez\b/i' => __('L ouverture "Imaginez" reste souvent trop demonstrative.', 'discoops-ai-orchestrator'),
			'/\ben conclusion\b/i' => __('La transition "En conclusion" donne un ton trop scolaire.', 'discoops-ai-orchestrator'),
			'/\bde plus\b/i' => __('La liaison "De plus" peut paraitre trop mecanique si elle revient souvent.', 'discoops-ai-orchestrator'),
			'/\bpar ailleurs\b/i' => __('La liaison "Par ailleurs" peut sonner trop generique.', 'discoops-ai-orchestrator'),
			'/\ble vrai point\b/i' => __('La formule "Le vrai point" est une signature IA frequente.', 'discoops-ai-orchestrator'),
			'/\ble detail qui\b/i' => __('La formule "Le detail qui" revient souvent dans les textes trop artificiels.', 'discoops-ai-orchestrator'),
		];
		$flags = [];
		foreach ($patterns as $pattern => $message) {
			if (preg_match($pattern, $text)) {
				$flags[] = $message;
			}
		}
		return $flags;
	}

	private function build_intro_variants(\WP_Post $post): array {
		$title = $this->normalize_display_text((string) $post->post_title);
		return [
			sprintf(__('Version plus concrete autour de %s.', 'discoops-ai-orchestrator'), $title),
			sprintf(__('Ouverture plus sensorielle et directe pour %s.', 'discoops-ai-orchestrator'), $title),
			__('Angle plus utile et plus pratique des les premieres lignes.', 'discoops-ai-orchestrator'),
		];
	}

	private function build_faq_suggestions(\WP_Post $post): array {
		unset($post);
		return [
			__('Peut-on tout preparer a l avance ?', 'discoops-ai-orchestrator'),
			__('Quel est le geste qui change vraiment le resultat ?', 'discoops-ai-orchestrator'),
			__('Comment eviter une texture trop molle ou trop seche ?', 'discoops-ai-orchestrator'),
		];
	}

	private function build_internal_link_suggestions(\WP_Post $post): array {
		$items = $this->build_internal_link_suggestion_items($post);
		if ($items) {
			return array_map(
				static fn(array $item): string => sprintf(__('Lier vers #%d - %s', 'discoops-ai-orchestrator'), (int) ($item['id'] ?? 0), (string) ($item['title'] ?? '')),
				$items
			);
		}

		return [
			__('Ajouter un lien vers une recette cousine plus simple.', 'discoops-ai-orchestrator'),
			__('Ajouter un lien vers un article erreurs a eviter.', 'discoops-ai-orchestrator'),
			__('Ajouter un lien vers une idee de service ou d accompagnement.', 'discoops-ai-orchestrator'),
		];
	}

	private function build_internal_link_suggestion_items(\WP_Post $post): array {
		$category_ids = wp_get_post_categories($post->ID);
		$query = new \WP_Query([
			'post_type' => 'post',
			'post_status' => 'publish',
			'posts_per_page' => 3,
			'post__not_in' => [$post->ID],
			'category__in' => $category_ids ?: [],
			'fields' => 'ids',
		]);

		$suggestions = [];
		foreach ((array) $query->posts as $candidate_id) {
			$title = $this->normalize_display_text((string) get_the_title((int) $candidate_id));
			$url = get_permalink((int) $candidate_id) ?: '';
			if ($title !== '' && $url !== '') {
				$suggestions[] = [
					'id' => (int) $candidate_id,
					'title' => $title,
					'url' => esc_url_raw($url),
				];
			}
		}
		wp_reset_postdata();

		return $suggestions;
	}

	private function build_missing_sections(\WP_Post $post): array {
		$content = (string) $post->post_content;
		$suggestions = [];
		if (stripos($content, 'faq') === false) {
			$suggestions[] = __('FAQ naturelle', 'discoops-ai-orchestrator');
		}
		if (stripos($content, 'erreur') === false) {
			$suggestions[] = __('Erreurs frequentes', 'discoops-ai-orchestrator');
		}
		if (stripos($content, 'astuce') === false) {
			$suggestions[] = __('Astuce de service', 'discoops-ai-orchestrator');
		}
		return $suggestions ?: [__('Structure deja bien couverte', 'discoops-ai-orchestrator')];
	}

	private function build_concrete_proof_ideas(\WP_Post $post): array {
		unset($post);
		return [
			__('Ajouter un geste qui change la texture finale.', 'discoops-ai-orchestrator'),
			__('Comparer la bonne et la mauvaise version du resultat.', 'discoops-ai-orchestrator'),
			__('Nommer une erreur frequente et comment la corriger.', 'discoops-ai-orchestrator'),
		];
	}

	private function load_refresh_history(int $postId, int $sourcePostId): array {
		global $wpdb;
		$ids = array_filter(array_unique([$postId, $sourcePostId]));
		if (! $ids) {
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT j.id, j.job_type, j.status, j.created_at, r.review_status, r.comment
			FROM ' . table_name('jobs') . ' j
			LEFT JOIN ' . table_name('reviews') . ' r ON r.job_id = j.id
			WHERE j.post_id IN (' . $placeholders . ')
			ORDER BY j.id DESC LIMIT 8',
			...$ids
		), ARRAY_A) ?: [];

		return array_map(
			static function (array $row): string {
				$details = [];
				if (! empty($row['review_status'])) {
					$details[] = 'review: ' . (string) $row['review_status'];
				}
				if (! empty($row['comment'])) {
					$details[] = 'note: ' . wp_trim_words((string) $row['comment'], 12, '');
				}

				$suffix = $details ? ' | ' . implode(' | ', $details) : '';
				return sprintf('#%d - %s - %s - %s%s', (int) $row['id'], (string) $row['job_type'], (string) $row['status'], (string) $row['created_at'], $suffix);
			},
			$rows
		);
	}

	private function load_job_timeline(int $jobId): array {
		global $wpdb;
		if ($jobId < 1) {
			return [];
		}

		$job = $wpdb->get_row(
			$wpdb->prepare('SELECT id, job_type, status, created_at, updated_at FROM ' . table_name('jobs') . ' WHERE id = %d', $jobId),
			ARRAY_A
		);
		if (! is_array($job)) {
			return [];
		}

		$runs = $wpdb->get_results(
			$wpdb->prepare('SELECT workflow, model, status, started_at, ended_at FROM ' . table_name('runs') . ' WHERE job_id = %d ORDER BY id DESC LIMIT 5', $jobId),
			ARRAY_A
		) ?: [];

		$timeline = [
			sprintf('Job #%d - %s - %s', (int) $job['id'], (string) $job['job_type'], (string) $job['status']),
			sprintf('Cree le %s, mis a jour le %s', (string) $job['created_at'], (string) $job['updated_at']),
		];

		foreach ($runs as $run) {
			$timeline[] = sprintf(
				'Run %s [%s] - %s - %s -> %s',
				(string) $run['workflow'],
				(string) $run['model'],
				(string) $run['status'],
				(string) ($run['started_at'] ?: 'n/a'),
				(string) ($run['ended_at'] ?: 'en cours')
			);
		}

		return $timeline;
	}

	public function render_dashboard(): void {
		$forceRefresh = isset($_GET['discoops_ai_refresh_subscription']);
		if (! $this->gate->is_allowed($forceRefresh)) {
			$this->render_subscription_locked('Dashboard', $forceRefresh);
			return;
		}
		$jobs = $this->jobs->list();
		$subscription_gate_status = $this->gate->get_status($forceRefresh);
		$can_bulk_scan = $this->gate->has_capability(SubscriptionGate::CAP_BULK_SCAN, $forceRefresh);
		include DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/views/dashboard.php';
	}

	public function render_jobs(): void {
		$forceRefresh = isset($_GET['discoops_ai_refresh_subscription']);
		if (! $this->gate->is_allowed($forceRefresh)) {
			$this->render_subscription_locked('Jobs', $forceRefresh);
			return;
		}
		$jobs = $this->jobs->list();
		$can_prioritize_jobs = $this->gate->has_capability(SubscriptionGate::CAP_PRIORITIZE_JOBS, $forceRefresh);
		include DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/views/jobs.php';
	}

	public function render_reviews(): void {
		$forceRefresh = isset($_GET['discoops_ai_refresh_subscription']);
		if (! $this->gate->is_allowed($forceRefresh)) {
			$this->render_subscription_locked('Reviews', $forceRefresh);
			return;
		}
		$reviews = $this->reviews->list_reviews();
		include DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/views/reviews.php';
	}

	public function render_signals(): void {
		$forceRefresh = isset($_GET['discoops_ai_refresh_subscription']);
		if (! $this->gate->is_allowed($forceRefresh)) {
			$this->render_subscription_locked('Signals', $forceRefresh);
			return;
		}
		global $wpdb;
		$signals = $wpdb->get_results('SELECT * FROM ' . table_name('signals') . ' ORDER BY created_at DESC LIMIT 50', ARRAY_A) ?: [];
		include DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/views/signals.php';
	}

	public function render_settings(): void {
		$settings = $this->settings->get();
		$subscription_gate_status = $this->gate->get_status(isset($_GET['discoops_ai_refresh_subscription']));
		$subscription_gate_capabilities = is_array($subscription_gate_status['capabilities'] ?? null) ? $subscription_gate_status['capabilities'] : [];
		include DISCOOPS_AI_ORCHESTRATOR_PATH . 'admin/views/settings.php';
	}

	public function handle_approve_review(): void {
		check_admin_referer('discoops_ai_review_action');
		if (! current_user_can('manage_options') || ! $this->gate->is_allowed()) {
			wp_die('Unauthorized');
		}

		$this->reviews->approve(absint($_POST['review_id'] ?? 0), sanitize_textarea_field((string) ($_POST['comment'] ?? '')));
		wp_safe_redirect(admin_url('admin.php?page=discoops-ai-reviews'));
		exit;
	}

	public function handle_reject_review(): void {
		check_admin_referer('discoops_ai_review_action');
		if (! current_user_can('manage_options') || ! $this->gate->is_allowed()) {
			wp_die('Unauthorized');
		}

		$this->reviews->reject(absint($_POST['review_id'] ?? 0), sanitize_textarea_field((string) ($_POST['comment'] ?? '')));
		wp_safe_redirect(admin_url('admin.php?page=discoops-ai-reviews'));
		exit;
	}

	public function render_admin_notices(): void {
		$gateStatus = $this->gate->get_status();
		if (empty($gateStatus['allowed'])) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html((string) ($gateStatus['message'] ?: 'An active Starter, Growth or Agency subscription is required to use Discoops AI.'))
			);
		}

		global $wpdb;
		$post_id = absint($_GET['discoops_ai_enqueued'] ?? 0);
		$current_post_id = absint($_GET['post'] ?? 0);
		$enqueue_error = sanitize_text_field((string) ($_GET['discoops_ai_enqueue_error'] ?? ''));
		$opportunity_id = absint($_GET['discoops_ai_opportunity'] ?? 0);
		$rework_scope = sanitize_key((string) ($_GET['discoops_ai_rework'] ?? ''));
		$rework_error = sanitize_text_field((string) ($_GET['discoops_ai_rework_error'] ?? ''));
		$applied_post_id = absint($_GET['discoops_ai_applied'] ?? 0);
		$applied_error = sanitize_text_field((string) ($_GET['discoops_ai_apply_error'] ?? ''));
		$priority_job_id = absint($_GET['discoops_ai_priority_job'] ?? 0);

		if ($post_id > 0) {
			$message = $opportunity_id > 0
				? sprintf("L'article #%d a ete envoye dans la file Discoops (opportunite #%d).", $post_id, $opportunity_id)
				: sprintf("L'article #%d a ete envoye dans la file Discoops.", $post_id);

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html($message)
			);
		}

		if ($rework_scope !== '') {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(sprintf("La demande de rework '%s' a ete enregistree pour l'article #%d.", str_replace('_', ' ', $rework_scope), $current_post_id > 0 ? $current_post_id : $post_id))
			);
		}

		if ($applied_post_id > 0) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(sprintf("Le draft Discoops a ete applique au post source depuis l'article #%d.", $applied_post_id))
			);
		}

		if ($priority_job_id > 0) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(sprintf('La priorite du job #%d a ete mise a jour.', $priority_job_id))
			);
		}

		if ($enqueue_error !== '') {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html($enqueue_error)
			);
		}

		if ($rework_error !== '') {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html($rework_error)
			);
		}

		if ($applied_error !== '') {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html($applied_error)
			);
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$show_discover_notice = $screen && in_array($screen->id, ['dashboard', 'edit-post', 'post'], true);
		if ($show_discover_notice) {
			$recent_drop = $wpdb->get_row(
				"SELECT post_id, signal_score, observed_at FROM " . table_name('signals') . " WHERE signal_type = 'drop' ORDER BY observed_at DESC LIMIT 1",
				ARRAY_A
			);
			if (is_array($recent_drop) && ! empty($recent_drop['observed_at'])) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html(sprintf(
						'Discoops a detecte une chute Discover recente sur le post #%d (score %s, observe le %s).',
						(int) ($recent_drop['post_id'] ?? 0),
						(string) ($recent_drop['signal_score'] ?? 'n/a'),
						(string) $recent_drop['observed_at']
					))
				);
			}
		}
	}

	public function add_post_row_actions(array $actions, \WP_Post $post): array {
		if ($post->post_type !== 'post' || ! current_user_can('edit_post', $post->ID) || ! $this->gate->is_allowed()) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url('admin-post.php?action=discoops_ai_enqueue_post&post_id=' . $post->ID),
			'discoops_ai_enqueue_post_' . $post->ID
		);

		$actions['discoops_ai_enqueue_post'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url($url),
			esc_html__('Envoyer en file Discoops', 'discoops-ai-orchestrator')
		);

		return $actions;
	}

	public function handle_enqueue_post(): void {
		$post_id = absint($_GET['post_id'] ?? 0);
		check_admin_referer('discoops_ai_enqueue_post_' . $post_id);

		if ($post_id < 1 || ! current_user_can('edit_post', $post_id) || ! $this->gate->is_allowed() || ! $this->gate->has_capability(SubscriptionGate::CAP_REWORK)) {
			wp_die('Unauthorized');
		}

		$result = $this->enqueue_post_to_discoops_platform($post_id);

		$redirect_args = [
			'post_type' => 'post',
		];

		if (! ($result['ok'] ?? false)) {
			$redirect_args['discoops_ai_enqueue_error'] = (string) ($result['message'] ?? 'Envoi vers Discoops impossible.');
		} else {
			$redirect_args['discoops_ai_enqueued'] = $post_id;
			if (! empty($result['opportunity_id'])) {
				$redirect_args['discoops_ai_opportunity'] = (int) $result['opportunity_id'];
			}
		}

		$redirect = add_query_arg($redirect_args, admin_url('edit.php'));

		wp_safe_redirect($redirect);
		exit;
	}

	public function handle_rework_post(): void {
		$post_id = absint($_GET['post_id'] ?? 0);
		$scope = sanitize_key((string) ($_GET['scope'] ?? ''));
		check_admin_referer('discoops_ai_rework_post_' . $post_id . '_' . $scope);

		if ($post_id < 1 || ! current_user_can('edit_post', $post_id) || ! $this->gate->is_allowed() || ! $this->gate->has_capability(SubscriptionGate::CAP_APPLY_TO_SOURCE)) {
			wp_die('Unauthorized');
		}

		$allowed_scopes = ['intro', 'title', 'faq', 'internal_links'];
		if (! in_array($scope, $allowed_scopes, true)) {
			wp_safe_redirect(add_query_arg(
				[
					'post' => $post_id,
					'action' => 'edit',
					'discoops_ai_rework_error' => 'Scope de rework invalide.',
				],
				admin_url('post.php')
			));
			exit;
		}

		$post = get_post($post_id);
		if (! $post instanceof \WP_Post) {
			wp_safe_redirect(add_query_arg(
				[
					'post_type' => 'post',
					'discoops_ai_rework_error' => 'Article introuvable.',
				],
				admin_url('edit.php')
			));
			exit;
		}

		$notes = $this->normalize_display_text((string) get_post_meta($post_id, '_discoops_editor_notes', true));
		$job = $this->jobs->enqueue_local([
			'site_id' => get_current_blog_id(),
			'post_id' => $post_id,
			'job_type' => 'rework_' . $scope,
			'source_signal' => 'manual_review',
			'priority' => 4,
			'payload' => [
				'scope' => $scope,
				'post_title' => $post->post_title,
				'opportunity_type' => (string) get_post_meta($post_id, '_discoops_opportunity_type', true),
				'editor_notes' => $notes,
			],
		], 'queued');

		update_post_meta($post_id, '_discoops_ai_status', 'queued_rework');
		update_post_meta($post_id, '_discoops_requested_rework', wp_json_encode([
			'scope' => $scope,
			'requested_at' => current_time('mysql'),
			'job_id' => (int) ($job['id'] ?? 0),
		]));

		wp_safe_redirect(add_query_arg(
			[
				'post' => $post_id,
				'action' => 'edit',
				'discoops_ai_rework' => $scope,
			],
			admin_url('post.php')
		));
		exit;
	}

	public function handle_apply_source(): void {
		$post_id = absint($_GET['post_id'] ?? 0);
		check_admin_referer('discoops_ai_apply_source_' . $post_id);

		if ($post_id < 1 || ! current_user_can('edit_post', $post_id) || ! $this->gate->is_allowed()) {
			wp_die('Unauthorized');
		}

		$source_post_id = (int) get_post_meta($post_id, '_discoops_source_post_id', true);
		if ($source_post_id < 1) {
			wp_safe_redirect(add_query_arg(
				[
					'post' => $post_id,
					'action' => 'edit',
					'discoops_ai_apply_error' => 'Aucun post source n est lie a ce draft.',
				],
				admin_url('post.php')
			));
			exit;
		}

		$this->reviews->publish_after_review([
			'post_id' => $post_id,
			'job_id' => (int) get_post_meta($post_id, '_discoops_ai_last_job_id', true),
		]);

		wp_safe_redirect(add_query_arg(
			[
				'post' => $post_id,
				'action' => 'edit',
				'discoops_ai_applied' => $post_id,
			],
			admin_url('post.php')
		));
		exit;
	}

	public function handle_set_job_priority(): void {
		global $wpdb;

		$job_id = absint($_GET['job_id'] ?? 0);
		$priority = min(9, max(1, absint($_GET['priority'] ?? 5)));
		check_admin_referer('discoops_ai_set_job_priority_' . $job_id . '_' . $priority);

		if ($job_id < 1 || ! current_user_can('manage_options') || ! $this->gate->is_allowed() || ! $this->gate->has_capability(SubscriptionGate::CAP_PRIORITIZE_JOBS)) {
			wp_die('Unauthorized');
		}

		$wpdb->update(
			table_name('jobs'),
			[
				'priority' => $priority,
				'updated_at' => now_mysql(),
			],
			['id' => $job_id]
		);

		wp_safe_redirect(add_query_arg(
			[
				'page' => 'discoops-ai-jobs',
				'discoops_ai_priority_job' => $job_id,
			],
			admin_url('admin.php')
		));
		exit;
	}

	private function enqueue_post_to_discoops_platform(int $post_id): array {
		$settings = $this->settings->get();
		$webhook_secret = trim((string) ($settings['discoops_webhook_secret'] ?? ''));
		$source_url = get_permalink($post_id) ?: '';
		$source_title = get_the_title($post_id);

		if ($webhook_secret === '') {
			return ['ok' => false, 'message' => 'Discoops webhook secret manquant dans les reglages du plugin.'];
		}

		if ($source_url === '') {
			return ['ok' => false, 'message' => "Impossible de resoudre l'URL de cet article WordPress."];
		}

		$endpoint = apply_filters(
			'discoops_ai_platform_api_url',
			'https://www.discoops.com/api/v1/discoops.php'
		);
		$endpoint = untrailingslashit((string) $endpoint) . '?action=manual_refresh_enqueue';

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'body' => wp_json_encode([
					'webhook_secret' => $webhook_secret,
					'wp_post_id' => $post_id,
					'source_url' => $source_url,
					'source_title' => $source_title,
				]),
			]
		);

		if (is_wp_error($response)) {
			return ['ok' => false, 'message' => $response->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);

		if ($code < 200 || $code >= 300 || ! is_array($json) || ! ($json['ok'] ?? false)) {
			$message = is_array($json)
				? (string) (($json['error']['message'] ?? '') ?: 'Reponse Discoops invalide.')
				: 'Reponse Discoops invalide.';
			return ['ok' => false, 'message' => $message];
		}

		return [
			'ok' => true,
			'opportunity_id' => (int) ($json['opportunity_id'] ?? 0),
			'site_id' => (int) ($json['site_id'] ?? 0),
			'status' => (string) ($json['status'] ?? 'queued'),
		];
	}

	private function render_subscription_locked(string $section, bool $forceRefresh = false): void {
		$status = $this->gate->get_status($forceRefresh);
		$message = (string) ($status['message'] ?? '');
		$plan = strtoupper((string) ($status['plan_id'] ?? ''));
		$accessLevel = strtoupper((string) ($status['access_level'] ?? ''));
		$subscriptionStatus = strtoupper((string) ($status['subscription_status'] ?? ''));
		$matchedBy = (string) ($status['matched_by'] ?? '');
		$checkedAt = (string) ($status['checked_at'] ?? '');
		$refreshUrl = add_query_arg(
			[
				'page' => sanitize_key((string) ($_GET['page'] ?? 'discoops-ai')),
				'discoops_ai_refresh_subscription' => 1,
			],
			admin_url('admin.php')
		);
		$platformUrl = 'https://www.discoops.com/?r=wp_connect';
		$billingUrl = 'https://www.discoops.com/?r=billing';
		echo '<div class="wrap discoops-ai-admin discoops-ai-surface">';
		echo '<div class="discoops-ai-lock">';
		echo '<div class="discoops-ai-lock__hero">';
		echo '<div class="discoops-ai-lock__copy">';
		echo '<div class="discoops-ai-lock__eyebrow">Discoops AI</div>';
		echo '<h1>' . esc_html($section) . '</h1>';
		echo '<p>' . esc_html__('Cette section reste disponible uniquement si le site connecte dispose d un abonnement Starter, Growth ou Agency actif sur Discoops.', 'discoops-ai-orchestrator') . '</p>';
		echo '<div class="discoops-ai-lock__chips">';
		printf(
			'<span class="discoops-ai-lock__chip %s">%s</span>',
			! empty($status['allowed']) ? 'is-success' : 'is-danger',
			esc_html(! empty($status['allowed']) ? __('Acces actif', 'discoops-ai-orchestrator') : __('Acces verrouille', 'discoops-ai-orchestrator'))
		);
		if ($plan !== '') {
			printf('<span class="discoops-ai-lock__chip">%s %s</span>', esc_html__('Plan', 'discoops-ai-orchestrator'), esc_html($plan));
		}
		if ($accessLevel !== '' && $accessLevel !== 'NONE') {
			printf('<span class="discoops-ai-lock__chip">%s %s</span>', esc_html__('Acces', 'discoops-ai-orchestrator'), esc_html($accessLevel));
		}
		if ($subscriptionStatus !== '') {
			printf('<span class="discoops-ai-lock__chip">%s %s</span>', esc_html__('Statut', 'discoops-ai-orchestrator'), esc_html($subscriptionStatus));
		}
		echo '</div>';
		if ($message !== '') {
			printf('<div class="discoops-ai-lock__message">%s</div>', esc_html($message));
		}
		echo '<div class="discoops-ai-lock__meta">';
		if ($matchedBy !== '') {
			printf('<span>%s %s</span>', esc_html__('Correspondance', 'discoops-ai-orchestrator'), esc_html($matchedBy));
		}
		if ($checkedAt !== '') {
			printf('<span>%s %s</span>', esc_html__('Derniere verification', 'discoops-ai-orchestrator'), esc_html($checkedAt));
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="discoops-ai-lock__actions">';
		echo '<a class="button button-primary discoops-ai-dashboard__scan-btn discoops-ai-lock__button" href="' . esc_url($platformUrl) . '" target="_blank" rel="noreferrer noopener"><span class="dashicons dashicons-external"></span><span>' . esc_html__('Ouvrir la plateforme Discoops', 'discoops-ai-orchestrator') . '</span></a>';
		echo '<a class="button discoops-ai-lock__secondary discoops-ai-lock__button" href="' . esc_url($refreshUrl) . '"><span class="dashicons dashicons-update"></span><span>' . esc_html__('Verifier a nouveau', 'discoops-ai-orchestrator') . '</span></a>';
		echo '<a class="button discoops-ai-lock__secondary discoops-ai-lock__button" href="' . esc_url($billingUrl) . '" target="_blank" rel="noreferrer noopener"><span class="dashicons dashicons-cart"></span><span>' . esc_html__('Choisir un plan', 'discoops-ai-orchestrator') . '</span></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="discoops-ai-lock__grid">';
		echo '<div class="discoops-ai-lock__card">';
		echo '<div class="discoops-ai-lock__label">' . esc_html__('Acces plugin', 'discoops-ai-orchestrator') . '</div>';
		echo '<strong>' . esc_html(! empty($status['allowed']) ? __('Actif', 'discoops-ai-orchestrator') : __('Verrouille', 'discoops-ai-orchestrator')) . '</strong>';
		echo '<p>' . esc_html($message !== '' ? $message : __('Verification d abonnement indisponible.', 'discoops-ai-orchestrator')) . '</p>';
		echo '</div>';
		echo '<div class="discoops-ai-lock__card">';
		echo '<div class="discoops-ai-lock__label">' . esc_html__('Plan detecte', 'discoops-ai-orchestrator') . '</div>';
		echo '<strong>' . esc_html($plan !== '' ? $plan : '—') . '</strong>';
		echo '<p>' . esc_html__('Starter, Growth et Agency sont autorises si l abonnement est actif.', 'discoops-ai-orchestrator') . '</p>';
		echo '</div>';
		echo '<div class="discoops-ai-lock__card">';
		echo '<div class="discoops-ai-lock__label">' . esc_html__('Niveau plugin', 'discoops-ai-orchestrator') . '</div>';
		echo '<strong>' . esc_html($accessLevel !== '' ? $accessLevel : '—') . '</strong>';
		echo '<p>' . esc_html__('Lite pour Starter, Pro pour Growth et Scale pour Agency.', 'discoops-ai-orchestrator') . '</p>';
		echo '</div>';
		echo '<div class="discoops-ai-lock__card">';
		echo '<div class="discoops-ai-lock__label">' . esc_html__('Statut d abonnement', 'discoops-ai-orchestrator') . '</div>';
		echo '<strong>' . esc_html($subscriptionStatus !== '' ? $subscriptionStatus : '—') . '</strong>';
		echo '<p>' . esc_html__('Si cela semble incorrect, reverifiez la connexion du site depuis Discoops puis relancez cette verification.', 'discoops-ai-orchestrator') . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}
}
