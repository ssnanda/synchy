<?php
@set_time_limit(0);

if (function_exists('ignore_user_abort')) {
	ignore_user_abort(true);
}

const SYNCHY_INSTALLER_ACCESS_TOKEN = '__SYNCHY_ACCESS_TOKEN__';
const SYNCHY_PACKAGE_ID = '__SYNCHY_PACKAGE_ID__';
const SYNCHY_PACKAGE_NAME = '__SYNCHY_PACKAGE_NAME__';
const SYNCHY_ARCHIVE_FILENAME = '__SYNCHY_ARCHIVE_FILENAME__';
const SYNCHY_ARCHIVE_SIZE_BYTES = '__SYNCHY_ARCHIVE_SIZE_BYTES__';
const SYNCHY_ARCHIVE_SHA256 = '__SYNCHY_ARCHIVE_SHA256__';
const SYNCHY_SOURCE_HOME_URL = '__SYNCHY_SOURCE_HOME_URL__';
const SYNCHY_SOURCE_SITE_URL = '__SYNCHY_SOURCE_SITE_URL__';

function synchyInstallerEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function synchyInstallerPath(string $base, string $path = ''): string
{
	$base = rtrim(str_replace('\\', '/', $base), '/');
	$path = ltrim(str_replace('\\', '/', $path), '/');

	if ($path === '') {
		return $base;
	}

	return $base . '/' . $path;
}

function synchyInstallerConfiguredValue(string $value): string
{
	if (preg_match('/^__SYNCHY_[A-Z0-9_]+__$/', $value)) {
		return '';
	}

	return $value;
}

function synchyInstallerReadableSize(int $bytes): string
{
	$units = ['B', 'KB', 'MB', 'GB', 'TB'];
	$size = max(0, $bytes);
	$power = $size > 0 ? (int) floor(log($size, 1024)) : 0;
	$power = min($power, count($units) - 1);
	$value = $size / (1024 ** $power);

	return number_format($value, $power === 0 ? 0 : 2) . ' ' . $units[$power];
}

function synchyInstallerFindArchivePath(string $directory): string
{
	$expected = synchyInstallerConfiguredValue(SYNCHY_ARCHIVE_FILENAME);

	if ($expected !== '') {
		$path = synchyInstallerPath($directory, $expected);

		if (is_readable($path)) {
			return $path;
		}
	}

	$items = @scandir($directory);

	if (!is_array($items)) {
		return '';
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}

		if (strtolower((string) pathinfo($item, PATHINFO_EXTENSION)) !== 'zip') {
			continue;
		}

		$path = synchyInstallerPath($directory, $item);

		if (is_readable($path)) {
			return $path;
		}
	}

	return '';
}

function synchyInstallerFindWordPressRoot(string $startDirectory): string
{
	$current = rtrim(str_replace('\\', '/', $startDirectory), '/');

	for ($depth = 0; $depth < 8; $depth++) {
		$wp_config = synchyInstallerPath($current, 'wp-config.php');
		$wp_admin = synchyInstallerPath($current, 'wp-admin');

		if (is_readable($wp_config) && is_dir($wp_admin)) {
			return $current;
		}

		$parent = dirname($current);

		if ($parent === $current) {
			break;
		}

		$current = rtrim(str_replace('\\', '/', $parent), '/');
	}

	return '';
}

function synchyInstallerRemoveTree(string $path): void
{
	if (!file_exists($path)) {
		return;
	}

	if (is_file($path) || is_link($path)) {
		@unlink($path);
		return;
	}

	$items = @scandir($path);

	if (!is_array($items)) {
		return;
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}

		synchyInstallerRemoveTree(synchyInstallerPath($path, $item));
	}

	@rmdir($path);
}

function synchyInstallerEnsureDirectory(string $path): void
{
	if (is_dir($path)) {
		return;
	}

	if (!@mkdir($path, 0755, true) && !is_dir($path)) {
		throw new RuntimeException('Could not create directory: ' . $path);
	}
}

function synchyInstallerWriteMaintenanceFile(string $root): void
{
	$maintenance_file = synchyInstallerPath($root, '.maintenance');
	$contents = '<?php $upgrading = ' . time() . ';';
	@file_put_contents($maintenance_file, $contents);
}

function synchyInstallerRemoveMaintenanceFile(string $root): void
{
	$maintenance_file = synchyInstallerPath($root, '.maintenance');

	if (file_exists($maintenance_file)) {
		@unlink($maintenance_file);
	}
}

function synchyInstallerExpectedArchiveSize(): int
{
	$expected = synchyInstallerConfiguredValue(SYNCHY_ARCHIVE_SIZE_BYTES);

	return ctype_digit($expected) ? (int) $expected : 0;
}

function synchyInstallerValidateArchive(string $archivePath): void
{
	if (!is_readable($archivePath)) {
		throw new RuntimeException('Could not read the Synchy archive next to this installer.');
	}

	$expected_size = synchyInstallerExpectedArchiveSize();

	if ($expected_size > 0) {
		$actual_size = (int) filesize($archivePath);

		if ($actual_size !== $expected_size) {
			throw new RuntimeException(
				'Archive size mismatch detected. Expected ' . synchyInstallerReadableSize($expected_size) . ' but found ' . synchyInstallerReadableSize($actual_size) . '.'
			);
		}
	}

	$expected_hash = strtolower(synchyInstallerConfiguredValue(SYNCHY_ARCHIVE_SHA256));

	if ($expected_hash !== '') {
		$actual_hash = strtolower((string) hash_file('sha256', $archivePath));

		if (!hash_equals($expected_hash, $actual_hash)) {
			throw new RuntimeException('Archive hash mismatch detected. Make sure installer.php and the zip belong to the same Synchy package.');
		}
	}
}

function synchyInstallerExtractArchive(string $archivePath, string $extractDirectory, array &$messages): void
{
	if (!class_exists('ZipArchive')) {
		throw new RuntimeException('ZipArchive is not available on this server.');
	}

	synchyInstallerRemoveTree($extractDirectory);
	synchyInstallerEnsureDirectory($extractDirectory);

	$zip = new ZipArchive();
	$result = $zip->open($archivePath);

	if ($result !== true) {
		throw new RuntimeException('Could not open the Synchy archive for extraction.');
	}

	if (!$zip->extractTo($extractDirectory)) {
		$zip->close();
		throw new RuntimeException('Could not extract the Synchy archive into the restore workspace.');
	}

	$zip->close();
	$messages[] = 'Archive extracted to ' . $extractDirectory . '.';
}

function synchyInstallerRequireWordPress(string $root): void
{
	$wp_load = synchyInstallerPath($root, 'wp-load.php');

	if (!is_readable($wp_load)) {
		throw new RuntimeException('Could not locate wp-load.php in the detected WordPress root.');
	}

	require_once $wp_load;

	if (!function_exists('home_url')) {
		throw new RuntimeException('WordPress did not bootstrap correctly from the detected root.');
	}
}

function synchyInstallerIterateSqlStatements(string $sqlPath, callable $callback): int
{
	$handle = @fopen($sqlPath, 'rb');

	if ($handle === false) {
		throw new RuntimeException('Could not open the database.sql file from the extracted package.');
	}

	$buffer = '';
	$in_single_quote = false;
	$in_double_quote = false;
	$escaped = false;
	$statement_count = 0;

	while (($line = fgets($handle)) !== false) {
		if (!$in_single_quote && !$in_double_quote && $buffer === '') {
			$trimmed = ltrim($line);

			if ($trimmed === '' || strncmp($trimmed, '-- ', 3) === 0) {
				continue;
			}
		}

		$length = strlen($line);

		for ($index = 0; $index < $length; $index++) {
			$character = $line[$index];
			$buffer .= $character;

			if ($escaped) {
				$escaped = false;
				continue;
			}

			if ($character === '\\') {
				$escaped = true;
				continue;
			}

			if ($character === "'" && !$in_double_quote) {
				$in_single_quote = !$in_single_quote;
				continue;
			}

			if ($character === '"' && !$in_single_quote) {
				$in_double_quote = !$in_double_quote;
				continue;
			}

			if ($character === ';' && !$in_single_quote && !$in_double_quote) {
				$statement = trim($buffer);
				$buffer = '';

				if ($statement === '' || strncmp(ltrim($statement), '-- ', 3) === 0) {
					continue;
				}

				$callback($statement);
				$statement_count++;
			}
		}
	}

	fclose($handle);

	$statement = trim($buffer);

	if ($statement !== '' && strncmp(ltrim($statement), '-- ', 3) !== 0) {
		$callback($statement);
		$statement_count++;
	}

	return $statement_count;
}

function synchyInstallerImportDatabase(string $sqlPath, array &$messages): void
{
	global $wpdb;

	if (!isset($wpdb) || !is_object($wpdb)) {
		throw new RuntimeException('WordPress database access is unavailable for the restore.');
	}

	$count = synchyInstallerIterateSqlStatements(
		$sqlPath,
		static function (string $statement) use ($wpdb): void {
			$result = $wpdb->query($statement);

			if ($result === false) {
				throw new RuntimeException('Database import failed while executing SQL: ' . $wpdb->last_error);
			}
		}
	);

	$messages[] = 'Database import completed (' . number_format($count) . ' SQL statements).';
}

function synchyInstallerReplaceValue($value, string $search, string $replace, bool &$changed)
{
	if (is_string($value)) {
		$updated = str_replace($search, $replace, $value);

		if ($updated !== $value) {
			$changed = true;
		}

		return $updated;
	}

	if (is_array($value)) {
		foreach ($value as $key => $item) {
			$value[$key] = synchyInstallerReplaceValue($item, $search, $replace, $changed);
		}

		return $value;
	}

	if (is_object($value)) {
		foreach (get_object_vars($value) as $key => $item) {
			$value->$key = synchyInstallerReplaceValue($item, $search, $replace, $changed);
		}

		return $value;
	}

	return $value;
}

function synchyInstallerMaybeUnserialize(string $value, bool &$wasSerialized)
{
	$wasSerialized = false;

	if (function_exists('is_serialized') && is_serialized($value)) {
		$wasSerialized = true;
		return maybe_unserialize($value);
	}

	return $value;
}

function synchyInstallerPrepareDatabaseValue(string $value, string $search, string $replace, bool &$changed): string
{
	$decoded = synchyInstallerMaybeUnserialize($value, $wasSerialized);
	$updated = synchyInstallerReplaceValue($decoded, $search, $replace, $changed);

	if ($wasSerialized) {
		return maybe_serialize($updated);
	}

	return (string) $updated;
}

function synchyInstallerSearchReplace(string $search, string $replace, array &$messages, array &$warnings): void
{
	global $wpdb;

	if ($search === '' || $replace === '' || $search === $replace) {
		return;
	}

	$tables = $wpdb->get_col('SHOW TABLES');

	if (!is_array($tables)) {
		throw new RuntimeException('Could not enumerate database tables for URL replacement.');
	}

	$total_updates = 0;

	foreach ($tables as $table) {
		$table_name = (string) $table;
		$columns = $wpdb->get_results('SHOW FULL COLUMNS FROM `' . str_replace('`', '``', $table_name) . '`', ARRAY_A);

		if (!is_array($columns)) {
			continue;
		}

		$text_columns = [];
		$primary_columns = [];

		foreach ($columns as $column) {
			$field = isset($column['Field']) ? (string) $column['Field'] : '';
			$type = strtolower((string) ($column['Type'] ?? ''));
			$key = strtoupper((string) ($column['Key'] ?? ''));

			if ($field === '') {
				continue;
			}

			if ($key === 'PRI') {
				$primary_columns[] = $field;
			}

			if (
				str_contains($type, 'char')
				|| str_contains($type, 'text')
				|| str_contains($type, 'json')
			) {
				$text_columns[] = $field;
			}
		}

		if ($text_columns === []) {
			continue;
		}

		if ($primary_columns === []) {
			$warnings[] = 'Skipped search-replace on ' . $table_name . ' because it does not have a primary key.';
			continue;
		}

		$select_columns = array_unique(array_merge($primary_columns, $text_columns));
		$select_sql = 'SELECT ' . implode(
			', ',
			array_map(
				static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
				$select_columns
			)
		) . ' FROM `' . str_replace('`', '``', $table_name) . '`';

		$rows = $wpdb->get_results($select_sql, ARRAY_A);

		if (!is_array($rows)) {
			continue;
		}

		foreach ($rows as $row) {
			$updates = [];

			foreach ($text_columns as $column) {
				$current = isset($row[$column]) ? (string) $row[$column] : '';

				if ($current === '' || strpos($current, $search) === false) {
					continue;
				}

				$changed = false;
				$updated = synchyInstallerPrepareDatabaseValue($current, $search, $replace, $changed);

				if ($changed && $updated !== $current) {
					$updates[$column] = $updated;
				}
			}

			if ($updates === []) {
				continue;
			}

			$where = [];

			foreach ($primary_columns as $column) {
				$where[$column] = $row[$column];
			}

			$result = $wpdb->update($table_name, $updates, $where);

			if ($result === false) {
				throw new RuntimeException('Search-replace failed for table ' . $table_name . ': ' . $wpdb->last_error);
			}

			$total_updates += (int) $result;
		}
	}

	$messages[] = 'URL replacement completed for ' . number_format($total_updates) . ' rows.';
}

function synchyInstallerCopyFiles(string $extractDirectory, string $wordpressRoot, array &$messages, array &$warnings): void
{
	$directory = new RecursiveDirectoryIterator($extractDirectory, FilesystemIterator::SKIP_DOTS);
	$iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

	foreach ($iterator as $item) {
		$source_path = str_replace('\\', '/', $item->getPathname());
		$relative_path = ltrim(substr($source_path, strlen(rtrim(str_replace('\\', '/', $extractDirectory), '/'))), '/');

		if ($relative_path === '') {
			continue;
		}

		if ($relative_path === 'synchy' || str_starts_with($relative_path . '/', 'synchy/')) {
			continue;
		}

		if ($relative_path === 'wp-config.php') {
			$warnings[] = 'Skipped wp-config.php so the destination database credentials stay intact.';
			continue;
		}

		$destination_path = synchyInstallerPath($wordpressRoot, $relative_path);

		if ($item->isDir()) {
			synchyInstallerEnsureDirectory($destination_path);
			continue;
		}

		synchyInstallerEnsureDirectory(dirname($destination_path));

		if (!@copy($source_path, $destination_path)) {
			throw new RuntimeException('Could not copy ' . $relative_path . ' into the destination site.');
		}
	}

	$messages[] = 'Package files copied into the destination WordPress root.';
}

function synchyInstallerNormalizeUrl(string $url): string
{
	return rtrim($url, '/');
}

$messages = [];
$warnings = [];
$errors = [];
$restore_complete = false;
$package_directory = str_replace('\\', '/', __DIR__);
$archive_path = synchyInstallerFindArchivePath($package_directory);
$wordpress_root = synchyInstallerFindWordPressRoot($package_directory);
$package_id = synchyInstallerConfiguredValue(SYNCHY_PACKAGE_ID);
$package_name = synchyInstallerConfiguredValue(SYNCHY_PACKAGE_NAME);
$source_home = synchyInstallerNormalizeUrl(synchyInstallerConfiguredValue(SYNCHY_SOURCE_HOME_URL));
$source_site = synchyInstallerNormalizeUrl(synchyInstallerConfiguredValue(SYNCHY_SOURCE_SITE_URL));
$workspace_root = synchyInstallerPath($package_directory, '.synchy-restore-' . preg_replace('/[^a-z0-9-]/i', '', $package_id !== '' ? $package_id : 'package'));
$extract_directory = synchyInstallerPath($workspace_root, 'extracted');
$database_path = synchyInstallerPath($extract_directory, 'synchy/database.sql');
$expected_archive_size = synchyInstallerExpectedArchiveSize();
$actual_archive_size = $archive_path !== '' && is_readable($archive_path) ? (int) filesize($archive_path) : 0;
$token_required = SYNCHY_INSTALLER_ACCESS_TOKEN !== '' && SYNCHY_INSTALLER_ACCESS_TOKEN !== '__SYNCHY_ACCESS_TOKEN__';
$provided_token = isset($_REQUEST['token']) ? trim((string) $_REQUEST['token']) : '';
$authorized = !$token_required || ($provided_token !== '' && hash_equals(SYNCHY_INSTALLER_ACCESS_TOKEN, $provided_token));

if ($archive_path === '') {
	$errors[] = 'Could not find the Synchy archive next to this installer.';
} elseif ($expected_archive_size > 0 && $actual_archive_size !== $expected_archive_size) {
	$errors[] = 'Archive size mismatch detected. Expected ' . synchyInstallerReadableSize($expected_archive_size) . ' but found ' . synchyInstallerReadableSize($actual_archive_size) . '.';
}

if ($wordpress_root === '') {
	$errors[] = 'Could not detect the WordPress root above this installer location.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_restore') {
	if (!$authorized) {
		$errors[] = 'This installer URL is locked. Open it with the full tokenized URL from Synchy Site Sync.';
	} elseif (!empty($errors)) {
		$errors[] = 'Resolve the installer preflight errors before attempting the restore.';
	} elseif (!isset($_POST['confirm_backup']) || $_POST['confirm_backup'] !== '1') {
		$errors[] = 'Confirm that you created a backup of the destination site before running the restore.';
	} else {
		try {
			synchyInstallerValidateArchive($archive_path);
			synchyInstallerWriteMaintenanceFile($wordpress_root);
			synchyInstallerExtractArchive($archive_path, $extract_directory, $messages);

			if (!is_readable($database_path)) {
				throw new RuntimeException('Could not find synchy/database.sql after extracting the package.');
			}

			synchyInstallerRequireWordPress($wordpress_root);
			synchyInstallerImportDatabase($database_path, $messages);

			$current_home = function_exists('home_url') ? synchyInstallerNormalizeUrl((string) home_url('/')) : '';
			$current_site = function_exists('site_url') ? synchyInstallerNormalizeUrl((string) site_url('/')) : '';

			$pairs = [
				[$source_home, $current_home],
				[$source_site, $current_site],
			];

			foreach ($pairs as $pair) {
				if ($pair[0] !== '' && $pair[1] !== '' && $pair[0] !== $pair[1]) {
					synchyInstallerSearchReplace($pair[0], $pair[1], $messages, $warnings);
				}
			}

			synchyInstallerCopyFiles($extract_directory, $wordpress_root, $messages, $warnings);

			if (function_exists('wp_cache_flush')) {
				wp_cache_flush();
			}

			$restore_complete = true;
			$messages[] = 'Restore complete. Review the destination site, then delete installer.php, the archive zip, and the hidden restore workspace.';
		} catch (Throwable $throwable) {
			$errors[] = $throwable->getMessage();
		}

		synchyInstallerRemoveMaintenanceFile($wordpress_root);
	}
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Synchy Installer</title>
<style>
body{margin:0;padding:32px;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#eef4ef;color:#12323b}
.shell{max-width:980px;margin:0 auto;padding:28px;border:1px solid #d7e1da;border-radius:24px;background:#fff;box-shadow:0 18px 36px rgba(18,35,28,.08)}
.eyebrow{margin:0 0 8px;color:#16824f;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
h1{margin:0 0 12px;font-size:44px;line-height:1}
h2{margin:0 0 12px;font-size:22px}
p{margin:0 0 12px;color:#4a676c}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:24px 0}
.card{padding:18px;border:1px solid #d7e1da;border-radius:18px;background:#f6fbf7}
.label{display:block;margin-bottom:6px;color:#426067;font-size:13px;text-transform:uppercase;letter-spacing:.04em}
.notice{margin:18px 0;padding:14px 16px;border-radius:16px}
.notice.error{background:#fff4f4;color:#8a1f1f}
.notice.success{background:#eefaf3;color:#19653f}
.notice.warn{background:#fff7e8;color:#845a0f}
.stack{display:grid;gap:14px}
code{padding:4px 8px;border-radius:10px;background:#f4f6f5}
ul{margin:0;padding-left:18px}
form{margin-top:24px;padding:18px;border:1px solid #d7e1da;border-radius:18px;background:#f8fbf8}
button{appearance:none;border:0;border-radius:12px;background:#1e7bc8;color:#fff;padding:12px 18px;font:inherit;font-weight:600;cursor:pointer}
button:disabled{opacity:.55;cursor:not-allowed}
label.checkbox{display:flex;gap:10px;align-items:flex-start;margin:14px 0;color:#304e55}
.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
a{color:#1e7bc8}
@media (max-width: 760px){body{padding:18px}.shell{padding:22px}.grid,.meta{grid-template-columns:1fr}h1{font-size:34px}}
</style>
</head>
<body>
<div class="shell">
	<p class="eyebrow">Synchy Installer</p>
	<h1>Manual Restore</h1>
	<p>This installer restores the Synchy archive staged next to it, using a root-level installer flow closer to Duplicator than the previous preview-only Synchy export screen.</p>

	<?php if ($token_required && !$authorized) : ?>
		<div class="notice error">
			<strong>Installer locked.</strong>
			<p>Open this installer with the full tokenized URL provided by Synchy Site Sync.</p>
		</div>
	<?php endif; ?>

	<?php foreach ($errors as $error) : ?>
		<div class="notice error"><?php echo synchyInstallerEscape($error); ?></div>
	<?php endforeach; ?>

	<?php if ($restore_complete) : ?>
		<div class="notice success">
			<strong>Restore complete.</strong>
			<p>Review the destination site and remove the installer artifacts after validation.</p>
		</div>
	<?php endif; ?>

	<?php if ($warnings !== []) : ?>
		<div class="notice warn">
			<strong>Warnings</strong>
			<ul>
				<?php foreach ($warnings as $warning) : ?>
					<li><?php echo synchyInstallerEscape($warning); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="grid">
		<div class="card">
			<h2>Package</h2>
			<div class="meta">
				<div>
					<span class="label">Package Name</span>
					<strong><?php echo synchyInstallerEscape($package_name !== '' ? $package_name : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Package ID</span>
					<strong><?php echo synchyInstallerEscape($package_id !== '' ? $package_id : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Source Home URL</span>
					<strong><?php echo synchyInstallerEscape($source_home !== '' ? $source_home : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Archive</span>
					<strong><?php echo synchyInstallerEscape($archive_path !== '' ? basename($archive_path) : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Archive Size</span>
					<strong><?php echo synchyInstallerEscape($actual_archive_size > 0 ? synchyInstallerReadableSize($actual_archive_size) : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Source Site URL</span>
					<strong><?php echo synchyInstallerEscape($source_site !== '' ? $source_site : 'Unavailable'); ?></strong>
				</div>
			</div>
		</div>

		<div class="card">
			<h2>Destination</h2>
			<div class="meta">
				<div>
					<span class="label">WordPress Root</span>
					<strong><?php echo synchyInstallerEscape($wordpress_root !== '' ? $wordpress_root : 'Unavailable'); ?></strong>
				</div>
				<div>
					<span class="label">Extract Workspace</span>
					<strong><?php echo synchyInstallerEscape($extract_directory); ?></strong>
				</div>
				<div>
					<span class="label">Database Dump</span>
					<strong><?php echo synchyInstallerEscape($database_path); ?></strong>
				</div>
				<div>
					<span class="label">Installer File</span>
					<strong><?php echo synchyInstallerEscape(basename(__FILE__)); ?></strong>
				</div>
			</div>
		</div>
	</div>

	<div class="card stack">
		<h2>What Restore Does</h2>
		<ul>
			<li>Validates that the zip next to this installer matches the expected Synchy package.</li>
			<li>Extracts the uploaded Synchy archive into a temporary workspace next to this installer.</li>
			<li>Bootstraps the current WordPress site and imports the package database dump.</li>
			<li>Runs URL replacement from the source package URLs to the current destination URLs.</li>
			<li>Copies the extracted files into the WordPress root while preserving the destination <code>wp-config.php</code>.</li>
		</ul>
		<p><strong>Backup first.</strong> This restore overwrites the destination database and site files.</p>
	</div>

	<?php if ($messages !== []) : ?>
		<div class="card stack">
			<h2>Restore Log</h2>
			<ul>
				<?php foreach ($messages as $message) : ?>
					<li><?php echo synchyInstallerEscape($message); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if (!$restore_complete) : ?>
		<form method="post">
			<input type="hidden" name="action" value="run_restore">
			<?php if ($provided_token !== '') : ?>
				<input type="hidden" name="token" value="<?php echo synchyInstallerEscape($provided_token); ?>">
			<?php endif; ?>
			<label class="checkbox">
				<input type="checkbox" name="confirm_backup" value="1">
				<span>I created a database and file backup of this destination site and I understand this restore will overwrite it.</span>
			</label>
			<button type="submit" <?php echo (!$authorized || $errors !== []) ? 'disabled' : ''; ?>>Run Restore</button>
		</form>
	<?php else : ?>
		<p><a href="<?php echo synchyInstallerEscape(function_exists('admin_url') ? admin_url() : '#'); ?>">Open WordPress admin</a></p>
	<?php endif; ?>
</div>
</body>
</html>
