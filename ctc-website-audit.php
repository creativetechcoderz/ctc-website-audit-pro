<?php
/**
 * Plugin Name: CTC Website Audit Pro
 * Description: High-performance, secure website audit tool by Creative Tech Coderz. Features progressive batch crawling, SSRF protection, strict TLS verification, technical SEO, on-page structure, security headers, schema validation, CSV export, and branded print/PDF reports.
 * Version: 3.8.1
 * Author: Creative Tech Coderz
 * Author URI: https://creativetechcoderz.com
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: ctc-website-audit
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CTC_WEBSITE_AUDIT_PRO_LOADED')) {
    return;
}
define('CTC_WEBSITE_AUDIT_PRO_LOADED', true);
define('CTC_AUDIT_VERSION', '3.8.1');

class CTC_Website_Audit {

    const OPT_PSI_KEY         = 'ctc_website_audit_psi_key';
    const OPT_MONITOR_ENABLED = 'ctc_website_audit_monitor_enabled';
    const OPT_MONITOR_STATE   = 'ctc_website_audit_monitor_state';
    const OPT_BRAND_NAME      = 'ctc_website_audit_brand_name';
    const OPT_BRAND_LOGO      = 'ctc_website_audit_brand_logo';
    const OPT_CONTACT_EMAIL   = 'ctc_website_audit_contact_email';
    const OPT_SCAN_HISTORY    = 'ctc_website_audit_history';
    const OPT_CHANGE_LOG      = 'ctc_website_audit_change_log';

    public function __construct() {
        add_shortcode('ctc_website_audit', [$this, 'shortcode']);

        add_action('rest_api_init', function() {
            // Progressive Batch Endpoints (Zero Gateway Timeout)
            register_rest_route('ctc-audit/v1', '/scan/init', [
                'methods'             => 'POST',
                'callback'            => [$this, 'scan_init'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'url'        => ['required' => true, 'type' => 'string'],
                    'audits'     => ['required' => false, 'type' => 'array'],
                    'scan_limit' => ['required' => false, 'type' => 'integer'],
                ],
            ]);

            register_rest_route('ctc-audit/v1', '/scan/batch', [
                'methods'             => 'POST',
                'callback'            => [$this, 'scan_batch'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'session_id' => ['required' => true, 'type' => 'string'],
                ],
            ]);

            register_rest_route('ctc-audit/v1', '/scan/finalize', [
                'methods'             => 'POST',
                'callback'            => [$this, 'scan_finalize'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'session_id' => ['required' => true, 'type' => 'string'],
                ],
            ]);

            // Legacy direct endpoint (Used by WP-Cron daily monitor)
            register_rest_route('ctc-audit/v1', '/scan', [
                'methods'             => 'POST',
                'callback'            => [$this, 'scan_legacy'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'url'        => ['required' => true, 'type' => 'string'],
                    'audits'     => ['required' => false, 'type' => 'array'],
                    'scan_limit' => ['required' => false, 'type' => 'integer'],
                ],
            ]);

            // Enterprise Large Audit Inquiries (21-500 pages)
            register_rest_route('ctc-audit/v1', '/large-audit-request', [
                'methods'             => 'POST',
                'callback'            => [$this, 'submit_large_audit_request'],
                'permission_callback' => '__return_true',
            ]);
        });

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_filter('cron_schedules', function($s) {
            $s['ctca_daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'Once daily'];
            return $s;
        });

        add_action('ctca_daily_monitor', [$this, 'scheduled_monitor']);
    }

    public static function on_activate() {
        if (!wp_next_scheduled('ctca_daily_monitor')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'ctca_daily', 'ctca_daily_monitor');
        }
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook('ctca_daily_monitor');
    }

    public function add_admin_menu() {
        add_menu_page(
            'CTC Website Audit Pro',
            'CTC Website Audit Pro',
            'manage_options',
            'ctc-website-audit',
            [$this, 'settings_page'],
            'dashicons-search',
            58
        );
    }

    public function register_settings() {
        register_setting('ctc_audit_group', self::OPT_PSI_KEY, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ctc_audit_group', self::OPT_MONITOR_ENABLED, [
            'type' => 'boolean',
            'sanitize_callback' => function($v) {
                $enabled = (bool)$v;
                if ($enabled && !wp_next_scheduled('ctca_daily_monitor')) {
                    wp_schedule_event(time() + DAY_IN_SECONDS, 'ctca_daily', 'ctca_daily_monitor');
                } elseif (!$enabled) {
                    wp_clear_scheduled_hook('ctca_daily_monitor');
                }
                return $enabled;
            },
            'default' => false
        ]);
        register_setting('ctc_audit_group', self::OPT_BRAND_NAME, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Creative Tech Coderz']);
        register_setting('ctc_audit_group', self::OPT_BRAND_LOGO, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '']);
        register_setting('ctc_audit_group', self::OPT_CONTACT_EMAIL, ['type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')]);
    }

    public function scheduled_monitor() {
        if (!get_option(self::OPT_MONITOR_ENABLED, false)) {
            return;
        }
        $url = home_url('/');
        $req = new WP_REST_Request('POST', '/ctc-audit/v1/scan');
        $req->set_param('url', $url);
        $req->set_param('audits', ['technical', 'onpage', 'ai', 'local', 'content', 'speed']);
        $req->set_param('scan_limit', 5);
        try {
            $this->scan_legacy($req);
        } catch (\Throwable $e) {
            // Keep silent in cron to avoid server hangs
        }
    }

    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $list = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $first = trim($list[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * Enterprise URL Validation & SSRF Defense.
     * Validates scheme, port, and resolves DNS to block private, loopback, and cloud metadata IPs.
     */
    public function safe_url($url) {
        $url = esc_url_raw(trim((string)$url));
        if (!$url || !wp_http_validate_url($url)) return false;
        $p = wp_parse_url($url);
        if (empty($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
        if (empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;

        if (isset($p['port'])) {
            $port = (int)$p['port'];
            if (strtolower($p['scheme']) === 'http' && $port !== 80) return false;
            if (strtolower($p['scheme']) === 'https' && $port !== 443) return false;
        }

        $host = strtolower(trim(rtrim($p['host'], '.'), '[]'));
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost') return false;

        // SSRF: Check if literal IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        } else {
            // SSRF: Resolve DNS and verify all returned A / AAAA records are public
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records) && !empty($records)) {
                foreach ($records as $rec) {
                    $ip = $rec['ip'] ?? ($rec['ipv6'] ?? '');
                    if ($ip && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return false;
                    }
                }
            } else {
                $resolved = @gethostbyname($host);
                if ($resolved && $resolved !== $host) {
                    if (!filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return false;
                    }
                }
            }
        }

        return $url;
    }

    public function page_identity($url) {
        $url = trim((string)$url);
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) return strtolower($url);

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower(rtrim($parts['host'], '.'));
        $port   = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) $port = null;

        $path = $parts['path'] ?? '/';
        if ($path === '') $path = '/';

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            foreach ($params as $k => $v) {
                $lk = strtolower((string)$k);
                if (strpos($lk, 'utm_') === 0 || in_array($lk, ['gclid', 'fbclid', 'msclkid', 'yclid', '_ga'], true)) {
                    continue;
                }
                $query[$k] = $v;
            }
            ksort($query);
        }

        $identity = $scheme . '://' . $host . ($port ? ':' . $port : '') . $path;
        if (!empty($query)) {
            $identity .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $identity;
    }

    /**
     * Outbound HTTP Request Engine.
     * Enforces sslverify => true, bounded timeouts, and public validation.
     */
    public function safe_remote_get($url, $args = []) {
        $safe = $this->safe_url($url);
        if (!$safe) return new WP_Error('unsafe_resource', 'URL failed public security and SSRF validation.');

        $timeout = max(2, min(8, (int)($args['timeout'] ?? 5)));
        $headers = (array)($args['headers'] ?? []);

        $wp_args = [
            'timeout'     => $timeout,
            'redirection' => 0,
            'user-agent'  => 'CTC-Website-Audit/' . CTC_AUDIT_VERSION . ' (+' . home_url('/') . ')',
            'headers'     => $headers,
            'sslverify'   => true, // Security: Strict TLS certificate validation enabled
        ];

        $response = wp_remote_get($safe, $wp_args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $raw_headers = wp_remote_retrieve_headers($response);
        $resp_headers = [];
        foreach ($raw_headers as $k => $v) {
            $resp_headers[strtolower((string)$k)] = is_array($v) ? implode(', ', $v) : (string)$v;
        }

        return [
            'headers'  => $resp_headers,
            'response' => ['code' => $code, 'message' => ''],
            'body'     => $body,
        ];
    }

    public function fetch_page($url) {
        $safe = $this->safe_url($url);
        if (!$safe) return new WP_Error('bad_url', 'URL is invalid or points to an internal/private address.');
        $current = $safe;

        for ($hop = 0; $hop <= 2; $hop++) {
            $current = $this->safe_url($current);
            if (!$current) return new WP_Error('unsafe_redirect', 'Redirect target was blocked by SSRF validation.');

            $r = $this->safe_remote_get($current, [
                'timeout' => 5,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            ]);
            if (is_wp_error($r)) return $r;

            $code = (int)($r['response']['code'] ?? 0);
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $loc = $r['headers']['location'] ?? '';
                if (!$loc || $hop === 2) return new WP_Error('redirect_limit', 'Too many redirects.');
                if (strpos($loc, '//') === 0) {
                    $loc = wp_parse_url($current, PHP_URL_SCHEME) . ':' . $loc;
                } elseif (strpos($loc, '/') === 0) {
                    $loc = wp_parse_url($current, PHP_URL_SCHEME) . '://' . wp_parse_url($current, PHP_URL_HOST) . $loc;
                } elseif (!wp_http_validate_url($loc)) {
                    $base   = wp_parse_url($current);
                    $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
                    if (strpos($loc, '?') === 0) {
                        $loc = ($base['path'] ?? '/') . $loc;
                    } else {
                        $loc = trailingslashit(dirname($base['path'] ?? '/')) . $loc;
                    }
                    $loc = $origin . '/' . ltrim(preg_replace('#/\./#', '/', $loc), '/');
                }
                $current = $loc;
                continue;
            }

            if ($code < 200 || $code >= 300) return new WP_Error('fetch_failed', 'Page returned HTTP ' . $code);
            $body = $r['body'] ?? '';
            $ctype = $r['headers']['content-type'] ?? '';
            if ($ctype !== '' && stripos($ctype, 'text/html') === false) {
                return new WP_Error('not_html', 'URL did not return HTML content.');
            }

            return [
                'body'      => $body,
                'code'      => $code,
                'headers'   => $r['headers'] ?? [],
                'final_url' => $current,
            ];
        }
        return new WP_Error('redirect_limit', 'Too many redirects.');
    }

    public function fetch_text_resource($url, $limit = 100000) {
        $safe = $this->safe_url($url);
        if (!$safe) return new WP_Error('unsafe_resource', 'Resource URL failed security validation.');
        $r = $this->safe_remote_get($safe, ['timeout' => 4, 'headers' => ['Accept' => 'text/plain, application/xml, text/xml, */*']]);
        if (is_wp_error($r)) return $r;
        $code = (int)($r['response']['code'] ?? 0);
        if ($code < 200 || $code >= 300) return new WP_Error('resource_http', 'Resource returned HTTP ' . $code);
        return ['body' => substr($r['body'], 0, $limit), 'code' => $code];
    }

    public function check_link_status($url) {
        $current = $this->safe_url($url);
        if (!$current) return new WP_Error('unsafe_link', 'Target URL failed security validation.');
        $r = $this->safe_remote_get($current, ['timeout' => 3, 'headers' => ['Accept' => 'text/html,*/*']]);
        if (is_wp_error($r)) return new WP_Error('link_unreachable', $r->get_error_message());
        $code = (int)($r['response']['code'] ?? 0);
        $loc  = $r['headers']['location'] ?? '';
        return [
            'status'     => $code,
            'final_url'  => $loc ? $loc : $current,
            'redirected' => in_array($code, [301, 302, 303, 307, 308], true),
        ];
    }

    public function parse_page($url, $body, $headers = []) {
        if (!class_exists('DOMDocument')) {
            return [
                'url' => $url, 'title' => '', 'description' => '', 'robots' => '',
                'h1' => 0, 'headings_count' => 0, 'skipped_headings' => 0, 'empty_headings' => 0,
                'canonical' => '', 'canonical_count' => 0, 'canonicals' => [],
                'canonical_missing' => true, 'canonical_external' => false,
                'images' => 0, 'missing_alt' => 0, 'missing_image_dims' => 0, 'legacy_image_formats' => 0,
                'text_length' => strlen(strip_tags($body)), 'word_count' => str_word_count(strip_tags($body)),
                'internal_links' => 0, 'external_links' => 0, 'links_nofollow_internal' => 0, 'external_unsafe_targets' => 0, 'empty_links' => 0,
                'viewport_present' => false, 'viewport_responsive' => false,
                'html_lang' => '', 'hreflangs' => [],
                'og_title_present' => false, 'og_description_present' => false, 'og_image_present' => false,
                'twitter_card_present' => false, 'twitter_title_present' => false, 'twitter_image_present' => false,
                'jsonld_blocks' => 0, 'jsonld_types' => [], 'jsonld_invalid' => 0,
                'breadcrumbs_present' => false, 'author_byline_present' => false,
                'question_headings' => 0, 'list_containers_count' => 0,
                'render_blocking_scripts' => 0,
                'mixed_content' => [], 'main_region_present' => false,
                'hsts_present' => !empty($headers['strict-transport-security']),
                'x_frame_options' => $headers['x-frame-options'] ?? '',
                'csp_present' => !empty($headers['content-security-policy']),
                'nosniff_present' => !empty($headers['x-content-type-options']) && stripos($headers['x-content-type-options'], 'nosniff') !== false,
                'x_robots_tag' => $headers['x-robots-tag'] ?? '',
                'header_canonical' => '',
                'links' => [], 'link_details' => [],
            ];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body);
        $xp = new DOMXPath($dom);

        $cleanText = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
        $wordCount = str_word_count(strip_tags($body));

        $title       = trim($xp->evaluate('string(//title)'));
        $description = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
        $robots      = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content)'));
        $viewport    = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="viewport"]/@content)'));
        $lang        = trim($xp->evaluate('string(//html/@lang)'));
        $ogTitle     = trim($xp->evaluate('string(//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content)'));
        $ogDesc      = trim($xp->evaluate('string(//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content)'));
        $ogImage     = trim($xp->evaluate('string(//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content)'));

        $twCard      = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:card"]/@content)'));
        $twTitle     = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:title"]/@content)'));
        $twImage     = trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content)'));

        // HTTP Header Canonicals
        $headerCanonical = '';
        if (!empty($headers['link'])) {
            if (stripos($headers['link'], 'canonical') !== false && preg_match('~<([^>]+)>~', $headers['link'], $m)) {
                $headerCanonical = trim($m[1]);
            }
        }

        $htmlCanonicalNodes = $xp->query('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " ") , " canonical ")]');
        $canonicals = [];
        if ($htmlCanonicalNodes) {
            foreach ($htmlCanonicalNodes as $cn) {
                $cv = trim($cn->getAttribute('href'));
                if ($cv !== '') $canonicals[] = $cv;
            }
        }
        if ($headerCanonical !== '') {
            $canonicals[] = $headerCanonical;
        }
        $canonicals       = array_values(array_unique($canonicals));
        $canonicalCount   = count($canonicals);
        $primaryCanonical = $canonicals[0] ?? '';
        $host             = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        $canonicalHost    = strtolower((string)wp_parse_url($primaryCanonical, PHP_URL_HOST));

        // Hreflang extraction
        $hreflangs = [];
        $hlNodes = $xp->query('//link[contains(concat(" ", normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")), " ") , " alternate ") and @hreflang]');
        if ($hlNodes) {
            foreach ($hlNodes as $hNode) {
                $hlVal = strtolower(trim($hNode->getAttribute('hreflang')));
                if ($hlVal !== '') $hreflangs[] = $hlVal;
            }
        }

        // Headings Structure & Quality
        $h1Count = (int)$xp->query('//h1')->length;
        $hNodes  = $xp->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $skippedHeadingLevels = 0;
        $emptyHeadings = 0;
        $questionHeadings = 0;
        $lastLevel = 0;

        foreach ($hNodes as $node) {
            $lvl = (int)substr(strtolower($node->tagName), 1);
            $hText = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($hText === '') {
                $emptyHeadings++;
            }
            if ($lastLevel > 0 && $lvl > ($lastLevel + 1)) {
                $skippedHeadingLevels++;
            }
            if (preg_match('/^(how|what|why|where|when|who|can|which|is|are|will)\b/i', $hText) || strpos($hText, '?') !== false) {
                $questionHeadings++;
            }
            $lastLevel = $lvl;
        }

        // Images: Alt Text, Dimensions (CLS), Formats
        $images = $xp->query('//img');
        $missingAlt  = 0;
        $missingDims = 0;
        $legacyFormats = 0;
        foreach ($images as $im) {
            if (trim($im->getAttribute('alt')) === '') $missingAlt++;
            $w = trim($im->getAttribute('width'));
            $h = trim($im->getAttribute('height'));
            if ($w === '' || $h === '') $missingDims++;
            $src = trim($im->getAttribute('src'));
            if (preg_match('/\.(png|jpg|jpeg)($|\?)/i', $src)) {
                $legacyFormats++;
            }
        }

        // Render-blocking scripts in <head>
        $rbScripts = (int)$xp->query('//head/script[@src and not(@async) and not(@defer) and not(@type="module")]')->length;

        // Structured Data JSON-LD deep check
        $jsonLdNodes = $xp->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
        $schemaTypes = [];
        $invalidJsonLd = 0;
        if ($jsonLdNodes) {
            foreach ($jsonLdNodes as $jNode) {
                $raw = trim($jNode->textContent);
                if ($raw === '') continue;
                $decoded = json_decode($raw, true);
                if ($decoded === null) {
                    $invalidJsonLd++;
                } else {
                    if (isset($decoded['@type'])) {
                        $schemaTypes[] = is_array($decoded['@type']) ? implode('/', $decoded['@type']) : (string)$decoded['@type'];
                    } elseif (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                        foreach ($decoded['@graph'] as $gItem) {
                            if (isset($gItem['@type'])) {
                                $schemaTypes[] = is_array($gItem['@type']) ? implode('/', $gItem['@type']) : (string)$gItem['@type'];
                            }
                        }
                    }
                }
            }
        }
        $schemaTypes = array_values(array_unique(array_filter($schemaTypes)));

        // Breadcrumbs and Author / E-E-A-T signals
        $hasBreadcrumbs = in_array('BreadcrumbList', $schemaTypes, true) || (int)$xp->query('//nav[contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"breadcrumb") or contains(@class,"breadcrumb")]')->length > 0;
        $hasAuthor = in_array('Person', $schemaTypes, true) || in_array('author', array_map('strtolower', $schemaTypes), true) || (int)$xp->query('//*[@rel="author" or contains(@class,"author") or contains(@itemprop,"author")]')->length > 0;
        $listContainers = (int)$xp->query('//ul | //ol | //table')->length;

        // Links & Anchors
        $base   = wp_parse_url($url);
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $links       = [];
        $linkDetails = [];
        $internal    = 0;
        $external    = 0;
        $nofollowInternal = 0;
        $externalUnsafe = 0;
        $emptyLinks = 0;

        foreach ($xp->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if (!$href || $href[0] === '#' || preg_match('/^(mailto:|tel:|javascript:|data:|sms:)/i', $href)) continue;

            $anchorText = trim($a->textContent);
            $hasChildImgAlt = false;
            foreach ($a->getElementsByTagName('img') as $imgChild) {
                if (trim($imgChild->getAttribute('alt')) !== '') {
                    $hasChildImgAlt = true;
                    break;
                }
            }
            if ($anchorText === '' && !$hasChildImgAlt && trim($a->getAttribute('aria-label')) === '') {
                $emptyLinks++;
            }

            if (strpos($href, '//') === 0) {
                $abs = $base['scheme'] . ':' . $href;
            } elseif (wp_http_validate_url($href)) {
                $abs = $href;
            } elseif (strpos($href, '/') === 0) {
                $abs = $origin . $href;
            } elseif (strpos($href, '?') === 0) {
                $abs = ($base['path'] ?? '/') . $href;
            } else {
                $abs = trailingslashit(dirname($base['path'] ?? '/')) . $href;
            }

            $clean = esc_url_raw(preg_replace('/#.*$/', '', $abs));
            if (!wp_http_validate_url($clean)) continue;

            // Filter non-HTML asset files from crawling queue
            if (preg_match('/\.(pdf|jpg|jpeg|png|gif|webp|svg|ico|zip|rar|tar|gz|exe|dmg|mp3|mp4|mov|avi|css|js|xml|json|woff|woff2|ttf|eot)(\?.*)?$/i', $clean)) {
                continue;
            }

            $isInternal = strtolower((string)wp_parse_url($clean, PHP_URL_HOST)) === $host;
            $rel = strtolower(trim($a->getAttribute('rel')));
            $target = strtolower(trim($a->getAttribute('target')));

            if ($isInternal) {
                $internal++;
                if (strpos($rel, 'nofollow') !== false) {
                    $nofollowInternal++;
                }
            } else {
                $external++;
                if ($target === '_blank' && (strpos($rel, 'noopener') === false && strpos($rel, 'noreferrer') === false)) {
                    $externalUnsafe++;
                }
            }

            if (count($linkDetails) < 40) {
                $linkDetails[] = [
                    'url'      => $clean,
                    'anchor'   => sanitize_text_field($anchorText),
                    'internal' => $isInternal,
                    'source'   => $url,
                ];
            }
            if ($isInternal && count($links) < 20) {
                $links[] = $clean;
            }
        }

        $mainRegion  = (int)$xp->query('(//main|//*[@role="main"])[1]')->length > 0;
        $viewportScale = ($viewport !== '' && stripos($viewport, 'width=device-width') !== false);

        $mixed = [];
        foreach ($xp->query('//*[@src or @href]') as $node) {
            foreach (['src', 'href'] as $attr) {
                $val = trim($node->getAttribute($attr));
                if (stripos($val, 'http://') === 0) {
                    $mixed[] = $val;
                }
            }
        }

        return [
            'url'                     => $url,
            'title'                   => $title,
            'description'             => $description,
            'robots'                  => $robots,
            'h1'                      => $h1Count,
            'headings_count'          => $hNodes->length,
            'skipped_headings'        => $skippedHeadingLevels,
            'empty_headings'          => $emptyHeadings,
            'question_headings'       => $questionHeadings,
            'canonical'               => $primaryCanonical,
            'canonical_count'         => $canonicalCount,
            'canonicals'              => $canonicals,
            'canonical_missing'       => ($canonicalCount === 0),
            'canonical_external'      => ($canonicalHost && $canonicalHost !== $host),
            'header_canonical'        => $headerCanonical,
            'images'                  => $images->length,
            'missing_alt'             => $missingAlt,
            'missing_image_dims'      => $missingDims,
            'legacy_image_formats'    => $legacyFormats,
            'render_blocking_scripts' => $rbScripts,
            'text_length'             => mb_strlen($cleanText),
            'word_count'              => $wordCount,
            'internal_links'          => $internal,
            'external_links'          => $external,
            'links_nofollow_internal' => $nofollowInternal,
            'external_unsafe_targets' => $externalUnsafe,
            'empty_links'             => $emptyLinks,
            'viewport_present'        => ($viewport !== ''),
            'viewport_responsive'     => $viewportScale,
            'html_lang'               => $lang,
            'hreflangs'               => array_values(array_unique($hreflangs)),
            'og_title_present'        => ($ogTitle !== ''),
            'og_description_present'  => ($ogDesc !== ''),
            'og_image_present'        => ($ogImage !== ''),
            'twitter_card_present'    => ($twCard !== ''),
            'twitter_title_present'   => ($twTitle !== ''),
            'twitter_image_present'   => ($twImage !== ''),
            'jsonld_blocks'           => $jsonLdNodes->length,
            'jsonld_types'            => $schemaTypes,
            'jsonld_invalid'          => $invalidJsonLd,
            'breadcrumbs_present'     => $hasBreadcrumbs,
            'author_byline_present'   => $hasAuthor,
            'list_containers_count'   => $listContainers,
            'mixed_content'           => array_values(array_unique($mixed)),
            'main_region_present'     => $mainRegion,
            'hsts_present'            => !empty($headers['strict-transport-security']),
            'x_frame_options'         => $headers['x-frame-options'] ?? '',
            'csp_present'             => !empty($headers['content-security-policy']),
            'nosniff_present'         => !empty($headers['x-content-type-options']) && stripos($headers['x-content-type-options'], 'nosniff') !== false,
            'x_robots_tag'            => $headers['x-robots-tag'] ?? '',
            'links'                   => array_values(array_unique($links)),
            'link_details'            => $linkDetails,
        ];
    }

    /* -------------------------------------------------------------------------
     * PROGRESSIVE BATCH ENGINE (ZERO GATEWAY TIMEOUT)
     * ---------------------------------------------------------------------- */

    public function scan_init($req) {
        $ip = $this->get_client_ip();
        $rateKey = 'ctca_rate_' . md5($ip);
        if (get_transient($rateKey)) {
            return new WP_Error('rate_limited', 'Please wait 30 seconds before initiating another audit.', ['status' => 429]);
        }
        set_transient($rateKey, 1, 30);

        $url = $this->safe_url((string)$req->get_param('url'));
        if (!$url) return new WP_Error('invalid_url', 'Enter a valid public HTTP or HTTPS website URL.', ['status' => 400]);

        $requestedLimit = absint($req->get_param('scan_limit'));
        if (!$requestedLimit) $requestedLimit = 5;
        $scanLimit = min(20, max(1, $requestedLimit));
        $audits = (array)$req->get_param('audits');
        if (empty($audits)) $audits = ['technical', 'onpage', 'ai', 'local', 'content', 'speed'];

        $sessionId = 'ctc_' . wp_generate_password(20, false);
        $rootIdentity = $this->page_identity($url);

        // Lightweight session state: NO raw HTML bodies stored!
        $sessionData = [
            'session_id'    => $sessionId,
            'start_url'     => $url,
            'root_host'     => strtolower((string)wp_parse_url($url, PHP_URL_HOST)),
            'scan_limit'    => $scanLimit,
            'audits'        => $audits,
            'queue'         => [$url],
            'depth_map'     => [$url => 0],
            'queued'        => [$rootIdentity => true],
            'seen'          => [],
            'scan_attempts' => 0,
            'pages'         => [],
            'created_at'    => current_time('mysql'),
        ];

        set_transient('ctc_audit_sess_' . $sessionId, $sessionData, 20 * MINUTE_IN_SECONDS);

        return rest_ensure_response([
            'session_id' => $sessionId,
            'url'        => $url,
            'scan_limit' => $scanLimit,
            'message'    => 'Scan initialized.',
        ]);
    }

    public function scan_batch($req) {
        $sessionId = sanitize_key((string)$req->get_param('session_id'));
        $session = get_transient('ctc_audit_sess_' . $sessionId);
        if (!$session || !is_array($session)) {
            return new WP_Error('session_expired', 'Your audit session expired or is invalid. Please restart.', ['status' => 404]);
        }

        $batchSize = 3; // 3 pages per roundtrip = ~3-5 seconds (Far below 30s gateway limits!)
        $processed = 0;
        $queue     = &$session['queue'];
        $seen      = &$session['seen'];
        $queued    = &$session['queued'];
        $depthMap  = &$session['depth_map'];
        $pages     = &$session['pages'];
        $rootHost  = $session['root_host'];
        $scanLimit = (int)$session['scan_limit'];

        while (!empty($queue) && $session['scan_attempts'] < $scanLimit && $processed < $batchSize) {
            $next = array_shift($queue);
            $next = $this->safe_url($next);
            if (!$next) continue;

            $identity = $this->page_identity($next);
            unset($queued[$identity]);
            if (isset($seen[$identity])) continue;
            if (strtolower((string)wp_parse_url($next, PHP_URL_HOST)) !== $rootHost) continue;

            $seen[$identity] = true;
            $session['scan_attempts']++;
            $processed++;
            $currentDepth = $depthMap[$next] ?? 0;

            $fetch = $this->fetch_page($next);
            if (is_wp_error($fetch)) {
                $pages[] = [
                    'url'         => $next,
                    'error'       => $fetch->get_error_message(),
                    'text_length' => 0,
                    'depth'       => $currentDepth,
                ];
                continue;
            }

            // Parse immediately and store ONLY lightweight metadata
            $parsed = $this->parse_page($next, $fetch['body'], $fetch['headers'] ?? []);
            $parsed['depth'] = $currentDepth;
            unset($fetch); // Free memory immediately
            $pages[] = $parsed;

            foreach ($parsed['links'] as $l) {
                $linkId = $this->page_identity($l);
                if (!isset($seen[$linkId]) && !isset($queued[$linkId]) && count($queue) < 40) {
                    $queue[]          = $l;
                    $queued[$linkId]  = true;
                    $depthMap[$l]     = $currentDepth + 1;
                }
            }
        }

        $isDone = empty($queue) || $session['scan_attempts'] >= $scanLimit;
        set_transient('ctc_audit_sess_' . $sessionId, $session, 20 * MINUTE_IN_SECONDS);

        return rest_ensure_response([
            'session_id'    => $sessionId,
            'pages_checked' => count($pages),
            'scan_attempts' => $session['scan_attempts'],
            'scan_limit'    => $scanLimit,
            'in_queue'      => count($queue),
            'is_finished'   => $isDone,
        ]);
    }

    public function scan_finalize($req) {
        $sessionId = sanitize_key((string)$req->get_param('session_id'));
        $session = get_transient('ctc_audit_sess_' . $sessionId);
        if (!$session || !is_array($session)) {
            return new WP_Error('session_expired', 'Your audit session expired. Please restart.', ['status' => 404]);
        }

        $report = $this->compile_report($session);
        delete_transient('ctc_audit_sess_' . $sessionId);
        return rest_ensure_response($report);
    }

    public function scan_legacy($req) {
        @ini_set('max_execution_time', '45');
        @set_time_limit(45);

        $url = $this->safe_url((string)$req->get_param('url'));
        if (!$url) return new WP_Error('invalid_url', 'Enter a valid public HTTP or HTTPS website URL.', ['status' => 400]);

        $scanLimit = min(10, max(1, absint($req->get_param('scan_limit') ?: 5)));
        $audits = (array)($req->get_param('audits') ?: ['technical', 'onpage', 'ai', 'local', 'content', 'speed']);
        $rootId = $this->page_identity($url);

        $session = [
            'start_url'     => $url,
            'root_host'     => strtolower((string)wp_parse_url($url, PHP_URL_HOST)),
            'scan_limit'    => $scanLimit,
            'audits'        => $audits,
            'queue'         => [$url],
            'depth_map'     => [$url => 0],
            'queued'        => [$rootId => true],
            'seen'          => [],
            'scan_attempts' => 0,
            'pages'         => [],
        ];

        while (!empty($session['queue']) && $session['scan_attempts'] < $scanLimit) {
            $next = array_shift($session['queue']);
            $next = $this->safe_url($next);
            if (!$next) continue;

            $identity = $this->page_identity($next);
            if (isset($session['seen'][$identity])) continue;
            if (strtolower((string)wp_parse_url($next, PHP_URL_HOST)) !== $session['root_host']) continue;

            $session['seen'][$identity] = true;
            $session['scan_attempts']++;
            $depth = $session['depth_map'][$next] ?? 0;

            $fetch = $this->fetch_page($next);
            if (is_wp_error($fetch)) {
                $session['pages'][] = ['url' => $next, 'error' => $fetch->get_error_message(), 'depth' => $depth];
                continue;
            }

            $parsed = $this->parse_page($next, $fetch['body'], $fetch['headers'] ?? []);
            $parsed['depth'] = $depth;
            $session['pages'][] = $parsed;

            foreach ($parsed['links'] as $l) {
                $linkId = $this->page_identity($l);
                if (!isset($session['seen'][$linkId]) && count($session['queue']) < 30) {
                    $session['queue'][] = $l;
                    $session['depth_map'][$l] = $depth + 1;
                }
            }
        }

        return rest_ensure_response($this->compile_report($session));
    }

    /* -------------------------------------------------------------------------
     * DIAGNOSTICS & REPORT COMPILER
     * ---------------------------------------------------------------------- */

    private function compile_report($session) {
        $url       = $session['start_url'];
        $pages     = $session['pages'];
        $audits    = $session['audits'];
        $scanLimit = $session['scan_limit'];
        $scanAttempts = $session['scan_attempts'];

        $issues  = [];
        $summary = ['title_issues' => 0, 'description_issues' => 0];

        $add = function($sev, $title, $detail, $page) use (&$issues) {
            $title = (string)$title; $detail = (string)$detail; $page = (string)$page; $sev = (string)$sev;
            $lowTitle = strtolower($title);

            // Canonical 6 categories
            $category = 'Technical SEO & Security';
            if (strpos($lowTitle, 'pagespeed') !== false || strpos($lowTitle, 'speed') !== false || strpos($lowTitle, 'render-blocking') !== false || strpos($lowTitle, 'lighthouse') !== false || strpos($lowTitle, 'vitals') !== false) {
                $category = 'PageSpeed & Core Web Vitals';
            } elseif (strpos($lowTitle, 'security') !== false || strpos($lowTitle, 'hsts') !== false || strpos($lowTitle, 'header') !== false || strpos($lowTitle, 'mixed-content') !== false || strpos($lowTitle, 'reverse tabnabbing') !== false || strpos($lowTitle, 'clickjacking') !== false || strpos($lowTitle, 'mime sniffing') !== false || strpos($lowTitle, 'canonical') !== false || strpos($lowTitle, 'robots') !== false || strpos($lowTitle, 'sitemap') !== false || strpos($lowTitle, 'crawl depth') !== false || strpos($lowTitle, 'broken link') !== false || strpos($lowTitle, 'link redirect') !== false || strpos($lowTitle, 'url') !== false) {
                $category = 'Technical SEO & Security';
            } elseif (strpos($lowTitle, 'meta') !== false || strpos($lowTitle, 'title') !== false || strpos($lowTitle, 'heading') !== false || strpos($lowTitle, 'open graph') !== false || strpos($lowTitle, 'image') !== false || strpos($lowTitle, 'twitter') !== false || strpos($lowTitle, 'alt') !== false) {
                $category = 'On-page SEO & Meta';
            } elseif (strpos($lowTitle, 'ai seo') !== false || strpos($lowTitle, 'structured data') !== false || strpos($lowTitle, 'author') !== false || strpos($lowTitle, 'question') !== false || strpos($lowTitle, 'json-ld') !== false || strpos($lowTitle, 'schema') !== false) {
                $category = 'AI Search Readiness';
            } elseif (strpos($lowTitle, 'local seo') !== false || strpos($lowTitle, 'local') !== false) {
                $category = 'Local SEO Signals';
            } elseif (strpos($lowTitle, 'content') !== false || strpos($lowTitle, 'word count') !== false || strpos($lowTitle, 'breadcrumb') !== false) {
                $category = 'Content Quality';
            } else {
                $category = 'Technical SEO & Security';
            }

            $recommendation = 'Review this finding and apply fixes that align with your site strategy.';
            $suggestion = '';
            if (strpos($lowTitle, 'missing page title') !== false) {
                $recommendation = 'Add a descriptive title tag under 60 characters with your primary keyword.';
                $suggestion = 'Title | Official Website';
            } elseif (strpos($lowTitle, 'meta description') !== false) {
                $recommendation = 'Craft an engaging meta description between 120 and 155 characters.';
                $suggestion = 'Discover information, services, and clear solutions on this page.';
            } elseif (strpos($lowTitle, 'hsts') !== false) {
                $recommendation = 'Enable HTTP Strict Transport Security (HSTS) on your web server.';
                $suggestion = 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload';
            } elseif (strpos($lowTitle, 'clickjacking') !== false) {
                $recommendation = 'Add X-Frame-Options or CSP frame-ancestors to prevent unauthorized embedding.';
                $suggestion = 'X-Frame-Options: SAMEORIGIN';
            } elseif (strpos($lowTitle, 'mime sniffing') !== false) {
                $recommendation = 'Add X-Content-Type-Options: nosniff header.';
                $suggestion = 'X-Content-Type-Options: nosniff';
            } elseif (strpos($lowTitle, 'reverse tabnabbing') !== false) {
                $recommendation = 'Add rel="noopener" to external links opening in new tabs.';
                $suggestion = 'rel="noopener noreferrer"';
            } elseif (strpos($lowTitle, 'broken link') !== false) {
                $recommendation = 'Update or redirect the broken URL.';
                $suggestion = 'Update href or set up a 301 redirect.';
            }

            $fingerprint = md5(strtolower($sev . '|' . $title . '|' . $detail . '|' . $page));
            foreach ($issues as $existing) {
                if (($existing['_fingerprint'] ?? '') === $fingerprint) return;
            }

            $priority = in_array(strtolower($sev), ['critical', 'high'], true) ? 'Urgent' :
                        (in_array(strtolower($sev), ['medium', 'warning'], true) ? 'Prioritize' : 'Routine');

            $issues[] = [
                'issue_id'       => 'CTC-' . strtoupper(substr(md5($category . '|' . $title . '|' . $page . '|' . $detail), 0, 8)),
                'category'       => $category,
                'severity'       => $sev,
                'priority'       => $priority,
                'effort'         => (strpos($lowTitle, 'broken link') !== false || strpos($lowTitle, 'meta') !== false || strpos($lowTitle, 'empty') !== false) ? 'Quick fix' : 'Moderate effort',
                'title'          => $title,
                'detail'         => $detail,
                'recommendation' => $recommendation,
                'suggested_fix'  => $suggestion,
                'page'           => $page,
                '_fingerprint'   => $fingerprint,
            ];
        };

        // Evaluate Page Diagnostics
        foreach ($pages as $p) {
            $next = $p['url'];
            if (!empty($p['error'])) {
                $add('Warning', 'Page could not be checked', $p['error'], $next);
                continue;
            }

            $currentDepth = $p['depth'] ?? 0;
            $urlPath = (string)wp_parse_url($next, PHP_URL_PATH);
            if (strlen($next) > 100) {
                $add('Review', 'Long URL length (' . strlen($next) . ' chars)', 'URLs longer than 100 characters can be difficult to read and share.', $next);
            }
            if (preg_match('/[A-Z]/', $urlPath)) {
                $add('Review', 'Uppercase characters in URL path', 'URLs with uppercase letters can create duplicate content issues on case-sensitive servers. Keep URLs lowercase.', $next);
            }
            if (!empty(wp_parse_url($next, PHP_URL_QUERY))) {
                $qStr = strtolower((string)wp_parse_url($next, PHP_URL_QUERY));
                if (preg_match('/(session|phpsessid|sid|sort|filter|ref|order)=/i', $qStr)) {
                    $add('Review', 'Dynamic query parameter detected', 'URL contains session or sorting query parameters. Ensure these are canonicalized to avoid duplicate indexing.', $next);
                }
            }

            if ($currentDepth >= 3) {
                $add('Review', 'Deep crawl depth (Depth ' . $currentDepth . ')', 'This page takes 3 or more clicks to reach from the start page. Bring key conversion pages closer to the homepage.', $next);
            }

            // Security & Headers
            if (in_array('technical', $audits, true)) {
                if (stripos($next, 'https://') === 0 && empty($p['hsts_present'])) {
                    $add('Medium', 'HSTS security header missing', 'Strict-Transport-Security header was not detected.', $next);
                }
                if (empty($p['x_frame_options']) && empty($p['csp_present'])) {
                    $add('Low', 'Clickjacking defense header missing', 'Neither X-Frame-Options nor CSP frame-ancestors was detected.', $next);
                }
                if (empty($p['nosniff_present'])) {
                    $add('Low', 'MIME sniffing header missing', 'X-Content-Type-Options: nosniff header was not detected.', $next);
                }
                if ($p['external_unsafe_targets'] > 0) {
                    $add('Low', 'External links missing rel="noopener"', $p['external_unsafe_targets'] . ' external target="_blank" link(s) lack rel="noopener". This protects against reverse tabnabbing.', $next);
                }
                if ($p['links_nofollow_internal'] > 0) {
                    $add('Review', 'Internal links marked nofollow', $p['links_nofollow_internal'] . ' internal link(s) use rel="nofollow", which blocks PageRank distribution across your own site.', $next);
                }
                if ($p['empty_links'] > 0) {
                    $add('Review', 'Empty or uninformative links detected', $p['empty_links'] . ' link(s) contain no text, image alt, or aria-label.', $next);
                }
            }

            // Indexability Directives
            if (!empty($p['x_robots_tag']) && stripos($p['x_robots_tag'], 'noindex') !== false) {
                $add('High', 'X-Robots-Tag HTTP header noindex detected', 'The server returned an HTTP response header blocking indexing: X-Robots-Tag: ' . $p['x_robots_tag'], $next);
            }
            if (stripos($p['robots'], 'noindex') !== false && !empty($p['canonical'])) {
                $add('High', 'Conflicting indexing directives (noindex + canonical)', 'Page declares noindex while also declaring a canonical tag. Remove noindex or verify intent.', $next);
            }

            // On-Page Checks
            if (in_array('onpage', $audits, true)) {
                if (!$p['title']) {
                    $summary['title_issues']++;
                    $add('High', 'Missing page title', 'The page does not declare a title tag.', $next);
                } elseif (mb_strlen($p['title']) > 60) {
                    $add('Review', 'Long page title (' . mb_strlen($p['title']) . ' chars)', 'Titles over 60 characters are often truncated in SERPs.', $next);
                }

                if (!$p['description']) {
                    $summary['description_issues']++;
                    $add('Medium', 'Missing meta description', 'Add a unique description to improve click-through rate.', $next);
                } elseif (mb_strlen($p['description']) > 160) {
                    $add('Review', 'Meta description too long (' . mb_strlen($p['description']) . ' chars)', 'Descriptions over 160 chars are often truncated.', $next);
                }

                if ($p['h1'] === 0) {
                    $add('Medium', 'No H1 heading detected', 'Add a primary H1 heading to identify the page topic.', $next);
                } elseif ($p['h1'] > 1) {
                    $add('Review', 'Multiple H1 headings (' . $p['h1'] . ')', 'Verify that having multiple H1s matches your outline.', $next);
                }

                if (!empty($p['skipped_headings']) && $p['skipped_headings'] > 0) {
                    $add('Review', 'Skipped heading hierarchy', 'Detected ' . $p['skipped_headings'] . ' skipped heading levels (e.g., H1 jumping to H3).', $next);
                }
                if (!empty($p['empty_headings']) && $p['empty_headings'] > 0) {
                    $add('Review', 'Empty heading tags detected', 'Found ' . $p['empty_headings'] . ' heading tag(s) with no text content. Clean up empty heading tags in page builders.', $next);
                }

                if ($p['missing_alt'] > 0) {
                    $add('Medium', 'Images missing alt text', $p['missing_alt'] . ' image(s) have empty or missing alt attributes.', $next);
                }
                if ($p['missing_image_dims'] > 0) {
                    $add('Low', 'Images missing width/height attributes', $p['missing_image_dims'] . ' image(s) lack explicit dimensions, which can increase layout shifts (CLS).', $next);
                }
                if ($p['legacy_image_formats'] > 3) {
                    $add('Info', 'Legacy image formats detected', $p['legacy_image_formats'] . ' image(s) use PNG/JPG. Consider converting to WebP or AVIF for faster load times.', $next);
                }

                if (empty($p['og_title_present']) || empty($p['og_image_present'])) {
                    $add('Low', 'Open Graph social preview tags incomplete', 'Page is missing og:title or og:image tags for social sharing previews.', $next);
                }
                if (empty($p['twitter_card_present'])) {
                    $add('Low', 'Twitter Card metadata missing', 'Add twitter:card, twitter:title, and twitter:image for rich snippets on X / Twitter.', $next);
                }

                // Generic anchor text check
                if (!empty($p['link_details'])) {
                    $generic = [];
                    foreach ($p['link_details'] as $ld) {
                        $anc = mb_strtolower(trim($ld['anchor'] ?? ''));
                        if (in_array($anc, ['click here', 'read more', 'learn more', 'here', 'link', 'more'], true)) {
                            $generic[] = $anc;
                        }
                    }
                    if (count($generic) >= 2) {
                        $add('Review', 'Generic link anchor text detected', 'Found ' . count($generic) . ' generic anchor(s) like "' . implode('", "', array_slice(array_unique($generic), 0, 3)) . '". Descriptive anchor text improves SEO and accessibility.', $next);
                    }
                }
            }

            // Technical SEO
            if (in_array('technical', $audits, true)) {
                if ($p['canonical_missing']) {
                    $add('Review', 'Canonical link not detected', 'No canonical link tag was found.', $next);
                }
                if ($p['canonical_external']) {
                    $add('High', 'Canonical points to an external domain', 'Canonical href points to: ' . $p['canonical'], $next);
                }
                if (!empty($p['header_canonical'])) {
                    $add('Info', 'HTTP Link Header Canonical detected', 'Canonical declared via HTTP response header: ' . $p['header_canonical'], $next);
                }
                if (!empty($p['mixed_content'])) {
                    $add('High', 'Mixed-content references detected', 'Detected ' . count($p['mixed_content']) . ' insecure HTTP resource(s) on HTTPS.', $next);
                }
                if (!$p['viewport_responsive']) {
                    $add('Review', 'Responsive viewport not detected', 'Page does not declare standard mobile viewport width=device-width.', $next);
                }
            }

            // Content Quality
            if (in_array('content', $audits, true)) {
                if ($p['word_count'] < 200) {
                    $add('Review', 'Thin content / low word count (' . $p['word_count'] . ' words)', 'The page contains fewer than 200 words of body text. Ensure primary search intent is well covered.', $next);
                }
                if (!$p['breadcrumbs_present']) {
                    $add('Review', 'Breadcrumb navigation missing', 'No breadcrumb schema or navigation found. Breadcrumbs help bots and users understand site hierarchy.', $next);
                }
            }

            // AI Search Readiness (GEO / AEO)
            if (in_array('ai', $audits, true)) {
                if ($p['jsonld_invalid'] > 0) {
                    $add('High', 'Malformed JSON-LD structured data syntax', 'Detected ' . $p['jsonld_invalid'] . ' JSON-LD block(s) with invalid JSON syntax.', $next);
                } elseif (empty($p['jsonld_blocks'])) {
                    $add('Review', 'Structured data (JSON-LD) missing', 'No schema JSON-LD blocks found. Machine-readable entities help AI search engines cite your content.', $next);
                } else {
                    $add('Info', 'Structured Data Entities: ' . implode(', ', array_slice($p['jsonld_types'], 0, 4)), 'Page declares valid Schema.org entities.', $next);
                }

                if ($p['question_headings'] === 0) {
                    $add('Info', 'No question-led headings found (AI Search hint)', 'AI search engines (Perplexity, ChatGPT, AI Overviews) favor FAQ/question-led headers followed by direct answers.', $next);
                }
                if (!$p['author_byline_present']) {
                    $add('Info', 'Author byline / E-E-A-T attribution missing', 'No author byline or Person schema detected. Author attribution strengthens E-E-A-T credibility.', $next);
                }
            }

            // Performance: Head scripts
            if (in_array('speed', $audits, true)) {
                if ($p['render_blocking_scripts'] > 2) {
                    $add('Medium', 'Render-blocking scripts in <head>', 'Found ' . $p['render_blocking_scripts'] . ' script(s) in <head> without defer or async attributes.', $next);
                }
            }
        }

        // Bounded Broken Link Checking (Sample max 6 links)
        if (in_array('technical', $audits, true)) {
            $destinations = [];
            foreach ($pages as $pg) {
                foreach (($pg['link_details'] ?? []) as $ld) {
                    $dest = $ld['url'];
                    if (!isset($destinations[$dest])) $destinations[$dest] = $ld;
                }
            }

            $checked = 0;
            foreach ($destinations as $dest => $ld) {
                if ($checked >= 6) break;
                $checked++;
                $st = $this->check_link_status($dest);
                if (is_wp_error($st)) continue;
                $code = (int)($st['status'] ?? 0);
                if ($code === 404 || $code === 410) {
                    $add('Medium', 'Broken link detected (HTTP ' . $code . ')', 'Destination returned HTTP ' . $code . ': ' . $dest, $ld['source'] ?? $url);
                } elseif (!empty($st['redirected'])) {
                    $add('Review', 'Link redirects', 'Destination redirects to: ' . $st['final_url'], $ld['source'] ?? $url);
                }
            }
        }

        // Robots.txt & Sitemap
        if (in_array('technical', $audits, true)) {
            $origin = wp_parse_url($url, PHP_URL_SCHEME) . '://' . wp_parse_url($url, PHP_URL_HOST);
            $robots = $this->fetch_text_resource($origin . '/robots.txt', 50000);
            if (is_wp_error($robots)) {
                $add('Review', 'robots.txt could not be fetched', $robots->get_error_message(), $origin . '/robots.txt');
            } else {
                $sitemapFound = false;
                if (preg_match('/^\s*Sitemap:\s*(https?:\/\/\S+)/im', $robots['body'], $sm)) {
                    $sitemapFound = true;
                    $add('Info', 'XML Sitemap listed in robots.txt', 'Detected: ' . trim($sm[1]), $origin . '/robots.txt');
                }
                if (!$sitemapFound) {
                    $common = [$origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml'];
                    foreach ($common as $candidate) {
                        $test = $this->fetch_text_resource($candidate, 30000);
                        if (!is_wp_error($test) && (stripos($test['body'], '<urlset') !== false || stripos($test['body'], '<sitemapindex') !== false)) {
                            $sitemapFound = true;
                            $add('Info', 'XML Sitemap detected', 'Detected: ' . $candidate, $candidate);
                            break;
                        }
                    }
                }
                if (!$sitemapFound) {
                    $add('Medium', 'No XML Sitemap detected', 'No sitemap found in robots.txt or standard paths.', $url);
                }
            }
        }

        // Google PageSpeed API (if key is set)
        $psiKey = get_option(self::OPT_PSI_KEY, '');
        if (in_array('speed', $audits, true) && !empty($psiKey)) {
            $api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode($url) . '&strategy=mobile&category=performance&category=seo&key=' . rawurlencode($psiKey);
            $pr = $this->safe_remote_get($api, ['timeout' => 6, 'headers' => ['Accept' => 'application/json']]);
            if (!is_wp_error($pr) && ($pr['response']['code'] ?? 0) === 200) {
                $j = json_decode($pr['body'], true);
                $perf = $j['lighthouseResult']['categories']['performance']['score'] ?? null;
                $auds = $j['lighthouseResult']['audits'] ?? [];
                $lcp  = $auds['largest-contentful-paint']['displayValue'] ?? 'N/A';
                $cls  = $auds['cumulative-layout-shift']['displayValue'] ?? 'N/A';
                $add('Info', 'Google PageSpeed Insights (Mobile)', 'Performance: ' . ($perf !== null ? round($perf * 100) : 'N/A') . '/100. Core Web Vitals: LCP: ' . $lcp . ', CLS: ' . $cls, $url);
            }
        }

        // Calculate Scores
        $weights = ['critical' => 10, 'high' => 5, 'medium' => 2.5, 'low' => 0.5, 'warning' => 1.0, 'review' => 1.0, 'info' => 0];
        $severityCounts = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'Review' => 0, 'Warning' => 0, 'Info' => 0];
        $categoryCounts = [];
        $rawDeductions = 0;
        $categoryDeductions = [];
        $deduped = [];
        $seenSig = [];

        foreach ($issues as $issue) {
            $sev = $issue['severity'] ?? 'Info';
            $cat = $issue['category'] ?? 'General';
            $sig = md5(strtolower($cat . '|' . $issue['title'] . '|' . ($issue['page'] ?? '')));
            if (isset($seenSig[$sig])) continue;
            $seenSig[$sig] = true;
            unset($issue['_fingerprint']);
            $deduped[] = $issue;

            $severityCounts[$sev] = ($severityCounts[$sev] ?? 0) + 1;
            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
            $w = $weights[strtolower($sev)] ?? 0;
            $rawDeductions += $w;
            $categoryDeductions[$cat] = ($categoryDeductions[$cat] ?? 0) + $w;
        }

        $pageCount = max(1, count($pages));
        $deductions = min(100, $rawDeductions / $pageCount);
        $finalScore = (int)round(max(0, 100 - $deductions));

        $categoryScores = [];
        $allCategories = ['Technical SEO & Security', 'On-page SEO & Meta', 'Content Quality', 'AI Search Readiness', 'Local SEO Signals', 'PageSpeed & Core Web Vitals'];
        foreach ($allCategories as $cName) {
            $cPen = min(100, ($categoryDeductions[$cName] ?? 0) / $pageCount);
            $categoryScores[$cName] = round(max(0, 100 - $cPen), 1);
        }

        $report = [
            'url'             => $url,
            'pages_checked'   => count($pages),
            'pages_attempted' => $scanAttempts,
            'scan_limit'      => $scanLimit,
            'pages'           => $pages,
            'issues'          => $deduped,
            'score'           => $finalScore,
            'brand'           => [
                'name'  => get_option(self::OPT_BRAND_NAME, 'Creative Tech Coderz'),
                'logo'  => get_option(self::OPT_BRAND_LOGO, 'https://creativetechcoderz.com/wp-content/uploads/2026/10/cropped-creative-tech-coderz-logo-transparent-2.png'),
                'email' => get_option(self::OPT_CONTACT_EMAIL, get_option('admin_email')),
            ],
            'summary'         => array_merge($summary, [
                'by_severity'     => $severityCounts,
                'by_category'     => $categoryCounts,
                'category_scores' => $categoryScores,
                'score_deductions'=> round($deductions, 2),
            ]),
            'generated_at'    => current_time('c'),
        ];

        // Save History (Max 15 items, lightweight)
        $history = get_option(self::OPT_SCAN_HISTORY, []);
        if (!is_array($history)) $history = [];
        array_unshift($history, [
            'date'   => current_time('mysql'),
            'url'    => $url,
            'pages'  => count($pages),
            'score'  => $finalScore,
            'issues' => count($deduped),
        ]);
        update_option(self::OPT_SCAN_HISTORY, array_slice($history, 0, 15), false);

        return $report;
    }

    public function submit_large_audit_request($req) {
        $name     = sanitize_text_field((string)$req->get_param('name'));
        $email    = sanitize_email((string)$req->get_param('email'));
        $url      = esc_url_raw(trim((string)$req->get_param('url')));
        $honeypot = trim((string)$req->get_param('company'));

        if ($honeypot !== '') {
            return rest_ensure_response(['submitted' => true, 'message' => 'Thank you. Your request has been received.']);
        }
        if ($name === '' || !is_email($email) || !$url || !wp_http_validate_url($url)) {
            return new WP_Error('invalid_request', 'Please provide a valid name, email address, and public website URL.', ['status' => 400]);
        }

        $to      = get_option(self::OPT_CONTACT_EMAIL, get_option('admin_email'));
        $subject = 'New Audit Request (21–500 pages): ' . $url;
        $message = "New large website audit request received:\n\nName: {$name}\nEmail: {$email}\nWebsite: {$url}\nSubmitted: " . current_time('mysql');
        $headers = ['Reply-To: ' . $name . ' <' . $email . '>'];

        wp_mail($to, $subject, $message, $headers);
        wp_mail($email, 'Audit Request Received - ' . get_option(self::OPT_BRAND_NAME, 'Creative Tech Coderz'), "Hello {$name},\n\nWe have received your audit request for {$url}. Our team will review and follow up shortly.\n\nBest regards,\n" . get_option(self::OPT_BRAND_NAME, 'Creative Tech Coderz'));

        return rest_ensure_response(['submitted' => true, 'message' => 'Your request was received successfully! We will follow up via email.']);
    }

    public function shortcode() {
        $initEndpoint     = esc_url(rest_url('ctc-audit/v1/scan/init'));
        $batchEndpoint    = esc_url(rest_url('ctc-audit/v1/scan/batch'));
        $finalizeEndpoint = esc_url(rest_url('ctc-audit/v1/scan/finalize'));
        $largeEndpoint    = esc_url(rest_url('ctc-audit/v1/large-audit-request'));
        $restNonce        = wp_create_nonce('wp_rest');

        ob_start(); ?>
        <style>
        .ctca { max-width: 1100px; margin: 30px auto; color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .ctca-hero { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); color: #fff; border-radius: 18px; padding: clamp(24px, 4vw, 42px); margin-bottom: 24px; box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12); }
        .ctca-hero h2 { color: #fff; font-size: clamp(24px, 3.5vw, 36px); line-height: 1.25; margin: 0 0 10px; font-weight: 800; }
        .ctca-hero p { color: #cbd5e1; max-width: 720px; font-size: 15px; margin: 0; line-height: 1.6; }
        .ctca-form, .ctca-results { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: clamp(20px, 3.5vw, 36px); box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .ctca label { font-weight: 600; display: block; margin-bottom: 7px; color: #1e293b; font-size: 14px; }
        .ctca input[type=url], .ctca select, .ctca input[type=text], .ctca input[type=email] { width: 100%; box-sizing: border-box; padding: 13px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 15px; color: #0f172a; background: #fff; transition: border-color .15s, box-shadow .15s; }
        .ctca input[type=url]:focus, .ctca select:focus, .ctca input[type=text]:focus, .ctca input[type=email]:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .ctca-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 18px 0; }
        .ctca-option { border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; display: flex; gap: 10px; align-items: center; background: #f8fafc; cursor: pointer; font-size: 14px; font-weight: 500; transition: border-color .15s, background .15s; }
        .ctca-option:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .ctca-option input[type=checkbox] { width: 17px; height: 17px; accent-color: #2563eb; cursor: pointer; }
        .ctca button.ctca-btn { background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 13px 26px; font-weight: 700; font-size: 15px; cursor: pointer; transition: background .15s, transform .1s; display: inline-flex; align-items: center; gap: 8px; }
        .ctca button.ctca-btn:hover { background: #1d4ed8; }
        .ctca button.ctca-btn:disabled { opacity: .65; cursor: wait; }
        .ctca .ctca-results > div[style*="border-top"] {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
            gap: 10px;
        }
        .ctca .ctca-results > div[style*="border-top"] .ctca-btn {
            box-sizing: border-box;
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        @media (max-width: 600px) {
            .ctca .ctca-results > div[style*="border-top"] {
                flex-direction: column;
                align-items: stretch;
            }
            .ctca .ctca-results > div[style*="border-top"] .ctca-btn {
                width: 100%;
                min-width: 0;
                margin: 0;
                text-align: center;
            }
        }
        .ctca-muted { color: #64748b; font-size: 13px; line-height: 1.5; margin: 8px 0; }
        
        /* Progress Bar */
        .ctca-progress-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 14px; padding: 20px; margin-top: 18px; }
        .ctca-progress-bar { height: 10px; background: #e2e8f0; border-radius: 6px; overflow: hidden; margin-top: 10px; }
        .ctca-progress-fill { height: 100%; width: 0%; background: #2563eb; transition: width 0.3s ease; }

        /* Modern Result Cards */
        .ctca-result-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin: 18px 0; }
        .ctca-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03); }
        .ctca-card strong { display: block; font-size: 26px; margin: 6px 0 2px; color: #0f172a; font-weight: 800; }
        
        /* Interactive Category Cards / Pills */
        .ctca-cat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin: 18px 0; }
        .ctca-cat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; cursor: pointer; text-align: left; transition: all .18s ease; outline: none; position: relative; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03); }
        .ctca-cat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08); border-color: #cbd5e1; }
        .ctca-cat-card.active { border-color: #2563eb !important; background: #f8fafc !important; box-shadow: 0 0 0 2px #2563eb, 0 8px 20px rgba(37, 99, 235, 0.12) !important; }
        .ctca-cat-card .ctca-cat-name { font-size: 13px; font-weight: 700; color: #475569; display: block; }
        .ctca-cat-card strong { display: block; font-size: 22px; margin: 6px 0 3px; color: #0f172a; font-weight: 800; }
        .ctca-cat-card .ctca-cat-sub { font-size: 12px; color: #64748b; display: block; }
        .ctca-cat-badge { position: absolute; top: 14px; right: 14px; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #e2e8f0; color: #334155; }
        .ctca-cat-card.active .ctca-cat-badge { background: #2563eb; color: #fff; }

        /* Finding Item Cards */
        .ctca-issue { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin: 14px 0; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04); transition: border-color .15s; }
        .ctca-issue:hover { border-color: #cbd5e1; }
        .ctca-badge { display: inline-block; border-radius: 6px; padding: 4px 10px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; margin-right: 8px; }
        .ctca-issue[data-severity="Critical"] .ctca-badge, .ctca-issue[data-severity="High"] .ctca-badge { background: #fee2e2; color: #b91c1c; }
        .ctca-issue[data-severity="Medium"] .ctca-badge, .ctca-issue[data-severity="Warning"] .ctca-badge { background: #fef3c7; color: #b45309; }
        .ctca-issue[data-severity="Low"] .ctca-badge, .ctca-issue[data-severity="Info"] .ctca-badge { background: #dcfce7; color: #15803d; }
        .ctca-issue[data-severity="Review"] .ctca-badge { background: #e0f2fe; color: #0369a1; }
        
        .ctca-recommendation-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 16px; margin: 12px 0 8px; font-size: 14px; color: #166534; line-height: 1.5; }
        .ctca-suggestion-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin: 10px 0 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; color: #0f172a; position: relative; overflow-x: auto; }
        .ctca-copy-btn { float: right; background: #334155; color: #fff; border: 0; border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; cursor: pointer; margin-left: 8px; }
        .ctca-copy-btn:hover { background: #1e293b; }
        
        /* Print & PDF */
        .ctca-print-brand { display: none; }
        @media print {
            body * { visibility: hidden; }
            .ctca-results, .ctca-results * { visibility: visible; }
            .ctca-results { position: absolute; left: 0; top: 0; width: 100%; border: 0; box-shadow: none; padding: 0; }
            .ctca-hero, .ctca-form, .ctca-filters, button, .ctca-cat-grid { display: none !important; }
            .ctca-print-brand { display: flex !important; align-items: center; gap: 16px; border-bottom: 2px solid #2563eb; padding-bottom: 12px; margin-bottom: 16px; }
            .ctca-print-brand img { max-height: 48px; }
            .ctca-issue { break-inside: avoid; page-break-inside: avoid; }
        }
        </style>

        <div class="ctca">
            <section class="ctca-hero">
                <p style="color:#60a5fa;font-weight:700;text-transform:uppercase;margin:0 0 6px;font-size:12px;letter-spacing:0.05em;">Professional SEO & Technical Health Checker</p>
                <h2>Find the issues holding your website back.</h2>
                <p>Scan key pages for technical SEO, security headers, on-page structure, image optimization, broken links, AI search readiness, and mobile responsiveness.</p>
            </section>

            <form class="ctca-form" id="ctca-form">
                <label for="ctca-url">Target Website URL</label>
                <input id="ctca-url" type="url" placeholder="https://example.com" required>
                <p class="ctca-muted">Enter any public HTTP or HTTPS website URL you wish to audit.</p>

                <label for="ctca-scan-limit" style="margin-top:16px;">Pages to audit</label>
                <select id="ctca-scan-limit">
                    <option value="1">Single page — 1 page</option>
                    <option value="5" selected>Fast scan — 5 pages</option>
