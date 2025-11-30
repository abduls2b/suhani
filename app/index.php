<?php
ini_set('memory_limit', '1024M');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);
/* ================== Configuration (edit if needed) ================== */
const PORTAL = "http://tv.fusion4k.cc/stalker_portal";
const MAC = "00:1a:79:00:84:8f";
const SESSION_FILE = __DIR__ . '/session.json';
const CHANNELS_FILE = __DIR__ . '/raw_channels.json';
const PROFILE_FILE = __DIR__ . '/profile_response.json';
const CREATED_LINK_FILE = __DIR__ . '/created_link.json';
const PLAYLIST_FILE = __DIR__ . '/playlist.m3u';
const PLAYLIST_META = __DIR__ . '/playlist.meta.json';
const HITS_FILE = __DIR__ . '/playlist_hits.json';
/* ================================================================== */

/* ========================= Utilities ========================= */
function md5Upper(string $text): string { return strtoupper(md5($text)); }
function sha256Upper(string $text): string { return strtoupper(hash('sha256', $text)); }

/** urlencode but ensure percent-hex are uppercase */
function encodeUpper(string $s): string {
    $q = rawurlencode($s);
    return preg_replace_callback('/%[0-9a-f]{2}/i', function($m){ return strtoupper($m[0]); }, $q);
}

function save_json(string $path, $data): bool {
    $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        $json = @json_encode($data);
        if ($json === false) $json = "{}";
    }
    $tmp = $path . '.tmp';
    $w = @file_put_contents($tmp, $json);
    if ($w === false) return false;
    return @rename($tmp, $path);
}

function load_json(string $path) {
    if (!file_exists($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false) return null;
    $decoded = @json_decode($txt, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $decoded;
}

function generateDeviceInfo(string $mac): array {
    $upperMac = strtoupper($mac);
    $sn = md5Upper($upperMac);
    $sncut = substr($sn, 0, 13);
    $deviceId = sha256Upper($upperMac);
    $signature = sha256Upper($sncut . $upperMac);
    return ['mac' => $upperMac, 'sn' => $sn, 'sncut' => $sncut, 'deviceId' => $deviceId, 'signature' => $signature];
}

function buildHeaders(string $portal, string $cookie = "", string $token = ""): array {
    $h = [
        "User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3",
        "X-User-Agent: Model: MAG250; Link: WiFi",
        "Referer: " . rtrim($portal, '/') . "/c/",
        "Accept: */*",
        "Connection: Keep-Alive",
        "Accept-Encoding: gzip",
    ];
    if ($cookie !== "") $h[] = "Cookie: " . $cookie;
    if ($token !== "") $h[] = "Authorization: Bearer " . $token;
    return $h;
}

/* cURL helper that returns headers, body, cookies, status */
function curl_get_raw(string $url, array $headers = [], int $timeout = 20): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, ""); // accept gzip
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: " . $err);
    }
    $info = curl_getinfo($ch);
    curl_close($ch);
    $headerSize = $info['header_size'] ?? 0;
    $header = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $headers_out = [];
    $cookies = [];
    $lines = preg_split("/\r\n|\n|\r/", trim($header));
    foreach ($lines as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k); $v = trim($v);
            if (!isset($headers_out[$k])) $headers_out[$k] = $v; else $headers_out[$k] .= ', ' . $v;
            if (strtolower($k) === 'set-cookie') {
                // split on comma but be conservative (some cookies contain commas)
                $parts = preg_split('/,(?=[^;]+=)/', $v);
                foreach ($parts as $p) {
                    $pair = explode(';', $p, 2)[0];
                    if (strpos($pair, '=') !== false) {
                        [$cn,$cv] = explode('=', $pair, 2);
                        $cookies[trim($cn)] = trim($cv);
                    }
                }
            }
        }
    }
    return ['status' => $info['http_code'] ?? 200, 'header' => $headers_out, 'body' => $body, 'cookies' => $cookies, 'raw_header' => $header];
}

/* fetch with retries */
function fetchWithRetry(string $url, array $headers, int $retries = 2, int $timeout = 15) {
    $last_exc = null;
    for ($i = 0; $i <= $retries; $i++) {
        try {
            return curl_get_raw($url, $headers, $timeout);
        } catch (Exception $e) {
            $last_exc = $e;
            if ($i == $retries) break;
            usleep(500000);
        }
    }
    throw $last_exc;
}

/* ====================== Handshake & Session Management ====================== */
function performHandshake(string $portal, string $mac): array {
    $cookie = "mac={$mac}; stb_lang=en; timezone=GMT";
    error_log("🔹 Handshake...");
    $handshakeURL = rtrim($portal, '/') . "/server/load.php?type=stb&action=handshake&prehash=" . encodeUpper($mac) . "&token=&JsHttpRequest=1-xml";
    $resp = curl_get_raw($handshakeURL, buildHeaders($portal, $cookie), 20);
    $token = "";
    $body = $resp['body'];
    $json = @json_decode($body, true);
    if (is_array($json)) {
        $token = $json['js']['token'] ?? $json['token'] ?? "";
    }
    // combine cookies from Set-Cookie header and parsed cookies
    $cookie_parts = ["mac={$mac}", "stb_lang=en", "timezone=GMT"];
    if (!empty($resp['cookies'])) {
        foreach ($resp['cookies'] as $k=>$v) $cookie_parts[] = "{$k}={$v}";
    }
    if (!empty($resp['header'])) {
        foreach ($resp['header'] as $hk => $hv) {
            if (strtolower($hk) === 'set-cookie') {
                // split conservatively
                $parts = preg_split('/,(?=[^;]+=)/', $hv);
                foreach ($parts as $part) {
                    $first_pair = explode(';', $part, 2)[0];
                    if (strpos($first_pair, '=') !== false) $cookie_parts[] = trim($first_pair);
                }
            }
        }
    }
    $cookie_combined = implode("; ", array_values(array_unique($cookie_parts)));
    if (!$token) throw new Exception("No token received from handshake.");
    $session = [
        "portal" => rtrim($portal, '/') . "/c/",
        "mac" => $mac,
        "token" => $token,
        "cookie" => $cookie_combined,
        "headers" => buildHeaders($portal, $cookie_combined, $token),
        "fetched_at" => round(microtime(true) * 1000),
    ];
    save_json(SESSION_FILE, $session);
    error_log("✅ Handshake Token: " . $token);
    return $session;
}

function validateSession(array $session, string $portal): bool {
    $device = generateDeviceInfo($session['mac']);
    $ts = time();
    $metrics_json = json_encode([
        "mac" => $device['mac'], "sn" => $device['sn'], "model" => "MAG250", "type" => "STB",
        "random" => bin2hex(random_bytes(8))
    ]);
    $metrics = encodeUpper($metrics_json);
    $profileURL = rtrim($portal, '/') . "/server/load.php?type=stb&action=get_profile&hd=1"
        . "&sn=" . urlencode($device['sncut'])
        . "&stb_type=MAG250&device_id=" . urlencode($device['deviceId'])
        . "&device_id2=" . urlencode($device['deviceId'])
        . "&signature=" . urlencode($device['signature'])
        . "&timestamp=" . $ts
        . "&metrics=" . $metrics
        . "&JsHttpRequest=1-xml";
    try {
        $resp = curl_get_raw($profileURL, $session['headers'] ?? [], 18);
        $body = $resp['body'];
        $json = @json_decode($body, true);
        if (!is_array($json)) return false;
        $js = $json['js'] ?? null;
        if (is_array($js) && (!empty($js['id']) || !empty($js['phone']))) return true;
        if (!empty($json['id']) || !empty($json['phone'])) return true;
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function ensure_session(string $portal = PORTAL, string $mac = MAC, bool $force_refresh = false): array {
    $session = load_json(SESSION_FILE);
    $now = round(microtime(true) * 1000);
    if (!is_array($session) || $force_refresh || ($now - ($session['fetched_at'] ?? 0) > 24 * 60 * 60 * 1000)) {
        error_log("⚠️ No recent session — doing handshake");
        $session = performHandshake($portal, $mac);
        // ALWAYS fetch profile after handshake (user requested "Use get_profile always")
        try { fetchProfile($session, $portal); } catch (Exception $e) { error_log("⚠️ fetchProfile after handshake failed: " . $e->getMessage()); }
    } else {
        if (!validateSession($session, $portal)) {
            error_log("🔁 Session invalid — re-handshake");
            $session = performHandshake($portal, $mac);
            try { fetchProfile($session, $portal); } catch (Exception $e) { error_log("⚠️ fetchProfile after re-handshake failed: " . $e->getMessage()); }
        } else {
            // also refresh profile periodically (every 30 minutes) to improve portal responses
            $profile = load_json(PROFILE_FILE);
            $profile_age_ok = true;
            if (!is_array($profile) || (time() - (@filemtime(PROFILE_FILE) ?: 0)) > 1800) $profile_age_ok = false;
            if (!$profile_age_ok) {
                try { fetchProfile($session, $portal); } catch (Exception $e) { error_log("⚠️ fetchProfile refresh failed: " . $e->getMessage()); }
            }
        }
    }
    return $session;
}

/* ====================== Profile fetch ====================== */
function fetchProfile(array $session, string $portal): void {
    error_log("🔹 Fetching profile...");
    $device = generateDeviceInfo($session['mac']);
    $ts = time();
    $metrics_json = json_encode(["mac"=>$device['mac'], "sn"=>$device['sn'], "model"=>"MAG250", "type"=>"STB", "random"=>bin2hex(random_bytes(8))]);
    $metrics = encodeUpper($metrics_json);
    $profileURL = rtrim($portal, '/') . "/server/load.php?type=stb&action=get_profile&hd=1"
        . "&sn=" . urlencode($device['sncut'])
        . "&stb_type=MAG250&device_id=" . urlencode($device['deviceId'])
        . "&device_id2=" . urlencode($device['deviceId'])
        . "&signature=" . urlencode($device['signature'])
        . "&timestamp=" . $ts
        . "&metrics=" . $metrics
        . "&JsHttpRequest=1-xml";
    $resp = curl_get_raw($profileURL, $session['headers'] ?? [], 20);
    $body = $resp['body'];
    $json = @json_decode($body, true);
    $toSave = is_array($json) ? $json : $body;
    save_json(PROFILE_FILE, $toSave);
    error_log("✅ Saved profile_response.json");
}

/* ====================== Genres (categories) ====================== */
function loadOrFetchGenres(array $session, string $portal, $channels_list = null): array {
    // Ensure get_profile has been called recently — it can affect which endpoints return data
    try {
        // fetchProfile may be heavy; call but ignore errors
        fetchProfile($session, $portal);
    } catch (Exception $e) {
        error_log("⚠️ loadOrFetchGenres: fetchProfile warning: " . $e->getMessage());
    }

    error_log("🔹 Fetching genres/categories...");
    $portalNormalized = rtrim($portal, '/') . "/";
    $genreMap = [];
    $endpoints = [
        // common endpoints used by different portals
        "server/load.php?type=itv&action=get_genres&JsHttpRequest=1-xml",
        "server/load.php?type=itv&action=get_genres&JsHttpRequest=1-utf8",
        "server/load.php?type=itv&action=get_all_genres&JsHttpRequest=1-xml",
        "server/load.php?type=stb&action=get_genres&JsHttpRequest=1-xml",
        "server/load.php?type=itv&action=get_categories&JsHttpRequest=1-xml",
        "server/load.php?type=itv&action=get_all_categories&JsHttpRequest=1-xml",
    ];

    $extract_list = function($body) {
        if ($body === null) return null;
        if (is_array($body)) {
            $js = $body['js'] ?? null;
            if (is_array($js) && array_key_exists('data', $js)) return $js['data'];
            if (is_array($js) && array_values($js) === $js) return $js;
            if (array_values($body) === $body) return $body;
            if (array_key_exists('data', $body)) return $body['data'];
            return $body;
        }
        return null;
    };

    foreach ($endpoints as $ep) {
        $url = $portalNormalized . $ep;
        try {
            $res = fetchWithRetry($url, $session['headers'] ?? []);
            $body = $res['body'];
            $lst = null;
            $json = @json_decode($body, true);
            if (is_array($json)) $lst = $extract_list($json);
            else {
                $trim = trim($body);
                if (($trim !== '') && ($trim[0] === '{' || $trim[0] === '[')) {
                    $try = @json_decode($trim, true);
                    if (is_array($try)) $lst = $extract_list($try);
                }
            }
            if (empty($lst)) continue;

            // Normalize and populate genreMap
            if (is_array($lst)) {
                // If it's a list/array of objects
                if (array_values($lst) === $lst) {
                    foreach ($lst as $g) {
                        if (!is_array($g)) continue;
                        $id_val = null;
                        foreach (['id','genre_id','tv_genre_id','category_id','key'] as $k) {
                            if (isset($g[$k]) && ($g[$k] !== "")) { $id_val = (string)$g[$k]; break; }
                        }
                        $name = $g['name'] ?? $g['title'] ?? $g['genre_name'] ?? $g['tv_genre_name'] ?? $g['category_name'] ?? "";
                        if ($id_val) $genreMap[(string)$id_val] = trim((string)$name);
                    }
                } else {
                    // associative map like { "1": "News", "2": "Sports" } or { "1": {"name":"News"}, ... }
                    foreach ($lst as $k => $v) {
                        if (is_string($v)) $genreMap[(string)$k] = $v;
                        elseif (is_array($v)) {
                            $name = $v['name'] ?? $v['title'] ?? null;
                            if ($name) $genreMap[(string)$k] = $name;
                        }
                    }
                }
            }

            if (!empty($genreMap)) {
                error_log("✅ Fetched " . count($genreMap) . " categories from {$ep}");
                return $genreMap;
            }
        } catch (Exception $e) {
            error_log("❌ Failed {$ep}: " . $e->getMessage());
        }
    }

    // Fallback: build from channels_list (deterministic and unique)
    if (is_array($channels_list)) {
        error_log("⚠️ No portal genres available — building fallback map from channel 'category' fields");
        $seen = [];
        $genreMap = [];
        $idx = 1;
        foreach ($channels_list as $ch) {
            if (!is_array($ch)) continue;
            // try many possible fields where category might be present
            $cat = null;
            foreach (['category','genres_str','group','group-title','tv_genre_name','genre_name'] as $fld) {
                if (!empty($ch[$fld]) && is_string($ch[$fld]) && trim($ch[$fld]) !== '') { $cat = trim($ch[$fld]); break; }
            }
            // also sometimes category is numeric id without name; try tv_genre_id mapping later
            if ($cat && !isset($seen[$cat])) {
                $seen[$cat] = (string)$idx;
                $genreMap[(string)$idx] = $cat;
                $idx++;
            }
        }
        // if still empty, we can attempt to map numeric genre ids to generated names (e.g. "Category 1")
        if (empty($genreMap)) {
            $seenIds = [];
            foreach ($channels_list as $ch) {
                if (!is_array($ch)) continue;
                foreach (['tv_genre_id','genre_id','category_id'] as $idfield) {
                    if (isset($ch[$idfield]) && $ch[$idfield] !== null && $ch[$idfield] !== '') {
                        $idstr = (string)$ch[$idfield];
                        if (!isset($seenIds[$idstr])) {
                            $seenIds[$idstr] = true;
                            $genreMap[$idstr] = "Category " . (count($genreMap) + 1);
                        }
                    }
                }
            }
        }
        if (!empty($genreMap)) {
            error_log("✅ Built " . count($genreMap) . " fallback categories from channels");
            return $genreMap;
        }
    }

    error_log("⚠️ No categories fetched — genreMap will be empty and channels will fallback to explicit names or 'Unknown'");
    return $genreMap;
}

/* ====================== Fetch channels ====================== */
function fetchChannels(array $session, string $portal): array {
    error_log("🔹 Fetching channels...");
    $channelsURL = rtrim($portal, '/') . "/server/load.php?type=itv&action=get_all_channels&JsHttpRequest=1-xml";
    $res = fetchWithRetry($channelsURL, $session['headers'] ?? []);
    $body = $res['body'];
    $json = @json_decode($body, true);
    if (!is_array($json)) {
        throw new Exception("Portal returned invalid response: " . substr($body, 0, 300));
    }
    $channels_list = [];
    if (is_array($json)) {
        // common shapes: { "js": { "data": [...] } } or direct list or { "data": {...} }
        if (isset($json['js']) && is_array($json['js']) && isset($json['js']['data'])) {
            $channels_list = $json['js']['data'];
        } elseif (array_values($json) === $json) {
            $channels_list = $json;
        } elseif (isset($json['data']) && is_array($json['data'])) {
            $channels_list = $json['data'];
        } else {
            $maybe = $json['js'] ?? $json['data'] ?? $json;
            if (is_array($maybe)) {
                $values = array_filter(array_values($maybe), function($v){ return is_array($v); });
                if (!empty($values)) $channels_list = array_values($values);
            }
        }
    }
    if (!is_array($channels_list)) $channels_list = [];
    error_log("✅ Got " . count($channels_list) . " channels");

    // Fetch genres (this now calls fetchProfile first inside loadOrFetchGenres)
    $genres = loadOrFetchGenres($session, $portal, $channels_list);

    $merged = [];
    foreach ($channels_list as $ch) {
        if (!is_array($ch)) {
            $merged[] = ['raw' => $ch, 'category' => 'Unknown'];
            continue;
        }

        // 1) explicit human-readable category fields
        $explicit_cat = null;
        foreach (['category','genres_str','group','group-title','tv_genre_name','genre_name'] as $possible) {
            if (!empty($ch[$possible]) && is_string($ch[$possible]) && trim($ch[$possible]) !== '') {
                $explicit_cat = trim($ch[$possible]);
                break;
            }
        }

        // 2) try to resolve by numeric genre id keys using $genres map
        $tv_genre_id = $ch['tv_genre_id'] ?? $ch['genre_id'] ?? $ch['category_id'] ?? null;
        $cat = null;
        if ($tv_genre_id !== null && $tv_genre_id !== '') {
            // try string key and numeric key
            $idstr = (string)$tv_genre_id;
            if (isset($genres[$idstr]) && $genres[$idstr] !== '') {
                $cat = $genres[$idstr];
            } elseif (isset($genres[$tv_genre_id]) && $genres[$tv_genre_id] !== '') {
                $cat = $genres[$tv_genre_id];
            } else {
                // Sometimes genres map uses different ids; try to coerce numeric keys
                foreach ($genres as $gk => $gv) {
                    if ((string)$gk === $idstr) { $cat = $gv; break; }
                }
            }
        }

        // 3) if that didn't work, fallback to explicitCat (already captured)
        if (!$cat && $explicit_cat) $cat = $explicit_cat;

        // 4) try any other id fields individually
        if (!$cat) {
            foreach (['tv_genre_id','genre_id','category_id'] as $idf) {
                if (isset($ch[$idf]) && $ch[$idf] !== null && $ch[$idf] !== '') {
                    $k = (string)$ch[$idf];
                    if (isset($genres[$k]) && $genres[$k] !== '') { $cat = $genres[$k]; break; }
                }
            }
        }

        // 5) last fallback: try to pick some readable field from channel content
        if (!$cat) {
            foreach (['category','genres_str','group','group-title','tv_genre_name','genre_name'] as $p) {
                if (!empty($ch[$p]) && is_string($ch[$p]) && trim($ch[$p]) !== '') { $cat = trim($ch[$p]); break; }
            }
        }

        // Final fallback
        $category_value = $cat ?? $explicit_cat ?? ($ch['category'] ?? 'Unknown');
        if ($category_value === '' || $category_value === null) $category_value = 'Unknown';

        $new_ch = $ch;
        $new_ch['category'] = $category_value;
        $merged[] = $new_ch;
    }

    // Save channels in consistent format
    save_json(CHANNELS_FILE, ["js" => ["data" => $merged]]);
    error_log("💾 Saved " . count($merged) . " channels with categories to " . CHANNELS_FILE);
    return $merged;
}

/* ====================== create_link (portal call) ====================== */
function create_link(array $session, string $portal, string $cmd) {
    error_log("🔹 create_link for cmd: " . (strlen($cmd) > 120 ? substr($cmd,0,120) . "..." : $cmd));
    if (preg_match('/(https?:\/\/[^\s"\']+)/i', $cmd, $m) && stripos(trim($cmd), 'ffrt') !== 0) {
        $url = trim($m[1]);
        return ['direct' => $url, 'source_cmd' => $cmd];
    }
    $encoded_cmd = rawurlencode($cmd);
    $encoded_cmd = preg_replace_callback('/%[0-9a-f]{2}/i', function($m){ return strtoupper($m[0]); }, $encoded_cmd);
    $url = rtrim($portal, '/') . "/server/load.php?type=itv&action=create_link&cmd=" . $encoded_cmd . "&JsHttpRequest=1-xml";
    $res = curl_get_raw($url, $session['headers'] ?? [], 20);
    $body = $res['body'];
    $json = @json_decode($body, true);
    if (!is_array($json)) {
        return ['status' => 'non-json', 'text' => $body];
    }
    $js = $json['js'] ?? $json;
    try { save_json(CREATED_LINK_FILE, $js); } catch (Exception $e) {}
    return $js;
}

/* ====================== Routing helpers ====================== */
/**
 * Compute "base" path for links so they don't include index.php.
 * Works whether script is in root or subfolder.
 */
function compute_base_url(): string {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // dirname of script name (eg /folder) — if root, dirname gives '/'
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    if ($scriptDir === DIRECTORY_SEPARATOR || $scriptDir === '.' ) $scriptDir = '';
    return rtrim($scheme . '://' . $host . $scriptDir, '/');
}

/**
 * Normalize incoming path when using mod_rewrite forwarding to index.php.
 * If REQUEST_URI contains /index.php/..., the script trims it. If not, it uses path directly.
 */
function detect_path(): string {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $uriPath = parse_url($requestUri, PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $path = $uriPath;
    // If server forwarded via index.php (URL contains /index.php/...), remove index.php part
    $indexPos = strpos($path, '/index.php/');
    if ($indexPos !== false) {
        $path = substr($path, $indexPos + strlen('/index.php/'));
    } else {
        // try to remove script directory prefix
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && $scriptDir !== '\\') {
            if (strpos($path, $scriptDir) === 0) {
                $path = substr($path, strlen($scriptDir));
            }
        }
    }
    $path = trim($path, "/");
    return $path;
}

/* ====================== Playlist caching & hit-based regen ====================== */
/**
 * Return true if playlist should be regenerated:
 *  - raw_channels.json content hash changed vs meta
 *  - or hits in last 60s >= threshold (default 5)
 *  - or playlist file missing
 */
function should_regen_playlist(int $threshold = 5, int $window_seconds = 60): bool {
    $rawPath = CHANNELS_FILE;
    $meta = load_json(PLAYLIST_META);
    $raw_hash = null;
    if (file_exists($rawPath)) {
        $raw_hash = md5_file($rawPath);
    }
    // If playlist missing -> regen
    if (!file_exists(PLAYLIST_FILE)) return true;
    // If meta missing or hash changed -> regen
    if (!is_array($meta) || !isset($meta['raw_hash']) || $meta['raw_hash'] !== $raw_hash) return true;
    // check hits
    $hits = load_json(HITS_FILE) ?: [];
    $now = time();
    // prune old timestamps and count recent
    $recent = array_filter($hits, function($t) use ($now, $window_seconds){ return ($now - (int)$t) <= $window_seconds; });
    if (count($recent) >= $threshold) {
        // reset hits after triggering
        @unlink(HITS_FILE);
        return true;
    }
    return false;
}

function record_playlist_hit(): void {
    $hits = load_json(HITS_FILE) ?: [];
    $hits[] = time();
    // keep only last 100 hits
    if (count($hits) > 200) $hits = array_slice($hits, -200);
    save_json(HITS_FILE, $hits);
}

/* ====================== Routing ====================== */
/*
 Supports:
  - /                 -> info
  - /refresh_session
  - /playlist.m3u
  - /getlink/<int> or /getlink?id=<int>
  - /create_link?cmd=...
  - /proxy?u=...  (simple proxy to forward headers — optional)
*/

$path = detect_path();

function json_resp($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/* Root */
/* Root – Show Stalker Portal Info + Useful Links */
if ($path === '' || $path === 'index.php') {
    $device = generateDeviceInfo(MAC);
    $portalUrl = PORTAL;
    $base = compute_base_url();
    $playlistUrl = $base . '/playlist.m3u';
    $getlinkUrl = $base . '/getlink/0';

    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Stalker Portal Info</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #0b0c10;
                color: #f2f2f2;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .container {
                background: #1f2833;
                padding: 30px 40px;
                border-radius: 10px;
                box-shadow: 0 0 15px rgba(0,0,0,0.4);
                width: 90%;
                max-width: 700px;
            }
            h1 {
                text-align: center;
                color: #66fcf1;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            td {
                padding: 10px;
                border-bottom: 1px solid #45a29e;
            }
            td.label {
                color: #66fcf1;
                font-weight: bold;
                width: 35%;
            }
            td.value {
                color: #c5c6c7;
                word-break: break-all;
            }
            a {
                color: #45a29e;
                text-decoration: none;
                font-weight: bold;
            }
            a:hover {
                color: #66fcf1;
                text-decoration: underline;
            }
            .links {
                text-align: center;
                margin-top: 15px;
            }
            .footer {
                text-align: center;
                margin-top: 25px;
                font-size: 0.9em;
                color: #888;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>Stalker Portal Info</h1>
            <table>
                <tr><td class='label'>Portal URL:</td><td class='value'>{$portalUrl}</td></tr>
                <tr><td class='label'>MAC Address:</td><td class='value'>{$device['mac']}</td></tr>
                <tr><td class='label'>SN Cut:</td><td class='value'>{$device['sncut']}</td></tr>
                <tr><td class='label'>Signature:</td><td class='value'>{$device['signature']}</td></tr>
                <tr><td class='label'>Device ID:</td><td class='value'>{$device['deviceId']}</td></tr>
            </table>

            <div class='links'>
                <p><a href='{$playlistUrl}' target='_blank'>📺 View Playlist (playlist.m3u)</a></p>
                <p><a href='{$getlinkUrl}' target='_blank'>🔗 Example GetLink (Channel 0)</a></p>
            </div>

            <div class='footer'>
                Generated by your Stalker Portal Script
            </div>
        </div>
    </body>
    </html>";
    exit;
}

/* refresh_session */
if ($path === 'refresh_session') {
    try {
        $session = performHandshake(PORTAL, MAC);
        // fetch profile immediately
        try { fetchProfile($session, PORTAL); } catch (Exception $e) { error_log("⚠️ fetchProfile after manual refresh failed: " . $e->getMessage()); }
        json_resp(['status' => 'ok', 'token' => $session['token']]);
    } catch (Exception $e) {
        json_resp(['status' => 'error', 'error' => $e->getMessage()], 500);
    }
}

/* Helper: generate playlist content from channels array */
function build_playlist_text(array $channels): string {
    $base = compute_base_url();
    $lines = ["#EXTM3U"];
    foreach ($channels as $i => $ch) {
        $name = $ch['name'] ?? $ch['title'] ?? ("Channel " . $i);
        $logo = $ch['logo'] ?? "";
        $group = $ch['category'] ?? ($ch['genres_str'] ?? "");
        // sanitize group/title values for M3U attributes
        $group = is_string($group) ? trim($group) : "";
        if ($group === "") {
            // try other fields
            foreach (['genres_str','group','group-title','tv_genre_name','genre_name'] as $gfield) {
                if (!empty($ch[$gfield]) && is_string($ch[$gfield]) && trim($ch[$gfield]) !== '') { $group = trim($ch[$gfield]); break; }
            }
        }
        if ($group === "") $group = "Unknown";

        // escape quotes in attributes
        $logo_safe = str_replace('"', "'", $logo);
        $group_safe = str_replace('"', "'", $group);
        $safe_name = str_replace(["\r","\n"], " ", trim((string)$name));
        $lines[] = '#EXTINF:-1 tvg-logo="' . $logo_safe . '" group-title="' . $group_safe . '",' . $safe_name;
        $lines[] = $base . '/getlink/' . $i;
    }
    return implode("\n", $lines);
}

/* playlist.m3u */
if ($path === 'playlist.m3u') {
    try {
        // record hit (used to trigger regeneration when many refreshes)
        record_playlist_hit();

        $session = ensure_session(PORTAL, MAC);
        $channels_obj = load_json(CHANNELS_FILE);
        $channels = [];
        if (is_array($channels_obj) && isset($channels_obj['js']['data']) && is_array($channels_obj['js']['data'])) {
            $channels = $channels_obj['js']['data'];
        }

        $need_regen = should_regen_playlist(5, 60);

        if ($need_regen || empty($channels)) {
            // fetch fresh channels and regenerate playlist text and save
            try {
                $channels = fetchChannels($session, PORTAL);
            } catch (Exception $e) {
                error_log("⚠️ Fetch channels error: " . $e->getMessage());
                try {
                    $session = performHandshake(PORTAL, MAC);
                    $channels = fetchChannels($session, PORTAL);
                } catch (Exception $e2) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "# Error fetching channels: " . $e2->getMessage();
                    exit;
                }
            }
            $playlist_text = build_playlist_text($channels);
            // write playlist file atomically
            @file_put_contents(PLAYLIST_FILE . '.tmp', $playlist_text);
            @rename(PLAYLIST_FILE . '.tmp', PLAYLIST_FILE);
            // save meta (raw hash and timestamp)
            $raw_hash = file_exists(CHANNELS_FILE) ? md5_file(CHANNELS_FILE) : null;
            save_json(PLAYLIST_META, ['raw_hash' => $raw_hash, 'generated_at' => time()]);
        }

        // Serve saved playlist file
        if (file_exists(PLAYLIST_FILE)) {
            header('Content-Type: audio/x-mpegurl; charset=utf-8');
            header('Content-Disposition: inline; filename="playlist.m3u"');
            readfile(PLAYLIST_FILE);
            exit;
        } else {
            // fallback: generate on the fly
            if (empty($channels)) $channels = fetchChannels($session, PORTAL);
            $playlist_text = build_playlist_text($channels);
            header('Content-Type: audio/x-mpegurl; charset=utf-8');
            echo $playlist_text;
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "# Error: " . $e->getMessage();
        exit;
    }
}

/* getlink handler (supports /getlink/<id> and /getlink?id=) */
if (preg_match('#^getlink(?:/(\d+))?$#', $path, $m)) {
    $chid = null;
    if (isset($m[1]) && $m[1] !== '') $chid = (int)$m[1];
    elseif (isset($_GET['id'])) $chid = (int)$_GET['id'];

    if ($chid === null) json_resp(['error' => 'Channel id required (path or ?id=)'], 400);

    try {
        $session = ensure_session(PORTAL, MAC);
        // If session.json exists, use its headers for portal requests
        $session_from_file = load_json(SESSION_FILE);
        if (is_array($session_from_file) && isset($session_from_file['headers'])) {
            // preserve session headers if needed by create_link() below
            $session['headers'] = $session_from_file['headers'];
        }

        $channels_obj = load_json(CHANNELS_FILE);
        $channels = [];
        if (is_array($channels_obj) && isset($channels_obj['js']['data']) && is_array($channels_obj['js']['data'])) $channels = $channels_obj['js']['data'];
        if (empty($channels)) $channels = fetchChannels($session, PORTAL);
        if ($chid < 0 || $chid >= count($channels)) json_resp(['error' => 'Invalid channel id'], 404);
        $ch = $channels[$chid];
        $cmd = $ch['cmd'] ?? null;
        if (!$cmd && isset($ch['cmds']) && is_array($ch['cmds']) && count($ch['cmds'])>0) {
            $first = $ch['cmds'][0];
            $cmd = $first['url'] ?? $first['command'] ?? $first['cmd'] ?? null;
        }
        if (!$cmd) json_resp(['error' => 'No cmd available for channel'], 400);
        $cmd_str = trim((string)$cmd);
        $cmd_l = strtolower($cmd_str);

        // 1) If cmd has direct http(s) URL and does NOT begin with ffrt -> direct redirect
        if (preg_match('/(https?:\/\/[^\s"\']+)/i', $cmd_str, $mm) && stripos($cmd_l, 'ffrt') !== 0) {
            header("Location: " . trim($mm[1]), true, 302); exit;
        }

        // 2) If cmd starts with ffmpeg -> strip wrapper and attempt redirect
        if (stripos($cmd_l, 'ffmpeg') === 0) {
            $stripped = trim(substr($cmd_str, strlen('ffmpeg')));
            if (preg_match('/(https?:\/\/[^\s"\']+)/i', $stripped, $m2)) {
                header("Location: " . trim($m2[1]), true, 302); exit;
            }
            if (stripos($stripped, 'http://') === 0 || stripos($stripped, 'https://') === 0) {
                header("Location: " . $stripped, true, 302); exit;
            }
        }

        // 3) If cmd starts with ffrt -> use portal create_link()
        if (stripos($cmd_l, 'ffrt') === 0) {
            $js = create_link($session, PORTAL, $cmd_str);
            if (is_array($js) && isset($js['status']) && $js['status'] === 'non-json') {
                error_log("⚠️ create_link returned non-json -> re-handshake and retry");
                try { $session = performHandshake(PORTAL, MAC); $js = create_link($session, PORTAL, $cmd_str); } catch (Exception $e) {}
            }
            if (is_array($js)) {
                if (!empty($js['direct']) && is_string($js['direct'])) { header("Location: " . trim($js['direct']), true, 302); exit; }
                if (!empty($js['cmd']) && is_string($js['cmd']) && stripos($js['cmd'],'http') === 0) { header("Location: " . trim($js['cmd']), true, 302); exit; }
                if (!empty($js['url']) && is_string($js['url']) && stripos($js['url'],'http') === 0) { header("Location: " . trim($js['url']), true, 302); exit; }
                $nested = (isset($js['js']) && is_array($js['js'])) ? $js['js'] : null;
                if ($nested) {
                    if (!empty($nested['cmd']) && is_string($nested['cmd']) && stripos($nested['cmd'],'http') === 0) { header("Location: " . trim($nested['cmd']), true, 302); exit; }
                    if (!empty($nested['url']) && is_string($nested['url']) && stripos($nested['url'],'http') === 0) { header("Location: " . trim($nested['url']), true, 302); exit; }
                }
                json_resp($js);
            }
        }

        // 4) Fallback: attempt create_link
        $js = create_link($session, PORTAL, $cmd_str);
        if (is_array($js) && isset($js['status']) && $js['status'] === 'non-json') {
            try { $session = performHandshake(PORTAL, MAC); $js = create_link($session, PORTAL, $cmd_str); } catch (Exception $e) {}
        }
        if (is_array($js)) {
            if (!empty($js['direct']) && is_string($js['direct'])) { header("Location: " . trim($js['direct']), true, 302); exit; }
            if (!empty($js['cmd']) && is_string($js['cmd']) && stripos($js['cmd'],'http') === 0) { header("Location: " . trim($js['cmd']), true, 302); exit; }
            if (!empty($js['url']) && is_string($js['url']) && stripos($js['url'],'http') === 0) { header("Location: " . trim($js['url']), true, 302); exit; }
            $nested = (isset($js['js']) && is_array($js['js'])) ? $js['js'] : null;
            if ($nested) {
                if (!empty($nested['cmd']) && is_string($nested['cmd']) && stripos($nested['cmd'],'http') === 0) { header("Location: " . trim($nested['cmd']), true, 302); exit; }
                if (!empty($nested['url']) && is_string($nested['url']) && stripos($nested['url'],'http') === 0) { header("Location: " . trim($nested['url']), true, 302); exit; }
            }
            json_resp($js);
        }

        // final fallback: return cmd
        json_resp(['cmd' => $cmd_str]);
    } catch (Exception $e) {
        json_resp(['error' => $e->getMessage()], 500);
    }
}

/* create_link manual endpoint: /create_link?cmd=... */
if ($path === 'create_link') {
    $cmd = $_GET['cmd'] ?? null;
    if ($cmd === null) json_resp(['error' => 'cmd query param required'], 400);
    try {
        $session = ensure_session(PORTAL, MAC);
        $js = create_link($session, PORTAL, $cmd);
        json_resp($js);
    } catch (Exception $e) {
        json_resp(['error' => $e->getMessage()], 500);
    }
}

/* Simple proxy to forward stream through this PHP process (optional).
   Use /proxy?u=<url-encoded-url>
   WARNING: proxying streams may be resource intensive on your server.
*/
if ($path === 'proxy' && isset($_GET['u'])) {
    $u = $_GET['u'];
    $session_from_file = load_json(SESSION_FILE);
    $headers = [];
    if (is_array($session_from_file) && isset($session_from_file['headers'])) $headers = $session_from_file['headers'];
    // make a simple GET and stream response (no range handling)
    try {
        $res = curl_get_raw($u, $headers, 30);
        foreach ($res['header'] as $hk=>$hv) {
            // pass through some safe headers
            if (in_array(strtolower($hk), ['content-type','content-length','content-range','accept-ranges'])) {
                header($hk . ': ' . $hv);
            }
        }
        echo $res['body'];
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo "Proxy error: " . $e->getMessage();
        exit;
    }
}

/* Not found fallback */
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>