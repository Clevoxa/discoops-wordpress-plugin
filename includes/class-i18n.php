<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

final class I18n {
	public static function language(): string {
		$locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
		return strtolower(substr($locale, 0, 2));
	}

	public static function ui(string $french, string $english): string {
		return self::language() === 'en' ? $english : $french;
	}

	public static function map(string $text): string {
		return self::translate_string($text, $text, 'discoops-ai-orchestrator');
	}

	public static function register(): void {
		add_action('init', [self::class, 'load_textdomain']);
		add_action('admin_menu', [self::class, 'relabel_admin_menu'], 999);
		add_filter('gettext', [self::class, 'translate'], 10, 3);
		add_filter('gettext_with_context', [self::class, 'translate_with_context'], 10, 4);
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'discoops-ai-orchestrator',
			false,
			dirname(plugin_basename(DISCOOPS_AI_ORCHESTRATOR_FILE)) . '/languages'
		);
	}

	public static function translate(string $translation, string $text, string $domain): string {
		return self::translate_string($translation, $text, $domain);
	}

	public static function translate_with_context(string $translation, string $text, string $context, string $domain): string {
		unset($context);
		return self::translate_string($translation, $text, $domain);
	}

	public static function relabel_admin_menu(): void {
		global $menu, $submenu;

		if (is_array($menu)) {
			foreach ($menu as &$item) {
				if (($item[2] ?? '') === 'discoops-ai') {
					$item[0] = __('Discoops AI', 'discoops-ai-orchestrator');
					$item[3] = __('Discoops AI', 'discoops-ai-orchestrator');
				}
			}
			unset($item);
		}

		if (isset($submenu['discoops-ai']) && is_array($submenu['discoops-ai'])) {
			foreach ($submenu['discoops-ai'] as &$item) {
				$slug = (string) ($item[2] ?? '');
				$item[0] = match ($slug) {
					'discoops-ai' => __('Dashboard', 'discoops-ai-orchestrator'),
					'discoops-ai-jobs' => __('Jobs', 'discoops-ai-orchestrator'),
					'discoops-ai-reviews' => __('Reviews', 'discoops-ai-orchestrator'),
					'discoops-ai-signals' => __('Signals', 'discoops-ai-orchestrator'),
					'discoops-ai-settings' => __('Settings', 'discoops-ai-orchestrator'),
					default => $item[0],
				};
			}
			unset($item);
		}
	}

	private static function translate_string(string $translation, string $text, string $domain): string {
		if ($domain !== 'discoops-ai-orchestrator') {
			return $translation;
		}

		$language = self::language();
		$map = $language === 'en' ? self::english_map() : self::french_map();

		if (isset($map[$text])) {
			return $map[$text];
		}

		$normalized = self::normalize_lookup_key($text);
		$normalized_map = self::normalized_map($language);

		return $normalized_map[$normalized] ?? $translation;
	}

	private static function normalized_map(string $language): array {
		static $cache = [];

		if (isset($cache[$language])) {
			return $cache[$language];
		}

		$source_map = $language === 'en' ? self::english_map() : self::french_map();
		$normalized = [];
		foreach ($source_map as $key => $value) {
			$normalized[self::normalize_lookup_key((string) $key)] = $value;
		}

		$cache[$language] = $normalized;

		return $cache[$language];
	}

	private static function normalize_lookup_key(string $text): string {
		$text = trim(wp_strip_all_tags($text));
		$text = str_replace(
			['Ã©', 'Ã¨', 'Ãª', 'Ã«', 'Ã ', 'Ã¢', 'Ã§', 'Ã¹', 'Ã»', 'Ã´', 'Ã®', 'Ãï', 'Ã‰', 'Ã€', 'Ã‡', 'Ã™', 'Ã›', 'Ã”', 'ÃŽ', 'Ã', 'Â·', 'â€¦'],
			['é', 'è', 'ê', 'ë', 'à', 'â', 'ç', 'ù', 'û', 'ô', 'î', 'ï', 'É', 'À', 'Ç', 'Ù', 'Û', 'Ô', 'Î', 'Ï', '·', '...'],
			$text
		);
		$text = str_replace(
			["\r", "\n", "\t", '’', '‘', '“', '”', '–', '—', 'â€™', 'â€˜', 'â€œ', 'â€', 'Ã', 'Â'],
			[' ', ' ', ' ', "'", "'", '"', '"', '-', '-', "'", "'", '"', '"', '', ''],
			$text
		);

		if (function_exists('remove_accents')) {
			$text = remove_accents($text);
		}

		$text = strtolower($text);
		$text = (string) preg_replace('/\s+/u', ' ', $text);

		return trim($text);
	}

	private static function french_map(): array {
		return [
			'Discoops – AI Discover Tools to Dominate Discover Rankings' => 'Discoops AI',
			'Dashboard' => 'Tableau de bord',
			'Settings' => 'Réglages',
			'Cockpit editorial Discoops' => 'Cockpit éditorial Discoops',
			'Recommandations, signaux qualite et score live pendant que vous editez.' => 'Recommandations, signaux qualité et score live pendant que vous éditez.',
			"Score d'audit live" => "Score d'audit live",
			'Synthese' => 'Synthèse',
			'Summary' => 'Synthèse',
			'Status' => 'Statut',
			'Review' => 'Review',
			'Applied to source' => 'Appliqué au post source',
			'Approved' => 'Approuvé',
			'Pending' => 'En attente',
			'Rejected' => 'Rejeté',
			'In review' => 'En review',
			'Editorial scores' => 'Scores éditoriaux',
			'Block score' => 'Score par bloc',
			'Block-by-block analysis of the current content.' => 'Analyse bloc par bloc du contenu en cours.',
			'Before / after comparison' => 'Comparaison avant / après',
			'Current draft' => 'Draft actuel',
			'Points to fix' => 'Points à corriger',
			'Verification plan' => 'Plan de vérification',
			'AI tone detector' => 'Détecteur de ton IA',
			'Ideas and enrichments' => 'Idées et enrichissements',
			'Intro variants' => 'Variantes d’intro',
			'Suggested internal links' => 'Liens internes suggérés',
			'Missing sections' => 'Sections manquantes',
			'Concrete proof ideas' => 'Preuves concrètes',
			'Editorial notes' => 'Commentaires éditoriaux',
			'Review assignment' => 'Assignation review',
			'Unassigned' => 'Non assigné',
			'Add a comment' => 'Ajouter un commentaire',
			'Comment visibility' => 'Visibilité du commentaire',
			'Internal' => 'Interne',
			'Editorial thread' => 'Fil éditorial',
			'Refresh and decision history' => 'Historique des refreshs & décisions',
			'Updated while editing' => 'Mise à jour pendant l’édition',
			'Content length' => 'Longueur de contenu',
			'Headings' => 'Intertitres',
			'Excerpt' => 'Extrait',
			'Title' => 'Titre',
			'Internal linking' => 'Maillage',
			'Originality' => 'Originalité',
			'Cannibalization' => 'Cannibalisation',
			'Very good' => 'Très bon niveau',
			'Needs work' => 'À travailler',
			'Monitor' => 'À suivre',
			'In place' => 'Bien en place',
			'Short, complete summary aimed at qualified clicks.' => 'Résumé court, complet et orienté clic qualifié.',
			'Primary keyword' => 'Mot-clé principal',
			'SEO title' => 'Titre SEO',
			'Internal links' => 'Liens internes',
			'Paragraphs' => 'Paragraphes',
			'Editorial rhythm' => 'Rythme éditorial',
			'Discover title' => 'Titre Discover',
			'Keyword' => 'Mot-clé',
			'SEO markers' => 'Repères SEO',
			'Discover mobile preview' => 'Aperçu mobile Discover',
			'Discover readiness' => 'Préparation Discover',
			'Freshness' => 'Fraîcheur',
			'Captures intent and sets a clear angle.' => 'Capte l’intention et pose un angle clair.',
			'Clarity, precision and editorial promise.' => 'Clarté, précision et promesse éditoriale.',
			'Useful and contextual internal links.' => 'Liens internes utiles et contextuels.',
			'Concrete value, proof and a non-interchangeable voice.' => 'Valeur concrète, preuve et ton non interchangeable.',
			'Overall distribution potential on Discover.' => 'Potentiel de diffusion global sur Discover.',
			'Expertise, transparency and credibility.' => 'Expertise, transparence et crédibilité.',
			'Collision risk with other site content.' => 'Risque de collision avec les autres contenus du site.',
			'Google Search & Discover policies' => 'Règles Google Search & Discover',
			'Compliant' => 'Conforme',
			'Policy risk' => 'Risque policy',
			'People-first content' => 'Contenu people-first',
			'Non-sensational headline' => 'Titre non sensationnaliste',
			'Editorial transparency' => 'Transparence éditoriale',
			'Anti-spam signals' => 'Signaux anti-spam',
			'Discover visual' => 'Visuel Discover',
			'Structure and links' => 'Structure et liens',
			'Author and transparency' => 'Auteur et transparence',
			'Visible expertise' => 'Expertise visible',
			'Explicit Why / How' => 'Why / How explicites',
			'Apply to source post' => 'Appliquer au post source',
			'Rewrite intro' => 'Relancer intro',
			'Rewrite title' => 'Relancer titre',
			'Rewrite FAQ' => 'Relancer FAQ',
			'Rewrite internal linking' => 'Relancer maillage',
			'View articles' => 'Voir les articles',
			'Hide articles' => 'Masquer les articles',
			'Edit' => 'Modifier',
			'View' => 'Voir',
			'Priority' => 'Priorité',
			'Updated at' => 'Mis à jour',
			'Lists / FAQ' => 'Listes / FAQ',
			'People-first' => 'People-first',
		];
	}

	private static function english_map(): array {
		return [
			'Discoops – AI Discover Tools to Dominate Discover Rankings' => 'Discoops AI',
			'Cockpit editorial Discoops' => 'Discoops editorial cockpit',
			'Recommandations, signaux qualite et score live pendant que vous editez.' => 'Recommendations, quality signals and live score while you edit.',
			'Synthese' => 'Summary',
			'Synthèse' => 'Summary',
			'Scores editoriaux' => 'Editorial scores',
			'Scores éditoriaux' => 'Editorial scores',
			'Score par bloc' => 'Block score',
			'Analyse bloc par bloc du contenu en cours.' => 'Block-by-block analysis of the current content.',
			'Comparaison avant / apres' => 'Before / after comparison',
			'Comparaison avant / après' => 'Before / after comparison',
			'Draft actuel' => 'Current draft',
			'Points a corriger' => 'Points to fix',
			'Points à corriger' => 'Points to fix',
			'Plan de verification' => 'Verification plan',
			'Plan de vérification' => 'Verification plan',
			'Detecteur de ton IA' => 'AI tone detector',
			'Détecteur de ton IA' => 'AI tone detector',
			'Idees et enrichissements' => 'Ideas and enrichments',
			'Idées et enrichissements' => 'Ideas and enrichments',
			'Variantes d intro' => 'Intro variants',
			'Variantes d’intro' => 'Intro variants',
			'Liens internes suggeres' => 'Suggested internal links',
			'Liens internes suggérés' => 'Suggested internal links',
			'Sections manquantes' => 'Missing sections',
			'FAQ naturelle' => 'Natural FAQ',
			'Erreurs frequentes' => 'Common mistakes',
			'Astuce de service' => 'Serving tip',
			'Structure deja bien couverte' => 'Structure already well covered',
			'Preuves concretes' => 'Concrete proof ideas',
			'Preuves concrètes' => 'Concrete proof ideas',
			'Commentaires editoriaux' => 'Editorial notes',
			'Commentaires éditoriaux' => 'Editorial notes',
			'Assignation review' => 'Review assignment',
			'Non assignee' => 'Unassigned',
			'Non assigné' => 'Unassigned',
			'Ajouter un commentaire' => 'Add a comment',
			'Visibilite du commentaire' => 'Comment visibility',
			'Visibilité du commentaire' => 'Comment visibility',
			'Interne' => 'Internal',
			'Editorial' => 'Editorial',
			'Fil editorial' => 'Editorial thread',
			'Fil éditorial' => 'Editorial thread',
			'Historique des refreshs & decisions' => 'Refresh and decision history',
			'Historique des refreshs & décisions' => 'Refresh and decision history',
			"Mise a jour pendant l'edition" => 'Updated while editing',
			'Mise à jour pendant l’édition' => 'Updated while editing',
			'Longueur de contenu' => 'Content length',
			'Intertitres' => 'Headings',
			'Extrait' => 'Excerpt',
			'Titre' => 'Title',
			'Maillage' => 'Internal linking',
			'Originalite' => 'Originality',
			'Originalité' => 'Originality',
			'Cannibalisation' => 'Cannibalization',
			'Tres bon niveau' => 'Very good',
			'Très bon niveau' => 'Very good',
			'A travailler' => 'Needs work',
			'À travailler' => 'Needs work',
			'A suivre' => 'Monitor',
			'À suivre' => 'Monitor',
			'Bien en place' => 'In place',
			'Resume court, complet et orienté clic qualifie.' => 'Short, complete summary aimed at qualified clicks.',
			'Résumé court, complet et orienté clic qualifié.' => 'Short, complete summary aimed at qualified clicks.',
			'Mot-cle principal' => 'Primary keyword',
			'Mot-clé principal' => 'Primary keyword',
			'Titre SEO' => 'SEO title',
			'Liens internes' => 'Internal links',
			'Listes / FAQ' => 'Lists / FAQ',
			'Paragraphes' => 'Paragraphs',
			'paragraphes utiles' => 'useful paragraphs',
			'Structure enrichie' => 'Enhanced structure',
			'liens trouves' => 'links found',
			'Rythme editorial' => 'Editorial rhythm',
			'Rythme éditorial' => 'Editorial rhythm',
			'Titre Discover' => 'Discover title',
			'Mot-cle' => 'Keyword',
			'Mot-clé' => 'Keyword',
			'Reperes SEO' => 'SEO markers',
			'Repères SEO' => 'SEO markers',
			'Apercu mobile Discover' => 'Discover mobile preview',
			'Aperçu mobile Discover' => 'Discover mobile preview',
			'Discover readiness' => 'Discover readiness',
			'Préparation Discover' => 'Discover readiness',
			'Fraicheur' => 'Freshness',
			'Fraîcheur' => 'Freshness',
			'Capte l intention et pose un angle clair.' => 'Captures intent and sets a clear angle.',
			'Capte l’intention et pose un angle clair.' => 'Captures intent and sets a clear angle.',
			'Clarte, precision et promesse editoriale.' => 'Clarity, precision and editorial promise.',
			'Clarté, précision et promesse éditoriale.' => 'Clarity, precision and editorial promise.',
			'Liens internes utiles et contextuels.' => 'Useful and contextual internal links.',
			'Valeur concrete, preuve et ton non interchangeable.' => 'Concrete value, proof and a non-interchangeable voice.',
			'Valeur concrète, preuve et ton non interchangeable.' => 'Concrete value, proof and a non-interchangeable voice.',
			'Potentiel de diffusion global sur Discover.' => 'Overall distribution potential on Discover.',
			'Expertise, transparence et credibilite.' => 'Expertise, transparency and credibility.',
			'Expertise, transparence et crédibilité.' => 'Expertise, transparency and credibility.',
			'Risque de collision avec les autres contenus du site.' => 'Collision risk with other site content.',
			'Applique au post source' => 'Applied to source',
			'Appliqué au post source' => 'Applied to source',
			'Approuve' => 'Approved',
			'Approuvé' => 'Approved',
			'En attente' => 'Pending',
			'Rejete' => 'Rejected',
			'Rejeté' => 'Rejected',
			'En review' => 'In review',
			'Regles Google Search & Discover' => 'Google Search & Discover policies',
			'Règles Google Search & Discover' => 'Google Search & Discover policies',
			'Conforme' => 'Compliant',
			'A surveiller' => 'Monitor',
			'A corriger' => 'Needs work',
			'Risque policy' => 'Policy risk',
			'Contenu people-first' => 'People-first content',
			'Titre non sensationnaliste' => 'Non-sensational headline',
			'Transparence editoriale' => 'Editorial transparency',
			'Transparence éditoriale' => 'Editorial transparency',
			'Signaux anti-spam' => 'Anti-spam signals',
			'Visuel Discover' => 'Discover visual',
			'Structure et liens' => 'Structure and links',
			'Le contenu apporte deja des gestes concrets, des consequences utiles ou des exemples pratiques.' => 'The content already brings concrete actions, useful outcomes or practical examples.',
			'Le contenu apporte déjà des gestes concrets, des conséquences utiles ou des exemples pratiques.' => 'The content already brings concrete actions, useful outcomes or practical examples.',
			'Le contenu doit apporter plus de valeur pratique visible : gestes, erreurs frequentes, exemples concrets ou consequences utiles.' => 'The content should show more visible practical value: actions, common mistakes, concrete examples or useful outcomes.',
			'Le texte varie bien ses ouvertures' => 'The text varies its openings well',
			'Bloc solide et assez detaille' => 'Solid and detailed block',
			'Bloc solide et assez détaillé' => 'Solid and detailed block',
			'Intertitre clair, utile et bien calibre pour introduire la section' => 'Clear, useful heading that introduces the section well.',
			'Intertitre clair, utile et bien calibré pour introduire la section' => 'Clear, useful heading that introduces the section well.',
			'Intertitre correct, mais il peut annoncer plus clairement la promesse de la section' => 'Decent heading, but it could signal the section promise more clearly.',
			'Approfondir' => 'Deepen',
			'Ajouter un exemple' => 'Add an example',
			'Humaniser' => 'Humanize',
			'RAS' => 'OK',
			'Ajouter un geste qui change la texture finale.' => 'Add one action that changes the final texture.',
			'Comparer la bonne et la mauvaise version du resultat.' => 'Compare the good and bad versions of the result.',
			'Nommer une erreur frequente et comment la corriger.' => 'Name one common mistake and how to fix it.',
			'Le titre semble trop sensationnaliste ou trop generique par rapport aux recommandations Google.' => 'The headline looks too sensational or too generic compared to Google guidelines.',
			'Le titre reste assez descriptif et fidele au contenu.' => 'The headline stays descriptive enough and faithful to the content.',
			'Auteur et date sont bien presents, ce qui renforce la transparence.' => 'Author and date are present, which improves transparency.',
			'Affichez clairement auteur et date pour renforcer la confiance et la lisibilite editoriale.' => 'Clearly display author and date to strengthen trust and editorial clarity.',
			'Des marqueurs de templating ou d illustration restent visibles et peuvent affaiblir la qualite percue.' => 'Templating or illustration markers are still visible and may weaken perceived quality.',
			'Pas de marqueur evident de templating massif ou de spam de contenu.' => 'No obvious marker of large-scale templating or content spam.',
			'L image principale est suffisamment grande pour Discover et semble plus credible.' => 'The main image is large enough for Discover and looks more credible.',
			'Ajoutez une image principale large et credible, idealement sans signal d illustration generique.' => 'Add a large and credible main image, ideally without generic illustration signals.',
			'La structure est assez lisible et le maillage ne semble pas artificiel.' => 'The structure is readable enough and the linking does not look artificial.',
			'Renforcez la structure avec plus d intertitres et un maillage interne utile, sans surcharger en liens externes.' => 'Strengthen the structure with more headings and useful internal linking, without overloading external links.',
			'Auteur et transparence' => 'Author and transparency',
			'Expertise visible' => 'Visible expertise',
			'Why / How explicites' => 'Explicit Why / How',
			'Auteur et date visibles dans WordPress.' => 'Author and date are visible in WordPress.',
			'Renforcer les signaux auteur/date et transparence.' => 'Strengthen author/date and transparency signals.',
			'Le texte montre deja des gestes, erreurs ou details concrets.' => 'The text already shows actions, mistakes or concrete details.',
			'Ajouter plus de preuves de maitrise et d observation pratique.' => 'Add more proof of expertise and practical observation.',
			'Le texte explique deja pourquoi les gestes comptent.' => 'The text already explains why the actions matter.',
			'Ajouter davantage de pourquoi / comment dans les conseils.' => 'Add more why/how explanations in the advice.',
			'Version plus concrete autour de %s.' => 'A more concrete version around %s.',
			'Ouverture plus sensorielle et directe pour %s.' => 'A more sensory and direct opening for %s.',
			'Angle plus utile et plus pratique des les premieres lignes.' => 'A more useful and practical angle from the opening lines.',
			'Peut-on tout preparer a l avance ?' => 'Can everything be prepared in advance?',
			'Quel est le geste qui change vraiment le resultat ?' => 'Which action truly changes the result?',
			'Comment eviter une texture trop molle ou trop seche ?' => 'How can you avoid a texture that is too soft or too dry?',
			'Ajouter un lien vers une recette cousine plus simple.' => 'Add a link to a simpler related recipe.',
			'Ajouter un lien vers un article erreurs a eviter.' => 'Add a link to an article about mistakes to avoid.',
			'Ajouter un lien vers une idee de service ou d accompagnement.' => 'Add a link to a serving or pairing idea.',
			'L ouverture "Imaginez" reste souvent trop demonstrative.' => 'The opening "Imagine" often feels too demonstrative.',
			'La transition "En conclusion" donne un ton trop scolaire.' => 'The transition "In conclusion" sounds too academic.',
			'La liaison "De plus" peut paraitre trop mecanique si elle revient souvent.' => 'The transition "Moreover" can feel too mechanical if it comes back too often.',
			'La liaison "Par ailleurs" peut sonner trop generique.' => 'The transition "Besides" can sound too generic.',
			'La formule "Le vrai point" est une signature IA frequente.' => 'The phrase "The real point" is a common AI signature.',
			'La formule "Le detail qui" revient souvent dans les textes trop artificiels.' => 'The phrase "The detail that" often comes back in overly artificial text.',
			'Lier vers #%d - %s' => 'Link to #%d - %s',
			'Appliquer au post source' => 'Apply to source post',
			'Relancer intro' => 'Rewrite intro',
			'Relancer titre' => 'Rewrite title',
			'Relancer FAQ' => 'Rewrite FAQ',
			'Relancer maillage' => 'Rewrite internal linking',
			'Statut temps reel du job MCP' => 'Live MCP job status',
			'Statut temps réel du job MCP' => 'Live MCP job status',
			'Timeline du job' => 'Job timeline',
			"Score d'audit live" => 'Live audit score',
			'Media et image principale' => 'Media and main image',
			'Image principale' => 'Main image',
			'Image detectee (%d x %d).' => 'Image detected (%d x %d).',
			'Image détectée (%d x %d).' => 'Image detected (%d x %d).',
			'Aucune image principale detectee.' => 'No main image detected.',
			'Aucune image principale détectée.' => 'No main image detected.',
			'Ratio Discover' => 'Discover ratio',
			'Grand format compatible Discover.' => 'Large format compatible with Discover.',
			'Visez un visuel plus large pour Discover.' => 'Aim for a wider visual for Discover.',
			'Qualite image' => 'Image quality',
			'Qualité image' => 'Image quality',
			'Definition suffisante pour les grands apercus.' => 'Sufficient definition for large previews.',
			'Définition suffisante pour les grands aperçus.' => 'Sufficient definition for large previews.',
			'Une image 1200px+ est recommandee.' => 'A 1200px+ image is recommended.',
			'Une image 1200px+ est recommandée.' => 'A 1200px+ image is recommended.',
			'Photo credible' => 'Credible photo',
			'Photo crédible' => 'Credible photo',
			'La media semble presentee comme une illustration plutot qu une photo editoriale.' => 'The media looks presented as an illustration rather than an editorial photo.',
			'La média semble présentée comme une illustration plutôt qu une photo éditoriale.' => 'The media looks presented as an illustration rather than an editorial photo.',
			'Le visuel ne porte pas de signal d illustration problematique.' => 'The visual does not show a problematic illustration signal.',
			'Le visuel ne porte pas de signal d’illustration problématique.' => 'The visual does not show a problematic illustration signal.',
			'Suggestion de legende' => 'Caption suggestion',
			'Suggestion de légende' => 'Caption suggestion',
			'Suggestion de alt' => 'Alt suggestion',
			'Risque de collision' => 'Collision risk',
			'Sous controle' => 'Under control',
			'Sous contrôle' => 'Under control',
			'Termine' => 'Done',
			'Terminé' => 'Done',
			'Voir les articles' => 'View articles',
			'Masquer les articles' => 'Hide articles',
			'Modifier' => 'Edit',
			'Voir' => 'View',
			'Priorite' => 'Priority',
			'Priorité' => 'Priority',
			'Mis a jour' => 'Updated at',
			'Mis à jour' => 'Updated at',
			'Reglages' => 'Settings',
			'Réglages' => 'Settings',
			'Dashboard' => 'Dashboard',
			'Jobs' => 'Jobs',
			'Reviews' => 'Reviews',
			'Signals' => 'Signals',
			'Settings' => 'Settings',
			'Preparation Discover' => 'Discover readiness',
			'Checklist live' => 'Checklist live',
			'Suggestions inline par paragraphe' => 'Inline suggestions by paragraph',
			'Zones du texte a enrichir ou a rendre plus concretes.' => 'Areas of the text to enrich or make more concrete.',
			'Zones du texte à enrichir ou à rendre plus concrètes.' => 'Areas of the text to enrich or make more concrete.',
			'FAQ naturelles' => 'Natural FAQs',
			'Collaboration' => 'Collaboration',
			'Cannibalisation avant publication' => 'Cannibalization before publication',
			'Score E-E-A-T visible' => 'Visible E-E-A-T score',
			'Le contenu doit montrer un angle actuel ou une remise a jour visible.' => 'The content should show a current angle or a visible refresh.',
			'Le contenu doit montrer un angle actuel ou une remise à jour visible.' => 'The content should show a current angle or a visible refresh.',
			'Le texte doit apporter des gestes, erreurs ou details non interchangeables.' => 'The text should bring actions, mistakes or non-interchangeable details.',
			'Le texte doit apporter des gestes, erreurs ou détails non interchangeables.' => 'The text should bring actions, mistakes or non-interchangeable details.',
			'Verifier que le texte aide vraiment le lecteur avant de chercher la performance.' => 'Check that the text genuinely helps the reader before chasing performance.',
			'Vérifier que le texte aide vraiment le lecteur avant de chercher la performance.' => 'Check that the text genuinely helps the reader before chasing performance.',
			'Plusieurs contenus proches existent deja sur ce sujet.' => 'Several similar contents already exist on this topic.',
			'Plusieurs contenus proches existent déjà sur ce sujet.' => 'Several similar contents already exist on this topic.',
			'Le titre semble assez distinct pour eviter une collision forte.' => 'The title seems distinct enough to avoid a strong collision.',
			'Le titre semble assez distinct pour éviter une collision forte.' => 'The title seems distinct enough to avoid a strong collision.',
		];
	}
}
