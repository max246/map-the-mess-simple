<?php
// Forwards reports to FixMyStreet via /report/new/mobile.
// Port of backend/app/fixmystreet.py from the main Map the Mess project.

require_once __DIR__ . '/config.php';

class FixMyStreetError extends Exception {}

// Sub-question answers that show up alongside categories in the same response
// but aren't real category values.
const FMS_NON_CATEGORY_VALUES = ['yes', 'no', 'other', 'front_garden', 'back_garden', 'commercial_property'];

// Preference order when matching against the bare category name (after any
// `G|` group prefix). First match wins.
const FMS_CATEGORY_KEYWORDS = ['flytipping', 'fly tipping', 'fly-tipping', 'rubbish', 'litter'];

/** One curl handle per submission so FMS session cookies carry across requests. */
function fms_client()
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'MapTheMess/1.0',
        CURLOPT_COOKIEFILE => '', // in-memory cookie jar
    ]);
    return $ch;
}

/** @return array{status:int, content_type:string, body:string} */
function fms_request($ch, string $method, string $path, ?array $params = null, $body = null): array
{
    $url = FMS_URL . $path;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // An array with CURLFile values is sent as multipart, a string as urlencoded
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new FixMyStreetError('Could not reach FixMyStreet: ' . curl_error($ch));
    }
    return [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'content_type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'body' => $resp,
    ];
}

function fms_keyword_rank(string $value): int
{
    $lower = strtolower($value);
    foreach (FMS_CATEGORY_KEYWORDS as $i => $kw) {
        if (strpos($lower, $kw) !== false) {
            return $i;
        }
    }
    return count(FMS_CATEGORY_KEYWORDS);
}

/**
 * Look up the FMS category form fields at lat/lon that best match fly-tipping.
 *
 * Returns the form fields to send (always includes `category`), or null if no
 * fly-tipping option exists at this location.
 */
function fms_resolve_categorisation($ch, float $lat, float $lon): ?array
{
    try {
        $resp = fms_request($ch, 'GET', '/report/new/ajax', [
            'latitude' => (string) $lat,
            'longitude' => (string) $lon,
            'w' => '1',
        ]);
    } catch (FixMyStreetError $e) {
        error_log("FMS category lookup failed for $lat, $lon: " . $e->getMessage());
        return null;
    }
    $payload = json_decode($resp['body'], true);
    if ($resp['status'] !== 200 || !is_array($payload)) {
        error_log("FMS category lookup non-200 for $lat, $lon: status {$resp['status']}");
        return null;
    }

    // FMS returns category options as HTML inside JSON string fields, e.g.
    // `value="G|Flytipping"` or `value='Fly tipping'`.
    $haystack = implode('', array_filter($payload, 'is_string'));
    preg_match_all('/value=[\'"]([^\'"]+)[\'"]/', $haystack, $m);
    $candidates = array_values(array_filter($m[1], function ($v) {
        return !in_array(strtolower($v), FMS_NON_CATEGORY_VALUES, true);
    }));
    if (!$candidates) {
        error_log("FMS category lookup returned no candidates for $lat, $lon");
        return null;
    }

    // Score: (keyword rank, top-level group preferred, shorter wins) — lower wins
    $catScore = function (string $opt): array {
        $bare = strpos($opt, '|') !== false ? explode('|', $opt, 2)[1] : $opt;
        $rank = fms_keyword_rank($bare);
        if ($rank === count(FMS_CATEGORY_KEYWORDS)) {
            return [$rank, 0, 0];
        }
        return [$rank, strpos($opt, 'G|') === 0 ? 0 : 1, strlen($opt)];
    };
    $best = null;
    foreach ($candidates as $c) {
        if ($best === null || $catScore($c) < $catScore($best)) {
            $best = $c;
        }
    }
    if ($catScore($best)[0] >= count(FMS_CATEGORY_KEYWORDS)) {
        error_log("FMS category lookup found no fly-tipping match at $lat, $lon");
        return null;
    }

    // The `G|` prefix is a UI-only group marker — the mobile endpoint expects the
    // leaf subcategory value as `category`, so pick the best leaf in that group.
    if (strpos($best, 'G|') === 0) {
        $bareKey = preg_replace('/[^A-Za-z0-9]/', '', substr($best, 2));
        $key = preg_quote($bareKey, '/');
        $fieldsetRe = '/<fieldset[^>]*id="subcategory_' . $key . '"[^>]*>(.*?)<\/fieldset>/s';
        if (!preg_match($fieldsetRe, (string) ($payload['subcategories'] ?? ''), $fs)) {
            error_log("FMS group $best at $lat, $lon has no subcategory fieldset");
            return null;
        }
        preg_match_all('/name="category\.' . $key . '"[^>]*value=[\'"]([^\'"]+)[\'"]/', $fs[1], $sm);
        if (!$sm[1]) {
            error_log("FMS group $best at $lat, $lon has empty subcategory list");
            return null;
        }
        $leaf = null;
        foreach ($sm[1] as $s) {
            $score = [fms_keyword_rank($s), strlen($s)];
            if ($leaf === null || $score < $leaf[0]) {
                $leaf = [$score, $s];
            }
        }
        return ['category' => $leaf[1]];
    }
    return ['category' => $best];
}

/** Pick a safe default value for a required FMS category-extra field. */
function fms_pick_extra_default(string $code, array $values): ?string
{
    $keys = [];
    foreach ($values as $v) {
        if (is_array($v) && isset($v['key']) && is_string($v['key']) && $v['key'] !== '') {
            $keys[] = $v['key'];
        }
    }
    if (!$keys) {
        return null;
    }
    $byLower = [];
    foreach ($keys as $k) {
        $byLower[strtolower($k)] = $k;
    }
    $codeL = strtolower($code);

    // Yes/No question → answer "No"
    if (count($byLower) === 2 && isset($byLower['yes'], $byLower['no'])) {
        return $byLower['no'];
    }

    // Location/land-type field → prefer Public land / Roadside
    foreach (['land', 'location', 'area', 'where'] as $kw) {
        if (strpos($codeL, $kw) !== false) {
            foreach (['public', 'roadside', 'footpath'] as $keyword) {
                foreach ($byLower as $kl => $k) {
                    if (strpos((string) $kl, $keyword) !== false) {
                        return $k;
                    }
                }
            }
            break;
        }
    }

    // Generic fallback: prefer "Other" if present, else first option
    return $byLower['other'] ?? $keys[0];
}

/** Fetch required category-extras for a category and pick safe defaults. */
function fms_resolve_extras($ch, float $lat, float $lon, string $category): array
{
    try {
        $resp = fms_request($ch, 'POST', '/report/new/category_extras', null, http_build_query([
            'latitude' => (string) $lat,
            'longitude' => (string) $lon,
            'category' => $category,
        ]));
    } catch (FixMyStreetError $e) {
        error_log("FMS extras lookup failed for $lat, $lon, $category: " . $e->getMessage());
        return [];
    }
    $payload = json_decode($resp['body'], true);
    if ($resp['status'] !== 200 || !is_array($payload)) {
        error_log("FMS extras lookup non-200 for $lat, $lon, $category: status {$resp['status']}");
        return [];
    }

    $fields = [];
    foreach (($payload['category_extra_json'] ?? []) ?: [] as $entry) {
        if (empty($entry['required']) || empty($entry['code'])) {
            continue;
        }
        $chosen = fms_pick_extra_default((string) $entry['code'], $entry['values'] ?? []);
        if ($chosen !== null) {
            $fields[$entry['code']] = $chosen;
        }
    }
    return $fields;
}

/** Upload a photo to FixMyStreet and return the upload file ID. */
function fms_upload_photo($ch, string $path, string $mime): ?string
{
    $resp = fms_request($ch, 'POST', '/photo/upload', null, [
        'photo1' => new CURLFile($path, $mime, 'photo.jpg'),
    ]);
    $data = json_decode($resp['body'], true);
    if ($resp['status'] === 200 && !empty($data['id'])) {
        return (string) $data['id'];
    }
    error_log("FMS photo upload failed: {$resp['status']} " . substr($resp['body'], 0, 200));
    return null;
}

/**
 * Submit a report to FixMyStreet. FMS emails the user to confirm it.
 *
 * @return array{success:bool, report_url:?string}
 */
function fms_submit(string $email, string $name, float $lat, float $lon, string $title, string $detail,
                    string $category, ?string $imagePath = null, ?string $imageMime = null): array
{
    $ch = fms_client();

    $categoryFields = fms_resolve_categorisation($ch, $lat, $lon) ?? ['category' => $category];
    $extras = fms_resolve_extras($ch, $lat, $lon, $categoryFields['category']);

    $uploadFileId = '';
    if ($imagePath) {
        $uploadFileId = fms_upload_photo($ch, $imagePath, $imageMime ?: 'image/jpeg') ?? '';
    }

    $data = array_merge([
        'latitude' => (string) $lat,
        'longitude' => (string) $lon,
        'title' => $title,
        'detail' => $detail,
        'name' => $name,
        'username_register' => $email,
        'phone' => '',
        'submit_problem' => '1',
        'submit_register' => '1',
        'may_show_name' => '1',
        'service' => FMS_SERVICE,
        'pc' => '',
    ], $categoryFields, $extras);
    if ($uploadFileId !== '') {
        $data['upload_fileid'] = $uploadFileId;
    }

    $resp = fms_request($ch, 'POST', '/report/new/mobile', null, http_build_query($data));
    if (strpos($resp['content_type'], 'json') === false) {
        throw new FixMyStreetError("FixMyStreet returned HTML instead of JSON (status {$resp['status']})");
    }

    $result = json_decode($resp['body'], true) ?: [];
    if (!empty($result['errors'])) {
        $details = [];
        foreach ($result['errors'] as $k => $v) {
            $details[] = "$k: " . (is_string($v) ? $v : json_encode($v));
        }
        error_log('FMS rejected submission: ' . implode('; ', $details));
        throw new FixMyStreetError('Validation errors: ' . implode('; ', $details));
    }

    return [
        'success' => !empty($result['success']),
        'report_url' => (!empty($result['success']) && !empty($result['report']))
            ? FMS_URL . '/report/' . $result['report']
            : null,
    ];
}
