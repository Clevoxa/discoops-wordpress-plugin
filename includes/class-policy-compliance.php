<?php
declare(strict_types=1);

namespace DiscoopsAI;

use WP_Post;

if (! defined('ABSPATH')) {
	exit;
}

final class PolicyCompliance {
	public function evaluate(WP_Post $post): array {
		$content = (string) $post->post_content;
		$text = trim(wp_strip_all_tags($content));
		$normalized = $this->normalize($text);
		$title = trim(wp_strip_all_tags((string) $post->post_title));
		$normalizedTitle = $this->normalize($title);
		$wordCount = $text !== '' ? count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) : 0;
		$paragraphCount = preg_match_all('/<p\b/i', $content) ?: 0;
		$headingCount = (preg_match_all('/<h[2-4]\b/i', $content) ?: 0);
		$externalLinkCount = preg_match_all('/<a\s[^>]*href=["\']https?:\/\/(?![^"\']*' . preg_quote((string) wp_parse_url(home_url('/'), PHP_URL_HOST), '/') . ')/i', $content) ?: 0;
		$internalLinkCount = preg_match_all('/<a\s[^>]*href=["\'][^"\']*' . preg_quote((string) home_url('/'), '/') . '/i', $content) ?: 0;
		$authorOk = ((int) $post->post_author) > 0;
		$dateOk = $post->post_date_gmt !== '0000-00-00 00:00:00' && $post->post_date_gmt !== '';

		$hasAgpMarkers = str_contains($content, '[[[AGP_') || str_contains($content, 'AGP_');
		$hasIllustrationMarker = str_contains($normalized, 'image illustration');
		$hasPolicyPracticalSignals = preg_match('/\b(en pratique|par exemple|si|quand|pour eviter|resultat|texture|cuisson|au moment de servir|geste|detail|erreur|comparaison)\b/u', $normalized) === 1;
		$titleLooksClicky = preg_match('/\b(incroyable|hallucinant|secret|qui change tout|magique|indecent|fait sensation|revolutionnaire|bluffant|jalouser|carton)\b/u', $normalizedTitle) === 1;
		$titleLooksGeneric = preg_match('/\b(recette facile|plat facile|dessert gourmand|astuce simple|idee repas)\b/u', $normalizedTitle) === 1;
		$repeatedTemplateSignals = preg_match_all('/\b(quand on le pose sur la table|le vrai point|le detail qui change tout|envie de|dans cette version)\b/u', $normalized) ?: 0;

		$imageId = (int) get_post_thumbnail_id($post->ID);
		$imageWidth = 0;
		$imageHeight = 0;
		$imageAlt = '';
		if ($imageId > 0) {
			$meta = wp_get_attachment_metadata($imageId);
			if (is_array($meta)) {
				$imageWidth = (int) ($meta['width'] ?? 0);
				$imageHeight = (int) ($meta['height'] ?? 0);
			}
			$imageAlt = $this->normalize((string) get_post_meta($imageId, '_wp_attachment_image_alt', true));
		}

		$items = [
			$this->buildItem(
				__('Contenu people-first', 'discoops-ai-orchestrator'),
				$wordCount >= 700 && $paragraphCount >= 5 && $hasPolicyPracticalSignals,
				$wordCount >= 500 && $paragraphCount >= 4,
				$hasPolicyPracticalSignals
					? __('Le contenu apporte deja des gestes concrets, des consequences utiles ou des exemples pratiques.', 'discoops-ai-orchestrator')
					: __('Le contenu doit apporter plus de valeur pratique visible : gestes, erreurs frequentes, exemples concrets ou consequences utiles.', 'discoops-ai-orchestrator')
			),
			$this->buildItem(
				__('Titre non sensationnaliste', 'discoops-ai-orchestrator'),
				! $titleLooksClicky && ! $titleLooksGeneric && mb_strlen($title) >= 35,
				! $titleLooksClicky && mb_strlen($title) >= 28,
				$titleLooksClicky || $titleLooksGeneric
					? __('Le titre semble trop sensationnaliste ou trop generique par rapport aux recommandations Google.', 'discoops-ai-orchestrator')
					: __('Le titre reste assez descriptif et fidele au contenu.', 'discoops-ai-orchestrator')
			),
			$this->buildItem(
				__('Transparence editoriale', 'discoops-ai-orchestrator'),
				$authorOk && $dateOk,
				$authorOk || $dateOk,
				$authorOk && $dateOk
					? __('Auteur et date sont bien presents, ce qui renforce la transparence.', 'discoops-ai-orchestrator')
					: __('Affichez clairement auteur et date pour renforcer la confiance et la lisibilite editoriale.', 'discoops-ai-orchestrator')
			),
			$this->buildItem(
				__('Signaux anti-spam', 'discoops-ai-orchestrator'),
				! $hasAgpMarkers && ! $hasIllustrationMarker && $repeatedTemplateSignals <= 1,
				! $hasAgpMarkers && $repeatedTemplateSignals <= 2,
				$hasAgpMarkers || $hasIllustrationMarker
					? __('Des marqueurs de templating ou d illustration restent visibles et peuvent affaiblir la qualite percue.', 'discoops-ai-orchestrator')
					: __('Pas de marqueur evident de templating massif ou de spam de contenu.', 'discoops-ai-orchestrator')
			),
			$this->buildItem(
				__('Visuel Discover', 'discoops-ai-orchestrator'),
				$imageWidth >= 1200 && $imageHeight >= 800 && ! str_contains($imageAlt, 'illustration'),
				$imageWidth >= 900 && $imageHeight >= 600,
				$imageWidth >= 1200
					? __('L image principale est suffisamment grande pour Discover et semble plus credible.', 'discoops-ai-orchestrator')
					: __('Ajoutez une image principale large et credible, idealement sans signal d illustration generique.', 'discoops-ai-orchestrator')
			),
			$this->buildItem(
				__('Structure et liens', 'discoops-ai-orchestrator'),
				$headingCount >= 3 && ($internalLinkCount >= 1 || $externalLinkCount <= 3),
				$headingCount >= 2,
				$headingCount >= 3
					? __('La structure est assez lisible et le maillage ne semble pas artificiel.', 'discoops-ai-orchestrator')
					: __('Renforcez la structure avec plus d intertitres et un maillage interne utile, sans surcharger en liens externes.', 'discoops-ai-orchestrator')
			),
		];

		$total = 0;
		foreach ($items as $item) {
			$total += (int) ($item['score'] ?? 0);
		}
		$score = (int) round($total / max(count($items), 1));

		$flags = [];
		foreach ($items as $item) {
			if (($item['state'] ?? '') === 'warn') {
				$flags[] = (string) ($item['title'] ?? '');
			}
		}

		return [
			'score' => $score,
			'state' => $score >= 80 ? 'good' : ($score >= 65 ? 'great' : 'warn'),
			'status_label' => $score >= 80 ? __('Conforme', 'discoops-ai-orchestrator') : ($score >= 65 ? __('A surveiller', 'discoops-ai-orchestrator') : __('Risque policy', 'discoops-ai-orchestrator')),
			'items' => $items,
			'flags' => $flags,
			'title_clickbait' => $titleLooksClicky || $titleLooksGeneric,
		];
	}

	private function buildItem(string $title, bool $good, bool $medium, string $hint): array {
		$score = $good ? 92 : ($medium ? 72 : 48);
		return [
			'title' => $title,
			'state' => $good ? 'good' : ($medium ? 'great' : 'warn'),
			'badge' => $good ? __('Conforme', 'discoops-ai-orchestrator') : ($medium ? __('A surveiller', 'discoops-ai-orchestrator') : __('A corriger', 'discoops-ai-orchestrator')),
			'hint' => $hint,
			'score' => $score,
		];
	}

	private function normalize(string $value): string {
		$value = remove_accents(wp_strip_all_tags($value));
		$value = mb_strtolower($value, 'UTF-8');
		$value = preg_replace('/\s+/u', ' ', $value) ?? '';
		return trim($value);
	}
}
