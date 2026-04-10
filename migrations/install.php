<?php
declare(strict_types=1);

namespace DiscoopsAI;

if (! defined('ABSPATH')) {
	exit;
}

function install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = [];

	$sql[] = "CREATE TABLE " . table_name('jobs') . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id VARCHAR(100) NOT NULL,
  post_id BIGINT UNSIGNED NULL,
  job_type VARCHAR(64) NOT NULL,
  source_signal VARCHAR(64) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  payload_json LONGTEXT NOT NULL,
  lock_token VARCHAR(128) NULL,
  locked_until DATETIME NULL,
  assigned_to BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_post_id (post_id),
  KEY idx_status (status),
  KEY idx_job_type (job_type),
  KEY idx_priority (priority)
) {$charset_collate};";

	$sql[] = "CREATE TABLE " . table_name('runs') . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  workflow VARCHAR(64) NOT NULL,
  model VARCHAR(64) NOT NULL,
  input_summary LONGTEXT NULL,
  output_summary LONGTEXT NULL,
  tool_calls_json LONGTEXT NULL,
  decision_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_job_id (job_id),
  KEY idx_workflow (workflow),
  KEY idx_status (status)
) {$charset_collate};";

	$sql[] = "CREATE TABLE " . table_name('signals') . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NULL,
  external_article_id VARCHAR(100) NULL,
  signal_type VARCHAR(64) NOT NULL,
  signal_score DECIMAL(8,4) NULL,
  source VARCHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  observed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_post_id (post_id),
  KEY idx_signal_type (signal_type),
  KEY idx_observed_at (observed_at)
) {$charset_collate};";

	$sql[] = "CREATE TABLE " . table_name('reviews') . " (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  reviewer_user_id BIGINT UNSIGNED NULL,
  comment LONGTEXT NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_job_id (job_id),
  KEY idx_review_status (review_status)
) {$charset_collate};";

	foreach ($sql as $statement) {
		dbDelta($statement);
	}
}
