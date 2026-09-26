<?php

// MySQL duplicate/idempotent cases tolerated during migrations:
// - 42S01 / 1050: table already exists
// - 42S21 / 1060: duplicate column
// - 1061: duplicate key name
// - 23000 / 1062: duplicate unique entry
const MIGRATION_TOLERATED_SQL_STATES = ['42S01', '42S21', '23000'];
const MIGRATION_TOLERATED_DRIVER_CODES = [1050, 1060, 1061, 1062];

function ensure_schema_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function fetch_applied_migrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT filename FROM schema_migrations');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $rows ? array_fill_keys($rows, true) : [];
}

function is_tolerated_migration_error(PDOException $e): bool
{
    $sqlState = (string) $e->getCode();
    $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;

    if (
        in_array($sqlState, MIGRATION_TOLERATED_SQL_STATES, true) ||
        in_array($driverCode, MIGRATION_TOLERATED_DRIVER_CODES, true)
    ) {
        return true;
    }

    $message = strtolower($e->getMessage());

    $toleratedParts = [
        'already exists',
        'duplicate column',
        'duplicate key',
        'duplicate entry',
    ];

    foreach ($toleratedParts as $part) {
        if (strpos($message, $part) !== false) {
            return true;
        }
    }

    return false;
}

function strip_sql_comments(string $sql): string
{
    $result = '';
    $length = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $result .= $char;

            if ($char === '\\' && $i + 1 < $length) {
                $i++;
                $result .= $sql[$i];
                continue;
            }

            if ($char === $quote) {
                $following = $i + 1 < $length ? $sql[$i + 1] : '';
                if ($following === $quote) {
                    $i++;
                    $result .= $sql[$i];
                    continue;
                }
                $quote = null;
            }

            continue;
        }

        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            $result .= $char;
            continue;
        }

        if ($char === '/' && $next === '*') {
            $third = $i + 2 < $length ? $sql[$i + 2] : '';
            if ($third === '!') {
                $j = $i + 3;
                while ($j < $length && ctype_digit($sql[$j])) {
                    $j++;
                }
                while ($j < $length && ctype_space($sql[$j])) {
                    $j++;
                }

                $end = strpos($sql, '*/', $j);
                if ($end === false) {
                    $result .= substr($sql, $j);
                    break;
                }

                $result .= substr($sql, $j, $end - $j);
                $i = $end + 1;
                continue;
            }

            $i += 2;
            while ($i < $length) {
                if ($sql[$i] === '*' && ($i + 1 < $length && $sql[$i + 1] === '/')) {
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }

        $prev = $i > 0 ? $sql[$i - 1] : "\n";
        $isStartOfLineComment = (
            $char === '-' &&
            $next === '-' &&
            ($prev === "\n" || ctype_space($prev))
        );

        if ($isStartOfLineComment) {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            if ($i < $length) {
                $result .= "\n";
            }
            continue;
        }

        $result .= $char;
    }

    return $result;
}

function split_sql_statements(string $sql): array
{
    $cleanSql = strip_sql_comments($sql);
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($cleanSql);

    for ($i = 0; $i < $length; $i++) {
        $char = $cleanSql[$i];

        if ($quote !== null) {
            $buffer .= $char;

            if ($char === '\\' && $i + 1 < $length) {
                $i++;
                $buffer .= $cleanSql[$i];
                continue;
            }

            if ($char === $quote) {
                $following = $i + 1 < $length ? $cleanSql[$i + 1] : '';
                if ($following === $quote) {
                    $i++;
                    $buffer .= $cleanSql[$i];
                    continue;
                }
                $quote = null;
            }

            continue;
        }

        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Execute one migration statement and fully consume every result set.
 *
 * MySQL statements such as EXECUTE may return a result set (for example when a
 * prepared fallback statement is SELECT ...). Leaving that result open causes
 * the next statement to fail with SQLSTATE HY000/2014, even when the caller is
 * otherwise executing statements sequentially.
 */
function execute_migration_statement(PDO $pdo, string $sql): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    try {
        do {
            if ($stmt->columnCount() > 0) {
                $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($stmt->nextRowset());
    } finally {
        $stmt->closeCursor();
    }
}

function run_pending_migrations(PDO $pdo, string $dir): array
{
    ensure_schema_migrations_table($pdo);

    $pattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql';
    $files = glob($pattern) ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $applied = fetch_applied_migrations($pdo);
    $recordStmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');

    $results = [];

    foreach ($files as $filePath) {
        $filename = basename($filePath);

        if (isset($applied[$filename])) {
            $results[] = [
                'filename' => $filename,
                'status' => 'already_applied',
                'statements' => 0,
                'errors' => [],
            ];
            continue;
        }

        $fileResult = [
            'filename' => $filename,
            'status' => 'applied',
            'statements' => 0,
            'errors' => [],
        ];
        $transactionStarted = false;

        try {
            $sql = file_get_contents($filePath);
            if ($sql === false || trim($sql) === '') {
                $fileResult['status'] = 'empty';
                $recordStmt->execute([$filename]);
                $results[] = $fileResult;
                continue;
            }

            $statements = split_sql_statements($sql);
            $fileResult['statements'] = count($statements);
            if (empty($statements)) {
                $fileResult['status'] = 'empty';
                $recordStmt->execute([$filename]);
                $results[] = $fileResult;
                continue;
            }
            $hasExplicitTransactionControl = false;
            foreach ($statements as $statement) {
                if (preg_match('/^\s*(start\s+transaction|commit|rollback|lock\s+tables|unlock\s+tables)\b/i', $statement) === 1) {
                    $hasExplicitTransactionControl = true;
                    break;
                }
            }
            $hasDdlStatements = false;
            foreach ($statements as $statement) {
                if (preg_match('/^\s*(create|alter|drop|truncate|rename)\b/i', $statement) === 1) {
                    $hasDdlStatements = true;
                    break;
                }
            }

            if (!$hasExplicitTransactionControl && !$hasDdlStatements && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }

            foreach ($statements as $statement) {
                try {
                    execute_migration_statement($pdo, $statement);
                } catch (PDOException $e) {
                    if (!is_tolerated_migration_error($e)) {
                        $fileResult['errors'][] = $e->getMessage();
                        break;
                    }
                }
            }

            if (empty($fileResult['errors'])) {
                $recordStmt->execute([$filename]);
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } else {
                $fileResult['status'] = 'failed';
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (Throwable $e) {
            if ($transactionStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $fileResult['status'] = 'failed';
            $fileResult['errors'][] = $e->getMessage();
        }

        $results[] = $fileResult;
    }

    return $results;
}
