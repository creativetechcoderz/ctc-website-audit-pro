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
