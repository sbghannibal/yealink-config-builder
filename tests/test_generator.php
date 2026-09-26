<?php
/**
 * Test script for generator helpers
 * Run with: php tests/test_generator.php
 */

require_once __DIR__ . '/../settings/generator.php';

echo "=== Testing Generator Helpers ===\n\n";

$cisco = get_device_output_profile('cisco_atabox_192', '00:11:22:33:44:55');
echo "Test 1: Cisco filename\n";
echo "  Uses init.cfg: " . ($cisco['filename'] === 'init.cfg' ? '✓ PASS' : '✗ FAIL') . "\n";

$fasttel = get_device_output_profile('fasttel_ft600', '00:11:22:33:44:55');
echo "\nTest 2: Fasttel filename\n";
echo "  Uses xml file: " . ($fasttel['filename'] === 'fasttel_ft600_001122334455.xml' ? '✓ PASS' : '✗ FAIL') . "\n";

$yealink = get_device_output_profile('T46P', '00:11:22:33:44:55');
echo "\nTest 3: Yealink filename\n";
echo "  Uses cfg file: " . ($yealink['filename'] === 'yealink_001122334455.cfg' ? '✓ PASS' : '✗ FAIL') . "\n";

$xmlVars = normalize_template_variables(['VALUE' => 'Lobby & Gate'], 'xml');
echo "\nTest 4: XML escaping\n";
echo "  Escapes ampersand: " . ($xmlVars['VALUE'] === 'Lobby &amp; Gate' ? '✓ PASS' : '✗ FAIL') . "\n";

$cfg = format_generated_config("account.1.label = test \r\n", 'cfg');
echo "\nTest 5: CFG formatting\n";
echo "  Normalizes equals and line endings: " . ($cfg === "account.1.label=test\n" ? '✓ PASS' : '✗ FAIL') . "\n";

$xml = format_generated_config("<schema>\r\n  <row value=\"1\" />  \r\n</schema>", 'xml');
echo "\nTest 6: XML formatting\n";
echo "  Preserves XML structure: " . ($xml === "<schema>\n  <row value=\"1\" />\n</schema>\n" ? '✓ PASS' : '✗ FAIL') . "\n";

$defaults = build_device_template_variables(
    ['device_name' => 'Front Door', 'mac_address' => '00:11:22:33:44:55', 'device_type_name' => 'fasttel_ft600'],
    []
);
echo "\nTest 7: SIP defaults\n";
echo "  Default server port 5060: " . ($defaults['SIP_SERVER_PORT'] === '5060' ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Default RTP port 5004: " . ($defaults['SIP_RTP_PORT'] === '5004' ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Default TLS disabled: " . ($defaults['SIP_USE_TLS'] === '0' ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\nTest 8: Yealink UA requirement\n";
echo "  Cisco bypasses Yealink-only gate: " . (!requires_yealink_user_agent('cisco_atabox_192') ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Yealink still requires Yealink UA: " . (requires_yealink_user_agent('T46P') ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\n=== All tests completed ===\n";
?>
