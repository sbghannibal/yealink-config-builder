<?php
/**
 * Test script for migration SQL parser helpers
 * Run with: php tests/test_migrations.php
 */

require_once __DIR__ . '/../includes/migrations.php';

echo "=== Testing Migration SQL Parser ===\n\n";

function assert_test(bool $condition, string $label): void
{
    echo $label . ': ' . ($condition ? "✓ PASS\n" : "✗ FAIL\n");
    if (!$condition) {
        exit(1);
    }
}

$sql = <<<'SQL'
-- comment before statement
CREATE TABLE test (id INT);
INSERT INTO test VALUES (1, 'value;with;semicolons');
/* block comment with ; ; */
INSERT INTO test VALUES (2, "double;quoted");
SQL;

$statements = split_sql_statements($sql);

assert_test(count($statements) === 3, 'Statement count');
assert_test(str_starts_with($statements[0] ?? '', 'CREATE TABLE'), 'Statement 1 starts with CREATE TABLE');
assert_test(strpos($statements[1] ?? '', "'value;with;semicolons'") !== false, 'Semicolon in single-quoted string preserved');
assert_test(strpos($statements[2] ?? '', '"double;quoted"') !== false, 'Semicolon in double-quoted string preserved');

$sqlWithCommentsAndBackticks = <<<'SQL'
CREATE TABLE `order;history` (`id` INT);
-- this comment should be removed
INSERT INTO `order;history` VALUES (1);
/* this whole block should be removed */
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
SQL;

$parsed = split_sql_statements($sqlWithCommentsAndBackticks);
assert_test(count($parsed) === 3, 'Comment and executable comment parsing count');
assert_test(strpos($parsed[0], '`order;history`') !== false, 'Backtick quoted identifier keeps semicolon');
assert_test(strpos(implode("\n", $parsed), 'this comment should be removed') === false, '-- comments removed');
assert_test(strpos(implode("\n", $parsed), 'SET @OLD_CHARACTER_SET_CLIENT') !== false, 'Executable /*! */ comment preserved as SQL');

$sqlWithEscapedQuotes = <<<'SQL'
INSERT INTO test VALUES ('it\'s fine;still one statement');
INSERT INTO test VALUES ('it''s also fine;still one statement');
SQL;

$escapedStatements = split_sql_statements($sqlWithEscapedQuotes);
assert_test(count($escapedStatements) === 2, 'Escaped and doubled quote statement splitting');
assert_test(strpos($escapedStatements[0], "it\\'s fine;still one statement") !== false, 'Backslash-escaped quote preserved');
assert_test(strpos($escapedStatements[1], "it''s also fine;still one statement") !== false, 'Doubled quote preserved');

echo "\n=== All tests completed ===\n";
