<?php
/**
 * Plugin Name: Synchy
 * Plugin URI: https://github.com/ssnanda/synchy
 * Description: Starter admin shell for Synchy backup, restore, schedule, and sync tooling.
 * Version: 0.6.1
 * Update URI: https://github.com/ssnanda/synchy
 * Author: Codex
 */

if (!defined('ABSPATH')) {
	exit;
}

const SYNCHY_VERSION = '0.6.1';
const SYNCHY_SLUG = 'synchy';
const SYNCHY_EXPORT_OPTIONS = 'synchy_export_options';
const SYNCHY_LAST_EXPORT_OPTION = 'synchy_last_export';
const SYNCHY_EXPORT_JOB_OPTION = 'synchy_export_job';
const SYNCHY_SITE_SYNC_OPTIONS = 'synchy_site_sync_options';
const SYNCHY_SITE_SYNC_JOB_OPTION = 'synchy_site_sync_job';
const SYNCHY_NOTICE_PREFIX = 'synchy_admin_notice_';

if (!defined('SYNCHY_GITHUB_REPOSITORY')) {
	define('SYNCHY_GITHUB_REPOSITORY', 'https://github.com/ssnanda/synchy');
}

if (!defined('SYNCHY_GITHUB_BRANCH')) {
	define('SYNCHY_GITHUB_BRANCH', 'main');
}

if (!defined('SYNCHY_GITHUB_RELEASE_ASSET')) {
	define('SYNCHY_GITHUB_RELEASE_ASSET', 'synchy.zip');
}

function synchy_get_plugin_basename(): string
{
	return plugin_basename(__FILE__);
}

function synchy_get_plugin_slug(): string
{
	return dirname(synchy_get_plugin_basename());
}

function synchy_parse_github_repository(string $value): string
{
	$value = trim(preg_replace('#\.git$#i', '', $value) ?? '');

	if ($value === '') {
		return '';
	}

	if (preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:/.*)?$#i', $value, $matches)) {
		return strtolower($matches[1] . '/' . $matches[2]);
	}

	if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value)) {
		return strtolower($value);
	}

	return '';
}

function synchy_get_github_update_config(): array
{
	$repository = synchy_parse_github_repository((string) apply_filters('synchy_github_repository', SYNCHY_GITHUB_REPOSITORY));
	$branch = sanitize_text_field((string) apply_filters('synchy_github_branch', SYNCHY_GITHUB_BRANCH));
	$asset_name = sanitize_file_name((string) apply_filters('synchy_github_release_asset', SYNCHY_GITHUB_RELEASE_ASSET));

	if ($branch === '') {
		$branch = 'main';
	}

	return [
		'enabled' => $repository !== '',
		'repository' => $repository,
		'branch' => $branch,
		'asset_name' => $asset_name,
		'html_url' => $repository !== '' ? 'https://github.com/' . $repository : '',
		'api_url' => $repository !== '' ? 'https://api.github.com/repos/' . $repository . '/releases/latest' : '',
	];
}

function synchy_get_github_release_cache_key(array $config): string
{
	return 'synchy_github_release_' . md5($config['repository'] . '|' . $config['asset_name']);
}

function synchy_find_github_release_asset_url(array $release, string $asset_name): string
{
	$assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];

	if ($asset_name !== '') {
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}

			if ((string) ($asset['name'] ?? '') === $asset_name && !empty($asset['browser_download_url'])) {
				return (string) $asset['browser_download_url'];
			}
		}
	}

	foreach ($assets as $asset) {
		if (!is_array($asset)) {
			continue;
		}

		$name = (string) ($asset['name'] ?? '');

		if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'zip' && !empty($asset['browser_download_url'])) {
			return (string) $asset['browser_download_url'];
		}
	}

	return '';
}

function synchy_get_github_release_data(bool $force = false)
{
	$config = synchy_get_github_update_config();

	if (empty($config['enabled'])) {
		return new WP_Error('synchy_github_updates_disabled', __('GitHub updates are not configured for Synchy.', 'synchy'));
	}

	$cache_key = synchy_get_github_release_cache_key($config);

	if (!$force) {
		$cached = get_site_transient($cache_key);

		if (is_array($cached) && !empty($cached['version']) && !empty($cached['package_url'])) {
			return $cached;
		}
	}

	$response = wp_remote_get(
		$config['api_url'],
		[
			'timeout' => 20,
			'headers' => [
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'Synchy/' . SYNCHY_VERSION . '; ' . wp_parse_url(home_url('/'), PHP_URL_HOST),
			],
		]
	);

	if (is_wp_error($response)) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	$body = (string) wp_remote_retrieve_body($response);
	$release = json_decode($body, true);

	if ($code < 200 || $code >= 300 || !is_array($release)) {
		return new WP_Error('synchy_github_release_request_failed', __('Synchy could not read the latest GitHub release metadata.', 'synchy'));
	}

	$version = ltrim((string) ($release['tag_name'] ?? ''), 'vV');
	$package_url = synchy_find_github_release_asset_url($release, (string) $config['asset_name']);

	if ($version === '' || $package_url === '') {
		return new WP_Error('synchy_github_release_invalid', __('Synchy could not find a valid version tag and zip asset in the latest GitHub release.', 'synchy'));
	}

	$data = [
		'version' => $version,
		'package_url' => $package_url,
		'html_url' => (string) ($release['html_url'] ?? $config['html_url']),
		'name' => (string) ($release['name'] ?? 'Synchy'),
		'body' => (string) ($release['body'] ?? ''),
		'published_at' => (string) ($release['published_at'] ?? ''),
		'repository_url' => (string) $config['html_url'],
	];

	set_site_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);

	return $data;
}

function synchy_get_github_release_sections(array $release): array
{
	$repository_url = !empty($release['repository_url']) ? esc_url($release['repository_url']) : '';
	$description = $repository_url === ''
		? '<p>' . esc_html__('Synchy updates are served from a public GitHub release feed.', 'synchy') . '</p>'
		: '<p>' . sprintf(
			/* translators: %s: GitHub repository URL */
			esc_html__('Synchy updates are served from %s.', 'synchy'),
			'<a href="' . $repository_url . '" target="_blank" rel="noopener noreferrer">' . esc_html($repository_url) . '</a>'
		) . '</p>';
	$changelog_body = trim((string) ($release['body'] ?? ''));
	$changelog = $changelog_body === ''
		? '<p>' . esc_html__('No changelog was provided in the latest GitHub release.', 'synchy') . '</p>'
		: wpautop(esc_html($changelog_body));

	return [
		'description' => $description,
		'changelog' => $changelog,
	];
}

function synchy_build_github_update_payload(array $release): object
{
	$plugin_file = synchy_get_plugin_basename();
	$slug = synchy_get_plugin_slug();

	return (object) [
		'id' => $release['html_url'] ?? '',
		'slug' => $slug,
		'plugin' => $plugin_file,
		'new_version' => (string) ($release['version'] ?? SYNCHY_VERSION),
		'url' => (string) ($release['html_url'] ?? ''),
		'package' => (string) ($release['package_url'] ?? ''),
		'icons' => [],
		'banners' => [],
		'banners_rtl' => [],
		'tested' => '',
		'requires' => '',
		'requires_php' => '',
	];
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
	if (!is_object($transient)) {
		$transient = new stdClass();
	}

	$config = synchy_get_github_update_config();

	if (empty($config['enabled'])) {
		return $transient;
	}

	$release = synchy_get_github_release_data();

	if (is_wp_error($release)) {
		return $transient;
	}

	$plugin_file = synchy_get_plugin_basename();
	$payload = synchy_build_github_update_payload($release);

	if (version_compare((string) $release['version'], SYNCHY_VERSION, '>')) {
		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = [];
		}

		$transient->response[$plugin_file] = $payload;
		return $transient;
	}

	if (!isset($transient->no_update) || !is_array($transient->no_update)) {
		$transient->no_update = [];
	}

	$transient->no_update[$plugin_file] = $payload;

	return $transient;
});

add_filter('plugins_api', function ($result, string $action, $args) {
	if ($action !== 'plugin_information' || !is_object($args) || (($args->slug ?? '') !== synchy_get_plugin_slug())) {
		return $result;
	}

	$config = synchy_get_github_update_config();

	if (empty($config['enabled'])) {
		return $result;
	}

	$release = synchy_get_github_release_data();

	if (is_wp_error($release)) {
		return $result;
	}

	$sections = synchy_get_github_release_sections($release);

	return (object) [
		'name' => 'Synchy',
		'slug' => synchy_get_plugin_slug(),
		'version' => (string) ($release['version'] ?? SYNCHY_VERSION),
		'author' => '<a href="https://github.com/' . esc_attr(str_replace('https://github.com/', '', (string) ($release['repository_url'] ?? ''))) . '">Synchy</a>',
		'author_profile' => (string) ($release['repository_url'] ?? ''),
		'homepage' => (string) ($release['html_url'] ?? $release['repository_url'] ?? ''),
		'download_link' => (string) ($release['package_url'] ?? ''),
		'last_updated' => (string) ($release['published_at'] ?? ''),
		'sections' => $sections,
		'banners' => [],
	];
}, 20, 3);

add_action('upgrader_process_complete', function ($upgrader, array $hook_extra): void {
	if (($hook_extra['type'] ?? '') !== 'plugin' || empty($hook_extra['plugins']) || !is_array($hook_extra['plugins'])) {
		return;
	}

	if (!in_array(synchy_get_plugin_basename(), $hook_extra['plugins'], true)) {
		return;
	}

	$config = synchy_get_github_update_config();

	if (empty($config['enabled'])) {
		return;
	}

	delete_site_transient(synchy_get_github_release_cache_key($config));
	delete_site_transient('update_plugins');
}, 10, 2);

function synchy_get_pages(): array
{
	return [
		[
			'slug' => SYNCHY_SLUG,
			'title' => __('Overview', 'synchy'),
			'menu_title' => __('Overview', 'synchy'),
			'headline' => __('Synchy', 'synchy'),
			'description' => __('WordPress backup and site sync tools, starting with a clean admin foundation.', 'synchy'),
		],
		[
			'slug' => 'synchy-export',
			'title' => __('Export Backups', 'synchy'),
			'menu_title' => __('Export Backups', 'synchy'),
			'headline' => __('Export Backups', 'synchy'),
			'description' => __('Create on-demand site packages with an archive and installer workflow.', 'synchy'),
		],
		[
			'slug' => 'synchy-import',
			'title' => __('Import and Restore', 'synchy'),
			'menu_title' => __('Import and Restore', 'synchy'),
			'headline' => __('Import and Restore', 'synchy'),
			'description' => __('Restore a site from a Synchy package and safely replace the current install.', 'synchy'),
		],
		[
			'slug' => 'synchy-scheduled-backups',
			'title' => __('Scheduled Backups', 'synchy'),
			'menu_title' => __('Scheduled Backups', 'synchy'),
			'headline' => __('Scheduled Backups', 'synchy'),
			'description' => __('Automate recurring backups with retention and destination controls.', 'synchy'),
		],
		[
			'slug' => 'synchy-site-sync',
			'title' => __('Site Sync', 'synchy'),
			'menu_title' => __('Site Sync', 'synchy'),
			'headline' => __('Site Sync', 'synchy'),
			'description' => __('Connect one WordPress site to another and sync from a chosen source.', 'synchy'),
		],
		[
			'slug' => 'synchy-settings',
			'title' => __('Settings', 'synchy'),
			'menu_title' => __('Settings', 'synchy'),
			'headline' => __('Settings', 'synchy'),
			'description' => __('Configure storage paths, credentials, and default behavior for Synchy.', 'synchy'),
		],
	];
}

function synchy_get_default_output_directory(): string
{
	return 'wp-content/uploads/synchy-backups/';
}

function synchy_get_default_package_name(): string
{
	$host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

	if ($host === '') {
		$host = 'site';
	}

	$host = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $host) ?: 'site');
	$host = trim($host, '-');

	if ($host === '') {
		$host = 'site';
	}

	return 'synchy-' . $host . '-export';
}

function synchy_get_export_defaults(): array
{
	return [
		'package_mode' => 'full_export',
		'output_directory' => synchy_get_default_output_directory(),
		'package_name' => '',
		'exclude_vcs' => 1,
		'exclude_local_dev' => 1,
		'exclude_os_junk' => 1,
		'exclude_cache_temp' => 1,
		'exclude_existing_backups' => 1,
		'exclude_dev_artifacts' => 1,
		'custom_excludes' => '',
	];
}

function synchy_normalize_relative_path(string $path): string
{
	$path = wp_normalize_path($path);
	$path = trim($path);
	$path = preg_replace('#/+#', '/', $path);

	return trim((string) $path, '/');
}

function synchy_sanitize_package_name(string $value): string
{
	$value = sanitize_text_field($value);
	$value = preg_replace('/\.(zip|php|json)$/i', '', $value);
	$value = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $value);
	$value = trim((string) $value);
	$value = preg_replace('/\s+/', '-', $value);
	$value = preg_replace('/-+/', '-', (string) $value);
	$value = trim((string) $value, '-_.');

	if ($value === '') {
		$value = synchy_get_default_package_name();
	}

	return strtolower($value);
}

function synchy_sanitize_output_directory(string $value): string
{
	$value = sanitize_text_field($value);
	$value = str_replace('\\', '/', $value);
	$value = trim($value);

	if ($value === '') {
		$value = synchy_get_default_output_directory();
	}

	$is_absolute = (bool) preg_match('#^([A-Za-z]:)?/#', $value);
	$value = preg_replace('#/+#', '/', $value);

	if ($is_absolute) {
		return untrailingslashit($value) . '/';
	}

	$value = ltrim($value, '/');

	return trailingslashit(synchy_normalize_relative_path($value));
}

function synchy_sanitize_export_options($value): array
{
	$value = is_array($value) ? $value : [];
	$defaults = synchy_get_export_defaults();
	$sanitized = [];

	foreach ($defaults as $key => $default) {
		if ($key === 'package_mode') {
			$sanitized[$key] = 'full_export';
			continue;
		}

		if ($key === 'output_directory') {
			$raw = isset($value[$key]) ? (string) $value[$key] : '';
			$sanitized[$key] = synchy_sanitize_output_directory($raw);
			continue;
		}

		if ($key === 'package_name') {
			$raw = isset($value[$key]) ? (string) $value[$key] : '';
			$raw = sanitize_text_field($raw);
			$sanitized[$key] = trim($raw);
			continue;
		}

		if ($key === 'custom_excludes') {
			$raw = isset($value[$key]) ? (string) $value[$key] : '';
			$lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
			$lines = array_map('trim', $lines);
			$lines = array_filter($lines, static fn(string $line): bool => $line !== '');
			$sanitized[$key] = implode("\n", $lines);
			continue;
		}

		$sanitized[$key] = empty($value[$key]) ? 0 : 1;
	}

	return $sanitized;
}

function synchy_get_export_options(): array
{
	$saved = get_option(SYNCHY_EXPORT_OPTIONS, []);

	if (!is_array($saved)) {
		$saved = [];
	}

	$options = wp_parse_args($saved, synchy_get_export_defaults());
	$options = synchy_sanitize_export_options($options);

	if ($options['package_name'] === '') {
		$options['package_name'] = synchy_get_default_package_name();
	}

	return $options;
}

function synchy_get_site_sync_defaults(): array
{
	return [
		'destination_url' => '',
		'destination_username' => '',
		'destination_application_password' => '',
		'verify_ssl' => 1,
	];
}

function synchy_sanitize_site_sync_url(string $value): string
{
	$value = trim($value);

	if ($value === '') {
		return '';
	}

	$value = esc_url_raw($value, ['http', 'https']);

	return $value === '' ? '' : untrailingslashit($value);
}

function synchy_normalize_application_password(string $value): string
{
	$value = sanitize_text_field($value);

	return (string) preg_replace('/\s+/', '', $value);
}

function synchy_sanitize_site_sync_options($value): array
{
	$value = is_array($value) ? $value : [];
	$existing = get_option(SYNCHY_SITE_SYNC_OPTIONS, []);

	if (!is_array($existing)) {
		$existing = [];
	}

	$sanitized = synchy_get_site_sync_defaults();
	$sanitized['destination_url'] = synchy_sanitize_site_sync_url((string) ($value['destination_url'] ?? ''));
	$sanitized['destination_username'] = trim(sanitize_text_field((string) ($value['destination_username'] ?? '')));

	$raw_password = isset($value['destination_application_password']) ? (string) $value['destination_application_password'] : '';
	$normalized_password = synchy_normalize_application_password($raw_password);

	if ($normalized_password === '' && !empty($existing['destination_application_password'])) {
		$normalized_password = (string) $existing['destination_application_password'];
	}

	$sanitized['destination_application_password'] = $normalized_password;
	$sanitized['verify_ssl'] = empty($value['verify_ssl']) ? 0 : 1;

	return $sanitized;
}

function synchy_get_site_sync_options(): array
{
	$saved = get_option(SYNCHY_SITE_SYNC_OPTIONS, []);

	if (!is_array($saved)) {
		$saved = [];
	}

	$options = wp_parse_args($saved, synchy_get_site_sync_defaults());

	return synchy_sanitize_site_sync_options($options);
}

function synchy_get_site_sync_password_hint(array $options): string
{
	$password = (string) ($options['destination_application_password'] ?? '');

	if ($password === '') {
		return __('No application password is saved yet.', 'synchy');
	}

	return sprintf(
		/* translators: %s: final 4 characters of the saved application password */
		__('Application password saved. Ending in %s.', 'synchy'),
		substr($password, -4)
	);
}

function synchy_get_export_included_items(): array
{
	return [
		[
			'label' => __('WordPress database', 'synchy'),
			'description' => __('Full SQL export of the current WordPress database.', 'synchy'),
		],
		[
			'label' => __('WordPress core files', 'synchy'),
			'description' => __('Root files plus wp-admin and wp-includes for a portable full-site package.', 'synchy'),
		],
		[
			'label' => __('Plugins', 'synchy'),
			'description' => __('Everything in wp-content/plugins needed by the live site.', 'synchy'),
		],
		[
			'label' => __('Themes', 'synchy'),
			'description' => __('All themes in wp-content/themes so the package is migration-ready.', 'synchy'),
		],
		[
			'label' => __('MU plugins', 'synchy'),
			'description' => __('Any must-use plugins in wp-content/mu-plugins.', 'synchy'),
		],
		[
			'label' => __('Uploads and media', 'synchy'),
			'description' => __('The full uploads library from wp-content/uploads.', 'synchy'),
		],
		[
			'label' => __('Package metadata', 'synchy'),
			'description' => __('Manifest details like site URL, timestamps, versions, checksums.', 'synchy'),
		],
	];
}

function synchy_get_export_filter_groups(): array
{
	return [
		'exclude_vcs' => [
			'label' => __('Version control artifacts', 'synchy'),
			'description' => __('Skip repository metadata and automation files that do not belong in a restore package.', 'synchy'),
			'patterns' => ['.git/', '.github/', '.gitignore', '.gitattributes', '.gitlab/', '.svn/', '.hg/'],
		],
		'exclude_local_dev' => [
			'label' => __('Local development files', 'synchy'),
			'description' => __('Skip machine-specific folders and environment files used for local tooling and editors.', 'synchy'),
			'patterns' => ['.ddev/', '.idea/', '.vscode/', '*.code-workspace', '.env', '.env.*'],
		],
		'exclude_os_junk' => [
			'label' => __('Operating system junk', 'synchy'),
			'description' => __('Exclude Mac and Windows metadata that should never be migrated.', 'synchy'),
			'patterns' => ['.DS_Store', '__MACOSX/', 'Thumbs.db', 'desktop.ini'],
		],
		'exclude_cache_temp' => [
			'label' => __('Cache and temporary files', 'synchy'),
			'description' => __('Exclude runtime caches, upgrade leftovers, and transient logs.', 'synchy'),
			'patterns' => ['wp-content/cache/', 'wp-content/upgrade/', 'wp-content/uploads/cache/', 'error_log', 'debug.log'],
		],
		'exclude_existing_backups' => [
			'label' => __('Existing backup archives', 'synchy'),
			'description' => __('Prevent recursive backup-of-backup packages and stale restore artifacts.', 'synchy'),
			'patterns' => ['wp-content/ai1wm-backups/', 'wp-content/updraft/', 'wp-content/backups/', 'wp-content/duplicator/', 'wp-content/uploads/synchy-backups/', 'wp-snapshots/'],
		],
		'exclude_dev_artifacts' => [
			'label' => __('Development build artifacts', 'synchy'),
			'description' => __('Exclude folders that are usually safe to omit from a production restore package.', 'synchy'),
			'patterns' => ['node_modules/', 'coverage/', '.sass-cache/'],
		],
	];
}

function synchy_get_notice_key(): string
{
	$user_id = get_current_user_id();

	return SYNCHY_NOTICE_PREFIX . ($user_id > 0 ? (string) $user_id : 'guest');
}

function synchy_set_notice(string $type, string $message): void
{
	set_transient(
		synchy_get_notice_key(),
		[
			'type' => $type,
			'message' => $message,
		],
		120
	);
}

function synchy_get_notice(): ?array
{
	$notice = get_transient(synchy_get_notice_key());

	if (!is_array($notice) || empty($notice['message'])) {
		return null;
	}

	delete_transient(synchy_get_notice_key());

	return $notice;
}

function synchy_render_notice(): void
{
	$notice = synchy_get_notice();

	if ($notice === null) {
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
		$settings_updated = isset($_GET['settings-updated']) ? sanitize_text_field(wp_unslash((string) $_GET['settings-updated'])) : '';

		if ($page === 'synchy-site-sync' && $settings_updated === 'true') {
			$notice = [
				'type' => 'success',
				'message' => __('Site Sync settings saved.', 'synchy'),
			];
		} else {
			return;
		}
	}

	$class = $notice['type'] === 'error' ? 'notice notice-error' : 'notice notice-success';
	?>
	<div class="<?php echo esc_attr($class); ?>">
		<p><?php echo esc_html((string) $notice['message']); ?></p>
	</div>
	<?php
}

function synchy_get_last_export(): array
{
	$value = get_option(SYNCHY_LAST_EXPORT_OPTION, []);

	return is_array($value) ? $value : [];
}

function synchy_update_export_job(array $job): array
{
	update_option(SYNCHY_EXPORT_JOB_OPTION, $job, false);

	return $job;
}

function synchy_get_export_job(): array
{
	$value = get_option(SYNCHY_EXPORT_JOB_OPTION, []);

	return is_array($value) ? $value : [];
}

function synchy_get_running_export_job(): array
{
	$job = synchy_get_export_job();

	if (($job['status'] ?? '') !== 'running') {
		return [];
	}

	return $job;
}

function synchy_update_site_sync_job(array $job): array
{
	update_option(SYNCHY_SITE_SYNC_JOB_OPTION, $job, false);

	return $job;
}

function synchy_get_site_sync_job(): array
{
	$value = get_option(SYNCHY_SITE_SYNC_JOB_OPTION, []);

	return is_array($value) ? $value : [];
}

function synchy_get_running_site_sync_job(): array
{
	$job = synchy_get_site_sync_job();

	if (($job['status'] ?? '') !== 'running') {
		return [];
	}

	return $job;
}

function synchy_absolute_to_relative(string $absolute_path, ?string $root = null): string
{
	$root = $root === null ? wp_normalize_path(untrailingslashit(ABSPATH)) : wp_normalize_path(untrailingslashit($root));
	$absolute_path = wp_normalize_path($absolute_path);

	if ($absolute_path === $root) {
		return '';
	}

	$prefix = $root . '/';

	if (!str_starts_with($absolute_path, $prefix)) {
		return synchy_normalize_relative_path($absolute_path);
	}

	return synchy_normalize_relative_path(substr($absolute_path, strlen($prefix)));
}

function synchy_is_absolute_path(string $path): bool
{
	return (bool) preg_match('#^([A-Za-z]:)?/#', wp_normalize_path($path));
}

function synchy_is_path_within(string $path, string $root): bool
{
	$path = wp_normalize_path(untrailingslashit($path));
	$root = wp_normalize_path(untrailingslashit($root));

	return $path === $root || str_starts_with($path . '/', $root . '/');
}

function synchy_resolve_output_directory_path(string $path)
{
	$path = synchy_sanitize_output_directory($path);

	if ($path === '') {
		$path = synchy_get_default_output_directory();
	}

	if (synchy_is_absolute_path($path)) {
		return wp_normalize_path(untrailingslashit($path));
	}

	return wp_normalize_path(untrailingslashit(trailingslashit(ABSPATH) . ltrim($path, '/')));
}

function synchy_display_output_directory(string $absolute_path): string
{
	$root = wp_normalize_path(untrailingslashit(ABSPATH));
	$absolute_path = wp_normalize_path(untrailingslashit($absolute_path));

	if (synchy_is_path_within($absolute_path, $root)) {
		$relative = synchy_absolute_to_relative($absolute_path, $root);

		return $relative === '' ? './' : trailingslashit($relative);
	}

	return trailingslashit($absolute_path);
}

function synchy_get_effective_exclude_patterns(array $options, string $output_directory_abs = ''): array
{
	$groups = synchy_get_export_filter_groups();
	$patterns = [];

	foreach ($groups as $key => $group) {
		if (empty($options[$key])) {
			continue;
		}

		foreach ($group['patterns'] as $pattern) {
			$patterns[] = synchy_normalize_relative_path($pattern);
		}
	}

	$custom = preg_split('/\r\n|\r|\n/', (string) ($options['custom_excludes'] ?? '')) ?: [];

	foreach ($custom as $pattern) {
		$pattern = synchy_normalize_relative_path($pattern);

		if ($pattern !== '') {
			$patterns[] = $pattern;
		}
	}

	if ($output_directory_abs !== '') {
		$root = wp_normalize_path(untrailingslashit(ABSPATH));
		$output_directory_abs = wp_normalize_path(untrailingslashit($output_directory_abs));

		if (synchy_is_path_within($output_directory_abs, $root)) {
			$patterns[] = trailingslashit(synchy_absolute_to_relative($output_directory_abs, $root));
		}
	}

	$patterns[] = 'wp-content/uploads/synchy-temp/';

	return array_values(array_unique(array_filter($patterns)));
}

function synchy_path_matches_pattern(string $relative_path, string $pattern, bool $is_dir): bool
{
	$relative_path = synchy_normalize_relative_path($relative_path);
	$pattern = synchy_normalize_relative_path($pattern);

	if ($relative_path === '' || $pattern === '') {
		return false;
	}

	$basename = wp_basename($relative_path);
	$is_prefix_pattern = str_ends_with($pattern, '/');
	$has_glob = strpbrk($pattern, '*?[') !== false;

	if ($is_prefix_pattern) {
		$prefix = rtrim($pattern, '/');

		return $relative_path === $prefix || str_starts_with($relative_path . '/', $prefix . '/');
	}

	if ($has_glob) {
		return fnmatch($pattern, $relative_path, FNM_PATHNAME) || fnmatch($pattern, $basename);
	}

	if (strpos($pattern, '/') === false) {
		if ($basename === $pattern) {
			return true;
		}

		return $is_dir && $relative_path === $pattern;
	}

	return $relative_path === $pattern;
}

function synchy_should_exclude_relative_path(string $relative_path, array $patterns, bool $is_dir): bool
{
	foreach ($patterns as $pattern) {
		if (synchy_path_matches_pattern($relative_path, (string) $pattern, $is_dir)) {
			return true;
		}
	}

	return false;
}

function synchy_collect_export_files(array $exclude_patterns)
{
	$root = wp_normalize_path(untrailingslashit(ABSPATH));

	try {
		$directory = new RecursiveDirectoryIterator(
			$root,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
		);
		$filter = new RecursiveCallbackFilterIterator(
			$directory,
			static function (SplFileInfo $current) use ($exclude_patterns, $root): bool {
				$absolute = wp_normalize_path($current->getPathname());
				$relative = synchy_absolute_to_relative($absolute, $root);

				if ($relative === '') {
					return true;
				}

				if ($current->isLink()) {
					return false;
				}

				return !synchy_should_exclude_relative_path($relative, $exclude_patterns, $current->isDir());
			}
		);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
	} catch (UnexpectedValueException $exception) {
		return new WP_Error('synchy_iterator_failed', $exception->getMessage());
	}

	$files = [];
	$total_bytes = 0;

	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || !$file->isFile()) {
			continue;
		}

		$absolute = wp_normalize_path($file->getPathname());

		if (!is_readable($absolute)) {
			return new WP_Error('synchy_unreadable_file', sprintf(__('Synchy could not read %s while building the export.', 'synchy'), $absolute));
		}

		$relative = synchy_absolute_to_relative($absolute, $root);
		$size = (int) $file->getSize();

		$files[] = [
			'absolute' => $absolute,
			'relative' => $relative,
			'size' => $size,
		];

		$total_bytes += $size;
	}

	return [
		'files' => $files,
		'count' => count($files),
		'bytes' => $total_bytes,
	];
}

function synchy_get_zip_batch_size(): int
{
	$batch_size = (int) apply_filters('synchy_export_zip_batch_size', 500);

	return max(100, $batch_size);
}

function synchy_get_zip_build_mode(): string
{
	$mode = (string) apply_filters('synchy_export_zip_build_mode', 'continuous');

	return $mode === 'batched' ? 'batched' : 'continuous';
}

function synchy_get_zip_progress_update_interval(): int
{
	$interval = (int) apply_filters('synchy_export_zip_progress_update_interval', 500);

	return max(100, $interval);
}

function synchy_should_store_without_compression(string $relative_path): bool
{
	$extension = strtolower((string) pathinfo($relative_path, PATHINFO_EXTENSION));

	if ($extension === '') {
		return false;
	}

	$stored_extensions = [
		'7z',
		'avif',
		'bz2',
		'gif',
		'gz',
		'heic',
		'heif',
		'ico',
		'jpeg',
		'jpg',
		'mov',
		'mp3',
		'mp4',
		'pdf',
		'png',
		'rar',
		'svgz',
		'webm',
		'webp',
		'woff',
		'woff2',
		'zip',
	];

	return in_array($extension, $stored_extensions, true);
}

function synchy_maybe_optimize_zip_entry(ZipArchive $zip, string $relative_path): void
{
	if (!method_exists($zip, 'setCompressionName')) {
		return;
	}

	if (!synchy_should_store_without_compression($relative_path)) {
		return;
	}

	@$zip->setCompressionName($relative_path, ZipArchive::CM_STORE);
}

function synchy_get_export_zip_progress(int $cursor, int $total): int
{
	if ($total <= 0) {
		return 95;
	}

	return (int) floor(35 + (60 * ($cursor / $total)));
}

function synchy_get_export_zip_message(int $cursor, int $total): string
{
	return sprintf(
		/* translators: 1: current file count, 2: total file count */
		__('Added %1$s of %2$s files to the archive.', 'synchy'),
		number_format_i18n($cursor),
		number_format_i18n($total)
	);
}

function synchy_finalize_export_job(array $job): array
{
	$manifest = synchy_build_manifest($job);
	$manifest_path = (string) $job['artifact_paths']['manifest'];
	$installer_path = (string) $job['artifact_paths']['installer'];
	$manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	if ($manifest_json === false || file_put_contents($manifest_path, $manifest_json) === false) {
		return synchy_mark_export_job_error($job, __('Synchy could not write the manifest file.', 'synchy'));
	}

	$installer_contents = synchy_generate_installer_contents($manifest);

	if ($installer_contents === '' || file_put_contents($installer_path, $installer_contents) === false) {
		return synchy_mark_export_job_error($job, __('Synchy could not write the installer file.', 'synchy'));
	}

	synchy_rrmdir((string) $job['temp_dir']);

	$last_export = [
		'package_id' => $job['package_id'],
		'package_name' => $job['package_name'],
		'created_at' => gmdate('c'),
		'output_directory' => $job['output_directory'],
		'archive_size' => (int) filesize((string) $job['artifact_paths']['archive']),
		'file_count' => (int) $job['file_count'],
		'file_bytes' => (int) $job['file_bytes'],
		'table_count' => (int) $job['table_count'],
		'artifacts' => [
			'archive' => [
				'label' => __('Download archive', 'synchy'),
				'filename' => $job['artifact_names']['archive'],
				'path' => $job['artifact_paths']['archive'],
			],
			'installer' => [
				'label' => __('Download installer', 'synchy'),
				'filename' => $job['artifact_names']['installer'],
				'path' => $job['artifact_paths']['installer'],
			],
			'manifest' => [
				'label' => __('Download manifest', 'synchy'),
				'filename' => $job['artifact_names']['manifest'],
				'path' => $job['artifact_paths']['manifest'],
			],
		],
	];

	update_option(SYNCHY_LAST_EXPORT_OPTION, $last_export, false);
	synchy_set_notice(
		'success',
		sprintf(
			/* translators: %s: export package name */
			__('Full export complete. %s is ready to download.', 'synchy'),
			$job['package_name']
		)
	);

	$job['status'] = 'complete';
	$job['phase'] = 'complete';
	$job['message'] = __('Export complete. Refreshing the page with download links.', 'synchy');
	$job['progress'] = 100;

	return synchy_update_export_job($job);
}

function synchy_process_export_zip_in_batches(array $job, array $files): array
{
	$cursor = (int) $job['cursor'];
	$total = count($files);
	$batch_size = synchy_get_zip_batch_size();
	$batch_end = min($cursor + $batch_size, $total);
	$archive_path = (string) $job['artifact_paths']['archive'];
	$flags = $cursor === 0 ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE;
	$zip = new ZipArchive();
	$open_result = $zip->open($archive_path, $flags);

	if ($open_result !== true) {
		return synchy_mark_export_job_error($job, __('Synchy could not open the export archive for writing.', 'synchy'));
	}

	if ($cursor === 0 && !$zip->addFile((string) $job['database_path'], 'synchy/database.sql')) {
		$zip->close();
		return synchy_mark_export_job_error($job, __('Synchy could not add the database dump to the export archive.', 'synchy'));
	}

	for ($index = $cursor; $index < $batch_end; $index++) {
		$file = $files[$index];
		$relative_path = (string) $file['relative'];

		if (!$zip->addFile((string) $file['absolute'], $relative_path)) {
			$zip->close();
			return synchy_mark_export_job_error($job, sprintf(__('Synchy could not add %s to the archive.', 'synchy'), $relative_path));
		}

		synchy_maybe_optimize_zip_entry($zip, $relative_path);
	}

	$zip->close();
	$cursor = $batch_end;
	$job['cursor'] = $cursor;
	$job['progress'] = synchy_get_export_zip_progress($cursor, $total);

	if ($cursor >= $total) {
		$job['phase'] = 'finalizing';
		$job['message'] = __('Writing the manifest and installer files.', 'synchy');
		$job['progress'] = 96;

		return synchy_finalize_export_job($job);
	}

	$job['message'] = synchy_get_export_zip_message($cursor, $total);

	return synchy_update_export_job($job);
}

function synchy_process_export_zip_continuously(array $job, array $files): array
{
	$cursor = (int) $job['cursor'];
	$total = count($files);
	$archive_path = (string) $job['artifact_paths']['archive'];
	$flags = $cursor === 0 ? ZipArchive::CREATE | ZipArchive::OVERWRITE : ZipArchive::CREATE;
	$zip = new ZipArchive();
	$open_result = $zip->open($archive_path, $flags);

	if ($open_result !== true) {
		return synchy_mark_export_job_error($job, __('Synchy could not open the export archive for writing.', 'synchy'));
	}

	if ($cursor === 0 && !$zip->addFile((string) $job['database_path'], 'synchy/database.sql')) {
		$zip->close();
		return synchy_mark_export_job_error($job, __('Synchy could not add the database dump to the export archive.', 'synchy'));
	}

	$progress_interval = synchy_get_zip_progress_update_interval();

	for ($index = $cursor; $index < $total; $index++) {
		$file = $files[$index];
		$relative_path = (string) $file['relative'];

		if (!$zip->addFile((string) $file['absolute'], $relative_path)) {
			$zip->close();
			return synchy_mark_export_job_error($job, sprintf(__('Synchy could not add %s to the archive.', 'synchy'), $relative_path));
		}

		synchy_maybe_optimize_zip_entry($zip, $relative_path);

		$cursor = $index + 1;

		if ($cursor < $total && $cursor % $progress_interval === 0) {
			$job['cursor'] = $cursor;
			$job['progress'] = synchy_get_export_zip_progress($cursor, $total);
			$job['message'] = synchy_get_export_zip_message($cursor, $total);
			synchy_update_export_job($job);
		}
	}

	$close_result = $zip->close();

	if ($close_result !== true) {
		return synchy_mark_export_job_error($job, __('Synchy could not finish writing the export archive.', 'synchy'));
	}

	$job['cursor'] = $total;
	$job['progress'] = 96;
	$job['phase'] = 'finalizing';
	$job['message'] = __('Writing the manifest and installer files.', 'synchy');

	return synchy_finalize_export_job($job);
}

function synchy_process_export_zip_phase(array $job): array
{
	$files = synchy_get_export_file_manifest($job);

	if ($files === []) {
		return synchy_mark_export_job_error($job, __('Synchy could not load the file list for this export.', 'synchy'));
	}

	if (synchy_get_zip_build_mode() === 'batched') {
		return synchy_process_export_zip_in_batches($job, $files);
	}

	return synchy_process_export_zip_continuously($job, $files);
}

function synchy_sql_escape_string(string $value): string
{
	return strtr(
		$value,
		[
			'\\' => '\\\\',
			"\0" => '\\0',
			"\n" => '\\n',
			"\r" => '\\r',
			"'" => "\\'",
			"\x1a" => '\\Z',
		]
	);
}

function synchy_sql_value($value): string
{
	if ($value === null) {
		return 'NULL';
	}

	if (is_bool($value)) {
		return $value ? '1' : '0';
	}

	return "'" . synchy_sql_escape_string((string) $value) . "'";
}

function synchy_write_database_dump(string $file_path)
{
	global $wpdb;

	$handle = fopen($file_path, 'wb');

	if ($handle === false) {
		return new WP_Error('synchy_db_dump_open_failed', __('Synchy could not create the SQL dump file.', 'synchy'));
	}

	$write = static function ($content) use ($handle): void {
		fwrite($handle, (string) $content);
	};

	$write("-- Synchy SQL Dump\n");
	$write('-- Site: ' . home_url('/') . "\n");
	$write('-- Generated: ' . gmdate('c') . "\n\n");
	$write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
	$write("SET FOREIGN_KEY_CHECKS = 0;\n\n");

	$tables = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);

	if (!is_array($tables)) {
		fclose($handle);

		return new WP_Error('synchy_db_tables_failed', __('Synchy could not read the database table list.', 'synchy'));
	}

	$table_count = 0;

	foreach ($tables as $table_row) {
		$table = isset($table_row[0]) ? (string) $table_row[0] : '';
		$type = strtoupper((string) ($table_row[1] ?? 'BASE TABLE'));

		if ($table === '') {
			continue;
		}

		$table_name = str_replace('`', '``', $table);
		$table_count++;

		if ($type === 'VIEW') {
			$create_view = $wpdb->get_row("SHOW CREATE VIEW `{$table_name}`", ARRAY_N);

			if (!is_array($create_view) || empty($create_view[1])) {
				fclose($handle);

				return new WP_Error('synchy_db_view_failed', sprintf(__('Synchy could not export the database view %s.', 'synchy'), $table));
			}

			$write("-- View: {$table}\n");
			$write("DROP VIEW IF EXISTS `{$table_name}`;\n");
			$write($create_view[1] . ";\n\n");
			continue;
		}

		$create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);

		if (!is_array($create_table) || empty($create_table[1])) {
			fclose($handle);

			return new WP_Error('synchy_db_create_failed', sprintf(__('Synchy could not export the table definition for %s.', 'synchy'), $table));
		}

		$write("-- Table: {$table}\n");
		$write("DROP TABLE IF EXISTS `{$table_name}`;\n");
		$write($create_table[1] . ";\n\n");

		$total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");

		if ($total_rows < 1) {
			$write("\n");
			continue;
		}

		$limit = 250;

		for ($offset = 0; $offset < $total_rows; $offset += $limit) {
			$query = $wpdb->prepare("SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $limit, $offset);
			$rows = $wpdb->get_results($query, ARRAY_A);

			if (!is_array($rows)) {
				fclose($handle);

				return new WP_Error('synchy_db_rows_failed', sprintf(__('Synchy could not read rows from %s.', 'synchy'), $table));
			}

			foreach ($rows as $row) {
				$columns = array_map(
					static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
					array_keys($row)
				);
				$values = array_map('synchy_sql_value', array_values($row));

				$write(
					'INSERT INTO `' . $table_name . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
				);
			}
		}

		$write("\n");
	}

	$write("SET FOREIGN_KEY_CHECKS = 1;\n");
	fclose($handle);

	return [
		'tables' => $table_count,
		'size' => (int) filesize($file_path),
	];
}

function synchy_rrmdir(string $path): void
{
	if (!file_exists($path)) {
		return;
	}

	if (is_file($path) || is_link($path)) {
		@unlink($path);
		return;
	}

	$items = scandir($path);

	if (!is_array($items)) {
		return;
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}

		synchy_rrmdir($path . DIRECTORY_SEPARATOR . $item);
	}

	@rmdir($path);
}

function synchy_export_phase_label(string $phase): string
{
	return match ($phase) {
		'queued' => __('Queued', 'synchy'),
		'dumping_database' => __('Database', 'synchy'),
		'scanning_files' => __('Scanning Files', 'synchy'),
		'zipping_files' => __('Building Archive', 'synchy'),
		'finalizing' => __('Finalizing', 'synchy'),
		'complete' => __('Complete', 'synchy'),
		'error' => __('Error', 'synchy'),
		default => __('Preparing', 'synchy'),
	};
}

function synchy_build_job_response(array $job): array
{
	if ($job === []) {
		return [];
	}

	return [
		'id' => (string) ($job['job_id'] ?? ''),
		'packageId' => (string) ($job['package_id'] ?? ''),
		'packageName' => (string) ($job['package_name'] ?? ''),
		'status' => (string) ($job['status'] ?? ''),
		'phase' => (string) ($job['phase'] ?? ''),
		'phaseLabel' => synchy_export_phase_label((string) ($job['phase'] ?? 'queued')),
		'message' => (string) ($job['message'] ?? ''),
		'progress' => (int) round((float) ($job['progress'] ?? 0)),
		'fileCount' => (int) ($job['file_count'] ?? 0),
		'fileCursor' => (int) ($job['cursor'] ?? 0),
		'fileBytes' => (int) ($job['file_bytes'] ?? 0),
		'outputDirectory' => (string) ($job['output_directory'] ?? ''),
		'artifactNames' => $job['artifact_names'] ?? [],
	];
}

function synchy_site_sync_phase_label(string $phase): string
{
	return match ($phase) {
		'testing_connection' => __('Testing Connection', 'synchy'),
		'starting_export' => __('Preparing Export', 'synchy'),
		'exporting_package' => __('Building Package', 'synchy'),
		'starting_remote_session' => __('Starting Destination Session', 'synchy'),
		'uploading_archive' => __('Uploading Archive', 'synchy'),
		'uploading_installer' => __('Uploading Installer', 'synchy'),
		'uploading_manifest' => __('Uploading Manifest', 'synchy'),
		'finalizing_remote_package' => __('Finalizing Package', 'synchy'),
		'complete' => __('Complete', 'synchy'),
		'error' => __('Error', 'synchy'),
		default => __('Preparing', 'synchy'),
	};
}

function synchy_build_site_sync_job_response(array $job): array
{
	if ($job === []) {
		return [];
	}

	$progress = (int) round((float) ($job['progress'] ?? 0));
	$current_artifact = (string) ($job['current_artifact'] ?? '');
	$artifact_uploads = isset($job['artifact_uploads']) && is_array($job['artifact_uploads']) ? $job['artifact_uploads'] : [];
	$current_artifact_state = isset($artifact_uploads[$current_artifact]) && is_array($artifact_uploads[$current_artifact]) ? $artifact_uploads[$current_artifact] : [];
	$artifact_bytes_uploaded = (int) ($current_artifact_state['offset'] ?? 0);
	$artifact_bytes_total = (int) ($current_artifact_state['size'] ?? 0);
	$artifact_progress = 0;

	if ($artifact_bytes_total > 0) {
		$artifact_progress = min(100, max(0, (int) floor(($artifact_bytes_uploaded / $artifact_bytes_total) * 100)));
	}

	return [
		'id' => (string) ($job['job_id'] ?? ''),
		'status' => (string) ($job['status'] ?? ''),
		'phase' => (string) ($job['phase'] ?? ''),
		'phaseLabel' => synchy_site_sync_phase_label((string) ($job['phase'] ?? '')),
		'message' => (string) ($job['message'] ?? ''),
		'progress' => $progress,
		'progressLabel' => in_array((string) ($job['phase'] ?? ''), ['uploading_archive', 'uploading_installer'], true)
			? sprintf(
				/* translators: %d: overall progress percent */
				__('%d%% overall', 'synchy'),
				$progress
			)
			: sprintf(
				/* translators: %d: progress percent */
				__('%d%%', 'synchy'),
				$progress
			),
		'packageName' => (string) ($job['package_name'] ?? ''),
		'destinationUrl' => (string) ($job['destination_url'] ?? ''),
		'remoteSiteName' => (string) ($job['remote_site']['name'] ?? ''),
		'remoteSiteUrl' => (string) ($job['remote_site']['siteUrl'] ?? ''),
		'bytesUploaded' => (int) ($job['bytes_uploaded'] ?? 0),
		'bytesTotal' => (int) ($job['bytes_total'] ?? 0),
		'currentArtifact' => $current_artifact,
		'artifactBytesUploaded' => $artifact_bytes_uploaded,
		'artifactBytesTotal' => $artifact_bytes_total,
		'artifactProgress' => $artifact_progress,
		'remoteSessionId' => (string) ($job['remote_session_id'] ?? ''),
		'installerUrl' => (string) (($job['remote_package']['installerUrl'] ?? '')),
		'installerPath' => (string) (($job['remote_package']['installerPath'] ?? '')),
		'archivePath' => (string) (($job['remote_package']['archivePath'] ?? '')),
		'stagedPath' => (string) (($job['remote_package']['destinationPath'] ?? '')),
		'rootPath' => (string) (($job['remote_package']['rootPath'] ?? '')),
		'deployStatus' => (string) (($job['remote_package']['deployStatus'] ?? '')),
	];
}

function synchy_mark_export_job_error(array $job, string $message): array
{
	$job['status'] = 'error';
	$job['phase'] = 'error';
	$job['message'] = $message;
	$job['progress'] = 100;

	if (!empty($job['temp_dir'])) {
		synchy_rrmdir((string) $job['temp_dir']);
	}

	synchy_set_notice('error', $message);

	return synchy_update_export_job($job);
}

function synchy_mark_site_sync_job_error(array $job, string $message): array
{
	$job['status'] = 'error';
	$job['phase'] = 'error';
	$job['message'] = $message;
	$job['progress'] = 100;

	synchy_set_notice('error', $message);

	return synchy_update_site_sync_job($job);
}

function synchy_get_export_file_manifest(array $job): array
{
	$path = (string) ($job['file_manifest_path'] ?? '');

	if ($path === '' || !is_readable($path)) {
		return [];
	}

	$decoded = json_decode((string) file_get_contents($path), true);

	return is_array($decoded) ? $decoded : [];
}

function synchy_build_manifest(array $job): array
{
	global $wpdb;

	$archive_path = (string) $job['artifact_paths']['archive'];

	return [
		'plugin' => 'Synchy',
		'plugin_version' => SYNCHY_VERSION,
		'package_id' => $job['package_id'],
		'package_name' => $job['package_name'],
		'package_mode' => 'full_export',
		'created_at_gmt' => gmdate('c'),
		'site' => [
			'home_url' => home_url('/'),
			'site_url' => site_url('/'),
			'wordpress_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'db_prefix' => $wpdb->prefix,
		],
		'output_directory' => $job['output_directory'],
		'filters' => [
			'active_groups' => array_values(
				array_keys(
					array_filter(
						$job['options'],
						static fn($value, $key): bool => str_starts_with((string) $key, 'exclude_') && !empty($value),
						ARRAY_FILTER_USE_BOTH
					)
				)
			),
			'custom_excludes' => preg_split('/\r\n|\r|\n/', (string) ($job['options']['custom_excludes'] ?? '')) ?: [],
		],
		'database' => [
			'path_in_archive' => 'synchy/database.sql',
			'tables' => (int) ($job['table_count'] ?? 0),
			'size_bytes' => (int) ($job['database_size'] ?? 0),
		],
		'files' => [
			'included_count' => (int) ($job['file_count'] ?? 0),
			'included_bytes' => (int) ($job['file_bytes'] ?? 0),
		],
		'artifacts' => [
			'archive' => [
				'filename' => $job['artifact_names']['archive'],
				'size_bytes' => (int) filesize($archive_path),
				'sha256' => hash_file('sha256', $archive_path),
			],
			'installer' => [
				'filename' => $job['artifact_names']['installer'],
			],
			'manifest' => [
				'filename' => $job['artifact_names']['manifest'],
			],
		],
	];
}

function synchy_escape_php_single_quoted_string(string $value): string
{
	return str_replace(
		["\\", "'"],
		["\\\\", "\\'"],
		$value
	);
}

function synchy_get_installer_template_path(): string
{
	return wp_normalize_path(plugin_dir_path(__FILE__) . 'assets/installer-template.php');
}

function synchy_generate_installer_contents(array $manifest, string $access_token = ''): string
{
	$template_path = synchy_get_installer_template_path();

	if (!is_readable($template_path)) {
		return '';
	}

	$template = (string) file_get_contents($template_path);
	$archive = isset($manifest['artifacts']['archive']) && is_array($manifest['artifacts']['archive']) ? $manifest['artifacts']['archive'] : [];
	$site = isset($manifest['site']) && is_array($manifest['site']) ? $manifest['site'] : [];
	$token_value = $access_token === '' ? '__SYNCHY_ACCESS_TOKEN__' : synchy_escape_php_single_quoted_string($access_token);

	return strtr(
		$template,
		[
			'__SYNCHY_ACCESS_TOKEN__' => $token_value,
			'__SYNCHY_PACKAGE_ID__' => synchy_escape_php_single_quoted_string((string) ($manifest['package_id'] ?? '')),
			'__SYNCHY_PACKAGE_NAME__' => synchy_escape_php_single_quoted_string((string) ($manifest['package_name'] ?? '')),
			'__SYNCHY_ARCHIVE_FILENAME__' => synchy_escape_php_single_quoted_string((string) ($archive['filename'] ?? 'site.zip')),
			'__SYNCHY_ARCHIVE_SIZE_BYTES__' => synchy_escape_php_single_quoted_string((string) ($archive['size_bytes'] ?? '0')),
			'__SYNCHY_ARCHIVE_SHA256__' => synchy_escape_php_single_quoted_string((string) ($archive['sha256'] ?? '')),
			'__SYNCHY_SOURCE_HOME_URL__' => synchy_escape_php_single_quoted_string((string) ($site['home_url'] ?? '')),
			'__SYNCHY_SOURCE_SITE_URL__' => synchy_escape_php_single_quoted_string((string) ($site['site_url'] ?? '')),
		]
	);
}

function synchy_start_export_job(array $raw_options, bool $force = false)
{
	$existing_job = synchy_get_running_export_job();

	if (!$force && $existing_job !== []) {
		return new WP_Error('synchy_export_running', __('A Synchy export is already running. Wait for it to finish before starting another one.', 'synchy'));
	}

	if (!class_exists('ZipArchive')) {
		return new WP_Error('synchy_missing_zip', __('ZipArchive is not available on this server.', 'synchy'));
	}

	$options = synchy_sanitize_export_options($raw_options);

	if ($options['package_name'] === '') {
		$options['package_name'] = synchy_get_default_package_name();
	}

	$package_name = synchy_sanitize_package_name((string) $options['package_name']);
	$output_directory_abs = synchy_resolve_output_directory_path((string) $options['output_directory']);
	$output_directory_abs = wp_normalize_path(untrailingslashit($output_directory_abs));

	if (!wp_mkdir_p($output_directory_abs)) {
		return new WP_Error('synchy_output_dir_failed', __('Synchy could not create the export destination folder.', 'synchy'));
	}

	$package_id = 'synchy-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false));
	$temp_dir = trailingslashit($output_directory_abs) . '.synchy-' . $package_id;
	$file_manifest_path = trailingslashit($temp_dir) . 'files.json';
	$database_path = trailingslashit($temp_dir) . 'database.sql';

	if (file_exists($temp_dir)) {
		synchy_rrmdir($temp_dir);
	}

	if (!wp_mkdir_p($temp_dir)) {
		return new WP_Error('synchy_temp_failed', __('Synchy could not create the temporary export workspace.', 'synchy'));
	}

	$artifact_paths = [
		'archive' => trailingslashit($output_directory_abs) . $package_name . '.zip',
		'installer' => trailingslashit($output_directory_abs) . $package_name . '-installer.php',
		'manifest' => trailingslashit($output_directory_abs) . $package_name . '-manifest.json',
	];
	$artifact_names = [
		'archive' => basename($artifact_paths['archive']),
		'installer' => basename($artifact_paths['installer']),
		'manifest' => basename($artifact_paths['manifest']),
	];

	foreach ($artifact_paths as $path) {
		if (file_exists($path)) {
			@unlink($path);
		}
	}

	$job = [
		'job_id' => wp_generate_uuid4(),
		'package_id' => $package_id,
		'package_name' => $package_name,
		'status' => 'running',
		'phase' => 'queued',
		'message' => __('Preparing export job.', 'synchy'),
		'progress' => 1,
		'created_at' => gmdate('c'),
		'output_directory' => synchy_display_output_directory($output_directory_abs),
		'output_directory_path' => $output_directory_abs,
		'options' => $options,
		'artifact_paths' => $artifact_paths,
		'artifact_names' => $artifact_names,
		'temp_dir' => $temp_dir,
		'file_manifest_path' => $file_manifest_path,
		'database_path' => $database_path,
		'file_count' => 0,
		'file_bytes' => 0,
		'cursor' => 0,
		'table_count' => 0,
		'database_size' => 0,
	];

	return synchy_update_export_job($job);
}

function synchy_process_export_job(array $job): array
{
	if (($job['status'] ?? '') !== 'running') {
		return $job;
	}

	if (function_exists('ignore_user_abort')) {
		ignore_user_abort(true);
	}

	if (function_exists('set_time_limit')) {
		@set_time_limit(0);
	}

	switch ($job['phase']) {
		case 'queued':
			$job['phase'] = 'dumping_database';
			$job['message'] = __('Exporting the database dump.', 'synchy');
			$job['progress'] = 8;
			return synchy_update_export_job($job);

		case 'dumping_database':
			$database_dump = synchy_write_database_dump((string) $job['database_path']);

			if (is_wp_error($database_dump)) {
				return synchy_mark_export_job_error($job, $database_dump->get_error_message());
			}

			$job['table_count'] = (int) $database_dump['tables'];
			$job['database_size'] = (int) $database_dump['size'];
			$job['phase'] = 'scanning_files';
			$job['message'] = __('Scanning files to include in the export.', 'synchy');
			$job['progress'] = 22;

			return synchy_update_export_job($job);

		case 'scanning_files':
			$exclude_patterns = synchy_get_effective_exclude_patterns(
				$job['options'],
				(string) $job['output_directory_path']
			);
			$file_collection = synchy_collect_export_files($exclude_patterns);

			if (is_wp_error($file_collection)) {
				return synchy_mark_export_job_error($job, $file_collection->get_error_message());
			}

			$json = wp_json_encode($file_collection['files'], JSON_UNESCAPED_SLASHES);

			if ($json === false || file_put_contents((string) $job['file_manifest_path'], $json) === false) {
				return synchy_mark_export_job_error($job, __('Synchy could not write the temporary file index for this export.', 'synchy'));
			}

			$job['file_count'] = (int) $file_collection['count'];
			$job['file_bytes'] = (int) $file_collection['bytes'];
			$job['cursor'] = 0;
			$job['phase'] = 'zipping_files';
			$job['message'] = __('Building the archive package.', 'synchy');
			$job['progress'] = 34;

			return synchy_update_export_job($job);

		case 'zipping_files':
			return synchy_process_export_zip_phase($job);

		case 'finalizing':
			return synchy_finalize_export_job($job);

		default:
			return synchy_mark_export_job_error($job, __('Synchy encountered an unknown export phase.', 'synchy'));
	}
}

function synchy_build_export_package(array $raw_options)
{
	$job = synchy_start_export_job($raw_options, true);

	if (is_wp_error($job)) {
		return $job;
	}

	do {
		$job = synchy_process_export_job($job);
	} while (($job['status'] ?? '') === 'running');

	if (($job['status'] ?? '') !== 'complete') {
		return new WP_Error('synchy_export_failed', (string) ($job['message'] ?? __('Synchy export failed.', 'synchy')));
	}

	return synchy_get_last_export();
}

function synchy_get_site_sync_package_directory(): string
{
	return trailingslashit(synchy_get_default_output_directory() . 'site-sync');
}

function synchy_get_site_sync_upload_chunk_bytes(): int
{
	$bytes = (int) apply_filters('synchy_site_sync_upload_chunk_bytes', 1 * MB_IN_BYTES);

	return max(512 * KB_IN_BYTES, $bytes);
}

function synchy_get_site_sync_export_options(array $site_sync_options): array
{
	$export_options = synchy_get_export_options();
	$destination_host = (string) wp_parse_url((string) ($site_sync_options['destination_url'] ?? ''), PHP_URL_HOST);
	$destination_slug = sanitize_title($destination_host !== '' ? $destination_host : 'destination');

	$export_options['output_directory'] = synchy_get_site_sync_package_directory();
	$export_options['package_name'] = 'synchy-site-sync-' . $destination_slug . '-' . gmdate('Ymd-His');

	return $export_options;
}

function synchy_validate_site_sync_options(array $options)
{
	if ((string) ($options['destination_url'] ?? '') === '') {
		return new WP_Error('synchy_site_sync_missing_url', __('Enter the destination WordPress URL before testing Site Sync.', 'synchy'));
	}

	if ((string) ($options['destination_username'] ?? '') === '') {
		return new WP_Error('synchy_site_sync_missing_username', __('Enter the destination username for Site Sync.', 'synchy'));
	}

	if ((string) ($options['destination_application_password'] ?? '') === '') {
		return new WP_Error('synchy_site_sync_missing_password', __('Enter the destination application password for Site Sync.', 'synchy'));
	}

	$current_home = untrailingslashit(home_url('/'));
	$destination_home = untrailingslashit((string) $options['destination_url']);

	if ($current_home !== '' && $destination_home !== '' && $current_home === $destination_home) {
		return new WP_Error('synchy_site_sync_same_site', __('The destination URL matches this site. Choose a different WordPress site for Site Sync.', 'synchy'));
	}

	return true;
}

function synchy_get_site_sync_route_url(array $options, string $route): string
{
	return trailingslashit((string) $options['destination_url']) . 'wp-json/synchy/v1/' . ltrim($route, '/');
}

function synchy_site_sync_remote_request(array $options, string $route, string $method = 'GET', array $request_args = [])
{
	$validation = synchy_validate_site_sync_options($options);

	if (is_wp_error($validation)) {
		return $validation;
	}

	$url = synchy_get_site_sync_route_url($options, $route);
	$headers = isset($request_args['headers']) && is_array($request_args['headers']) ? $request_args['headers'] : [];
	$headers['Authorization'] = 'Basic ' . base64_encode(
		(string) $options['destination_username'] . ':' . (string) $options['destination_application_password']
	);
	$headers['Accept'] = 'application/json';

	$args = [
		'method' => strtoupper($method),
		'timeout' => (int) ($request_args['timeout'] ?? 30),
		'sslverify' => !empty($options['verify_ssl']),
		'headers' => $headers,
	];

	if (array_key_exists('body', $request_args)) {
		$args['body'] = $request_args['body'];
	}

	if (isset($request_args['data_format'])) {
		$args['data_format'] = $request_args['data_format'];
	}

	$response = wp_remote_request($url, $args);

	if (is_wp_error($response)) {
		return new WP_Error(
			'synchy_site_sync_remote_request_failed',
			sprintf(
				/* translators: 1: remote URL, 2: error message */
				__('Synchy could not reach %1$s. %2$s', 'synchy'),
				$url,
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	$body = (string) wp_remote_retrieve_body($response);
	$decoded = json_decode($body, true);
	$data = is_array($decoded) && array_key_exists('data', $decoded) ? $decoded['data'] : $decoded;

	if ($code < 200 || $code >= 300) {
		$message = is_array($data) && !empty($data['message'])
			? (string) $data['message']
			: sprintf(
				/* translators: %d: HTTP status code */
				__('Destination site returned HTTP %d.', 'synchy'),
				$code
			);

		return new WP_Error('synchy_site_sync_remote_http_error', $message, ['status' => $code, 'body' => $data]);
	}

	if (is_array($decoded) && array_key_exists('success', $decoded) && !$decoded['success']) {
		$message = is_array($data) && !empty($data['message']) ? (string) $data['message'] : __('The destination site rejected the Synchy request.', 'synchy');

		return new WP_Error('synchy_site_sync_remote_error', $message, ['body' => $data]);
	}

	if (!is_array($data)) {
		return ['rawBody' => $body];
	}

	return $data;
}

function synchy_test_site_sync_connection(array $options)
{
	$response = synchy_site_sync_remote_request($options, 'push/ping', 'GET', ['timeout' => 20]);

	if (is_wp_error($response)) {
		return $response;
	}

	if (empty($response['siteUrl']) || empty($response['pluginVersion'])) {
		return new WP_Error('synchy_site_sync_invalid_ping', __('The destination site responded, but it did not look like a Synchy receiver.', 'synchy'));
	}

	return $response;
}

function synchy_get_remote_push_root_path(): string
{
	$uploads = wp_upload_dir();

	if (!empty($uploads['error']) || empty($uploads['basedir'])) {
		return '';
	}

	return wp_normalize_path(trailingslashit((string) $uploads['basedir']) . 'synchy-site-sync');
}

function synchy_sanitize_remote_push_session_id(string $session_id): string
{
	return (string) preg_replace('/[^a-z0-9-]/i', '', $session_id);
}

function synchy_get_remote_push_session_dir(string $session_id): string
{
	return wp_normalize_path(trailingslashit(synchy_get_remote_push_root_path()) . synchy_sanitize_remote_push_session_id($session_id));
}

function synchy_get_remote_push_session_meta_path(string $session_id): string
{
	return wp_normalize_path(trailingslashit(synchy_get_remote_push_session_dir($session_id)) . 'session.json');
}

function synchy_get_site_root_path(): string
{
	return wp_normalize_path(untrailingslashit(ABSPATH));
}

function synchy_get_remote_push_root_archive_path(string $filename): string
{
	$filename = sanitize_file_name($filename);

	if ($filename === '') {
		$filename = 'site.zip';
	}

	return wp_normalize_path(trailingslashit(synchy_get_site_root_path()) . $filename);
}

function synchy_get_remote_push_root_installer_path(): string
{
	return wp_normalize_path(trailingslashit(synchy_get_site_root_path()) . 'installer.php');
}

function synchy_deploy_remote_push_package_to_root(array $session): array
{
	$root_path = synchy_get_site_root_path();
	$archive_source = wp_normalize_path((string) ($session['artifacts']['archive']['path'] ?? ''));
	$installer_source = wp_normalize_path((string) ($session['artifacts']['installer']['path'] ?? ''));
	$archive_filename = sanitize_file_name((string) ($session['artifacts']['archive']['filename'] ?? 'site.zip'));

	if ($archive_source === '' || !is_readable($archive_source) || $installer_source === '' || !is_readable($installer_source)) {
		return [
			'status' => 'staged_only',
			'message' => __('Synchy staged the package, but the standalone installer artifacts are incomplete on the destination.', 'synchy'),
			'rootPath' => $root_path,
		];
	}

	if (!is_dir($root_path) || !is_writable($root_path)) {
		return [
			'status' => 'staged_only',
			'message' => __('Synchy staged the package, but the WordPress root is not writable for installer deployment.', 'synchy'),
			'rootPath' => $root_path,
		];
	}

	$archive_target = synchy_get_remote_push_root_archive_path($archive_filename);
	$installer_target = synchy_get_remote_push_root_installer_path();
	$installer_contents = file_get_contents($installer_source);

	if ($installer_contents === false) {
		return [
			'status' => 'staged_only',
			'message' => __('Synchy staged the package, but it could not read the installer artifact before root deployment.', 'synchy'),
			'rootPath' => $root_path,
		];
	}

	if (@copy($archive_source, $archive_target) === false) {
		return [
			'status' => 'staged_only',
			'message' => __('Synchy staged the package, but it could not copy the archive into the destination WordPress root.', 'synchy'),
			'rootPath' => $root_path,
		];
	}

	$access_token = wp_generate_password(32, false, false);
	$tokenized_installer = str_replace('__SYNCHY_ACCESS_TOKEN__', synchy_escape_php_single_quoted_string($access_token), $installer_contents);

	if (file_put_contents($installer_target, $tokenized_installer) === false) {
		return [
			'status' => 'staged_only',
			'message' => __('Synchy copied the archive to the destination root, but it could not write installer.php there.', 'synchy'),
			'rootPath' => $root_path,
			'archivePath' => $archive_target,
		];
	}

	return [
		'status' => 'ready',
		'message' => __('Synchy copied the archive and installer.php into the destination WordPress root. Open the installer URL to run the manual restore.', 'synchy'),
		'rootPath' => $root_path,
		'archivePath' => $archive_target,
		'installerPath' => $installer_target,
		'archiveUrl' => site_url('/' . basename($archive_target)),
		'installerUrl' => add_query_arg('token', $access_token, site_url('/installer.php')),
	];
}

function synchy_read_remote_push_session(string $session_id): array
{
	$session_id = synchy_sanitize_remote_push_session_id($session_id);

	if ($session_id === '') {
		return [];
	}

	$meta_path = synchy_get_remote_push_session_meta_path($session_id);

	if (!is_readable($meta_path)) {
		return [];
	}

	$decoded = json_decode((string) file_get_contents($meta_path), true);

	return is_array($decoded) ? $decoded : [];
}

function synchy_write_remote_push_session(array $session)
{
	$session_id = synchy_sanitize_remote_push_session_id((string) ($session['session_id'] ?? ''));

	if ($session_id === '') {
		return new WP_Error('synchy_remote_session_invalid', __('Synchy could not save the destination sync session.', 'synchy'));
	}

	$session['session_id'] = $session_id;
	$session_dir = synchy_get_remote_push_session_dir($session_id);

	if (!wp_mkdir_p($session_dir)) {
		return new WP_Error('synchy_remote_session_write_failed', __('Synchy could not create the destination sync session folder.', 'synchy'));
	}

	$json = wp_json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	if ($json === false || file_put_contents(synchy_get_remote_push_session_meta_path($session_id), $json) === false) {
		return new WP_Error('synchy_remote_session_write_failed', __('Synchy could not write the destination sync session metadata.', 'synchy'));
	}

	return $session;
}

function synchy_create_remote_push_session(array $payload)
{
	$root = synchy_get_remote_push_root_path();

	if ($root === '') {
		return new WP_Error('synchy_remote_session_root_failed', __('Synchy could not resolve the destination sync storage folder.', 'synchy'));
	}

	if (!wp_mkdir_p($root)) {
		return new WP_Error('synchy_remote_session_root_failed', __('Synchy could not create the destination sync storage folder.', 'synchy'));
	}

	$session_id = wp_generate_uuid4();
	$session_dir = synchy_get_remote_push_session_dir($session_id);

	if (!wp_mkdir_p($session_dir)) {
		return new WP_Error('synchy_remote_session_dir_failed', __('Synchy could not create the destination upload session folder.', 'synchy'));
	}

	$archive_filename = sanitize_file_name((string) ($payload['archiveFilename'] ?? 'site.zip'));
	$installer_filename = sanitize_file_name((string) ($payload['installerFilename'] ?? 'installer.php'));
	$manifest_filename = sanitize_file_name((string) ($payload['manifestFilename'] ?? ''));

	if ($archive_filename === '') {
		$archive_filename = 'site.zip';
	}

	if ($installer_filename === '') {
		$installer_filename = 'installer.php';
	}

	$session = [
		'session_id' => $session_id,
		'created_at' => gmdate('c'),
		'status' => 'receiving',
		'package_name' => sanitize_text_field((string) ($payload['packageName'] ?? 'synchy-site-sync')),
		'package_id' => sanitize_text_field((string) ($payload['packageId'] ?? '')),
		'source_home_url' => esc_url_raw((string) ($payload['sourceHomeUrl'] ?? '')),
		'source_site_url' => esc_url_raw((string) ($payload['sourceSiteUrl'] ?? '')),
		'directory' => $session_dir,
		'artifacts' => [
			'archive' => [
				'filename' => $archive_filename,
				'path' => wp_normalize_path(trailingslashit($session_dir) . $archive_filename),
				'bytes' => 0,
			],
			'installer' => [
				'filename' => $installer_filename,
				'path' => wp_normalize_path(trailingslashit($session_dir) . $installer_filename),
				'bytes' => 0,
			],
		],
	];

	if ($manifest_filename !== '') {
		$session['artifacts']['manifest'] = [
			'filename' => $manifest_filename,
			'path' => wp_normalize_path(trailingslashit($session_dir) . $manifest_filename),
			'bytes' => 0,
		];
	}

	$session = synchy_write_remote_push_session($session);

	if (is_wp_error($session)) {
		return $session;
	}

	$session['uploadChunkBytes'] = synchy_get_site_sync_upload_chunk_bytes();

	return $session;
}

function synchy_prepare_site_sync_local_artifacts(array $job, array $export_job)
{
	$artifact_paths = isset($export_job['artifact_paths']) && is_array($export_job['artifact_paths']) ? $export_job['artifact_paths'] : [];
	$artifacts = [];
	$total = 0;

	foreach (['archive', 'installer'] as $artifact) {
		$path = isset($artifact_paths[$artifact]) ? (string) $artifact_paths[$artifact] : '';

		if ($path === '' || !is_readable($path)) {
			return new WP_Error(
				'synchy_site_sync_missing_artifact',
				sprintf(
					/* translators: %s: artifact type */
					__('Synchy could not find the %s artifact after building the push package.', 'synchy'),
					$artifact
				)
			);
		}

		$size = (int) filesize($path);
		$artifacts[$artifact] = [
			'path' => $path,
			'filename' => basename($path),
			'size' => $size,
			'offset' => 0,
		];
		$total += $size;
	}

	$job['package_id'] = (string) ($export_job['package_id'] ?? '');
	$job['package_name'] = (string) ($export_job['package_name'] ?? '');
	$job['artifact_uploads'] = $artifacts;
	$job['bytes_total'] = $total;
	$job['bytes_uploaded'] = 0;
	$job['current_artifact'] = 'archive';

	return $job;
}

function synchy_start_site_sync_job(array $raw_options)
{
	$existing_job = synchy_get_running_site_sync_job();

	if ($existing_job !== []) {
		return new WP_Error('synchy_site_sync_running', __('A Synchy Site Sync push is already running. Wait for it to finish before starting another one.', 'synchy'));
	}

	if (synchy_get_running_export_job() !== []) {
		return new WP_Error('synchy_site_sync_export_busy', __('Synchy is already building an export package. Wait for it to finish before starting Site Sync.', 'synchy'));
	}

	$options = synchy_sanitize_site_sync_options($raw_options);
	$validation = synchy_validate_site_sync_options($options);

	if (is_wp_error($validation)) {
		return $validation;
	}

	$job = [
		'job_id' => wp_generate_uuid4(),
		'status' => 'running',
		'phase' => 'testing_connection',
		'message' => __('Testing the destination Synchy receiver.', 'synchy'),
		'progress' => 2,
		'created_at' => gmdate('c'),
		'options' => $options,
		'destination_url' => (string) $options['destination_url'],
		'remote_site' => [],
		'export_job_id' => '',
		'package_name' => '',
		'package_id' => '',
		'remote_session_id' => '',
		'artifact_uploads' => [],
		'current_artifact' => '',
		'bytes_total' => 0,
		'bytes_uploaded' => 0,
	];

	return synchy_update_site_sync_job($job);
}

function synchy_process_site_sync_job(array $job): array
{
	if (($job['status'] ?? '') !== 'running') {
		return $job;
	}

	if (function_exists('ignore_user_abort')) {
		ignore_user_abort(true);
	}

	if (function_exists('set_time_limit')) {
		@set_time_limit(90);
	}

	$options = isset($job['options']) && is_array($job['options']) ? $job['options'] : [];

	switch ((string) ($job['phase'] ?? '')) {
		case 'testing_connection':
			$connection = synchy_test_site_sync_connection($options);

			if (is_wp_error($connection)) {
				return synchy_mark_site_sync_job_error($job, $connection->get_error_message());
			}

			$job['remote_site'] = $connection;
			$job['phase'] = 'starting_export';
			$job['message'] = __('Destination verified. Starting a fresh Synchy package for Site Sync.', 'synchy');
			$job['progress'] = 8;

			return synchy_update_site_sync_job($job);

		case 'starting_export':
			$export_options = synchy_get_site_sync_export_options($options);
			$export_job = synchy_start_export_job($export_options);

			if (is_wp_error($export_job)) {
				return synchy_mark_site_sync_job_error($job, $export_job->get_error_message());
			}

			$job['export_job_id'] = (string) ($export_job['job_id'] ?? '');
			$job['phase'] = 'exporting_package';
			$job['message'] = __('Building the Site Sync package.', 'synchy');
			$job['progress'] = 10;

			return synchy_update_site_sync_job($job);

		case 'exporting_package':
			$export_job = synchy_get_export_job();

			if ($export_job === [] || (string) ($export_job['job_id'] ?? '') !== (string) ($job['export_job_id'] ?? '')) {
				return synchy_mark_site_sync_job_error($job, __('Synchy lost track of the export job for this Site Sync run.', 'synchy'));
			}

			if (($export_job['status'] ?? '') === 'running') {
				$export_job = synchy_process_export_job($export_job);
			}

			if (($export_job['status'] ?? '') === 'error') {
				return synchy_mark_site_sync_job_error($job, (string) ($export_job['message'] ?? __('Synchy could not build the Site Sync package.', 'synchy')));
			}

			if (($export_job['status'] ?? '') !== 'complete') {
				$job['message'] = (string) ($export_job['message'] ?? __('Building the Site Sync package.', 'synchy'));
				$job['progress'] = max(10, min(55, 10 + (int) floor(((int) ($export_job['progress'] ?? 0)) * 0.45)));

				return synchy_update_site_sync_job($job);
			}

			$prepared_job = synchy_prepare_site_sync_local_artifacts($job, $export_job);

			if (is_wp_error($prepared_job)) {
				return synchy_mark_site_sync_job_error($job, $prepared_job->get_error_message());
			}

			$job = $prepared_job;

			$job['phase'] = 'starting_remote_session';
			$job['message'] = __('Local package complete. Opening the destination upload session.', 'synchy');
			$job['progress'] = 56;

			return synchy_update_site_sync_job($job);

		case 'starting_remote_session':
			$artifact_uploads = isset($job['artifact_uploads']) && is_array($job['artifact_uploads']) ? $job['artifact_uploads'] : [];

			$remote_session = synchy_site_sync_remote_request(
				$options,
				'push/session',
				'POST',
				[
					'timeout' => 30,
					'headers' => ['Content-Type' => 'application/json'],
					'body' => wp_json_encode(
						[
							'packageName' => $job['package_name'],
							'packageId' => $job['package_id'],
							'sourceHomeUrl' => home_url('/'),
							'sourceSiteUrl' => site_url('/'),
							'archiveFilename' => $artifact_uploads['archive']['filename'] ?? 'site.zip',
							'installerFilename' => $artifact_uploads['installer']['filename'] ?? 'installer.php',
						]
					),
					'data_format' => 'body',
				]
			);

			if (is_wp_error($remote_session)) {
				return synchy_mark_site_sync_job_error($job, $remote_session->get_error_message());
			}

			$job['remote_session_id'] = (string) ($remote_session['session_id'] ?? '');
			$job['upload_chunk_bytes'] = max(
				512 * KB_IN_BYTES,
				(int) ($remote_session['uploadChunkBytes'] ?? synchy_get_site_sync_upload_chunk_bytes())
			);
			$job['phase'] = 'uploading_archive';
			$job['current_artifact'] = 'archive';
			$job['message'] = __('Uploading the Site Sync archive to the destination.', 'synchy');
			$job['progress'] = 60;

			return synchy_update_site_sync_job($job);

		case 'uploading_archive':
		case 'uploading_installer':
			$artifact = (string) ($job['current_artifact'] ?? '');
			$artifact_uploads = isset($job['artifact_uploads']) && is_array($job['artifact_uploads']) ? $job['artifact_uploads'] : [];
			$upload_state = isset($artifact_uploads[$artifact]) && is_array($artifact_uploads[$artifact]) ? $artifact_uploads[$artifact] : [];

			if ($artifact === '' || $upload_state === []) {
				return synchy_mark_site_sync_job_error($job, __('Synchy could not find the active upload state for Site Sync.', 'synchy'));
			}

			$path = (string) ($upload_state['path'] ?? '');
			$size = (int) ($upload_state['size'] ?? 0);
			$offset = (int) ($upload_state['offset'] ?? 0);

			if ($path === '' || !is_readable($path)) {
				return synchy_mark_site_sync_job_error($job, __('Synchy could not read the local Site Sync artifact before upload.', 'synchy'));
			}

			if ($offset >= $size) {
				if ($artifact === 'archive') {
					$job['current_artifact'] = 'installer';
					$job['phase'] = 'uploading_installer';
					$job['message'] = __('Archive upload complete. Sending installer.php to the destination.', 'synchy');

					return synchy_update_site_sync_job($job);
				}

				$job['phase'] = 'finalizing_remote_package';
				$job['message'] = __('Upload complete. Deploying the package and installer on the destination.', 'synchy');
				$job['progress'] = 96;

				return synchy_update_site_sync_job($job);
			}

			$chunk_size = max(512 * KB_IN_BYTES, (int) ($job['upload_chunk_bytes'] ?? synchy_get_site_sync_upload_chunk_bytes()));
			$chunk = file_get_contents($path, false, null, $offset, $chunk_size);

			if ($chunk === false || $chunk === '') {
				return synchy_mark_site_sync_job_error($job, __('Synchy could not read the next upload chunk for Site Sync.', 'synchy'));
			}

			$upload_response = synchy_site_sync_remote_request(
				$options,
				sprintf(
					'push/upload?session_id=%1$s&artifact=%2$s&offset=%3$d&filename=%4$s',
					rawurlencode((string) $job['remote_session_id']),
					rawurlencode($artifact),
					$offset,
					rawurlencode((string) ($upload_state['filename'] ?? basename($path)))
				),
				'POST',
				[
					'timeout' => 120,
					'headers' => [
						'Content-Type' => 'application/octet-stream',
						'Content-Length' => (string) strlen($chunk),
					],
					'body' => $chunk,
					'data_format' => 'body',
				]
			);

			if (is_wp_error($upload_response)) {
				return synchy_mark_site_sync_job_error($job, $upload_response->get_error_message());
			}

			$offset += strlen($chunk);
			$artifact_uploads[$artifact]['offset'] = $offset;
			$job['artifact_uploads'] = $artifact_uploads;
			$job['bytes_uploaded'] = array_sum(array_map(static fn(array $item): int => (int) ($item['offset'] ?? 0), $artifact_uploads));
			$job['progress'] = $job['bytes_total'] > 0
				? 60 + (int) floor(35 * ($job['bytes_uploaded'] / (int) $job['bytes_total']))
				: 90;
			$job['message'] = sprintf(
				/* translators: 1: artifact label, 2: uploaded bytes, 3: total bytes */
				__('Uploading %1$s: %2$s of %3$s transferred.', 'synchy'),
				$artifact === 'archive' ? __('archive', 'synchy') : __('installer.php', 'synchy'),
				size_format($job['bytes_uploaded'], 2),
				size_format((int) $job['bytes_total'], 2)
			);

			if ($offset >= $size) {
				if ($artifact === 'archive') {
					$job['current_artifact'] = 'installer';
					$job['phase'] = 'uploading_installer';
					$job['message'] = __('Archive upload complete. Sending installer.php to the destination.', 'synchy');
				} else {
					$job['phase'] = 'finalizing_remote_package';
					$job['message'] = __('Upload complete. Deploying the package and installer on the destination.', 'synchy');
					$job['progress'] = 96;
				}
			}

			return synchy_update_site_sync_job($job);

		case 'finalizing_remote_package':
			$finalize = synchy_site_sync_remote_request(
				$options,
				'push/complete',
				'POST',
				[
					'timeout' => 30,
					'headers' => ['Content-Type' => 'application/json'],
					'body' => wp_json_encode(['session_id' => $job['remote_session_id']]),
					'data_format' => 'body',
				]
			);

			if (is_wp_error($finalize)) {
				return synchy_mark_site_sync_job_error($job, $finalize->get_error_message());
			}

			$job['status'] = 'complete';
			$job['phase'] = 'complete';
			$job['message'] = !empty($finalize['message'])
				? (string) $finalize['message']
				: __('Package delivered to the destination Synchy receiver.', 'synchy');
			$job['progress'] = 100;
			$job['remote_package'] = $finalize;

			synchy_set_notice('success', $job['message']);

			return synchy_update_site_sync_job($job);

		default:
			return synchy_mark_site_sync_job_error($job, __('Synchy encountered an unknown Site Sync phase.', 'synchy'));
	}
}

function synchy_get_download_url(string $package_id, string $artifact_type): string
{
	return wp_nonce_url(
		admin_url(
			'admin-post.php?action=synchy_download_export&package=' . rawurlencode($package_id) . '&artifact=' . rawurlencode($artifact_type)
		),
		'synchy_download_export_' . $package_id . '_' . $artifact_type
	);
}

function synchy_render_last_export(array $last_export): void
{
	if (empty($last_export['package_id'])) {
		return;
	}

	$artifacts = isset($last_export['artifacts']) && is_array($last_export['artifacts']) ? $last_export['artifacts'] : [];
	$created = !empty($last_export['created_at']) ? strtotime((string) $last_export['created_at']) : false;
	?>
	<div class="synchy-panel synchy-panel--wide">
		<div class="synchy-stack synchy-stack--compact">
			<div class="synchy-stack__split">
				<div>
					<h2><?php esc_html_e('Latest Export', 'synchy'); ?></h2>
					<p class="synchy-field-note">
						<?php esc_html_e('The newest package stays available here until you overwrite it or move it out of the selected destination folder.', 'synchy'); ?>
					</p>
				</div>
				<span class="synchy-badge"><?php echo esc_html((string) ($last_export['package_name'] ?? $last_export['package_id'])); ?></span>
			</div>

			<div class="synchy-export-meta">
				<div>
					<strong><?php esc_html_e('Created', 'synchy'); ?></strong>
					<span>
						<?php
						echo esc_html(
							$created ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $created) : __('Unknown', 'synchy')
						);
						?>
					</span>
				</div>
				<div>
					<strong><?php esc_html_e('Save path', 'synchy'); ?></strong>
					<span><?php echo esc_html((string) ($last_export['output_directory'] ?? '')); ?></span>
				</div>
				<div>
					<strong><?php esc_html_e('Included files', 'synchy'); ?></strong>
					<span><?php echo esc_html(number_format_i18n((int) ($last_export['file_count'] ?? 0))); ?></span>
				</div>
				<div>
					<strong><?php esc_html_e('Archive size', 'synchy'); ?></strong>
					<span><?php echo esc_html(size_format((int) ($last_export['archive_size'] ?? 0), 2)); ?></span>
				</div>
			</div>

			<div class="synchy-downloads">
				<?php foreach ($artifacts as $type => $artifact) : ?>
					<a class="button" href="<?php echo esc_url(synchy_get_download_url((string) $last_export['package_id'], (string) $type)); ?>">
						<?php echo esc_html((string) ($artifact['label'] ?? 'Download')); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}

function synchy_get_browse_root_path(): string
{
	return wp_normalize_path(untrailingslashit(ABSPATH));
}

function synchy_resolve_browse_directory(string $requested_path): string
{
	$root = synchy_get_browse_root_path();
	$requested_path = trim($requested_path);

	if ($requested_path === '' || $requested_path === './') {
		return $root;
	}

	if (synchy_is_absolute_path($requested_path)) {
		$absolute = wp_normalize_path(untrailingslashit($requested_path));

		if (is_dir($absolute) && synchy_is_path_within($absolute, $root)) {
			return $absolute;
		}

		return $root;
	}

	$absolute = wp_normalize_path(untrailingslashit(trailingslashit($root) . ltrim($requested_path, '/')));

	if (is_dir($absolute) && synchy_is_path_within($absolute, $root)) {
		return $absolute;
	}

	return $root;
}

function synchy_get_browse_payload(string $requested_path): array
{
	$root = synchy_get_browse_root_path();
	$current = synchy_resolve_browse_directory($requested_path);
	$relative = synchy_absolute_to_relative($current, $root);
	$display = $relative === '' ? './' : trailingslashit($relative);
	$directories = [];
	$items = scandir($current);

	if (is_array($items)) {
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			if (str_starts_with($item, '.')) {
				continue;
			}

			$child = wp_normalize_path($current . '/' . $item);

			if (!is_dir($child)) {
				continue;
			}

			$child_relative = synchy_absolute_to_relative($child, $root);
			$directories[] = [
				'name' => $item,
				'path' => trailingslashit($child_relative),
			];
		}
	}

	usort(
		$directories,
		static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name'])
	);

	$parent = '';

	if ($current !== $root) {
		$parent_path = dirname($current);
		$parent = $parent_path === $root ? './' : trailingslashit(synchy_absolute_to_relative($parent_path, $root));
	}

	return [
		'currentPath' => $display,
		'currentAbsolutePath' => $current,
		'parentPath' => $parent,
		'directories' => $directories,
	];
}

function synchy_render_export_page(array $current): void
{
	$options = synchy_get_export_options();
	$groups = synchy_get_export_filter_groups();
	$included = synchy_get_export_included_items();
	$last_export = synchy_get_last_export();
	$running_job = synchy_get_running_export_job();
	$archive_preview = synchy_sanitize_package_name((string) $options['package_name']) . '.zip';
	$installer_preview = synchy_sanitize_package_name((string) $options['package_name']) . '-installer.php';
	$manifest_preview = synchy_sanitize_package_name((string) $options['package_name']) . '-manifest.json';
	?>
	<div class="wrap synchy-admin">
		<?php synchy_render_notice(); ?>
		<div class="synchy-shell">
			<div class="synchy-hero">
				<div>
					<p class="synchy-eyebrow"><?php esc_html_e('Export Design', 'synchy'); ?></p>
					<h1><?php echo esc_html($current['headline']); ?></h1>
					<p class="synchy-description"><?php echo esc_html($current['description']); ?></p>
				</div>
				<div class="synchy-status">
					<span class="synchy-status__dot" aria-hidden="true"></span>
					<?php echo esc_html($running_job === [] ? __('Export ready', 'synchy') : __('Export running', 'synchy')); ?>
				</div>
			</div>

			<form method="post" action="options.php" class="synchy-form" data-synchy-export-form>
				<?php settings_fields('synchy_export'); ?>

				<div class="synchy-grid synchy-grid--export">
					<div class="synchy-panel">
						<h2><?php esc_html_e('Full Export Includes', 'synchy'); ?></h2>
						<ul class="synchy-checklist synchy-checklist--detail">
							<?php foreach ($included as $item) : ?>
								<li>
									<strong><?php echo esc_html($item['label']); ?></strong>
									<span><?php echo esc_html($item['description']); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					<div class="synchy-panel synchy-panel--muted">
						<h2><?php esc_html_e('Package Output', 'synchy'); ?></h2>
						<div class="synchy-field">
							<label class="synchy-label" for="synchy-output-directory"><?php esc_html_e('Save path', 'synchy'); ?></label>
							<div class="synchy-input-row">
								<input
									id="synchy-output-directory"
									type="text"
									class="regular-text code"
									name="<?php echo esc_attr(SYNCHY_EXPORT_OPTIONS); ?>[output_directory]"
									value="<?php echo esc_attr((string) $options['output_directory']); ?>"
									data-synchy-output-directory
								/>
								<button type="button" class="button" data-synchy-browse><?php esc_html_e('Browse', 'synchy'); ?></button>
								<button type="button" class="button" data-synchy-use-default data-default-path="<?php echo esc_attr(synchy_get_default_output_directory()); ?>"><?php esc_html_e('Default', 'synchy'); ?></button>
							</div>
							<p class="synchy-field-note">
								<?php esc_html_e('Relative paths resolve from the WordPress root. The browser only lists folders inside this site.', 'synchy'); ?>
							</p>
						</div>

						<div class="synchy-field">
							<label class="synchy-label" for="synchy-package-name"><?php esc_html_e('Package name', 'synchy'); ?></label>
							<input
								id="synchy-package-name"
								type="text"
								class="regular-text"
								name="<?php echo esc_attr(SYNCHY_EXPORT_OPTIONS); ?>[package_name]"
								value="<?php echo esc_attr((string) $options['package_name']); ?>"
								data-synchy-package-name
							/>
							<p class="synchy-field-note">
								<?php esc_html_e('Synchy will create three files with this base name. Reusing the same name overwrites the previous files in that folder.', 'synchy'); ?>
							</p>
						</div>

						<div class="synchy-package">
							<div><code data-synchy-archive-preview><?php echo esc_html($archive_preview); ?></code></div>
							<div><code data-synchy-installer-preview><?php echo esc_html($installer_preview); ?></code></div>
							<div><code data-synchy-manifest-preview><?php echo esc_html($manifest_preview); ?></code></div>
						</div>

						<div class="synchy-progress<?php echo $running_job === [] ? ' is-hidden' : ''; ?>" data-synchy-progress>
							<div class="synchy-progress__top">
								<strong data-synchy-progress-phase><?php echo esc_html(synchy_export_phase_label((string) ($running_job['phase'] ?? 'queued'))); ?></strong>
								<span data-synchy-progress-percent><?php echo esc_html((string) (int) ($running_job['progress'] ?? 0)); ?>%</span>
							</div>
							<div class="synchy-progress__bar">
								<span data-synchy-progress-bar style="width: <?php echo esc_attr((string) (int) ($running_job['progress'] ?? 0)); ?>%;"></span>
							</div>
							<p class="synchy-progress__message" data-synchy-progress-message><?php echo esc_html((string) ($running_job['message'] ?? '')); ?></p>
							<p class="synchy-progress__detail" data-synchy-progress-detail>
								<?php
								if ($running_job !== []) {
									printf(
										/* translators: 1: current file count, 2: total file count */
										esc_html__('Files processed: %1$s / %2$s', 'synchy'),
										esc_html(number_format_i18n((int) ($running_job['cursor'] ?? 0))),
										esc_html(number_format_i18n((int) ($running_job['file_count'] ?? 0)))
									);
								}
								?>
							</p>
						</div>

						<div class="synchy-run-export">
							<button type="button" class="button button-primary button-large" data-synchy-run-export><?php esc_html_e('Run Full Export', 'synchy'); ?></button>
						</div>
					</div>
				</div>

				<?php synchy_render_last_export($last_export); ?>

				<div class="synchy-panel synchy-panel--wide">
					<h2><?php esc_html_e('Default Exclude Filters', 'synchy'); ?></h2>
					<p class="synchy-field-note">
						<?php esc_html_e('These filters define what Synchy should leave out of a full export by default. Your selected export destination is also excluded automatically at runtime.', 'synchy'); ?>
					</p>

					<div class="synchy-filter-list">
						<?php foreach ($groups as $key => $group) : ?>
							<div class="synchy-filter-card">
								<label class="synchy-toggle">
									<input
										type="checkbox"
										name="<?php echo esc_attr(SYNCHY_EXPORT_OPTIONS); ?>[<?php echo esc_attr($key); ?>]"
										value="1"
										<?php checked(!empty($options[$key])); ?>
									/>
									<span><?php echo esc_html($group['label']); ?></span>
								</label>
								<p><?php echo esc_html($group['description']); ?></p>
								<div class="synchy-patterns">
									<?php foreach ($group['patterns'] as $pattern) : ?>
										<code><?php echo esc_html($pattern); ?></code>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="synchy-grid synchy-grid--export">
					<div class="synchy-panel">
						<h2><?php esc_html_e('Custom Excludes', 'synchy'); ?></h2>
						<p class="synchy-field-note">
							<?php esc_html_e('Enter one path or glob per line. These will be added on top of the default filters.', 'synchy'); ?>
						</p>
						<textarea
							class="large-text code"
							rows="8"
							name="<?php echo esc_attr(SYNCHY_EXPORT_OPTIONS); ?>[custom_excludes]"
							placeholder=".env\nwp-content/synchy-temp/\ncustom-cache/"
						><?php echo esc_textarea((string) $options['custom_excludes']); ?></textarea>
					</div>

					<div class="synchy-panel synchy-panel--muted">
						<h2><?php esc_html_e('Notes', 'synchy'); ?></h2>
						<ul class="synchy-checklist">
							<li><?php esc_html_e('The chosen export folder is excluded from the backup automatically if it lives inside this WordPress install.', 'synchy'); ?></li>
							<li><?php esc_html_e('Duplicator backup folders remain excluded by default through wp-content/duplicator/.', 'synchy'); ?></li>
							<li><?php esc_html_e('Runtime dependencies like Composer vendor folders should not be stripped by default.', 'synchy'); ?></li>
							<li><?php esc_html_e('This package is not a Duplicator archive format. It will be restored through Synchy Import and Restore, not Duplicator Import.', 'synchy'); ?></li>
						</ul>
					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e('Save Export Settings', 'synchy'); ?></button>
				</p>
			</form>
		</div>
	</div>

	<div class="synchy-modal is-hidden" data-synchy-directory-modal aria-hidden="true">
		<div class="synchy-modal__backdrop" data-synchy-modal-close></div>
		<div class="synchy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="synchy-folder-browser-title">
			<div class="synchy-modal__header">
				<h2 id="synchy-folder-browser-title"><?php esc_html_e('Choose Export Folder', 'synchy'); ?></h2>
				<button type="button" class="button-link" data-synchy-modal-close><?php esc_html_e('Close', 'synchy'); ?></button>
			</div>
			<p class="synchy-modal__path" data-synchy-directory-current>./</p>
			<div class="synchy-modal__actions">
				<button type="button" class="button" data-synchy-directory-up><?php esc_html_e('Up One Level', 'synchy'); ?></button>
				<button type="button" class="button button-primary" data-synchy-directory-select><?php esc_html_e('Use This Folder', 'synchy'); ?></button>
			</div>
			<div class="synchy-directory-list" data-synchy-directory-list></div>
		</div>
	</div>
	<?php
}

function synchy_render_site_sync_page(array $current): void
{
	$options = synchy_get_site_sync_options();
	$running_job = synchy_get_running_site_sync_job();
	$password_hint = synchy_get_site_sync_password_hint($options);
	?>
	<div class="wrap synchy-admin">
		<?php synchy_render_notice(); ?>
		<div class="synchy-shell">
			<div class="synchy-hero">
				<div>
					<p class="synchy-eyebrow"><?php esc_html_e('Push Workflow', 'synchy'); ?></p>
					<h1><?php echo esc_html($current['headline']); ?></h1>
					<p class="synchy-description"><?php echo esc_html($current['description']); ?></p>
				</div>
				<div class="synchy-status">
					<span class="synchy-status__dot" aria-hidden="true"></span>
					<?php echo esc_html($running_job === [] ? __('Destination ready', 'synchy') : __('Push running', 'synchy')); ?>
				</div>
			</div>

			<form method="post" action="options.php" class="synchy-form" data-synchy-site-sync-form>
				<?php settings_fields('synchy_site_sync'); ?>

				<div class="synchy-grid synchy-grid--export">
					<div class="synchy-panel">
						<h2><?php esc_html_e('What This Build Does', 'synchy'); ?></h2>
						<ul class="synchy-checklist synchy-checklist--detail">
							<li>
								<strong><?php esc_html_e('Authenticates against the destination site', 'synchy'); ?></strong>
								<span><?php esc_html_e('Synchy connects with the destination username and WordPress application password over the REST API.', 'synchy'); ?></span>
							</li>
							<li>
								<strong><?php esc_html_e('Builds a fresh full-site package locally', 'synchy'); ?></strong>
								<span><?php esc_html_e('The same export engine powers Site Sync so you always push a complete package generated from the local site.', 'synchy'); ?></span>
							</li>
							<li>
								<strong><?php esc_html_e('Uploads the package to the destination Synchy receiver', 'synchy'); ?></strong>
								<span><?php esc_html_e('Archive and installer.php are transferred in chunks so large packages can move through normal HTTPS requests.', 'synchy'); ?></span>
							</li>
							<li>
								<strong><?php esc_html_e('Deploys a manual restore installer to the destination root', 'synchy'); ?></strong>
								<span><?php esc_html_e('After upload, Synchy tries to place the zip and installer.php in the destination WordPress root so you can launch the restore manually.', 'synchy'); ?></span>
							</li>
						</ul>
					</div>

					<div class="synchy-panel synchy-panel--muted">
						<h2><?php esc_html_e('Destination Connection', 'synchy'); ?></h2>
						<div class="synchy-field">
							<label class="synchy-label" for="synchy-destination-url"><?php esc_html_e('WordPress URL', 'synchy'); ?></label>
							<input
								id="synchy-destination-url"
								type="url"
								class="regular-text code"
								name="<?php echo esc_attr(SYNCHY_SITE_SYNC_OPTIONS); ?>[destination_url]"
								value="<?php echo esc_attr((string) $options['destination_url']); ?>"
								placeholder="https://live-site.com"
								data-synchy-site-sync-url
							/>
						</div>

						<div class="synchy-field">
							<label class="synchy-label" for="synchy-destination-username"><?php esc_html_e('Username', 'synchy'); ?></label>
							<input
								id="synchy-destination-username"
								type="text"
								class="regular-text"
								name="<?php echo esc_attr(SYNCHY_SITE_SYNC_OPTIONS); ?>[destination_username]"
								value="<?php echo esc_attr((string) $options['destination_username']); ?>"
								autocomplete="username"
								data-synchy-site-sync-username
							/>
						</div>

						<div class="synchy-field">
							<label class="synchy-label" for="synchy-destination-application-password"><?php esc_html_e('Application Password', 'synchy'); ?></label>
							<input
								id="synchy-destination-application-password"
								type="password"
								class="regular-text"
								name="<?php echo esc_attr(SYNCHY_SITE_SYNC_OPTIONS); ?>[destination_application_password]"
								value=""
								autocomplete="new-password"
								placeholder="<?php esc_attr_e('Leave blank to keep the saved password', 'synchy'); ?>"
								data-synchy-site-sync-password
							/>
							<p class="synchy-field-note"><?php echo esc_html($password_hint); ?></p>
						</div>

						<div class="synchy-field">
							<label class="synchy-toggle">
								<input
									type="checkbox"
									name="<?php echo esc_attr(SYNCHY_SITE_SYNC_OPTIONS); ?>[verify_ssl]"
									value="1"
									<?php checked(!empty($options['verify_ssl'])); ?>
								/>
								<span><?php esc_html_e('Verify HTTPS certificates', 'synchy'); ?></span>
							</label>
							<p class="synchy-field-note">
								<?php esc_html_e('Leave this on for real live sites. Turn it off only when you are testing against a local or self-signed destination.', 'synchy'); ?>
							</p>
						</div>

						<div class="synchy-progress<?php echo $running_job === [] ? ' is-hidden' : ''; ?>" data-synchy-site-sync-progress>
							<div class="synchy-progress__top">
								<strong data-synchy-site-sync-phase><?php echo esc_html(synchy_site_sync_phase_label((string) ($running_job['phase'] ?? ''))); ?></strong>
								<span data-synchy-site-sync-percent><?php echo esc_html((string) (int) ($running_job['progress'] ?? 0)); ?>%</span>
							</div>
							<div class="synchy-progress__bar">
								<span data-synchy-site-sync-bar style="width: <?php echo esc_attr((string) (int) ($running_job['progress'] ?? 0)); ?>%;"></span>
							</div>
							<p class="synchy-progress__message" data-synchy-site-sync-message><?php echo esc_html((string) ($running_job['message'] ?? '')); ?></p>
							<p class="synchy-progress__detail" data-synchy-site-sync-detail>
								<?php
								if ($running_job !== []) {
									echo esc_html(
										sprintf(
											/* translators: 1: uploaded bytes, 2: total bytes */
											__('Uploaded %1$s of %2$s', 'synchy'),
											size_format((int) ($running_job['bytes_uploaded'] ?? 0), 2),
											size_format((int) ($running_job['bytes_total'] ?? 0), 2)
										)
									);
								}
								?>
							</p>
							<p class="synchy-field-note">
								<?php esc_html_e('Keep this tab open while Site Sync is pushing. Refreshing or leaving the page interrupts the upload.', 'synchy'); ?>
							</p>
						</div>

						<div class="synchy-run-export">
							<div class="synchy-input-row">
								<button type="submit" class="button" data-synchy-save-site-sync><?php esc_html_e('Save Connection', 'synchy'); ?></button>
								<button type="button" class="button" data-synchy-test-site-sync><?php esc_html_e('Test Connection', 'synchy'); ?></button>
								<button type="button" class="button button-primary button-large" data-synchy-run-site-sync><?php esc_html_e('Push Package to Destination', 'synchy'); ?></button>
							</div>
						</div>
					</div>
				</div>

				<div class="synchy-panel synchy-panel--wide synchy-site-sync-result is-hidden" data-synchy-site-sync-result>
					<div class="synchy-stack synchy-stack--compact">
						<div class="synchy-stack__split">
							<h2><?php esc_html_e('Destination Check', 'synchy'); ?></h2>
							<span class="synchy-badge" data-synchy-site-sync-result-badge><?php esc_html_e('Pending', 'synchy'); ?></span>
						</div>
						<p class="synchy-field-note" data-synchy-site-sync-result-message><?php esc_html_e('Use Test Connection to verify the destination Synchy receiver.', 'synchy'); ?></p>
						<div class="synchy-export-meta" data-synchy-site-sync-result-meta></div>
					</div>
				</div>

				<div class="synchy-grid synchy-grid--export">
					<div class="synchy-panel">
						<h2><?php esc_html_e('Destination Requirements', 'synchy'); ?></h2>
						<ul class="synchy-checklist">
							<li><?php esc_html_e('Synchy must be installed and active on the destination site.', 'synchy'); ?></li>
							<li><?php esc_html_e('The destination user must be able to manage options so Synchy can verify and receive the push package.', 'synchy'); ?></li>
							<li><?php esc_html_e('Application Passwords should be created on the live site user profile and used here instead of the raw wp-admin password.', 'synchy'); ?></li>
							<li><?php esc_html_e('If you want Synchy to place installer.php and the zip directly in the destination root, that WordPress root must be writable by PHP.', 'synchy'); ?></li>
						</ul>
					</div>

					<div class="synchy-panel synchy-panel--muted">
						<h2><?php esc_html_e('Current Limits', 'synchy'); ?></h2>
						<ul class="synchy-checklist">
							<li><?php esc_html_e('This Site Sync build authenticates, packages, uploads, and deploys a standalone installer to the destination root when that root is writable.', 'synchy'); ?></li>
							<li><?php esc_html_e('The actual overwrite is still a manual installer run on the destination. One-click remote apply is not connected yet.', 'synchy'); ?></li>
							<li><?php esc_html_e('If the destination root is not writable, Synchy leaves the package in wp-content/uploads/synchy-site-sync and you must move the zip and installer.php manually.', 'synchy'); ?></li>
							<li><?php esc_html_e('HTTPS chunk uploads depend on the destination host accepting request bodies at your configured chunk size.', 'synchy'); ?></li>
						</ul>
					</div>
				</div>

			</form>
		</div>
	</div>
	<?php
}

function synchy_render_page(string $page_slug): void
{
	if (!current_user_can('manage_options')) {
		return;
	}

	$pages = synchy_get_pages();
	$current = null;

	foreach ($pages as $page) {
		if ($page['slug'] === $page_slug) {
			$current = $page;
			break;
		}
	}

	if ($current === null) {
		$current = $pages[0];
	}

	if ($page_slug === 'synchy-export') {
		synchy_render_export_page($current);
		return;
	}

	if ($page_slug === 'synchy-site-sync') {
		synchy_render_site_sync_page($current);
		return;
	}

	$cards = [
		__('Export on-demand backups', 'synchy'),
		__('Import and overwrite safely', 'synchy'),
		__('Scheduled backup runs', 'synchy'),
		__('One-click site sync', 'synchy'),
	];
	?>
	<div class="wrap synchy-admin">
		<div class="synchy-shell">
			<div class="synchy-hero">
				<div>
					<p class="synchy-eyebrow"><?php esc_html_e('Plugin Template', 'synchy'); ?></p>
					<h1><?php echo esc_html($current['headline']); ?></h1>
					<p class="synchy-description"><?php echo esc_html($current['description']); ?></p>
				</div>
				<div class="synchy-status">
					<span class="synchy-status__dot" aria-hidden="true"></span>
					<?php esc_html_e('Coming soon', 'synchy'); ?>
				</div>
			</div>

			<div class="synchy-grid">
				<div class="synchy-panel">
					<h2><?php esc_html_e('Planned Scope', 'synchy'); ?></h2>
					<ul class="synchy-checklist">
						<?php foreach ($cards as $card) : ?>
							<li><?php echo esc_html($card); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="synchy-panel synchy-panel--muted">
					<h2><?php esc_html_e('Next Step', 'synchy'); ?></h2>
					<p><?php esc_html_e('This screen is a placeholder for future Synchy functionality. The export workflow is now the active build area.', 'synchy'); ?></p>
				</div>
			</div>
		</div>
	</div>
	<?php
}

add_action('admin_menu', function (): void {
	$pages = synchy_get_pages();
	$first = array_shift($pages);

	add_menu_page(
		__('Synchy', 'synchy'),
		__('Synchy', 'synchy'),
		'manage_options',
		$first['slug'],
		static function () use ($first): void {
			synchy_render_page($first['slug']);
		},
		'dashicons-backup',
		58
	);

	foreach ($pages as $page) {
		add_submenu_page(
			SYNCHY_SLUG,
			$page['title'],
			$page['menu_title'],
			'manage_options',
			$page['slug'],
			static function () use ($page): void {
				synchy_render_page($page['slug']);
			}
		);
	}
});

add_action('admin_init', function (): void {
	register_setting(
		'synchy_export',
		SYNCHY_EXPORT_OPTIONS,
		[
			'type' => 'array',
			'sanitize_callback' => 'synchy_sanitize_export_options',
			'default' => synchy_get_export_defaults(),
		]
	);

	register_setting(
		'synchy_site_sync',
		SYNCHY_SITE_SYNC_OPTIONS,
		[
			'type' => 'array',
			'sanitize_callback' => 'synchy_sanitize_site_sync_options',
			'default' => synchy_get_site_sync_defaults(),
		]
	);
});

add_action('wp_ajax_synchy_start_export', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to run Synchy exports.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_export_ajax', 'nonce');

	$options = isset($_POST[SYNCHY_EXPORT_OPTIONS]) ? synchy_sanitize_export_options(wp_unslash($_POST[SYNCHY_EXPORT_OPTIONS])) : synchy_get_export_options();
	$job = synchy_start_export_job($options);

	if (is_wp_error($job)) {
		wp_send_json_error(['message' => $job->get_error_message()], 400);
	}

	wp_send_json_success(['job' => synchy_build_job_response($job)]);
});

add_action('wp_ajax_synchy_continue_export', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to run Synchy exports.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_export_ajax', 'nonce');

	$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
	$job = synchy_get_export_job();

	if ($job === [] || $job_id === '' || $job_id !== (string) ($job['job_id'] ?? '')) {
		wp_send_json_error(['message' => __('Synchy could not find the requested export job.', 'synchy')], 404);
	}

	$job = synchy_process_export_job($job);

	wp_send_json_success(['job' => synchy_build_job_response($job)]);
});

add_action('wp_ajax_synchy_browse_export_directories', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to browse Synchy export folders.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_export_ajax', 'nonce');

	$path = isset($_POST['path']) ? sanitize_text_field(wp_unslash((string) $_POST['path'])) : '';
	wp_send_json_success(synchy_get_browse_payload($path));
});

add_action('wp_ajax_synchy_test_site_sync_connection', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to test Synchy Site Sync connections.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_site_sync_ajax', 'nonce');

	$options = isset($_POST[SYNCHY_SITE_SYNC_OPTIONS]) ? synchy_sanitize_site_sync_options(wp_unslash($_POST[SYNCHY_SITE_SYNC_OPTIONS])) : synchy_get_site_sync_options();
	$result = synchy_test_site_sync_connection($options);

	if (is_wp_error($result)) {
		wp_send_json_error(['message' => $result->get_error_message()], 400);
	}

	wp_send_json_success(['remoteSite' => $result]);
});

add_action('wp_ajax_synchy_start_site_sync_push', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to start Synchy Site Sync pushes.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_site_sync_ajax', 'nonce');

	$options = isset($_POST[SYNCHY_SITE_SYNC_OPTIONS]) ? synchy_sanitize_site_sync_options(wp_unslash($_POST[SYNCHY_SITE_SYNC_OPTIONS])) : synchy_get_site_sync_options();
	$job = synchy_start_site_sync_job($options);

	if (is_wp_error($job)) {
		wp_send_json_error(['message' => $job->get_error_message()], 400);
	}

	wp_send_json_success(['job' => synchy_build_site_sync_job_response($job)]);
});

add_action('wp_ajax_synchy_continue_site_sync_push', function (): void {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('You are not allowed to continue Synchy Site Sync pushes.', 'synchy')], 403);
	}

	check_ajax_referer('synchy_site_sync_ajax', 'nonce');

	$job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
	$job = synchy_get_site_sync_job();

	if ($job === [] || $job_id === '' || $job_id !== (string) ($job['job_id'] ?? '')) {
		wp_send_json_error(['message' => __('Synchy could not find the requested Site Sync job.', 'synchy')], 404);
	}

	$job = synchy_process_site_sync_job($job);

	wp_send_json_success(['job' => synchy_build_site_sync_job_response($job)]);
});

add_action('admin_post_synchy_download_export', function (): void {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You are not allowed to download Synchy exports.', 'synchy'));
	}

	$package_id = isset($_GET['package']) ? sanitize_text_field(wp_unslash((string) $_GET['package'])) : '';
	$artifact = isset($_GET['artifact']) ? sanitize_text_field(wp_unslash((string) $_GET['artifact'])) : '';
	$allowed = ['archive', 'installer', 'manifest'];

	if ($package_id === '' || !in_array($artifact, $allowed, true)) {
		wp_die(esc_html__('The requested Synchy export file is invalid.', 'synchy'));
	}

	check_admin_referer('synchy_download_export_' . $package_id . '_' . $artifact);

	$last_export = synchy_get_last_export();

	if (($last_export['package_id'] ?? '') !== $package_id) {
		wp_die(esc_html__('That export is no longer available through Synchy.', 'synchy'));
	}

	$artifact_meta = $last_export['artifacts'][$artifact] ?? null;

	if (!is_array($artifact_meta) || empty($artifact_meta['path']) || !is_readable((string) $artifact_meta['path'])) {
		wp_die(esc_html__('The requested Synchy export file could not be found.', 'synchy'));
	}

	$file_path = wp_normalize_path((string) $artifact_meta['path']);
	$filename = basename($file_path);
	$mime = match ($artifact) {
		'archive' => 'application/zip',
		'manifest' => 'application/json',
		default => 'application/x-httpd-php',
	};

	nocache_headers();
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . (string) filesize($file_path));
	header('Content-Disposition: attachment; filename="' . $filename . '"');

	readfile($file_path);
	exit;
});

add_action('rest_api_init', function (): void {
	$permission = static function () {
		if (current_user_can('manage_options')) {
			return true;
		}

		return new WP_Error(
			'synchy_rest_forbidden',
			__('You are not allowed to access Synchy Site Sync receiver endpoints.', 'synchy'),
			['status' => rest_authorization_required_code()]
		);
	};

	register_rest_route(
		'synchy/v1',
		'/push/ping',
		[
			'methods' => 'GET',
			'callback' => static function () {
				$user = wp_get_current_user();

				return rest_ensure_response(
					[
						'name' => get_bloginfo('name'),
						'siteUrl' => home_url('/'),
						'pluginVersion' => SYNCHY_VERSION,
						'wordpressVersion' => get_bloginfo('version'),
						'authenticatedAs' => $user instanceof WP_User ? (string) $user->user_login : '',
						'receiverMode' => 'root_installer_package_upload',
					]
				);
			},
			'permission_callback' => $permission,
		]
	);

	register_rest_route(
		'synchy/v1',
		'/push/session',
		[
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				$session = synchy_create_remote_push_session(
					[
						'packageName' => (string) $request->get_param('packageName'),
						'packageId' => (string) $request->get_param('packageId'),
						'sourceHomeUrl' => (string) $request->get_param('sourceHomeUrl'),
						'sourceSiteUrl' => (string) $request->get_param('sourceSiteUrl'),
						'archiveFilename' => (string) $request->get_param('archiveFilename'),
						'installerFilename' => (string) $request->get_param('installerFilename'),
						'manifestFilename' => (string) $request->get_param('manifestFilename'),
					]
				);

				if (is_wp_error($session)) {
					return $session;
				}

				return rest_ensure_response(
					[
						'session_id' => $session['session_id'],
						'uploadChunkBytes' => $session['uploadChunkBytes'],
						'packageName' => $session['package_name'],
					]
				);
			},
			'permission_callback' => $permission,
		]
	);

	register_rest_route(
		'synchy/v1',
		'/push/upload',
		[
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				$session_id = synchy_sanitize_remote_push_session_id((string) $request->get_param('session_id'));
				$artifact = sanitize_key((string) $request->get_param('artifact'));
				$offset = max(0, (int) $request->get_param('offset'));
				$allowed_artifacts = ['archive', 'installer', 'manifest'];

				if ($session_id === '' || !in_array($artifact, $allowed_artifacts, true)) {
					return new WP_Error('synchy_push_upload_invalid', __('The Synchy upload request is missing a valid session or artifact.', 'synchy'), ['status' => 400]);
				}

				$session = synchy_read_remote_push_session($session_id);

				if ($session === []) {
					return new WP_Error('synchy_push_upload_missing_session', __('Synchy could not find the destination upload session.', 'synchy'), ['status' => 404]);
				}

				$artifact_meta = $session['artifacts'][$artifact] ?? null;

				if (!is_array($artifact_meta) || empty($artifact_meta['path'])) {
					return new WP_Error('synchy_push_upload_missing_artifact', __('Synchy could not resolve the destination artifact path.', 'synchy'), ['status' => 500]);
				}

				$path = wp_normalize_path((string) $artifact_meta['path']);
				$current_size = file_exists($path) ? (int) filesize($path) : 0;

				if ($offset !== $current_size) {
					return new WP_Error(
						'synchy_push_upload_offset_mismatch',
						__('Synchy detected an upload offset mismatch while receiving the package.', 'synchy'),
						['status' => 409, 'expectedOffset' => $current_size]
					);
				}

				$body = $request->get_body();

				if ($body === '') {
					return new WP_Error('synchy_push_upload_empty', __('Synchy received an empty upload chunk.', 'synchy'), ['status' => 400]);
				}

				if (file_put_contents($path, $body, FILE_APPEND) === false) {
					return new WP_Error('synchy_push_upload_write_failed', __('Synchy could not write the uploaded package chunk.', 'synchy'), ['status' => 500]);
				}

				clearstatcache(true, $path);

				$session['status'] = 'receiving';
				$session['artifacts'][$artifact]['bytes'] = (int) filesize($path);
				$session = synchy_write_remote_push_session($session);

				if (is_wp_error($session)) {
					return $session;
				}

				return rest_ensure_response(
					[
						'session_id' => $session_id,
						'artifact' => $artifact,
						'bytes' => (int) $session['artifacts'][$artifact]['bytes'],
					]
				);
			},
			'permission_callback' => $permission,
		]
	);

	register_rest_route(
		'synchy/v1',
		'/push/complete',
		[
			'methods' => 'POST',
			'callback' => static function (WP_REST_Request $request) {
				$session_id = synchy_sanitize_remote_push_session_id((string) $request->get_param('session_id'));
				$session = synchy_read_remote_push_session($session_id);

				if ($session_id === '' || $session === []) {
					return new WP_Error('synchy_push_complete_missing_session', __('Synchy could not find the destination upload session to finalize.', 'synchy'), ['status' => 404]);
				}

				$archive_path = (string) ($session['artifacts']['archive']['path'] ?? '');
				$installer_path = (string) ($session['artifacts']['installer']['path'] ?? '');
				$manifest_path = (string) ($session['artifacts']['manifest']['path'] ?? '');

				if ($archive_path === '' || !is_readable($archive_path)) {
					return new WP_Error('synchy_push_complete_missing_archive', __('Synchy could not find the uploaded archive on the destination site.', 'synchy'), ['status' => 400]);
				}

				$has_installer = $installer_path !== '' && is_readable($installer_path);
				$has_manifest = $manifest_path !== '' && is_readable($manifest_path);

				if (!$has_installer && !$has_manifest) {
					return new WP_Error('synchy_push_complete_missing_installer', __('Synchy could not find installer.php for the uploaded destination package.', 'synchy'), ['status' => 400]);
				}

				$manifest = [];

				if ($has_manifest) {
					$manifest = json_decode((string) file_get_contents($manifest_path), true);

					if (!is_array($manifest)) {
						return new WP_Error('synchy_push_complete_invalid_manifest', __('Synchy could not decode the uploaded manifest on the destination site.', 'synchy'), ['status' => 400]);
					}
				}

				$session['status'] = 'ready';
				$session['completed_at'] = gmdate('c');
				$session['manifest_summary'] = [
					'package_id' => (string) ($session['package_id'] ?? ($manifest['package_id'] ?? '')),
					'package_name' => (string) ($session['package_name'] ?? ($manifest['package_name'] ?? '')),
					'source_home_url' => (string) ($session['source_home_url'] ?? ($manifest['site']['home_url'] ?? '')),
				];
				$session['root_deploy'] = $has_installer
					? synchy_deploy_remote_push_package_to_root($session)
					: [
						'status' => 'staged_only',
						'message' => __('Synchy staged the package, but this session did not include installer.php for root deployment.', 'synchy'),
						'rootPath' => synchy_get_site_root_path(),
					];
				$session = synchy_write_remote_push_session($session);

				if (is_wp_error($session)) {
					return $session;
				}

				return rest_ensure_response(
					[
						'session_id' => $session_id,
						'status' => 'ready',
						'packageName' => (string) ($session['package_name'] ?? ''),
						'packageId' => (string) ($session['manifest_summary']['package_id'] ?? ''),
						'destinationPath' => (string) ($session['directory'] ?? ''),
						'deployStatus' => (string) ($session['root_deploy']['status'] ?? 'staged_only'),
						'message' => (string) ($session['root_deploy']['message'] ?? __('Package delivered to the destination Synchy receiver.', 'synchy')),
						'installerUrl' => (string) ($session['root_deploy']['installerUrl'] ?? ''),
						'installerPath' => (string) ($session['root_deploy']['installerPath'] ?? ''),
						'archivePath' => (string) ($session['root_deploy']['archivePath'] ?? ''),
						'rootPath' => (string) ($session['root_deploy']['rootPath'] ?? ''),
					]
				);
			},
			'permission_callback' => $permission,
		]
	);
});

add_action('admin_enqueue_scripts', function (string $hook_suffix): void {
	if (strpos($hook_suffix, 'synchy') === false) {
		return;
	}

	$style_path = plugin_dir_path(__FILE__) . 'assets/admin.css';

	if (file_exists($style_path)) {
		wp_enqueue_style(
			'synchy-admin',
			plugin_dir_url(__FILE__) . 'assets/admin.css',
			[],
			(string) filemtime($style_path)
		);
	}

	if (!isset($_GET['page']) || sanitize_key((string) $_GET['page']) !== 'synchy-export') {
		if (!isset($_GET['page']) || sanitize_key((string) $_GET['page']) !== 'synchy-site-sync') {
			return;
		}

		$site_sync_script_path = plugin_dir_path(__FILE__) . 'assets/site-sync.js';

		if (!file_exists($site_sync_script_path)) {
			return;
		}

		wp_enqueue_script(
			'synchy-site-sync',
			plugin_dir_url(__FILE__) . 'assets/site-sync.js',
			[],
			(string) filemtime($site_sync_script_path),
			true
		);

		wp_localize_script(
			'synchy-site-sync',
			'synchySiteSyncConfig',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('synchy_site_sync_ajax'),
				'currentJob' => synchy_build_site_sync_job_response(synchy_get_running_site_sync_job()),
				'strings' => [
					'uploaded' => __('Uploaded', 'synchy'),
					'connectionReady' => __('Connection ready', 'synchy'),
					'connectionError' => __('Connection failed', 'synchy'),
					'unknownError' => __('Synchy hit an unexpected Site Sync error.', 'synchy'),
				],
			]
		);

		return;
	}

	$script_path = plugin_dir_path(__FILE__) . 'assets/export.js';

	if (!file_exists($script_path)) {
		return;
	}

	wp_enqueue_script(
		'synchy-export',
		plugin_dir_url(__FILE__) . 'assets/export.js',
		[],
		(string) filemtime($script_path),
		true
	);

	wp_localize_script(
		'synchy-export',
		'synchyExportConfig',
		[
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('synchy_export_ajax'),
			'currentJob' => synchy_build_job_response(synchy_get_running_export_job()),
			'strings' => [
				'filesProcessed' => __('Files processed:', 'synchy'),
				'unknownError' => __('Synchy hit an unexpected error while exporting.', 'synchy'),
			],
		]
	);
});
