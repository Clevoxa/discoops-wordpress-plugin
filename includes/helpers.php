<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

function table_name(string $suffix): string {
	global $wpdb;

	return $wpdb->prefix . 'discoops_ai_' . $suffix;
}

function now_mysql(): string {
	return current_time('mysql', true);
}

function decode_json_array(?string $value): array {
	if ($value === null || $value === '') {
		return [];
	}

	$decoded = json_decode($value, true);
	return is_array($decoded) ? $decoded : [];
}

function redact_array(array $data): array {
	$redacted = [];
	$sensitive_keys = ['authorization', 'password', 'token', 'secret', 'api_key', 'app_password'];

	foreach ($data as $key => $value) {
		if (in_array((string) $key, $sensitive_keys, true)) {
			$redacted[$key] = '[REDACTED]';
			continue;
		}

		$redacted[$key] = is_array($value) ? redact_array($value) : $value;
	}

	return $redacted;
}
