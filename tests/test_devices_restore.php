<?php
/**
 * Test script for the deleted devices empty state
 * Run with: for lang in nl en fr; do php tests/test_devices_restore.php "$lang"; done
 */

require_once __DIR__ . '/../includes/i18n.php';

$language = $argv[1] ?? 'nl';
if (!array_key_exists($language, get_available_languages())) {
    exit(1);
}
$_SESSION['language'] = $language;

$source = file_get_contents(__DIR__ . '/../admin/devices_restore.php');
$start = strpos($source, '<?php if (!empty($deleted_devices)): ?>');
$end = $start === false ? false : strpos($source, "\n    </div>", $start);
if ($start === false || $end === false) {
    echo "Empty-state template found: ✗ FAIL\n";
    exit(1);
}

$deleted_devices = [];
ob_start();
eval('?>' . substr($source, $start, $end - $start));
$html = trim(ob_get_clean());

$translations = load_translations($language);
$message = $translations['page.devices_restore.no_deleted'] ?? '';
$passed = $message !== '' && $html === '<p>' . $message . '</p>';
echo $language . ' empty state: ' . ($passed ? "✓ PASS\n" : "✗ FAIL\n");
exit($passed ? 0 : 1);
