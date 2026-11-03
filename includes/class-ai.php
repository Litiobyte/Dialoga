<?php
/**
 * AI integration for DIALOGA
 * Handles AI chat, website crawling, and knowledge base management
 *
 * Retrieval strategy:
 *   - At crawl time: generate an embedding vector for each page and store it
 *   - At query time: embed the user's question, rank chunks by cosine similarity,
 *     send only the top N relevant chunks — not the entire knowledge base
 *   - Fallback: keyword overlap scoring when no embedding key is available
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dialoga_AI {

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    public static function init() {
        // Widget-facing (public)
        add_action('wp_ajax_dialoga_ai_chat',        array(__CLASS__, 'ajax_ai_chat'));
        add_action('wp_ajax_nopriv_dialoga_ai_chat', array(__CLASS__, 'ajax_ai_chat'));

        // Admin-only
        add_action('wp_ajax_dialoga_save_ai_settings',      array(__CLASS__, 'ajax_save_ai_settings'));
        add_action('wp_ajax_dialoga_get_ai_settings',       array(__CLASS__, 'ajax_get_ai_settings'));
        add_action('wp_ajax_dialoga_crawl_website',         array(__CLASS__, 'ajax_crawl_website'));
        add_action('wp_ajax_dialoga_generate_embeddings',   array(__CLASS__, 'ajax_generate_embeddings'));
        add_action('wp_ajax_dialoga_generate_flow',         array(__CLASS__, 'ajax_generate_flow'));
        add_action('wp_ajax_dialoga_get_ai_knowledge',      array(__CLASS__, 'ajax_get_ai_knowledge'));
        add_action('wp_ajax_dialoga_delete_knowledge_item', array(__CLASS__, 'ajax_delete_knowledge_item'));
        add_action('wp_ajax_dialoga_test_ai',               array(__CLASS__, 'ajax_test_ai'));
    }

    // -------------------------------------------------------------------------
    // Widget-facing: handle AI chat request
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Bot protection constants (configurable via filter)
    // -------------------------------------------------------------------------
    // v1.0.2 — these are abuse ceilings, not feature tiers. They exist to stop
    // runaway loops and scripted hammering from burning through the site
    // owner's own API credit, and are set high enough that a real visitor
    // having a real conversation will never reach them.
    const AI_MSG_LIMIT       = 50;   // Max AI replies in one visitor session
    const AI_IP_LIMIT        = 100;  // Max AI calls per IP per hour
    const AI_MIN_INTERVAL_MS = 800;  // Min ms between messages (bots are instant)

    public static function ajax_ai_chat() {
        check_ajax_referer('dialoga_widget', 'nonce');

        $user_message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        // v1.0.0 — history is a JSON string; unslash + decode + recursively
        // sanitize each string value before passing along.
        $history_raw = isset($_POST['history']) ? sanitize_textarea_field(wp_unslash($_POST['history'])) : '[]';
        $session_id  = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $flow_id     = isset($_POST['flow_id']) ? absint($_POST['flow_id']) : 0;
        $node_id     = isset($_POST['node_id']) ? sanitize_text_field(wp_unslash($_POST['node_id'])) : '';
        $session_token = isset($_POST['session_token']) ? sanitize_text_field(wp_unslash($_POST['session_token'])) : '';
        $honeypot    = isset($_POST['_h']) ? sanitize_text_field(wp_unslash($_POST['_h'])) : null;

        if (empty($user_message)) {
            wp_send_json_error('Empty message');
            return;
        }

        // --- Bot protection: honeypot field must be empty ---
        if (!empty($honeypot)) {
            wp_send_json_error('Invalid request.');
            return;
        }

        if (!class_exists('Dialoga_Frontend')
            || !Dialoga_Frontend::verify_widget_session($session_id, $session_token, $flow_id)) {
            wp_send_json_error('Invalid session.');
            return;
        }

        // Resolve the AI node config from the stored flow. The client used to
        // send `node_config` and the server trusted it, which let any visitor
        // rewrite the system prompt and use the site's API key as a free LLM.
        $node_config = self::resolve_node_config($flow_id, $node_id);
        if (null === $node_config) {
            wp_send_json_error('Invalid chat context.');
            return;
        }
        $node_config = dialoga_sanitize_json($node_config);
        if (!is_array($node_config)) {
            $node_config = array();
        }

        // --- Bot protection: IP rate limiting ---
        $ip          = self::get_client_ip();
        $ip_key      = 'dialoga_ai_ip_' . md5($ip);
        $ip_count    = (int) get_transient($ip_key);
        if ($ip_count >= apply_filters('dialoga_ai_ip_limit', self::AI_IP_LIMIT)) {
            wp_send_json_error(array('limit' => true, 'message' => 'Too many requests. Please try again later.'));
            return;
        }
        set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);

        // --- Bot protection: minimum timing between messages ---
        if (!empty($session_id)) {
            $timing_key  = 'dialoga_ai_ts_' . sanitize_key($session_id);
            $last_ts     = (int) get_transient($timing_key);
            $now_ms      = (int) (microtime(true) * 1000);
            $min_ms      = apply_filters('dialoga_ai_min_interval_ms', self::AI_MIN_INTERVAL_MS);
            if ($last_ts && ($now_ms - $last_ts) < $min_ms) {
                wp_send_json_error(array('limit' => true, 'message' => 'Please slow down.'));
                return;
            }
            set_transient($timing_key, $now_ms, 60); // 60s window
        }

        // --- Message limit: max AI replies per session ---
        // Counted server-side. The old implementation counted assistant turns
        // in client-supplied history, so a visitor could send an empty history
        // and bypass the ceiling, spending the site owner's API credit.
        $history    = dialoga_sanitize_json(json_decode($history_raw, true) ?: array());
        $msg_limit  = (int) apply_filters('dialoga_ai_message_limit', self::AI_MSG_LIMIT);
        $turn_key   = 'dialoga_ai_turns_' . md5(($session_id !== '' ? $session_id : $ip) . '|' . $flow_id);
        $ai_turns   = (int) get_transient($turn_key);
        if ($ai_turns >= $msg_limit) {
            wp_send_json_error(array('limit' => true, 'message' => 'limit_reached'));
            return;
        }
        // Reserve this turn before calling the provider so parallel requests
        // cannot race past the ceiling.
        set_transient($turn_key, $ai_turns + 1, DAY_IN_SECONDS);

        $settings = self::get_ai_settings();
        $settings['max_tokens'] = self::clamp_public_max_tokens($settings);
        $provider = isset($settings['provider']) ? $settings['provider'] : 'anthropic';
        $api_key  = isset($settings['api_key'])  ? $settings['api_key']  : '';

        if (empty($api_key)) {
            wp_send_json_error('AI not configured. Please add an API key in DIALOGA > AI Settings.');
            return;
        }

        $auto_extract = !empty($node_config['auto_extract_lead']);

        // --- AUTO-EXTRACT LEAD MODE (Option B: AI conversationally captures lead) ---
        if ($auto_extract) {
            $lead_fields = isset($node_config['lead_fields']) && is_array($node_config['lead_fields'])
                ? $node_config['lead_fields']
                : array('name' => true, 'email' => true, 'phone' => false);

            $lead_draft_raw = isset($_POST['lead_draft']) ? sanitize_textarea_field(wp_unslash($_POST['lead_draft'])) : '{}';
            // v1.0.0 — recursively sanitize decoded lead draft (each string
            // gets sanitize_text_field'd) before we reason about it.
            $lead_draft     = dialoga_sanitize_json(json_decode($lead_draft_raw, true) ?: array());
            if (!is_array($lead_draft)) $lead_draft = array();

            $awaiting_confirm = !empty($lead_draft['_awaiting_confirm']);

            $extraction_prompt = self::build_extraction_prompt($settings, $node_config, $lead_fields, $lead_draft, $awaiting_confirm);

            if ($provider === 'openai') {
                $raw = self::call_openai_json($api_key, $settings, $extraction_prompt, $history, $user_message);
            } else {
                $raw = self::call_anthropic_json($api_key, $settings, $extraction_prompt, $history, $user_message);
            }

            if (is_wp_error($raw)) {
                error_log('DIALOGA AI extraction error: ' . $raw->get_error_message());
                wp_send_json_error('AI service error. Please try again.');
                return;
            }

            $parsed = self::parse_extraction_json($raw);
            if (!$parsed) {
                // Fallback — if model didn't return valid JSON, treat as plain reply so chat doesn't break
                wp_send_json_success(array(
                    'reply'      => is_string($raw) ? $raw : 'Sorry, I had trouble there. Could you rephrase?',
                    'lead_draft' => $lead_draft,
                    'status'     => 'collecting',
                    'remaining'  => $msg_limit - ($ai_turns + 1),
                ));
                return;
            }

            // Merge extracted values into the running lead_draft (AI is forced by schema to
            // only return values literally present in the transcript; nulls skip the merge)
            foreach (array('name', 'email', 'phone') as $f) {
                if (isset($parsed['extracted'][$f]) && !empty($parsed['extracted'][$f])) {
                    $lead_draft[$f] = sanitize_text_field($parsed['extracted'][$f]);
                }
            }

            // Check which required fields are still missing
            $missing = array();
            foreach ($lead_fields as $field => $required) {
                if ($required && empty($lead_draft[$field])) $missing[] = $field;
            }

            $reply_msg = isset($parsed['message']) ? $parsed['message'] : '';
            $status    = isset($parsed['status'])  ? $parsed['status']  : 'collecting';

            // State machine transitions
            $lead_captured = false;
            if ($status === 'confirmed' && empty($missing)) {
                // Visitor confirmed — save the lead and fire the webhook chain
                $lead_data = array(
                    'name'  => isset($lead_draft['name'])  ? $lead_draft['name']  : '',
                    'email' => isset($lead_draft['email']) ? $lead_draft['email'] : '',
                    'phone' => isset($lead_draft['phone']) ? $lead_draft['phone'] : '',
                );

                $flow_id = isset($_POST['flow_id']) ? intval($_POST['flow_id']) : 0;
                $source_node_id = isset($_POST['node_id']) ? sanitize_text_field(wp_unslash($_POST['node_id'])) : null;

                // Auto-tag with flow name (same as Capture Lead path)
                $auto_tags = array();
                if ($flow_id) {
                    global $wpdb;
                    $flow_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT flow_name FROM {$wpdb->prefix}dialoga_flows WHERE id = %d",
                        $flow_id
                    ));
                    if ($flow_name) $auto_tags[] = sanitize_title($flow_name);
                }

                $lead_id = Dialoga_Database::save_lead($lead_data, $auto_tags);

                if ($lead_id) {
                    // Update conversation with lead data
                    if (!empty($session_id)) {
                        Dialoga_Database::update_conversation_lead($session_id, $lead_data);
                    }

                    // Fire the post-lead hook with source_node_id so webhook walker
                    // starts from this AI Chat node instead of looking for capture_lead
                    $variables = isset($lead_draft) ? $lead_draft : array();
                    do_action('dialoga_lead_captured', $lead_id, $lead_data, $flow_id, $variables, $auto_tags, $source_node_id);

                    $lead_captured = true;
                    $lead_draft = array(); // clear draft; it's saved now
                } else {
                    $status = 'error';
                    $reply_msg = "I had trouble saving that. Could you try again?";
                }
            } elseif ($status === 'confirming' && empty($missing)) {
                // Mark draft as awaiting confirmation so the next turn's prompt knows
                $lead_draft['_awaiting_confirm'] = true;
            } elseif ($status === 'collecting' || !empty($missing)) {
                // Clear any stale awaiting-confirm flag if AI went back to collecting
                unset($lead_draft['_awaiting_confirm']);
            }

            // v0.39.0: when a lead was just captured via AI extract, attach the same
            // tracking payload as the form-capture path so the widget fires dataLayer.push
            // + `dialoga:lead-captured` DOM event regardless of which route captured it.
            $response_data = array(
                'reply'         => $reply_msg,
                'lead_draft'    => $lead_draft,
                'status'        => $status,
                'missing'       => $missing,
                'lead_captured' => $lead_captured,
                'remaining'     => $msg_limit - ($ai_turns + 1),
            );
            if ($lead_captured && !empty($lead_id) && class_exists('Dialoga_Frontend')) {
                $response_data['tracking'] = Dialoga_Frontend::build_tracking_payload(
                    $lead_id, $lead_data, $flow_id, $source_node_id ?: 'ai_chat'
                );
            }
            wp_send_json_success($response_data);
            return;
        }

        // --- STANDARD AI CHAT (no lead extraction) ---
        $system_prompt = self::build_system_prompt($settings, $node_config, $user_message);

        if ($provider === 'openai') {
            $reply = self::call_openai($api_key, $settings, $system_prompt, $history, $user_message);
        } else {
            $reply = self::call_anthropic($api_key, $settings, $system_prompt, $history, $user_message);
        }

        if (is_wp_error($reply)) {
            error_log('DIALOGA AI error: ' . $reply->get_error_message());
            wp_send_json_error('AI service error. Please try again.');
            return;
        }

        // Send back remaining turns so widget can show a warning when close to limit
        $remaining = $msg_limit - ($ai_turns + 1);
        wp_send_json_success(array('reply' => $reply, 'remaining' => $remaining));
    }

    // -------------------------------------------------------------------------
    // Lead-extraction prompt + JSON-mode provider wrappers
    // -------------------------------------------------------------------------

    private static function build_extraction_prompt($settings, $node_config, $lead_fields, $lead_draft, $awaiting_confirm) {
        $site_name         = get_bloginfo('name');
        $site_url          = get_site_url();
        $biz_instructions  = isset($settings['instructions']) ? trim($settings['instructions']) : '';
        $node_instructions = isset($node_config['instructions']) ? trim($node_config['instructions']) : '';

        $required_list = array();
        foreach ($lead_fields as $field => $required) {
            if ($required) $required_list[] = $field;
        }
        $required_str = empty($required_list) ? 'name, email' : implode(', ', $required_list);

        $draft_json = wp_json_encode(array_intersect_key($lead_draft, array_flip(array('name', 'email', 'phone'))));

        $prompt  = "You are a helpful AI assistant for {$site_name} ({$site_url}).\n";
        $prompt .= "Your job is to answer questions AND naturally collect the visitor's contact information so the team can follow up.\n";
        $prompt .= "Keep replies to 2-4 sentences. Be friendly, professional, and conversational.\n";
        $prompt .= "The `message` field must be plain text only — no markdown, no **bold**, no *italics*, no bullet lists. Write as if speaking naturally in a chat window.\n\n";

        if (!empty($biz_instructions)) {
            $prompt .= "--- Business Context ---\n{$biz_instructions}\n\n";
        }
        if (!empty($node_instructions)) {
            $prompt .= "--- Node Instructions ---\n{$node_instructions}\n\n";
        }

        $prompt .= "--- Lead Collection Rules ---\n";
        $prompt .= "Required fields to collect: {$required_str}.\n";
        $prompt .= "Current draft (already collected): {$draft_json}\n";
        $prompt .= "\nOUTPUT FORMAT — this is non-negotiable:\n";
        $prompt .= "Respond with ONLY a raw JSON object. No markdown fences (```). No prose before or after. No explanations. Your entire response must be parseable as JSON by json_decode().\n";
        $prompt .= "Schema:\n";
        $prompt .= "{\n";
        $prompt .= "  \"message\":    string   // your chat reply to the visitor — this is what they see\n";
        $prompt .= "  \"extracted\": { \"name\": string|null, \"email\": string|null, \"phone\": string|null },\n";
        $prompt .= "  \"status\":     \"collecting\" | \"confirming\" | \"confirmed\" | \"cancelled\"\n";
        $prompt .= "}\n\n";
        $prompt .= "CRITICAL: For `extracted`, only include values the visitor LITERALLY STATED in this conversation. NEVER invent or guess values. Use null for any field not mentioned.\n\n";
        $prompt .= "State machine:\n";
        $prompt .= "- status='collecting' when you still need more fields. Ask for the next missing one naturally.\n";
        $prompt .= "- status='confirming' when ALL required fields are collected. Your message must recap the fields and ask for confirmation, e.g. \"Just to confirm — NAME, EMAIL. Should I have someone reach out?\"\n";
        $prompt .= "- status='confirmed' when the visitor explicitly agreed (yes, sure, correct, confirm, that's right, etc.) AFTER you asked to confirm. Your message should thank them.\n";
        $prompt .= "- status='cancelled' only if the visitor clearly refuses to share info.\n";

        if ($awaiting_confirm) {
            $prompt .= "\nIMPORTANT: You previously asked to confirm. The visitor's next message is their YES or NO. If they agree, return status='confirmed'. If they want to change a field, return status='collecting' with the corrected extracted value.\n";
        }

        return $prompt;
    }

    private static function parse_extraction_json($raw) {
        if (!is_string($raw)) return null;

        // Strip any markdown fencing (```json ... ```) wherever it appears, not just at edges.
        $cleaned = trim(preg_replace('/```(?:json)?\s*|\s*```/', '', $raw));

        // Try direct decode first
        $parsed = json_decode($cleaned, true);

        // Fallback: model sometimes prepends/appends prose around the JSON.
        // Extract the first balanced {...} block from the text and try again.
        if (!is_array($parsed) && preg_match('/\{(?:[^{}]|(?R))*\}/s', $cleaned, $matches)) {
            $parsed = json_decode($matches[0], true);
        }

        if (!is_array($parsed) || !isset($parsed['message'], $parsed['status'])) return null;
        if (!isset($parsed['extracted']) || !is_array($parsed['extracted'])) {
            $parsed['extracted'] = array();
        }
        return $parsed;
    }

    private static function call_anthropic_json($api_key, $settings, $system_prompt, $history, $user_message) {
        $model      = !empty($settings['model']) ? $settings['model'] : 'claude-haiku-4-5-20251001';
        $max_tokens = !empty($settings['max_tokens']) ? (int) $settings['max_tokens'] : 500;

        $messages = array();
        foreach ($history as $h) {
            $role       = ($h['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sanitize_text_field($h['content']));
        }
        $messages[] = array('role' => 'user', 'content' => $user_message);

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system_prompt,
                'messages'   => $messages,
            )),
        ));

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            return new WP_Error('anthropic_error', $msg);
        }
        return isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
    }

    private static function call_openai_json($api_key, $settings, $system_prompt, $history, $user_message) {
        $model      = !empty($settings['model']) ? $settings['model'] : 'gpt-4o-mini';
        $max_tokens = !empty($settings['max_tokens']) ? (int) $settings['max_tokens'] : 500;

        $messages = array(array('role' => 'system', 'content' => $system_prompt));
        foreach ($history as $h) {
            $role       = ($h['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sanitize_text_field($h['content']));
        }
        $messages[] = array('role' => 'user', 'content' => $user_message);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model'           => $model,
                'max_tokens'      => $max_tokens,
                'messages'        => $messages,
                'response_format' => array('type' => 'json_object'), // forces valid JSON
            )),
        ));

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            return new WP_Error('openai_error', $msg);
        }
        return isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
    }

    /**
     * Resolve an AI node's config from the stored flow.
     *
     * The visitor only supplies flow_id + node_id; everything that shapes the
     * prompt (instructions, auto_extract_lead, lead_fields) is read back from
     * the database. This stops a visitor from rewriting the system prompt and
     * using the operator's API key as a general-purpose LLM.
     *
     * @return array|null Node config, or null when the flow/node is not valid
     *                    for the current visitor.
     */
    private static function resolve_node_config($flow_id, $node_id) {
        $flow_id = absint($flow_id);
        $node_id = is_string($node_id) ? sanitize_text_field($node_id) : '';
        if (!$flow_id || '' === $node_id) {
            return null;
        }
        if (!class_exists('Dialoga_Frontend') || !method_exists('Dialoga_Frontend', 'get_flow_by_id_cached')) {
            return null;
        }

        // Only flows this visitor is actually eligible for may be driven.
        $eligible = Dialoga_Frontend::get_eligible_active_flows();
        $allowed  = false;
        foreach ($eligible as $candidate) {
            if ((int) $candidate->id === $flow_id) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return null;
        }

        $flow = Dialoga_Frontend::get_flow_by_id_cached($flow_id);
        if (!$flow || empty($flow->flow_data)) {
            return null;
        }

        $flow_data = json_decode($flow->flow_data, true);
        if (!is_array($flow_data) || empty($flow_data['nodes']) || !is_array($flow_data['nodes'])) {
            return null;
        }

        foreach ($flow_data['nodes'] as $node) {
            if (isset($node['id']) && (string) $node['id'] === $node_id) {
                return isset($node['data']['config']) && is_array($node['data']['config'])
                    ? $node['data']['config']
                    : array();
            }
        }

        return null;
    }

    /**
     * Hard ceiling for tokens spent on public chat requests.
     */
    private static function clamp_public_max_tokens($settings) {
        $cap        = (int) apply_filters('dialoga_ai_max_tokens_cap', 1000);
        $configured = !empty($settings['max_tokens']) ? (int) $settings['max_tokens'] : 500;
        return max(1, min($configured, $cap));
    }

    /**
     * Read AI settings with the stored API key decrypted.
     */
    private static function get_ai_settings() {
        $settings = get_option('dialoga_ai_settings', array());
        if (!is_array($settings)) {
            return array();
        }
        if (!empty($settings['api_key'])) {
            if (!self::is_encrypted_secret($settings['api_key'])) {
                // One-time migration of legacy plaintext keys.
                $settings['api_key'] = self::encrypt_secret($settings['api_key']);
                update_option('dialoga_ai_settings', $settings);
            }
            $settings['api_key'] = self::decrypt_secret($settings['api_key']);
        }
        return $settings;
    }

    private static function is_encrypted_secret($value) {
        return is_string($value) && 0 === strpos($value, 'dgwp:v1:');
    }

    private static function secret_cipher_key() {
        return hash('sha256', wp_salt('auth'), true);
    }

    private static function encrypt_secret($plain) {
        if (!is_string($plain) || '' === $plain) {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return $plain;
        }
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::secret_cipher_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $cipher) {
            return $plain;
        }
        return 'dgwp:v1:' . base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt_secret($stored) {
        if (!is_string($stored) || '' === $stored) {
            return '';
        }
        if (!self::is_encrypted_secret($stored)) {
            return $stored;
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 8), true);
        if (false === $raw || strlen($raw) < 28) {
            return '';
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', self::secret_cipher_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return false === $plain ? '' : $plain;
    }

    /**
     * Get the real client IP.
     *
     * Proxy headers are spoofable, so they are only trusted when the site
     * explicitly opts in via the `dialoga_trusted_proxies` filter. Without
     * that, REMOTE_ADDR is authoritative.
     */
    private static function get_client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        if (apply_filters('dialoga_trusted_proxies', false)) {
            foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP') as $key) {
                if (empty($_SERVER[$key])) {
                    continue;
                }
                foreach (explode(',', sanitize_text_field(wp_unslash($_SERVER[$key]))) as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        return $candidate;
                    }
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Build system prompt — retrieves only relevant knowledge for the query
    // -------------------------------------------------------------------------

    private static function build_system_prompt($settings, $node_config = array(), $user_query = '') {
        $custom_instructions = isset($settings['instructions']) ? trim($settings['instructions']) : '';
        $node_instructions   = isset($node_config['instructions']) ? trim($node_config['instructions']) : '';
        $site_name           = get_bloginfo('name');
        $site_url            = get_site_url();

        $prompt  = "You are a helpful AI assistant for {$site_name} ({$site_url}).\n";
        $prompt .= "Your role is to answer questions about the business and encourage visitors to submit their contact details so the team can follow up.\n";
        $prompt .= "Be friendly, concise, and professional. Keep responses to 2-4 sentences unless more detail is clearly needed.\n";
        $prompt .= "Do not make up information. If you don't know something, say so and offer to connect them with the team.\n";
        $prompt .= "Reply in plain text only. Do NOT use markdown formatting — no **bold**, no *italics*, no `code`, no # headings, no - bullet lists. Write as if speaking naturally in a chat window.\n\n";
        $prompt .= "Lead capture goal: Whenever a visitor expresses interest in booking, scheduling, or being contacted, encourage them to click the \"Connect with our team\" button below the chat — this takes them directly to the contact form so the team can follow up personally. ";
        $prompt .= "You may answer general questions including contact info if asked, but always follow up by pointing them to the button. Keep it natural, e.g. \"Feel free to click 'Connect with our team' below and we'll have someone reach out to you directly!\"\n\n";

        if (!empty($custom_instructions)) {
            $prompt .= "--- Business Instructions ---\n{$custom_instructions}\n\n";
        }

        if (!empty($node_instructions)) {
            $prompt .= "--- Additional Context ---\n{$node_instructions}\n\n";
        }

        // Retrieve only the chunks relevant to this specific question
        $knowledge = self::get_relevant_knowledge($user_query, $settings);
        if (!empty($knowledge)) {
            $prompt .= "--- Relevant Website Content ---\n";
            $prompt .= "Use the following to answer questions about the business. Only use what is relevant.\n\n";
            $prompt .= $knowledge;
        }

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Retrieval: find the most relevant knowledge chunks for a query
    // -------------------------------------------------------------------------

    /**
     * Returns formatted knowledge context for the given query.
     * Uses cosine similarity on embeddings if available, falls back to keyword scoring.
     *
     * @param string $query      The user's message
     * @param array  $settings   Plugin AI settings
     * @param int    $top_k      How many chunks to include
     * @param int    $max_chars  Hard cap on total context characters
     */
    private static function get_relevant_knowledge($query, $settings, $top_k = 4, $max_chars = 3000) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_ai_knowledge';

        if (empty($query)) {
            // No query — return nothing rather than dumping everything
            return '';
        }

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title, url, content, embedding FROM %i ORDER BY created_at DESC LIMIT 100",
                $table
            ),
            ARRAY_A
        );

        if (empty($items)) {
            return '';
        }

        // --- Attempt embedding-based retrieval ---
        $query_embedding = self::generate_embedding($query, $settings);

        if ($query_embedding !== null) {
            $scored = array();
            foreach ($items as $item) {
                if (!empty($item['embedding'])) {
                    $item_vec = json_decode($item['embedding'], true);
                    $score    = self::cosine_similarity($query_embedding, $item_vec);
                } else {
                    // Item has no embedding yet — give it a keyword score so it
                    // can still surface, but rank it lower than embedded items
                    $score = self::keyword_score($query, $item['content']) * 0.5;
                }
                $scored[] = array('item' => $item, 'score' => $score);
            }
        } else {
            // --- Fallback: keyword scoring ---
            $scored = array();
            foreach ($items as $item) {
                $score    = self::keyword_score($query, $item['content']);
                $scored[] = array('item' => $item, 'score' => $score);
            }
        }

        // Sort by score descending
        usort($scored, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Take top_k, discard near-zero scores
        $top        = array_slice($scored, 0, $top_k);
        $threshold  = ($query_embedding !== null) ? 0.25 : 0.05;
        $top        = array_filter($top, function($s) use ($threshold) {
            return $s['score'] >= $threshold;
        });

        if (empty($top)) {
            return '';
        }

        // Format as context string
        $output     = '';
        $chars_used = 0;

        foreach ($top as $s) {
            $item  = $s['item'];
            $chunk = "## {$item['title']}\nURL: {$item['url']}\n{$item['content']}\n\n";

            if ($chars_used + strlen($chunk) > $max_chars) {
                break;
            }
            $output    .= $chunk;
            $chars_used += strlen($chunk);
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Embeddings
    // -------------------------------------------------------------------------

    /**
     * Generate an embedding vector for a piece of text using OpenAI.
     * Always uses OpenAI's text-embedding-3-small model (cheapest, 1536 dimensions).
     * Returns null if no embedding key is configured.
     */
    private static function generate_embedding($text, $settings) {
        $api_key = self::get_embedding_key($settings);
        if (empty($api_key)) {
            return null;
        }

        // Truncate to ~8000 chars (~2000 tokens) to stay within model limits
        $text = substr(trim($text), 0, 8000);

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => 'text-embedding-3-small',
                'input' => $text,
            )),
        ));

        if (is_wp_error($response)) {
            error_log('DIALOGA embedding error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !isset($data['data'][0]['embedding'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown';
            error_log('DIALOGA embedding API error: ' . $msg);
            return null;
        }

        return $data['data'][0]['embedding'];
    }

    /**
     * Get the API key to use for embeddings.
     * Only OpenAI supports embeddings — Anthropic users fall back to keyword search.
     */
    private static function get_embedding_key($settings) {
        if (isset($settings['provider']) && $settings['provider'] === 'openai' && !empty($settings['api_key'])) {
            return $settings['api_key'];
        }
        return '';
    }

    /**
     * Cosine similarity between two float vectors.
     * Returns a value between -1 and 1. Higher = more similar.
     */
    private static function cosine_similarity($vec_a, $vec_b) {
        $dot    = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        $len    = min(count($vec_a), count($vec_b));

        for ($i = 0; $i < $len; $i++) {
            $dot    += $vec_a[$i] * $vec_b[$i];
            $norm_a += $vec_a[$i] * $vec_a[$i];
            $norm_b += $vec_b[$i] * $vec_b[$i];
        }

        if ($norm_a == 0.0 || $norm_b == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($norm_a) * sqrt($norm_b));
    }

    /**
     * Keyword-based relevance score (fallback when no embedding key).
     * Counts how many query words appear in the content, normalized by query length.
     */
    private static function keyword_score($query, $content) {
        $query_words   = array_filter(array_unique(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $query)))));
        $content_lower = strtolower($content);
        $hits          = 0;

        foreach ($query_words as $word) {
            if (strlen($word) < 3) continue; // skip short words
            if (strpos($content_lower, $word) !== false) {
                $hits++;
            }
        }

        $total = count($query_words) ?: 1;
        return $hits / $total;
    }

    // -------------------------------------------------------------------------
    // Anthropic API
    // -------------------------------------------------------------------------

    private static function call_anthropic($api_key, $settings, $system_prompt, $history, $user_message) {
        $model      = !empty($settings['model']) ? $settings['model'] : 'claude-haiku-4-5-20251001';
        $max_tokens = !empty($settings['max_tokens']) ? (int) $settings['max_tokens'] : 500;

        $messages = array();
        foreach ($history as $h) {
            $role       = ($h['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sanitize_text_field($h['content']));
        }
        $messages[] = array('role' => 'user', 'content' => $user_message);

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system_prompt,
                'messages'   => $messages,
            )),
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            return new WP_Error('anthropic_error', $msg);
        }

        return isset($data['content'][0]['text']) ? $data['content'][0]['text'] : '';
    }

    // -------------------------------------------------------------------------
    // OpenAI API
    // -------------------------------------------------------------------------

    private static function call_openai($api_key, $settings, $system_prompt, $history, $user_message) {
        $model      = !empty($settings['model']) ? $settings['model'] : 'gpt-4o-mini';
        $max_tokens = !empty($settings['max_tokens']) ? (int) $settings['max_tokens'] : 500;

        $messages = array(array('role' => 'system', 'content' => $system_prompt));
        foreach ($history as $h) {
            $role       = ($h['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sanitize_text_field($h['content']));
        }
        $messages[] = array('role' => 'user', 'content' => $user_message);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'messages'   => $messages,
            )),
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            return new WP_Error('openai_error', $msg);
        }

        return isset($data['choices'][0]['message']['content'])
            ? $data['choices'][0]['message']['content']
            : '';
    }

    // -------------------------------------------------------------------------
    // Admin: save / get settings
    // -------------------------------------------------------------------------

    public static function ajax_save_ai_settings() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        $existing = get_option('dialoga_ai_settings', array());

        // Preserve stored key if the field was left blank (masked display)
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        $settings = array(
            'provider'     => isset($_POST['provider'])     ? sanitize_text_field(wp_unslash($_POST['provider']))        : 'anthropic',
            'api_key'      => !empty($api_key) ? self::encrypt_secret($api_key) : (isset($existing['api_key']) ? $existing['api_key'] : ''),
            'model'        => isset($_POST['model'])        ? sanitize_text_field(wp_unslash($_POST['model']))            : '',
            'max_tokens'   => isset($_POST['max_tokens'])   ? absint($_POST['max_tokens'])                   : 500,
            'instructions' => isset($_POST['instructions']) ? sanitize_textarea_field(wp_unslash($_POST['instructions'])) : '',
        );

        update_option('dialoga_ai_settings', $settings);
        wp_send_json_success(__('Settings saved', 'dialoga'));
    }

    public static function ajax_get_ai_settings() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        $settings = self::get_ai_settings();

        // Mask key for display — never send the actual key to the browser
        $key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $settings['api_key_masked'] = !empty($key)
            ? substr($key, 0, 8) . str_repeat('*', max(0, strlen($key) - 12)) . substr($key, -4)
            : '';
        unset($settings['api_key']);

        wp_send_json_success($settings);
    }

    // -------------------------------------------------------------------------
    // Content extraction helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the best possible text content from a post.
     * Tries multiple sources so page-builder sites (Elementor, Divi) still get indexed.
     */
    private static function extract_post_content($post) {
        $parts = array();

        // 1. Excerpt — hand-crafted summary, highest quality when available
        $excerpt = trim(get_the_excerpt($post));
        if (!empty($excerpt)) {
            $parts[] = $excerpt;
        }

        // 2. SEO meta description — builder-agnostic, always reliable when filled in
        $meta_desc = self::extract_seo_meta_description($post->ID);
        if (!empty($meta_desc)) {
            $parts[] = $meta_desc;
        }

        // 3. Page builder content — try each builder in order until we get enough text
        $body = self::extract_builder_content($post);
        if (!empty($body)) {
            $parts[] = $body;
        }

        $combined = implode(' | ', array_unique(array_filter($parts)));
        $combined = trim(preg_replace('/\s+/', ' ', $combined));

        if (strlen($combined) > 3000) {
            $combined = substr($combined, 0, 3000) . '...';
        }

        return $combined;
    }

    /**
     * Extract SEO meta description from any common SEO plugin.
     */
    private static function extract_seo_meta_description($post_id) {
        $checks = array(
            '_yoast_wpseo_metadesc',   // Yoast SEO
            'rank_math_description',    // RankMath
            '_aioseo_description',      // All in One SEO
            '_seopress_titles_desc',    // SEOPress
            '_genesis_description',     // Genesis Framework
        );
        foreach ($checks as $key) {
            $val = trim((string) get_post_meta($post_id, $key, true));
            if (!empty($val)) return $val;
        }
        return '';
    }

    /**
     * Extract readable text from whichever page builder is in use.
     * Tries each builder in turn and returns the first result with enough content.
     */
    private static function extract_builder_content($post) {
        $body = '';

        // --- Elementor ---
        // Stores layout as JSON in _elementor_data meta; post_content is usually empty.
        $elementor_raw = get_post_meta($post->ID, '_elementor_data', true);
        if (!empty($elementor_raw)) {
            $body = self::extract_elementor_text($elementor_raw);
        }

        // --- Beaver Builder ---
        // Stores layout as serialized PHP objects in _fl_builder_data meta.
        if (strlen($body) < 100) {
            $fl_raw = get_post_meta($post->ID, '_fl_builder_data', true);
            if (!empty($fl_raw)) {
                $body = self::extract_beaver_builder_text($fl_raw);
            }
        }

        // --- Oxygen Builder ---
        // Stores shortcode string in ct_builder_shortcodes meta.
        if (strlen($body) < 100) {
            $oxygen_raw = get_post_meta($post->ID, 'ct_builder_shortcodes', true);
            if (!empty($oxygen_raw)) {
                $body = self::strip_shortcode_syntax($oxygen_raw);
            }
        }

        // --- Brizy ---
        // Stores data in brizy-global-data or brizy meta; also renders via post_content.
        if (strlen($body) < 100) {
            $brizy_raw = get_post_meta($post->ID, 'brizy_data', true);
            if (empty($brizy_raw)) {
                $brizy_raw = get_post_meta($post->ID, 'brizy-global-data', true);
            }
            if (!empty($brizy_raw)) {
                // Brizy stores JSON; strip tags on the raw string as a best-effort
                $body = wp_strip_all_tags((string) $brizy_raw);
                $body = trim(preg_replace('/\s+/', ' ', $body));
            }
        }

        // --- Classic Editor / Gutenberg / Divi / WPBakery (shortcode-based) ---
        // do_shortcode() renders active shortcode builders. For inactive ones,
        // strip_shortcode_syntax() pulls the inner text out of the markup.
        if (strlen($body) < 100 && !empty($post->post_content)) {
            $via_shortcode = wp_strip_all_tags(do_shortcode($post->post_content), true);
            $via_shortcode = trim(preg_replace('/\s+/', ' ', $via_shortcode));

            if (strlen($via_shortcode) >= 100) {
                $body = $via_shortcode;
            } else {
                // Builder not active — strip shortcode syntax and grab inner text
                $via_strip = self::strip_shortcode_syntax($post->post_content);
                $body = strlen($via_strip) > strlen($via_shortcode) ? $via_strip : $via_shortcode;
            }
        }

        return $body;
    }

    /**
     * Walk Elementor's nested JSON structure and collect all text widget values.
     */
    private static function extract_elementor_text($json_raw) {
        $data = json_decode($json_raw, true);
        if (!is_array($data)) return '';

        $texts = array();
        $stack = $data;
        $text_keys = array('title', 'text', 'editor', 'description', 'content', 'caption', 'prefix', 'suffix');

        while (!empty($stack)) {
            $node = array_shift($stack);
            if (!is_array($node)) continue;

            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($text_keys as $key) {
                    if (!empty($node['settings'][$key])) {
                        $texts[] = wp_strip_all_tags((string) $node['settings'][$key]);
                    }
                }
            }

            if (isset($node['elements']) && is_array($node['elements'])) {
                foreach ($node['elements'] as $child) {
                    $stack[] = $child;
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $texts)));
    }

    /**
     * Extract text from Beaver Builder's serialized _fl_builder_data meta.
     * Data is an array of row/module objects; text lives in $module->settings->field.
     */
    private static function extract_beaver_builder_text($fl_raw) {
        $data = maybe_unserialize($fl_raw);
        if (!is_array($data)) return '';

        $texts = array();
        $text_fields = array('text', 'content', 'title', 'heading', 'html', 'description', 'label');

        foreach ($data as $item) {
            if (!is_object($item) || !isset($item->settings)) continue;
            foreach ($text_fields as $field) {
                if (!empty($item->settings->$field)) {
                    $texts[] = wp_strip_all_tags((string) $item->settings->$field);
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $texts)));
    }

    /**
     * Strip shortcode syntax from content and return the inner text.
     * Handles Divi, WPBakery, and any other shortcode-based builder
     * when the plugin is not active and do_shortcode() is a no-op.
     *
     * [et_pb_text]Hello world[/et_pb_text] → "Hello world"
     */
    private static function strip_shortcode_syntax($content) {
        // Remove self-closing shortcodes: [tag attr="val" /] or [tag /]
        $content = preg_replace('/\[[\w\-][\w\-_]*[^\]]*\/\]/', ' ', $content);
        // Remove closing tags: [/tag]
        $content = preg_replace('/\[\/[\w\-][\w\-_]*\]/', ' ', $content);
        // Remove opening tags with attributes: [tag attr="val"]
        $content = preg_replace('/\[[\w\-][\w\-_]*[^\]]+\]/', ' ', $content);
        // Remove bare opening tags: [tag]
        $content = preg_replace('/\[[\w\-][\w\-_]*\]/', ' ', $content);

        $content = wp_strip_all_tags($content);
        return trim(preg_replace('/\s+/', ' ', $content));
    }

    // -------------------------------------------------------------------------
    // Admin: crawl website + generate embeddings
    // -------------------------------------------------------------------------

    public static function ajax_crawl_website() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        // Raise time limit for large sites
        @set_time_limit(120);

        global $wpdb;
        $table    = $wpdb->prefix . 'dialoga_ai_knowledge';
        $settings = self::get_ai_settings();

        $posts = get_posts(array(
            'post_type'      => array('page', 'post'),
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ));

        $saved   = 0;
        $updated = 0;
        $skipped = 0;
        $embedded = 0;

        foreach ($posts as $post) {
            $content = self::extract_post_content($post);

            if (strlen($content) < 30) {
                $skipped++;
                continue;
            }

            // Generate embedding for this chunk
            $embedding     = self::generate_embedding($content, $settings);
            $embedding_json = $embedding ? wp_json_encode($embedding) : null;
            if ($embedding) $embedded++;

            $token_estimate = (int) (strlen($content) / 4);
            $existing_id    = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM %i WHERE content_type = %s AND content_id = %d",
                $table, $post->post_type, $post->ID
            ));

            $row = array(
                'title'          => $post->post_title,
                'url'            => get_permalink($post->ID),
                'content'        => $content,
                'token_estimate' => $token_estimate,
                'created_at'     => current_time('mysql'),
            );
            if ($embedding_json !== null) {
                $row['embedding'] = $embedding_json;
            }

            if ($existing_id) {
                $wpdb->update($table, $row, array('id' => $existing_id));
                $updated++;
            } else {
                $row['content_type'] = $post->post_type;
                $row['content_id']   = $post->ID;
                $wpdb->insert($table, $row);
                $saved++;
            }
        }

        $provider = isset($settings['provider']) ? $settings['provider'] : 'anthropic';

        wp_send_json_success(array(
            'saved'    => $saved,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'embedded' => $embedded,
            'message'  => sprintf(
                /* translators: 1: indexed count, 2: new count, 3: updated count, 4: skipped count, 5: retrieval status. */
                __('Crawl complete. %1$d items indexed (%2$d new, %3$d updated, %4$d skipped). %5$s', 'dialoga'),
                $saved + $updated,
                $saved,
                $updated,
                $skipped,
                $provider === 'openai'
                    /* translators: %d: number of embeddings generated. */
                    ? sprintf(__('%d embeddings generated for smart retrieval.', 'dialoga'), $embedded)
                    : __('Keyword search will be used for retrieval.', 'dialoga')
            ),
        ));
    }

    /**
     * Generate embeddings for knowledge items that don't have one yet.
     * Called separately so crawl doesn't time out on large sites.
     */
    public static function ajax_generate_embeddings() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        @set_time_limit(120);

        global $wpdb;
        $table    = $wpdb->prefix . 'dialoga_ai_knowledge';
        $settings = self::get_ai_settings();

        if (empty(self::get_embedding_key($settings))) {
            wp_send_json_error(__('Embeddings require an OpenAI API key. Switch the provider to OpenAI, or use keyword search (works automatically for Anthropic users).', 'dialoga'));
            return;
        }

        // Process items missing embeddings
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, content FROM %i WHERE embedding IS NULL OR embedding = '' LIMIT 50",
                $table
            ),
            ARRAY_A
        );

        if (empty($items)) {
            wp_send_json_success(array('embedded' => 0, 'message' => __('All items already have embeddings.', 'dialoga')));
            return;
        }

        $count = 0;
        foreach ($items as $item) {
            $embedding = self::generate_embedding($item['content'], $settings);
            if ($embedding) {
                $wpdb->update(
                    $table,
                    array('embedding' => wp_json_encode($embedding)),
                    array('id' => $item['id']),
                    array('%s'),
                    array('%d')
                );
                $count++;
            }
        }

        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE embedding IS NULL OR embedding = ''",
            $table
        ));

        wp_send_json_success(array(
            'embedded'  => $count,
            'remaining' => (int) $remaining,
            // translators: 1: generated embeddings count, 2: remaining knowledge items count.
            'message'   => $remaining > 0
                // translators: 1: generated embeddings count, 2: remaining knowledge items count.
                ? sprintf(__('%1$d embeddings generated. %2$d items remaining - run again to continue.', 'dialoga'), $count, $remaining)
                // translators: %d: number of embeddings generated in this batch.
                : sprintf(__('%d embeddings generated. All items embedded!', 'dialoga'), $count),
        ));
    }

    // -------------------------------------------------------------------------
    // Admin: knowledge base list + delete
    // -------------------------------------------------------------------------

    public static function ajax_get_ai_knowledge() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_ai_knowledge';

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, content_type, title, url, token_estimate,
                       (embedding IS NOT NULL AND embedding != '') AS has_embedding,
                       created_at
                FROM %i ORDER BY created_at DESC",
                $table
            ),
            ARRAY_A
        );

        wp_send_json_success($items ?: array());
    }

    public static function ajax_delete_knowledge_item() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(__('Invalid ID', 'dialoga'));
            return;
        }

        global $wpdb;
        $result = $wpdb->delete($wpdb->prefix . 'dialoga_ai_knowledge', array('id' => $id), array('%d'));

        $result === false ? wp_send_json_error(__('Delete failed', 'dialoga')) : wp_send_json_success(__('Deleted', 'dialoga'));
    }

    // -------------------------------------------------------------------------
    // Admin: test AI connection
    // -------------------------------------------------------------------------

    public static function ajax_test_ai() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        $settings = self::get_ai_settings();
        $provider = isset($settings['provider']) ? $settings['provider'] : 'anthropic';
        $api_key  = isset($settings['api_key'])  ? $settings['api_key']  : '';

        if (empty($api_key)) {
            wp_send_json_error(__('No API key saved. Save your settings first.', 'dialoga'));
            return;
        }

        $test_settings = array(
            'model'      => !empty($settings['model']) ? $settings['model'] : ($provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001'),
            'max_tokens' => 50,
        );

        if ($provider === 'openai') {
            $reply = self::call_openai($api_key, $test_settings, 'You are a test assistant.', array(), 'Reply with exactly: "DIALOGA AI connection successful!"');
        } else {
            $reply = self::call_anthropic($api_key, $test_settings, 'You are a test assistant.', array(), 'Reply with exactly: "DIALOGA AI connection successful!"');
        }

        if (is_wp_error($reply)) {
            wp_send_json_error($reply->get_error_message());
            return;
        }

        wp_send_json_success(array('message' => __('DIALOGA AI connection successful!', 'dialoga')));
    }

    // -------------------------------------------------------------------------
    // Flow Wizard: generate a suggested flow from the knowledge base
    // -------------------------------------------------------------------------

    public static function ajax_generate_flow() {
        check_ajax_referer('dialoga_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'dialoga'));
            return;
        }

        @set_time_limit(120);

        $settings = self::get_ai_settings();
        $provider = isset($settings['provider']) ? $settings['provider'] : 'anthropic';
        $api_key  = isset($settings['api_key'])  ? $settings['api_key']  : '';

        if (empty($api_key)) {
            wp_send_json_error(array(
                'message'  => __('No API key configured. Save your AI settings first.', 'dialoga'),
                'fallback' => null,
            ));
            return;
        }

        $knowledge = self::get_all_knowledge_for_wizard();

        if (empty($knowledge)) {
            wp_send_json_error(array(
                'message'  => __('No content in the knowledge base. Click "Crawl Website" first.', 'dialoga'),
                'fallback' => null,
            ));
            return;
        }

        $custom_instructions = isset($settings['instructions']) ? trim($settings['instructions']) : '';

        // Collect wizard context if provided
        // v1.0.0 — wp_unslash before sanitize (was using stripslashes which
        // is not the WordPress way); goals decoded then recursively sanitized.
        $goals_raw = isset($_POST['goals']) ? sanitize_textarea_field(wp_unslash($_POST['goals'])) : '[]';
        $goals_arr = json_decode($goals_raw, true) ?: array();
        $wizard_context = array(
            'business_name'  => isset($_POST['business_name'])  ? sanitize_text_field(wp_unslash($_POST['business_name']))  : '',
            'business_type'  => isset($_POST['business_type'])  ? sanitize_text_field(wp_unslash($_POST['business_type']))  : '',
            'other_industry' => isset($_POST['other_industry']) ? sanitize_text_field(wp_unslash($_POST['other_industry'])) : '',
            'goals'          => array_values(array_filter(array_map('sanitize_text_field', $goals_arr), 'is_string')),
        );

        $prompt = self::build_flow_generation_prompt($knowledge, $custom_instructions, $wizard_context);

        // Use higher token limit for generation — override the chat setting
        $gen_settings = array_merge($settings, array('max_tokens' => 4000));

        if ($provider === 'openai') {
            $raw = self::call_openai($api_key, $gen_settings, $prompt, array(), 'Generate the flow JSON now. Return only the JSON object, nothing else.');
        } else {
            $raw = self::call_anthropic($api_key, $gen_settings, $prompt, array(), 'Generate the flow JSON now. Return only the JSON object, nothing else.');
        }

        if (is_wp_error($raw)) {
            wp_send_json_error(array(
                'message'  => $raw->get_error_message(),
                'fallback' => self::get_fallback_flow_template(),
            ));
            return;
        }

        $flow_data = self::extract_and_validate_flow_json($raw);

        if ($flow_data === null) {
            error_log('DIALOGA wizard: invalid JSON from AI. Raw response (' . strlen($raw) . ' chars): ' . substr($raw, 0, 1000));
            wp_send_json_error(array(
                'message'  => __('AI returned an unexpected format.', 'dialoga'),
                'raw_preview' => substr($raw, 0, 300),
                'fallback' => self::get_fallback_flow_template(),
            ));
            return;
        }

        wp_send_json_success(array('flow' => $flow_data));
    }

    /**
     * Collect all knowledge base content for the wizard prompt.
     * Unlike retrieval (which searches for a query), the wizard needs a
     * broad overview of the whole business, so we grab the richest items.
     */
    private static function get_all_knowledge_for_wizard($max_chars = 10000) {
        global $wpdb;
        $table = $wpdb->prefix . 'dialoga_ai_knowledge';

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title, url, content FROM %i ORDER BY token_estimate DESC LIMIT 30",
                $table
            ),
            ARRAY_A
        );

        if (empty($items)) return '';

        // Score each item: boost homepage and key pages so they always lead
        $key_slugs = array('home', 'about', 'about-us', 'services', 'service', 'what-we-do', 'welcome', 'index');
        $front_url  = trailingslashit(get_site_url());

        $scored = array();
        foreach ($items as $item) {
            $priority = 0;

            // Homepage gets highest priority
            $item_url = trailingslashit($item['url']);
            if ($item_url === $front_url) {
                $priority += 100;
            }

            // Key-slug pages get a boost
            $slug = strtolower(basename(rtrim($item['url'], '/')));
            if (in_array($slug, $key_slugs, true)) {
                $priority += 50;
            }

            // Title contains key words
            $title_lower = strtolower($item['title']);
            foreach (array('about', 'service', 'home', 'welcome', 'who we are', 'what we do') as $kw) {
                if (strpos($title_lower, $kw) !== false) {
                    $priority += 20;
                    break;
                }
            }

            $scored[] = array('item' => $item, 'priority' => $priority);
        }

        usort($scored, function($a, $b) { return $b['priority'] - $a['priority']; });

        $output     = '';
        $chars_used = 0;

        foreach ($scored as $entry) {
            $item  = $entry['item'];
            $chunk = "## {$item['title']}\n{$item['content']}\n\n";
            if ($chars_used + strlen($chunk) > $max_chars) break;
            $output     .= $chunk;
            $chars_used += strlen($chunk);
        }

        return $output;
    }

    /**
     * Build the prompt that instructs the AI to generate a flow JSON.
     * Uses a strict schema with examples so output is reliably parseable.
     */
    private static function build_flow_generation_prompt($knowledge, $custom_instructions = '', $wizard_context = array()) {
        $site_name = get_bloginfo('name');
        $site_url  = get_site_url();
        $tagline   = get_bloginfo('description');
        $site_info = $site_name;
        if (!empty($tagline)) {
            $site_info .= " — \"{$tagline}\"";
        }

        // Build wizard context block
        $wizard_block = '';
        if (!empty($wizard_context['business_name'])) {
            $wizard_block .= "BUSINESS NAME: {$wizard_context['business_name']}\n";
        }
        if (!empty($wizard_context['business_type'])) {
            $industry = $wizard_context['business_type'];
            if ($industry === 'other' && !empty($wizard_context['other_industry'])) {
                $industry = $wizard_context['other_industry'];
            }
            $wizard_block .= "INDUSTRY: {$industry}\n";
        }
        if (!empty($wizard_context['goals'])) {
            $goal_labels = array(
                'leads' => 'Capture visitor leads (name, email, phone)',
                'booking' => 'Help visitors book appointments or consultations',
                'support' => 'Provide customer support and answer questions from knowledge base',
                'info' => 'Be an informational AI chatbot that answers visitor questions',
                'qualify' => 'Qualify leads by asking scoring questions (budget, timeline, authority)',
                'survey' => 'Collect visitor feedback and satisfaction surveys',
                'webhooks' => 'Send data to third-party integrations via webhooks',
            );
            $goal_descriptions = array();
            foreach ($wizard_context['goals'] as $g) {
                if (isset($goal_labels[$g])) {
                    $goal_descriptions[] = $goal_labels[$g];
                }
            }
            if (!empty($goal_descriptions)) {
                $wizard_block .= "CHATBOT GOALS (the flow MUST achieve ALL of these):\n";
                foreach ($goal_descriptions as $gd) {
                    $wizard_block .= "- {$gd}\n";
                }
            }
        }

        // Determine flow structure based on goals
        $has_leads = in_array('leads', $wizard_context['goals'] ?? array());
        $has_booking = in_array('booking', $wizard_context['goals'] ?? array());
        $has_support = in_array('support', $wizard_context['goals'] ?? array());
        $has_info = in_array('info', $wizard_context['goals'] ?? array());
        $needs_capture = $has_leads || $has_booking;
        $needs_ai_chat = $has_support || $has_info;

        $task_description = "Create a conversational flow that greets visitors";
        if ($needs_capture && $needs_ai_chat) {
            $task_description .= ", helps them find what they need, captures lead information from interested visitors, and provides AI-powered answers for those with questions.";
        } elseif ($needs_capture) {
            $task_description .= ", qualifies them through conversation, and captures their contact information.";
        } elseif ($needs_ai_chat) {
            $task_description .= " and provides helpful AI-powered answers based on your website content.";
        } else {
            $task_description .= ", explains services, and qualifies leads.";
        }

        if ($has_booking) {
            $task_description .= " Include questions about scheduling preferences (preferred day/time, type of appointment).";
        }

        return "You are an expert at creating chatbot flows for businesses.

STEP 1 — Identify the business:
Read the website content below carefully. Determine:
- What type of business this is (e.g. law firm, dental practice, marketing agency, HVAC company)
- What specific services or products they offer
- Who their target customers are
- What the most common reason a visitor would contact them is

STEP 2 — Design the flow using ONLY real details from the content above.
Do NOT use generic placeholders like \"[Service A]\" — replace every placeholder with actual business details.

BUSINESS: {$site_info} | {$site_url}
{$wizard_block}
WEBSITE CONTENT:
{$knowledge}
" . (!empty($custom_instructions) ? "BUSINESS OWNER INSTRUCTIONS (follow these precisely — they override defaults):
{$custom_instructions}

" : "") . "YOUR TASK:
{$task_description}

PRESCRIBED STRUCTURE — FOLLOW EXACTLY:
1. Welcome question — one message, exactly 2 service buttons (pick the 2 most common real services from the site)
2. Branch node — routes each service button to its own path
3. Service A path (left column):
   a. MESSAGE node — 1-2 sentences explaining what service A involves, written for a potential client. Set auto_continue:true, delay:2, options:[]
   b. Follow-up question — ask something like \"Would you like to get started?\" with 2 buttons: one to book (e.g. \"Yes, schedule a consultation\") and one for more info (e.g. \"Tell me more first\")
   c. Branch node — routes the book button to the shared Capture Lead node, the more-info button to the shared AI Chat node
4. Service B path (right column) — same pattern as Service A (message → follow-up question → branch)
5. ONE shared Capture Lead node — both service paths' \"book\" branches connect here
6. ONE shared AI Chat node — both service paths' \"more info\" branches connect here

COMPLETE NODE LIST (10 nodes total, in this exact order):
node_1: question (welcome, 2 service buttons)
node_2: branch (routes service A vs service B)
node_3: question/MESSAGE (auto_continue, service A description)
node_4: question (follow-up for service A, 2 buttons)
node_5: branch (routes book vs more-info for service A)
node_6: question/MESSAGE (auto_continue, service B description)
node_7: question (follow-up for service B, 2 buttons)
node_8: branch (routes book vs more-info for service B)
node_9: action/capture_lead (shared — both yes paths land here)
node_10: action/ai_chat (shared — both more-info paths land here)

HARD LIMITS:
- Exactly 10 nodes. No more, no less.
- Exactly 2 services. Pick the 2 most prominent from the website.
- MESSAGE nodes (node_3, node_6) must contain specific, real info about the service — not generic filler text.
- The capture_lead success_message must be warm and service-specific.
- The ai_chat greeting must invite questions about the business.

CRITICAL RULES:
- Every question node with options[] MUST be followed by a branch node. No exceptions.
- NEVER set allow_text_input to true on question nodes. Free-form input is only for ai_chat.
- MESSAGE nodes use nodeType \"question\" with auto_continue:true and options:[] — the canvas shows these as blue MESSAGE nodes automatically.
- Branch sourceHandle must match the group id exactly (e.g. group id \"group_1\" → sourceHandle \"group_1\"). Plain question output uses \"output-default\".
- Return ONLY valid JSON. No markdown, no code fences, no explanation before or after.
- Node IDs: node_1 through node_10. Edge IDs: edge_1, edge_2 ... (sequential).
- First node must have is_start:true. All others is_start:false.

POSITIONING (vertical layout, top to bottom):
- node_1: x:300, y:100
- node_2: x:300, y:350
- node_3: x:100, y:600   (service A — left column)
- node_4: x:100, y:850
- node_5: x:100, y:1100
- node_6: x:500, y:600   (service B — right column)
- node_7: x:500, y:850
- node_8: x:500, y:1100
- node_9: x:200, y:1400  (shared capture)
- node_10: x:500, y:1400 (shared AI chat)

EXAMPLE FLOW (replace all bracketed placeholders with real business details):

{
  \"flow_name\": \"[Business Name] Intake\",
  \"business_summary\": \"One sentence describing what this business does.\",
  \"nodes\": [
    {
      \"id\": \"node_1\",
      \"type\": \"custom\",
      \"position\": {\"x\": 300, \"y\": 100},
      \"data\": {
        \"nodeType\": \"question\",
        \"label\": \"Welcome\",
        \"is_start\": true,
        \"message\": \"Hi! Welcome to [Business Name]. What can we help you with today?\",
        \"auto_continue\": false,
        \"delay\": 0,
        \"allow_text_input\": false,
        \"text_placeholder\": \"\",
        \"options\": [
          {\"id\": \"opt_1\", \"label\": \"[Real service A name]\"},
          {\"id\": \"opt_2\", \"label\": \"[Real service B name]\"}
        ]
      }
    },
    {
      \"id\": \"node_2\",
      \"type\": \"custom\",
      \"position\": {\"x\": 300, \"y\": 350},
      \"data\": {
        \"nodeType\": \"branch\",
        \"label\": \"Route by Service\",
        \"is_start\": false,
        \"output_groups\": [
          {
            \"id\": \"group_1\",
            \"label\": \"Service A\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"[Real service A name]\"}],
            \"logic\": \"and\"
          },
          {
            \"id\": \"group_2\",
            \"label\": \"Service B\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"[Real service B name]\"}],
            \"logic\": \"and\"
          }
        ]
      }
    },
    {
      \"id\": \"node_3\",
      \"type\": \"custom\",
      \"position\": {\"x\": 100, \"y\": 600},
      \"data\": {
        \"nodeType\": \"question\",
        \"label\": \"About Service A\",
        \"is_start\": false,
        \"message\": \"[1-2 sentences: what service A is, who it helps, what to expect — written in plain language for a potential client.]\",
        \"auto_continue\": true,
        \"delay\": 2,
        \"allow_text_input\": false,
        \"text_placeholder\": \"\",
        \"options\": []
      }
    },
    {
      \"id\": \"node_4\",
      \"type\": \"custom\",
      \"position\": {\"x\": 100, \"y\": 850},
      \"data\": {
        \"nodeType\": \"question\",
        \"label\": \"Book Service A?\",
        \"is_start\": false,
        \"message\": \"Would you like to get started with [service A]?\",
        \"auto_continue\": false,
        \"delay\": 0,
        \"allow_text_input\": false,
        \"text_placeholder\": \"\",
        \"options\": [
          {\"id\": \"opt_1\", \"label\": \"Yes, schedule a consultation\"},
          {\"id\": \"opt_2\", \"label\": \"Tell me more first\"}
        ]
      }
    },
    {
      \"id\": \"node_5\",
      \"type\": \"custom\",
      \"position\": {\"x\": 100, \"y\": 1100},
      \"data\": {
        \"nodeType\": \"branch\",
        \"label\": \"A — Book or More Info\",
        \"is_start\": false,
        \"output_groups\": [
          {
            \"id\": \"group_1\",
            \"label\": \"Book\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"Yes, schedule a consultation\"}],
            \"logic\": \"and\"
          },
          {
            \"id\": \"group_2\",
            \"label\": \"More Info\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"Tell me more first\"}],
            \"logic\": \"and\"
          }
        ]
      }
    },
    {
      \"id\": \"node_6\",
      \"type\": \"custom\",
      \"position\": {\"x\": 500, \"y\": 600},
      \"data\": {
        \"nodeType\": \"question\",
        \"label\": \"About Service B\",
        \"is_start\": false,
        \"message\": \"[1-2 sentences: what service B is, who it helps, what to expect — written in plain language for a potential client.]\",
        \"auto_continue\": true,
        \"delay\": 2,
        \"allow_text_input\": false,
        \"text_placeholder\": \"\",
        \"options\": []
      }
    },
    {
      \"id\": \"node_7\",
      \"type\": \"custom\",
      \"position\": {\"x\": 500, \"y\": 850},
      \"data\": {
        \"nodeType\": \"question\",
        \"label\": \"Book Service B?\",
        \"is_start\": false,
        \"message\": \"Would you like to get started with [service B]?\",
        \"auto_continue\": false,
        \"delay\": 0,
        \"allow_text_input\": false,
        \"text_placeholder\": \"\",
        \"options\": [
          {\"id\": \"opt_1\", \"label\": \"Yes, schedule a consultation\"},
          {\"id\": \"opt_2\", \"label\": \"Tell me more first\"}
        ]
      }
    },
    {
      \"id\": \"node_8\",
      \"type\": \"custom\",
      \"position\": {\"x\": 500, \"y\": 1100},
      \"data\": {
        \"nodeType\": \"branch\",
        \"label\": \"B — Book or More Info\",
        \"is_start\": false,
        \"output_groups\": [
          {
            \"id\": \"group_1\",
            \"label\": \"Book\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"Yes, schedule a consultation\"}],
            \"logic\": \"and\"
          },
          {
            \"id\": \"group_2\",
            \"label\": \"More Info\",
            \"conditions\": [{\"id\": \"cond_1\", \"operator\": \"equals\", \"value\": \"Tell me more first\"}],
            \"logic\": \"and\"
          }
        ]
      }
    },
    {
      \"id\": \"node_9\",
      \"type\": \"custom\",
      \"position\": {\"x\": 200, \"y\": 1400},
      \"data\": {
        \"nodeType\": \"action\",
        \"label\": \"Capture Lead\",
        \"is_start\": false,
        \"action_type\": \"capture_lead\",
        \"config\": {
          \"fields\": {
            \"name\":    {\"enabled\": true,  \"label\": \"Your Name\",     \"required\": true},
            \"email\":   {\"enabled\": true,  \"label\": \"Email Address\", \"required\": true},
            \"phone\":   {\"enabled\": true,  \"label\": \"Phone Number\",  \"required\": false},
            \"company\": {\"enabled\": false, \"label\": \"Company\",       \"required\": false}
          },
          \"success_message\": \"[Warm confirmation, e.g. You are all set! Our team will reach out shortly to schedule your consultation.]\",
          \"redirect_url\": \"\"
        }
      }
    },
    {
      \"id\": \"node_10\",
      \"type\": \"custom\",
      \"position\": {\"x\": 500, \"y\": 1400},
      \"data\": {
        \"nodeType\": \"action\",
        \"label\": \"AI Chat\",
        \"is_start\": false,
        \"action_type\": \"ai_chat\",
        \"config\": {
          \"greeting\": \"[Inviting message, e.g. Of course! I am happy to answer any questions about our services. What would you like to know?]\",
          \"placeholder\": \"Type your question...\",
          \"instructions\": \"\"
        }
      }
    }
  ],
  \"edges\": [
    {\"id\": \"edge_1\",  \"source\": \"node_1\",  \"target\": \"node_2\",  \"sourceHandle\": \"output-default\"},
    {\"id\": \"edge_2\",  \"source\": \"node_2\",  \"target\": \"node_3\",  \"sourceHandle\": \"group_1\"},
    {\"id\": \"edge_3\",  \"source\": \"node_2\",  \"target\": \"node_6\",  \"sourceHandle\": \"group_2\"},
    {\"id\": \"edge_4\",  \"source\": \"node_3\",  \"target\": \"node_4\",  \"sourceHandle\": \"output-default\"},
    {\"id\": \"edge_5\",  \"source\": \"node_4\",  \"target\": \"node_5\",  \"sourceHandle\": \"output-default\"},
    {\"id\": \"edge_6\",  \"source\": \"node_5\",  \"target\": \"node_9\",  \"sourceHandle\": \"group_1\"},
    {\"id\": \"edge_7\",  \"source\": \"node_5\",  \"target\": \"node_10\", \"sourceHandle\": \"group_2\"},
    {\"id\": \"edge_8\",  \"source\": \"node_6\",  \"target\": \"node_7\",  \"sourceHandle\": \"output-default\"},
    {\"id\": \"edge_9\",  \"source\": \"node_7\",  \"target\": \"node_8\",  \"sourceHandle\": \"output-default\"},
    {\"id\": \"edge_10\", \"source\": \"node_8\",  \"target\": \"node_9\",  \"sourceHandle\": \"group_1\"},
    {\"id\": \"edge_11\", \"source\": \"node_8\",  \"target\": \"node_10\", \"sourceHandle\": \"group_2\"},
    {\"id\": \"edge_12\", \"source\": \"node_10\", \"target\": \"node_9\",  \"sourceHandle\": \"output-default\"}
  ]
}

IMPORTANT: edge_12 above is required. It connects the AI Chat node back to the Capture Lead node. This powers a \"Connect with our team\" button that appears inside the AI chat — when the visitor clicks it, the flow advances from ai_chat to capture_lead so their contact details can be collected.

Now generate the complete JSON for {$site_name}. Replace every placeholder with real details from the website content above. Return only the JSON:";
    }

    /**
     * Strip markdown fences and extract valid JSON from AI response.
     * Returns validated array or null if unparseable/invalid.
     */
    private static function extract_and_validate_flow_json($raw) {
        // Strip markdown code fences
        $str = preg_replace('/```(?:json)?\s*/i', '', $raw);
        $str = preg_replace('/```/', '', $str);
        $str = trim($str);

        // Find outermost { ... } in case there's stray text
        $start = strpos($str, '{');
        $end   = strrpos($str, '}');
        if ($start === false || $end === false || $end <= $start) return null;

        $str  = substr($str, $start, $end - $start + 1);
        $data = json_decode($str, true);

        if (json_last_error() !== JSON_ERROR_NONE) return null;
        if (!isset($data['nodes']) || !is_array($data['nodes']) || empty($data['nodes'])) return null;
        if (!isset($data['edges']) || !is_array($data['edges'])) $data['edges'] = array();

        // Ensure required fields on each node and at least one start node
        $has_start = false;
        foreach ($data['nodes'] as &$node) {
            if (!isset($node['id'], $node['type'], $node['data']['nodeType'])) return null;
            if (!isset($node['position'])) $node['position'] = array('x' => 100, 'y' => 200);
            if (!empty($node['data']['is_start'])) $has_start = true;
        }
        unset($node);

        if (!$has_start) {
            $data['nodes'][0]['data']['is_start'] = true;
        }

        return array(
            'flow_name'        => isset($data['flow_name'])        ? sanitize_text_field($data['flow_name'])        : 'AI-Generated Flow',
            'business_summary' => isset($data['business_summary']) ? sanitize_text_field($data['business_summary']) : '',
            'nodes'            => $data['nodes'],
            'edges'            => $data['edges'],
        );
    }

    /**
     * Generic template flow used when AI generation fails.
     */
    private static function get_fallback_flow_template() {
        $site_name = get_bloginfo('name');
        return array(
            'flow_name'        => 'Lead Capture Flow',
            'business_summary' => '',
            'nodes' => array(
                array(
                    'id'       => 'node_1',
                    'type'     => 'custom',
                    'position' => array('x' => 100, 'y' => 200),
                    'data'     => array(
                        'nodeType'         => 'question',
                        'label'            => 'Welcome',
                        'is_start'         => true,
                        'message'          => "Hi! Welcome to {$site_name}. How can we help you today?",
                        'auto_continue'    => false,
                        'delay'            => 0,
                        'allow_text_input' => false,
                        'text_placeholder' => '',
                        'options'          => array(
                            array('id' => 'opt_1', 'label' => 'Learn About Services'),
                            array('id' => 'opt_2', 'label' => 'Schedule a Consultation'),
                            array('id' => 'opt_3', 'label' => 'Something Else'),
                        ),
                    ),
                ),
                array(
                    'id'       => 'node_2',
                    'type'     => 'custom',
                    'position' => array('x' => 420, 'y' => 200),
                    'data'     => array(
                        'nodeType'    => 'action',
                        'label'       => 'Capture Lead',
                        'is_start'    => false,
                        'action_type' => 'capture_lead',
                        'config'      => array(
                            'fields' => array(
                                'name'    => array('enabled' => true,  'label' => 'Your Name',     'required' => true),
                                'email'   => array('enabled' => true,  'label' => 'Email Address', 'required' => true),
                                'phone'   => array('enabled' => false, 'label' => 'Phone Number',  'required' => false),
                                'company' => array('enabled' => false, 'label' => 'Company',       'required' => false),
                            ),
                            'success_message' => "Thanks! We'll be in touch soon.",
                            'redirect_url'    => '',
                        ),
                    ),
                ),
                array(
                    'id'       => 'node_3',
                    'type'     => 'custom',
                    'position' => array('x' => 740, 'y' => 200),
                    'data'     => array(
                        'nodeType'    => 'action',
                        'label'       => 'AI Chat',
                        'is_start'    => false,
                        'action_type' => 'ai_chat',
                        'config'      => array(
                            'greeting'     => "Thanks for reaching out! Do you have any questions about {$site_name}?",
                            'placeholder'  => 'Type your question...',
                            'instructions' => '',
                        ),
                    ),
                ),
            ),
            'edges' => array(
                array('id' => 'edge_1', 'source' => 'node_1', 'target' => 'node_2', 'sourceHandle' => 'output-default'),
                array('id' => 'edge_2', 'source' => 'node_2', 'target' => 'node_3', 'sourceHandle' => 'output-default'),
            ),
        );
    }
}
