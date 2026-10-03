<?php

function massupdate_period(DateTimeImmutable $now): string
{
    $local = $now->setTimezone(new DateTimeZone('Europe/Brussels'));
    if ($local < $local->setTime(8, 0)) {
        $local = $local->modify('-1 day');
    }
    return $local->format('Y-m-d');
}

function massupdate_normalize_mac(string $mac): ?string
{
    if (!preg_match('/\A(?:[a-f0-9]{12}|[a-f0-9]{2}(?::[a-f0-9]{2}){5}|[a-f0-9]{2}(?:-[a-f0-9]{2}){5})\z/i', $mac)) {
        return null;
    }
    return strtoupper(str_replace([':', '-'], '', $mac));
}

function massupdate_parse_request(string $uri, array $query, string $ua): ?array
{
    if (!preg_match('/\bYealink\s+(?:SIP-)?([A-Z][A-Z0-9]{0,63})[\s\/]+([0-9]+(?:\.[0-9]+)+)(?=\s|[;()]|$)/i', $ua, $match)
        || strlen($match[2]) > 64) {
        return null;
    }
    $model = strtoupper($match[1]);
    $version = $match[2];
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }
    $filenameMac = null;
    $generic = false;
    if (preg_match('#\A/massupdate/([a-f0-9]{12})\.cfg\z#i', $path, $file)) {
        $filenameMac = strtoupper($file[1]);
    } elseif (preg_match('#\A/massupdate/y000000000[0-9]{3}\.cfg\z#i', $path)) {
        $generic = true;
    } elseif (!in_array($path, ['/massupdate/', '/massupdate/index.php'], true)) {
        return null;
    }
    $queryMac = null;
    if (array_key_exists('mac', $query)) {
        if (!is_string($query['mac']) || ($queryMac = massupdate_normalize_mac($query['mac'])) === null) {
            return null;
        }
    }
    $uaMacs = [];
    preg_match_all('/(?<![a-z0-9:.-])(?:[a-f0-9]{12}|[a-f0-9]{2}(?::[a-f0-9]{2}){5}|[a-f0-9]{2}(?:-[a-f0-9]{2}){5})(?![a-z0-9:.-])/i', $ua, $macMatches);
    foreach ($macMatches[0] as $macMatch) {
        $uaMacs[] = massupdate_normalize_mac($macMatch);
    }
    $uaMacs = array_values(array_unique($uaMacs));
    if (count($uaMacs) > 1 || ($generic && !$uaMacs)) {
        return null;
    }
    $macs = array_values(array_unique(array_filter([$filenameMac, $queryMac, $uaMacs[0] ?? null])));
    if (count($macs) !== 1) {
        return null;
    }
    return ['mac' => $macs[0], 'model' => $model, 'version' => $version];
}

function massupdate_validate_campaign(array $input): array
{
    foreach (['model', 'target_version', 'firmware_url'] as $field) {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }
    }
    $model = strtoupper(trim($input['model']));
    if (str_starts_with($model, 'SIP-')) {
        $model = substr($model, 4);
    }
    if (!preg_match('/\A[A-Z][A-Z0-9]{0,63}\z/', $model)) {
        throw new InvalidArgumentException('Invalid model.');
    }
    $version = $input['target_version'];
    if (strlen($version) > 64 || !preg_match('/\A[0-9]+(?:\.[0-9]+)+\z/', $version)) {
        throw new InvalidArgumentException('Invalid target version.');
    }
    $url = $input['firmware_url'];
    // Decode repeatedly to also reject nested encoded line breaks and whitespace.
    $decoded = $url;
    do {
        if (preg_match('/[\x00-\x20\x7f]/', $decoded)) {
            throw new InvalidArgumentException('Invalid firmware URL.');
        }
        $previous = $decoded;
        $decoded = rawurldecode($decoded);
    } while ($decoded !== $previous);
    $parts = parse_url($url);
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || str_contains($url, '\\')) {
        throw new InvalidArgumentException('Invalid firmware URL.');
    }
    $limit = $input['daily_limit'] ?? null;
    if ((!is_int($limit) && !is_string($limit))
        || !preg_match('/\A[0-9]+\z/', (string)$limit)
        || (float)$limit < 1 || (float)$limit > 25000) {
        throw new InvalidArgumentException('Daily limit must be between 1 and 25000.');
    }
    return [
        'model' => $model,
        'target_version' => $version,
        'firmware_url' => $url,
        'daily_limit' => (int)$limit,
    ];
}

function massupdate_admit(PDO $pdo, array $request, DateTimeImmutable $now): ?string
{
    if (!isset($request['mac'], $request['model'], $request['version'])
        || !is_string($request['mac']) || !is_string($request['model']) || !is_string($request['version'])
        || massupdate_normalize_mac($request['mac']) !== $request['mac']
        || !preg_match('/\A[A-Z][A-Z0-9]{0,63}\z/', $request['model'])
        || strlen($request['version']) > 64
        || !preg_match('/\A[0-9]+(?:\.[0-9]+)+\z/', $request['version'])) {
        return null;
    }
    $period = massupdate_period($now);
    $pdo->beginTransaction();
    try {
        // Every admission for this campaign uses this lock, including retries.
        $stmt = $pdo->prepare('SELECT * FROM massupdate_campaigns WHERE model = ? FOR UPDATE');
        $stmt->execute([$request['model']]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign || !(int)$campaign['is_active']) {
            $pdo->commit();
            return null;
        }
        $valid = massupdate_validate_campaign($campaign);
        if (version_compare($request['version'], $valid['target_version'], '>=')) {
            $pdo->commit();
            return null;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM massupdate_downloads WHERE campaign_id = ? AND period_date = ? AND mac_address = ?');
        $stmt->execute([$campaign['id'], $period, $request['mac']]);
        if (!$stmt->fetchColumn()) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM massupdate_downloads WHERE campaign_id = ? AND period_date = ?');
            $stmt->execute([$campaign['id'], $period]);
            if ((int)$stmt->fetchColumn() >= $valid['daily_limit']) {
                $pdo->commit();
                return null;
            }
            $stmt = $pdo->prepare('INSERT INTO massupdate_downloads (campaign_id, period_date, mac_address) VALUES (?, ?, ?)');
            $stmt->execute([$campaign['id'], $period, $request['mac']]);
        }
        $pdo->commit();
        return "#!version:1.0.0.1\nfirmware.url = " . $valid['firmware_url'] . "\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Resolves the HTTP status and body for a Mass Update request.
 * Unsupported resources and requests without an available config return 404.
 * HEAD never connects to the database or consumes quota: supported resources return 204.
 */
function massupdate_respond(string $method, string $uri, array $query, string $ua, callable $connect, DateTimeImmutable $now): array
{
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return [405, ''];
    }
    $request = massupdate_parse_request($uri, $query, $ua);
    if ($request === null) {
        return [404, ''];
    }
    if ($method === 'HEAD') {
        return [204, ''];
    }
    try {
        $config = massupdate_admit($connect(), $request, $now);
    } catch (Throwable $e) {
        return [503, ''];
    }
    return $config === null ? [404, ''] : [200, $config];
}
