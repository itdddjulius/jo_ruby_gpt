<?php
header("Content-Type: application/json; charset=utf-8");

$dataDir = __DIR__ . "/../data";

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

function json_response($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function fetch_url($url, $accept = "text/html", $timeout = 15) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => "RUBY-GPT/1.0 Mozilla/5.0",
        CURLOPT_HTTPHEADER => [
            "Accept: " . $accept,
            "Accept-Language: en-GB,en;q=0.9"
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    return [
        "body" => $body,
        "error" => $error,
        "code" => $code,
        "url" => $finalUrl
    ];
}

function clean_text($html) {
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function save_flat_file($inputChat, $answer, $engine, $model, $complexity, $sourceUrl, $provider) {
    global $dataDir;

    $id = date("Ymd_His") . "_" . bin2hex(random_bytes(4));

    $record = [
        "id" => $id,
        "created_at" => date("c"),
        "input_chat" => $inputChat,
        "gpt_engine" => $engine,
        "gpt_model" => $model,
        "complexity" => $complexity,
        "provider" => $provider,
        "source_url" => $sourceUrl,
        "answer" => $answer
    ];

    file_put_contents(
        $dataDir . "/" . $id . ".json",
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    file_put_contents(
        $dataDir . "/latest.json",
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    return $record;
}

function is_bad_url($url) {
    if (!$url) return true;

    $badParts = [
        "javascript:",
        "mailto:",
        "/preferences",
        "/settings",
        "duckduckgo.com/y.js",
        "duckduckgo.com/settings"
    ];

    foreach ($badParts as $bad) {
        if (stripos($url, $bad) !== false) return true;
    }

    return false;
}

function result_score($result, $query) {
    $score = 0;

    $title = strtolower($result["title"] ?? "");
    $snippet = strtolower($result["snippet"] ?? "");
    $url = strtolower($result["url"] ?? "");
    $queryWords = preg_split('/\s+/', strtolower($query));

    foreach ($queryWords as $word) {
        $word = trim($word);

        if (strlen($word) < 3) continue;

        if (str_contains($title, $word)) $score += 6;
        if (str_contains($snippet, $word)) $score += 4;
        if (str_contains($url, $word)) $score += 2;
    }

    if (str_contains($url, "wikipedia.org")) $score -= 2;
    if (str_contains($url, "youtube.com")) $score -= 2;
    if (str_contains($url, "pinterest.")) $score -= 4;
    if (str_contains($url, "facebook.")) $score -= 4;
    if (str_contains($url, "instagram.")) $score -= 4;

    return $score;
}

function searxng_search($query) {
    $instances = [];

    $envInstance = getenv("SEARXNG_URL");
    if ($envInstance) {
        $instances[] = rtrim($envInstance, "/");
    }

    $instances = array_merge($instances, [
        "https://search.inetol.net",
        "https://searx.be",
        "https://search.sapti.me",
        "https://opnxng.com"
    ]);

    $allResults = [];

    foreach ($instances as $instance) {
        $url = $instance . "/search?q=" . urlencode($query)
            . "&format=json"
            . "&language=en"
            . "&safesearch=0"
            . "&categories=general";

        $response = fetch_url($url, "application/json", 12);

        if (!$response["body"] || $response["code"] !== 200) {
            continue;
        }

        $json = json_decode($response["body"], true);

        if (!$json || empty($json["results"])) {
            continue;
        }

        foreach ($json["results"] as $item) {
            $resultUrl = $item["url"] ?? "";

            if (is_bad_url($resultUrl)) {
                continue;
            }

            $candidate = [
                "provider" => "SearXNG Open Source Metasearch",
                "title" => trim($item["title"] ?? "Untitled result"),
                "url" => $resultUrl,
                "snippet" => trim($item["content"] ?? ""),
                "engine" => $item["engine"] ?? "unknown",
                "score" => 0
            ];

            $candidate["score"] = result_score($candidate, $query);
            $allResults[] = $candidate;
        }

        if (count($allResults) > 0) break;
    }

    if (empty($allResults)) return null;

    usort($allResults, fn($a, $b) => $b["score"] <=> $a["score"]);

    return $allResults[0];
}

function duckduckgo_instant_answer($query) {
    $url = "https://api.duckduckgo.com/?q=" . urlencode($query)
        . "&format=json"
        . "&no_html=1"
        . "&skip_disambig=1";

    $response = fetch_url($url, "application/json", 12);

    if (!$response["body"] || $response["code"] !== 200) return null;

    $json = json_decode($response["body"], true);

    if (!$json) return null;

    $abstract = trim($json["AbstractText"] ?? "");
    $abstractUrl = trim($json["AbstractURL"] ?? "");
    $heading = trim($json["Heading"] ?? "");

    if ($abstract !== "" && $abstractUrl !== "") {
        return [
            "provider" => "DuckDuckGo Instant Answer",
            "title" => $heading ?: $query,
            "url" => $abstractUrl,
            "snippet" => $abstract,
            "score" => 0
        ];
    }

    if (!empty($json["RelatedTopics"])) {
        foreach ($json["RelatedTopics"] as $topic) {
            if (!empty($topic["Text"]) && !empty($topic["FirstURL"])) {
                return [
                    "provider" => "DuckDuckGo Instant Answer Related Topic",
                    "title" => $heading ?: $query,
                    "url" => $topic["FirstURL"],
                    "snippet" => $topic["Text"],
                    "score" => 0
                ];
            }
        }
    }

    return null;
}

function duckduckgo_html_search($query) {
    $url = "https://html.duckduckgo.com/html/?q=" . urlencode($query);
    $response = fetch_url($url, "text/html", 15);

    if (!$response["body"] || $response["code"] >= 400) return null;

    preg_match_all(
        '/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is',
        $response["body"],
        $matches,
        PREG_SET_ORDER
    );

    if (!$matches) return null;

    $results = [];

    foreach ($matches as $match) {
        $resultUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, "UTF-8");
        $title = clean_text($match[2]);

        if (str_contains($resultUrl, "uddg=")) {
            parse_str(parse_url($resultUrl, PHP_URL_QUERY) ?? "", $queryParts);
            if (!empty($queryParts["uddg"])) {
                $resultUrl = urldecode($queryParts["uddg"]);
            }
        }

        if (is_bad_url($resultUrl)) continue;

        $candidate = [
            "provider" => "DuckDuckGo HTML Search",
            "title" => $title,
            "url" => $resultUrl,
            "snippet" => "DuckDuckGo returned this open web result.",
            "score" => 0
        ];

        $candidate["score"] = result_score($candidate, $query);
        $results[] = $candidate;
    }

    if (empty($results)) return null;

    usort($results, fn($a, $b) => $b["score"] <=> $a["score"]);

    return $results[0];
}

function wikipedia_fallback($query) {
    $searchUrl =
        "https://en.wikipedia.org/w/api.php?action=query"
        . "&list=search"
        . "&srsearch=" . urlencode($query)
        . "&format=json"
        . "&utf8=1"
        . "&srlimit=5";

    $search = fetch_url($searchUrl, "application/json", 12);

    if (!$search["body"] || $search["code"] >= 400) return null;

    $json = json_decode($search["body"], true);

    if (empty($json["query"]["search"])) return null;

    $best = null;
    $bestScore = -999;

    foreach ($json["query"]["search"] as $item) {
        $title = $item["title"] ?? "";
        $snippet = clean_text($item["snippet"] ?? "");

        $candidate = [
            "provider" => "Wikipedia Fallback Search",
            "title" => $title,
            "snippet" => $snippet,
            "url" => "https://en.wikipedia.org/wiki/" . rawurlencode(str_replace(" ", "_", $title))
        ];

        $score = result_score($candidate, $query);

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    if (!$best) return null;

    $summaryUrl =
        "https://en.wikipedia.org/api/rest_v1/page/summary/"
        . rawurlencode(str_replace(" ", "_", $best["title"]));

    $summary = fetch_url($summaryUrl, "application/json", 12);
    $summaryJson = json_decode($summary["body"] ?? "", true);

    $extract = trim($summaryJson["extract"] ?? $best["snippet"]);

    if ($extract === "") return null;

    return [
        "provider" => "Wikipedia Fallback Search",
        "title" => $best["title"],
        "url" => $best["url"],
        "snippet" => $extract,
        "score" => $bestScore
    ];
}

function recursive_search($query) {
    $results = [];

    $providers = [
        "searxng_search",
        "duckduckgo_html_search",
        "duckduckgo_instant_answer",
        "wikipedia_fallback"
    ];

    foreach ($providers as $providerFunction) {
        $result = $providerFunction($query);

        if ($result && !empty($result["url"])) {
            $result["score"] = result_score($result, $query);
            $results[] = $result;
        }
    }

    if (empty($results)) return null;

    usort($results, fn($a, $b) => $b["score"] <=> $a["score"]);

    $best = $results[0];

    $combined = "RECURSIVE MULTI-PROVIDER SEARCH RESULTS\n\n";

    foreach ($results as $index => $result) {
        $combined .= ($index + 1) . ". " . $result["provider"] . "\n";
        $combined .= "Title: " . $result["title"] . "\n";
        $combined .= "URL: " . $result["url"] . "\n";
        $combined .= "Snippet: " . $result["snippet"] . "\n\n";
    }

    $best["provider"] = "Recursive Multi-Provider Search";
    $best["snippet"] = $combined;

    return $best;
}

function fetch_page_extract($url) {
    $response = fetch_url($url, "text/html", 15);

    if (!$response["body"] || $response["code"] >= 400) return "";

    $text = clean_text($response["body"]);

    if (mb_strlen($text) > 3500) {
        $text = mb_substr($text, 0, 3500) . "...";
    }

    return $text;
}

$raw = file_get_contents("php://input");
$payload = json_decode($raw, true);

if (!$payload) {
    json_response([
        "success" => false,
        "error" => "Invalid JSON payload."
    ], 400);
}

$inputChat = trim($payload["input_chat"] ?? "");
$engine = trim($payload["gpt_engine"] ?? "CHATGPT");
$model = trim($payload["gpt_model"] ?? "gpt-4o-mini");
$complexity = trim($payload["complexity"] ?? "SHALLOW");

if ($inputChat === "") {
    json_response([
        "success" => false,
        "error" => "INPUT_CHAT is required."
    ], 400);
}

$result = null;

switch ($complexity) {
    case "DEEP-DIVE":
        $result = searxng_search($inputChat);
        break;

    case "DIVE":
        $result = duckduckgo_instant_answer($inputChat);
        break;

    case "LEVEL":
        $result = duckduckgo_html_search($inputChat);
        break;

    case "SHALLOW":
        $result = wikipedia_fallback($inputChat);
        break;

    case "RECURSIVE":
        $result = recursive_search($inputChat);
        break;

    default:
        $result = searxng_search($inputChat);
        break;
}

if (!$result) {
    $answer = "ERROR:\n"
        . "No useful result was found for this selected complexity.\n\n"
        . "Selected Complexity: " . $complexity . "\n\n"
        . "Search Mapping:\n"
        . "- DEEP-DIVE = SearXNG Open Source Metasearch\n"
        . "- DIVE = DuckDuckGo Instant Answer\n"
        . "- LEVEL = DuckDuckGo HTML Search\n"
        . "- SHALLOW = Wikipedia Fallback Search\n"
        . "- RECURSIVE = Multi-provider aggregate search\n\n"
        . "Original query: " . $inputChat;

    $record = save_flat_file($inputChat, $answer, $engine, $model, $complexity, null, "NONE");

    json_response([
        "success" => false,
        "answer" => $answer,
        "record" => $record
    ], 200);
}

$pageExtract = fetch_page_extract($result["url"]);

$answer = "RUBY-GPT INTERNET SEARCH ANSWER\n\n"
    . "Query: " . $inputChat . "\n"
    . "Selected Complexity: " . $complexity . "\n"
    . "Search Provider: " . $result["provider"] . "\n"
    . "Engine Selected: " . $engine . "\n"
    . "Model Selected: " . $model . "\n\n"
    . "Title:\n"
    . $result["title"] . "\n\n"
    . "Source URL:\n"
    . $result["url"] . "\n\n"
    . "Search Snippet:\n"
    . $result["snippet"] . "\n\n";

if ($pageExtract !== "") {
    $answer .= "Extracted Page Content:\n\n" . $pageExtract;
} else {
    $answer .= "Extracted Page Content:\n\n"
        . "The result was found, but RUBY-GPT could not extract readable page content. "
        . "Use the Source URL above.";
}

$record = save_flat_file(
    $inputChat,
    $answer,
    $engine,
    $model,
    $complexity,
    $result["url"],
    $result["provider"]
);

json_response([
    "success" => true,
    "answer" => $answer,
    "source_url" => $result["url"],
    "provider" => $result["provider"],
    "complexity" => $complexity,
    "record" => $record
]);