<?php
/**
 * Plugin Name: MP AI Content Generator
 * Description: Multi-modal AI editor assistant. Features Text, Image Generation, Web Search, Code assistance, Dynamic Model Syncing, and Image Analysis.
 * Version: 2.0.0
 * OriginAuthor: Mayank Pandya 
 * Requires at least: 5.8
 * License: GPL-2.0+
 * Requires PHP: 7.4
 * Text Domain: mp-ai-content-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'MP_AI_PLUGIN_DIR' ) ) {
    define( 'MP_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MP_AI_PLUGIN_URL' ) ) {
    define( 'MP_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Define Secret Key for Encryption if not in wp-config.php
if ( ! defined( 'MP_AI_SECRET_KEY' ) ) {
    define( 'MP_AI_SECRET_KEY', wp_salt( 'auth' ) );
}

$settings_page_path = MP_AI_PLUGIN_DIR . 'admin/settings-page.php';
if ( file_exists( $settings_page_path ) ) {
    require_once $settings_page_path;
}

// --- Encryption Functions ---

function mp_ai_encrypt_key( $data ) {
    if ( empty( $data ) ) return $data;
    $method = 'AES-256-CBC';
    $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
    $encrypted = openssl_encrypt( $data, $method, MP_AI_SECRET_KEY, 0, $iv );
    return base64_encode( $encrypted . '::' . $iv );
}

function mp_ai_decrypt_key( $data ) {
    if ( empty( $data ) ) return $data;

    // Check if it's potentially an encrypted string with our separator
    if ( strpos( $data, '::' ) === false ) {
        // Assume it's legacy plain text
        return $data;
    }

    // Try to decode
    $decoded = base64_decode( $data );
    if ( $decoded === false ) return $data; // fallback

    $parts = explode( '::', $decoded, 2 );
    if ( count( $parts ) !== 2 ) return $data; // fallback

    list( $encrypted_data, $iv ) = $parts;
    $method = 'AES-256-CBC';
    $decrypted = openssl_decrypt( $encrypted_data, $method, MP_AI_SECRET_KEY, 0, $iv );

    // If decryption fails (e.g., key changed, or invalid data), return original
    return $decrypted === false ? $data : $decrypted;
}

// --- Enqueue Assets ---
function mp_ai_plugin_enqueue_editor_assets( $hook_suffix ) {
    if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
        return;
    }

    // Enqueue Media Uploader scripts
    wp_enqueue_media();

    // Legacy Script
    wp_enqueue_script(
        'mp-ai-editor-script',
        MP_AI_PLUGIN_URL . 'assets/js/editor-integration.js',
        array( 'jquery', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-data' ),
        '1.6.0',
        true
    );

    wp_localize_script(
        'mp-ai-editor-script',
        'mpAiPluginData',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mp_ai_plugin_nonce' ),
        )
    );

    wp_enqueue_style(
        'mp-ai-editor-style',
        MP_AI_PLUGIN_URL . 'assets/css/editor-styles.css',
        array(),
        '1.6.0'
    );

    // NEW: React Sidebar Script
    $build_path = MP_AI_PLUGIN_DIR . 'build/index.js';
    $build_url  = MP_AI_PLUGIN_URL . 'build/index.js';
    $asset_file = MP_AI_PLUGIN_DIR . 'build/index.asset.php';

    if ( file_exists( $build_path ) && file_exists( $asset_file ) ) {
        $assets = require $asset_file;
        wp_enqueue_script(
            'mp-ai-sidebar-script',
            $build_url,
            $assets['dependencies'],
            $assets['version'],
            true
        );

        wp_enqueue_style(
            'mp-ai-sidebar-style',
            MP_AI_PLUGIN_URL . 'build/index.css',
            array(),
            $assets['version']
        );

        wp_localize_script(
            'mp-ai-sidebar-script',
            'mpAiSettings',
            array(
                'root'  => esc_url_raw( rest_url( 'mp-ai/v1' ) ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'user_models' => mp_ai_get_available_models(),
            )
        );
    }
}
add_action( 'admin_enqueue_scripts', 'mp_ai_plugin_enqueue_editor_assets' );

function mp_ai_get_available_models() {
    // This could be dynamic, but for now hardcoded based on snippet + OpenAI options
    return [
        ['label' => 'Gemini 1.5 Flash', 'value' => 'gemini-1.5-flash'],
        ['label' => 'Gemini 1.5 Pro', 'value' => 'gemini-1.5-pro'],
        ['label' => 'GPT-4o', 'value' => 'gpt-4o'],
        ['label' => 'GPT-4', 'value' => 'gpt-4'],
        ['label' => 'DALL-E 3 (Image)', 'value' => 'dall-e-3'],
        ['label' => 'Imagen 3 (Image)', 'value' => 'imagen-3.0-generate-001'],
    ];
}

// --- User Profile ---
function mp_ai_plugin_user_profile_fields( $user ) {
    $uid = $user->ID;
    $raw_key = get_user_meta( $uid, 'mp_ai_user_api_key', true );
    $decrypted_key = mp_ai_decrypt_key( $raw_key );

    $raw_search_key = get_user_meta( $uid, 'mp_ai_search_api_key', true );
    $decrypted_search_key = mp_ai_decrypt_key( $raw_search_key );

    $search_cx = get_user_meta( $uid, 'mp_ai_search_cx', true );
    ?>
    <h3><?php esc_html_e( 'AI Content Generator Settings (v2.0)', 'mp-ai-content-generator' ); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="mp_ai_user_api_key">AI Model API Key</label></th>
            <td>
                <input type="password" name="mp_ai_user_api_key" id="mp_ai_user_api_key" value="<?php echo esc_attr( $decrypted_key ); ?>" class="regular-text" />
                <p class="description">Google Gemini or OpenAI API Key. (Encrypted)</p>
            </td>
        </tr>
        <tr>
            <th><label for="mp_ai_search_api_key">Google Search API Key</label></th>
            <td>
                <input type="password" name="mp_ai_search_api_key" id="mp_ai_search_api_key" value="<?php echo esc_attr( $decrypted_search_key ); ?>" class="regular-text" />
                <p class="description">Google Custom Search API Key. (Encrypted)</p>
            </td>
        </tr>
        <tr>
            <th><label for="mp_ai_search_cx">Search Engine ID (CX)</label></th>
            <td>
                <input type="text" name="mp_ai_search_cx" id="mp_ai_search_cx" value="<?php echo esc_attr( $search_cx ); ?>" class="regular-text" />
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'mp_ai_plugin_user_profile_fields' );
add_action( 'edit_user_profile', 'mp_ai_plugin_user_profile_fields' );

function mp_ai_plugin_save_user_profile_fields( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) return false;

    if ( isset( $_POST['mp_ai_user_api_key'] ) ) {
        $encrypted = mp_ai_encrypt_key( sanitize_text_field( $_POST['mp_ai_user_api_key'] ) );
        update_user_meta( $user_id, 'mp_ai_user_api_key', $encrypted );
    }

    if ( isset( $_POST['mp_ai_search_api_key'] ) ) {
        $encrypted = mp_ai_encrypt_key( sanitize_text_field( $_POST['mp_ai_search_api_key'] ) );
        update_user_meta( $user_id, 'mp_ai_search_api_key', $encrypted );
    }

    if ( isset( $_POST['mp_ai_search_cx'] ) ) {
        // CX is usually not secret, but can encrypt if desired. keeping plain for now based on context.
        update_user_meta( $user_id, 'mp_ai_search_cx', sanitize_text_field( $_POST['mp_ai_search_cx'] ) );
    }
}
add_action( 'personal_options_update', 'mp_ai_plugin_save_user_profile_fields' );
add_action( 'edit_user_profile_update', 'mp_ai_plugin_save_user_profile_fields' );

// --- Meta Box ---
function mp_ai_plugin_add_meta_box() {
    add_meta_box( 'mp_ai_content_box', __( 'AI Content Studio (Legacy)', 'mp-ai-content-generator' ), 'mp_ai_plugin_meta_box_callback', array( 'post', 'page' ), 'side', 'default' );
}
add_action( 'add_meta_boxes', 'mp_ai_plugin_add_meta_box' );

function mp_ai_plugin_meta_box_callback( $post ) {
    wp_nonce_field( 'mp_ai_plugin_generate_content', 'mp_ai_plugin_generate_content_nonce' );
    $uid = get_current_user_id();
    $has_ai_key = !empty(get_user_meta( $uid, 'mp_ai_user_api_key', true ));
    $has_search_key = !empty(get_user_meta( $uid, 'mp_ai_search_api_key', true )) && !empty(get_user_meta( $uid, 'mp_ai_search_cx', true ));
    ?>
    <div class="mp-ai-controls">
        <?php if ( !$has_ai_key ) : ?>
            <div class="mp-ai-alert"><strong>Setup:</strong> Add API Key in <a href="<?php echo esc_url( get_edit_profile_url( $uid ) . '#mp_ai_user_api_key' ); ?>" target="_blank">Profile</a>.</div>
        <?php endif; ?>

        <!-- Model Selector -->
        <div class="mp-ai-row" style="margin-bottom:10px; display:flex; gap:5px; align-items:center;">
            <select id="mp_ai_model_select" style="flex-grow:1; max-width:85%; font-size:12px;" <?php disabled( !$has_ai_key ); ?>>
                <optgroup label="Defaults">
                    <option value="gemini-1.5-flash" selected>Gemini 1.5 Flash</option>
                </optgroup>
            </select>
            <button type="button" id="mp_ai_refresh_models" class="button" title="Sync Models" <?php disabled( !$has_ai_key ); ?>>
                <span class="dashicons dashicons-update"></span>
            </button>
        </div>

        <!-- Toolbar -->
        <div class="mp-ai-toolbar">
            <label title="Web Search" class="mp-tool-btn <?php echo $has_search_key ? '' : 'disabled'; ?>">
                <input type="checkbox" id="mp_tool_web" <?php disabled(!$has_search_key); ?>> 
                <span class="dashicons dashicons-search"></span>
            </label>
            <label title="Generate Image" class="mp-tool-btn">
                <input type="radio" name="mp_tool_mode" value="image"> 
                <span class="dashicons dashicons-format-image"></span>
            </label>
            <label title="Code Mode" class="mp-tool-btn">
                <input type="radio" name="mp_tool_mode" value="code"> 
                <span class="dashicons dashicons-editor-code"></span>
            </label>
            <label title="Standard Text" class="mp-tool-btn active">
                <input type="radio" name="mp_tool_mode" value="text" checked> 
                <span class="dashicons dashicons-text"></span>
            </label>
        </div>

        <textarea id="mp_ai_prompt" name="mp_ai_prompt" rows="4" placeholder="<?php esc_attr_e('Prompt...', 'mp-ai-content-generator'); ?>" <?php disabled( !$has_ai_key ); ?>></textarea>
        
        <!-- NEW: Image Upload Area -->
        <div id="mp_ai_image_context_area" style="margin-top:5px; padding:5px; border:1px dashed #ccc; border-radius:4px; text-align:center; display:none;">
            <input type="hidden" id="mp_ai_context_image_id" name="mp_ai_context_image_id" value="">
            <div id="mp_ai_context_image_preview" style="display:none; margin-bottom:5px;"></div>
            <button type="button" id="mp_ai_select_image_btn" class="button button-small">Select Image Context</button>
            <button type="button" id="mp_ai_clear_image_btn" class="button button-small" style="display:none; color:#a00;">Remove</button>
        </div>

        <input type="url" id="mp_ai_ref_url" placeholder="Reference URL (Optional)" style="width:100%; margin-top:5px;" <?php disabled( !$has_ai_key ); ?>>

        <button type="button" id="mp_ai_generate_button" class="button button-primary button-large" <?php disabled( !$has_ai_key ); ?>>Generate</button>
        
        <div id="mp_ai_loading_indicator" style="display:none;">
            <span class="spinner is-active"></span> <span id="mp_ai_loading_text">Thinking...</span>
        </div>
        <div id="mp_ai_message_area" style="display:none;"></div>
    </div>
    <?php
}

// --- AJAX: List Models ---
function mp_ai_plugin_list_models_ajax() {
    check_ajax_referer( 'mp_ai_plugin_nonce', 'nonce' );
    $uid = get_current_user_id();
    $raw_key = get_user_meta( $uid, 'mp_ai_user_api_key', true );
    $api_key = mp_ai_decrypt_key( $raw_key ); // DECRYPTED

    $provider = get_option( 'mp_ai_plugin_ai_model', 'gemini' );

    if ( empty( $api_key ) ) wp_send_json_error( 'Missing API Key' );

    $models = [];
    if ( $provider === 'gemini' ) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=$api_key";
        $res = wp_remote_get($url, ['timeout' => 15]);
        if ( is_wp_error( $res ) ) wp_send_json_error( $res->get_error_message() );
        
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( !empty($body['models']) ) {
            foreach ( $body['models'] as $m ) {
                $methods = $m['supportedGenerationMethods'] ?? [];
                // Check if it supports generation or image creation
                $can_image = in_array('predict', $methods) || in_array('generateImage', $methods) || strpos($m['name'], 'imagen') !== false;
                $can_text = in_array('generateContent', $methods);
                $id = str_replace('models/', '', $m['name']);
                
                // Only add models that can actually do something useful
                if ($can_image || $can_text) {
                    $models[] = [
                        'id' => $id,
                        'name' => $m['displayName'] ?? $id,
                        'type' => $can_image ? 'image' : 'text'
                    ];
                }
            }
        }
    } else {
        $common_openai = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4o', 'dall-e-3', 'dall-e-2'];
        foreach($common_openai as $m) $models[] = ['id' => $m, 'name' => ucfirst($m), 'type' => strpos($m, 'dall-e') !== false ? 'image' : 'text'];
    }
    wp_send_json_success( $models );
}
add_action( 'wp_ajax_mp_ai_list_models', 'mp_ai_plugin_list_models_ajax' );

// --- AJAX: Generate ---
function mp_ai_plugin_generate_content_ajax() {
    check_ajax_referer( 'mp_ai_plugin_nonce', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );

    $prompt = sanitize_textarea_field( $_POST['prompt'] ?? '' );
    $ref_url = esc_url_raw( $_POST['ref_url'] ?? '' );
    $tool_mode = sanitize_text_field( $_POST['tool_mode'] ?? 'text' );
    $selected_model = sanitize_text_field( $_POST['model'] ?? '' );
    $use_web_search = filter_var( $_POST['use_web_search'] ?? false, FILTER_VALIDATE_BOOLEAN );
    $image_context_id = intval( $_POST['context_image_id'] ?? 0 );

    if ( empty( $prompt ) ) wp_send_json_error( 'Prompt empty.' );

    $uid = get_current_user_id();
    $raw_key = get_user_meta( $uid, 'mp_ai_user_api_key', true );
    $api_key = mp_ai_decrypt_key( $raw_key ); // DECRYPTED

    if ( empty( $api_key ) ) wp_send_json_error( 'Missing AI API Key.' );

    $ai_provider = get_option( 'mp_ai_plugin_ai_model', 'gemini' );

    // --- MODE 1: IMAGE GENERATION (Text -> Image) ---
    if ( $tool_mode === 'image' ) {
        try {
            $img_data = null;
            if ( $ai_provider === 'gemini' ) {
                // FALLBACK FIX: If model is invalid or not selected, default to a safe bet.
                $model = (strpos($selected_model, 'imagen') !== false) ? $selected_model : 'imagen-3.0-generate-001';
                $img_data = mp_ai_generate_image_gemini( $prompt, $api_key, $model );
            } else {
                $model = (strpos($selected_model, 'dall-e') !== false) ? $selected_model : 'dall-e-3';
                $img_data = mp_ai_generate_image_openai( $prompt, $api_key, $model );
            }
            
            $upload_result = mp_ai_upload_base64_image( $img_data['base64'], $img_data['mime'], $prompt );
            if ( is_wp_error( $upload_result ) ) throw new Exception( $upload_result->get_error_message() );

            wp_send_json_success([
                'type' => 'image',
                'media_id' => $upload_result['id'],
                'url' => $upload_result['url'],
                'alt' => $prompt
            ]);
        } catch ( Exception $e ) {
            wp_send_json_error( 'Image Gen Error: ' . $e->getMessage() );
        }
        return;
    }

    // --- MODE 2: TEXT/CODE (Text+Image -> Text) ---
    $context = "";
    
    // Web Search
    if ( $use_web_search ) {
        $raw_s_key = get_user_meta( $uid, 'mp_ai_search_api_key', true );
        $s_key = mp_ai_decrypt_key( $raw_s_key ); // DECRYPTED

        $s_cx = get_user_meta( $uid, 'mp_ai_search_cx', true );
        if ( $s_key && $s_cx ) {
            $search_res = mp_ai_google_search( $prompt, $s_key, $s_cx );
            if ( ! is_wp_error( $search_res ) ) $context .= "\n\n[WEB SEARCH RESULTS]:\n" . $search_res;
        }
    }

    // URL Scraper
    if ( ! empty( $ref_url ) ) {
        $scraped = mp_ai_scrape_url( $ref_url );
        if ( ! is_wp_error( $scraped ) ) $context .= "\n\n[CONTEXT from $ref_url]:\n" . $scraped['content'];
    }

    $cats = get_categories(['hide_empty'=>false]);
    $cat_names = wp_list_pluck($cats, 'name');
    $context .= "\n\n[SITE INFO]: Categories: " . implode(', ', $cat_names);

    $sys_instruction = ($tool_mode === 'code') 
        ? "You are an expert developer. Provide ONLY the code requested." 
        : "Format response in Markdown (## H2, ### H3, **Bold**, - Lists).";

    $final_prompt = $prompt . $context . "\n\n[INSTRUCTION]: " . $sys_instruction;

    // Fetch Image Context if present
    $image_base64 = null;
    $image_mime = null;
    if ( $image_context_id > 0 ) {
        $img_info = mp_ai_get_image_base64( $image_context_id );
        if ( ! is_wp_error( $img_info ) ) {
            $image_base64 = $img_info['base64'];
            $image_mime = $img_info['mime'];
        }
    }

    try {
        $response_text = "";
        if ( $ai_provider === 'gemini' ) {
            // Ensure model handles vision if image provided, usually 1.5-flash or 1.5-pro
            $model = !empty($selected_model) ? $selected_model : 'gemini-1.5-flash';
            $response_text = mp_ai_call_gemini_text( $final_prompt, $api_key, $model, $image_base64, $image_mime );
        } else {
            $model = !empty($selected_model) ? $selected_model : 'gpt-4o'; // GPT-4o supports vision
            $response_text = mp_ai_call_openai_text( $final_prompt, $api_key, $model, $image_base64, $image_mime );
        }
        
        wp_send_json_success([
            'type' => $tool_mode === 'code' ? 'code' : 'text',
            'content' => $response_text
        ]);

    } catch ( Exception $e ) {
        wp_send_json_error( $e->getMessage() );
    }
}
add_action( 'wp_ajax_mp_ai_plugin_generate_content', 'mp_ai_plugin_generate_content_ajax' );

// --- REST API & Streaming Endpoint ---

add_action( 'rest_api_init', function () {
    register_rest_route( 'mp-ai/v1', '/generate-stream', [
        'methods' => 'POST',
        'callback' => 'mp_ai_handle_stream',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ]);

    register_rest_route( 'mp-ai/v1', '/generate-image', [
        'methods' => 'POST',
        'callback' => 'mp_ai_handle_rest_image',
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
    ]);
});

// REST Image Generation Handler (Wrapper around existing logic)
function mp_ai_handle_rest_image( $request ) {
    $params = $request->get_json_params();
    $prompt = sanitize_textarea_field( $params['prompt'] ?? '' );
    $model  = sanitize_text_field( $params['model'] ?? 'dall-e-3' );

    $uid = get_current_user_id();
    $raw_key = get_user_meta( $uid, 'mp_ai_user_api_key', true );
    $api_key = mp_ai_decrypt_key( $raw_key );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_key', 'API Key missing', ['status' => 403] );
    }

    try {
        $img_data = null;
        // Determine provider by model name approximation
        if ( strpos( $model, 'dall-e' ) !== false ) {
            $img_data = mp_ai_generate_image_openai( $prompt, $api_key, $model );
        } else {
            // Assume gemini/imagen
            $img_data = mp_ai_generate_image_gemini( $prompt, $api_key, $model );
        }

        $upload_result = mp_ai_upload_base64_image( $img_data['base64'], $img_data['mime'], $prompt );
        if ( is_wp_error( $upload_result ) ) throw new Exception( $upload_result->get_error_message() );

        return new WP_REST_Response([
            'type' => 'image',
            'media_id' => $upload_result['id'],
            'url' => $upload_result['url'],
            'alt' => $prompt
        ], 200);

    } catch ( Exception $e ) {
        return new WP_Error( 'gen_error', $e->getMessage(), ['status' => 500] );
    }
}

function mp_ai_handle_stream( $request ) {
    $params = $request->get_json_params();
    $prompt = sanitize_textarea_field( $params['prompt'] ?? '' );
    $model = sanitize_text_field( $params['model'] ?? 'gemini-1.5-flash' );

    $use_web_search = !empty($params['use_web_search']);
    $ref_url = esc_url_raw( $params['ref_url'] ?? '' );
    $image_context_id = intval( $params['context_image_id'] ?? 0 );

    $uid = get_current_user_id();
    $api_key = mp_ai_decrypt_key( get_user_meta( $uid, 'mp_ai_user_api_key', true ) );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_key', 'API Key missing', ['status' => 403] );
    }

    // --- Build Context (Copied from AJAX handler) ---
    $context = "";

    // Web Search
    if ( $use_web_search ) {
        $s_key = mp_ai_decrypt_key( get_user_meta( $uid, 'mp_ai_search_api_key', true ) );
        $s_cx = get_user_meta( $uid, 'mp_ai_search_cx', true );
        if ( $s_key && $s_cx ) {
            $search_res = mp_ai_google_search( $prompt, $s_key, $s_cx );
            if ( ! is_wp_error( $search_res ) ) $context .= "\n\n[WEB SEARCH RESULTS]:\n" . $search_res;
        }
    }

    // URL Scraper
    if ( ! empty( $ref_url ) ) {
        $scraped = mp_ai_scrape_url( $ref_url );
        if ( ! is_wp_error( $scraped ) ) $context .= "\n\n[CONTEXT from $ref_url]:\n" . $scraped['content'];
    }

    $final_prompt = $prompt . $context . "\n\n[INSTRUCTION]: Format response in Markdown.";

    // Image Context
    $image_base64 = null;
    $image_mime = null;
    if ( $image_context_id > 0 ) {
        $img_info = mp_ai_get_image_base64( $image_context_id );
        if ( ! is_wp_error( $img_info ) ) {
            $image_base64 = $img_info['base64'];
            $image_mime = $img_info['mime'];
        }
    }

    // --- Start SSE Stream ---
    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    header( 'Connection: keep-alive' );
    header( 'X-Accel-Buffering: no' ); // Nginx specific

    // Flush initial headers
    if ( function_exists( 'apache_setenv' ) ) apache_setenv( 'no-gzip', 1 );
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    ob_implicit_flush( true );
    while ( ob_get_level() > 0 ) ob_end_flush();

    // Determine provider based on model name
    $is_openai = strpos($model, 'gpt') !== false;

    if ( $is_openai ) {
        $url = "https://api.openai.com/v1/chat/completions";

        $messages = [['role' => 'user', 'content' => $final_prompt]];
        if ( $image_base64 && $image_mime ) {
            // OpenAI Image format (using standard chat completions with vision if supported)
             $messages[0]['content'] = [
                ['type' => 'text', 'text' => $final_prompt],
                ['type' => 'image_url', 'image_url' => ['url' => "data:$image_mime;base64,$image_base64"]]
             ];
        }

        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true
        ]);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ];
    } else {
        // Gemini
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:streamGenerateContent?key=$api_key";

        $parts = [['text' => $final_prompt]];
        if ( $image_base64 && $image_mime ) {
            array_unshift($parts, [
                'inlineData' => [
                    'mimeType' => $image_mime,
                    'data'     => $image_base64
                ]
            ]);
        }

        $body = json_encode([
            'contents' => [['parts' => $parts]]
        ]);

        $headers = ['Content-Type: application/json'];
    }

    // We use cURL directly for streaming support
    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_POST, true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) use ($is_openai) {
        if ( $is_openai ) {
            // OpenAI Format: data: {"id":..., "choices":[{ "delta": {"content": "..."} }]}
            // OpenAI sends data prefixed with "data: " and ends with "data: [DONE]"

            // We need to pass through the chunks but normalize the content extraction for the frontend.
            // Our frontend expects: data: {"text": "chunk"}

            // Note: $data might contain multiple lines or partial lines.
            // For robustness, we should buffer. But for this simplified impl, we process what we can.

            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') continue;

                    $json = json_decode($json_str, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        echo "data: " . json_encode(['text' => $chunk]) . "\n\n";
                        flush();
                    }
                }
            }
        } else {
            // Gemini Format
            $clean_data = trim( $data, "[],\n\r" );

            if ( !empty( $clean_data ) ) {
                $json = json_decode( $clean_data, true );
                if ( isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
                    $text_chunk = $json['candidates'][0]['content']['parts'][0]['text'];
                    echo "data: " . json_encode(['text' => $text_chunk]) . "\n\n";
                    flush();
                }
            }
        }
        return strlen( $data );
    });

    curl_exec( $ch );
    curl_close( $ch );

    echo "data: [DONE]\n\n";
    flush();
    exit; // Terminate WP execution
}

// --- HELPER FUNCTIONS ---

function mp_ai_get_image_base64( $media_id ) {
    $path = get_attached_file( $media_id );
    if ( ! file_exists( $path ) ) return new WP_Error( 'file_not_found', 'Image file missing.' );
    
    $type = get_post_mime_type( $media_id );
    $data = file_get_contents( $path );
    return [
        'base64' => base64_encode( $data ),
        'mime'   => $type
    ];
}

function mp_ai_call_gemini_text($prompt, $key, $model, $img_base64 = null, $img_mime = null) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$key";
    
    $parts = [['text' => $prompt]];
    
    // Add Image to Payload if present
    if ( $img_base64 && $img_mime ) {
        array_unshift($parts, [
            'inlineData' => [
                'mimeType' => $img_mime,
                'data'     => $img_base64
            ]
        ]);
    }

    $body = json_encode(['contents' => [['role' => 'user', 'parts' => $parts]]]);
    $res = wp_remote_post($url, ['body' => $body, 'headers' => ['Content-Type'=>'application/json'], 'timeout'=>60]);
    if(is_wp_error($res)) throw new Exception($res->get_error_message());
    $d = json_decode(wp_remote_retrieve_body($res), true);
    return $d['candidates'][0]['content']['parts'][0]['text'] ?? 'Error: ' . ($d['error']['message'] ?? 'Unknown');
}

function mp_ai_call_openai_text($prompt, $key, $model, $img_base64 = null, $img_mime = null) {
    $url = "https://api.openai.com/v1/chat/completions";
    
    $content = [['type' => 'text', 'text' => $prompt]];
    
    if ( $img_base64 && $img_mime ) {
        $content[] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:$img_mime;base64,$img_base64"
            ]
        ];
    }

    $body = json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => $content]]]);
    $res = wp_remote_post($url, ['body' => $body, 'headers' => ['Content-Type'=>'application/json', 'Authorization'=>"Bearer $key"], 'timeout'=>60]);
    if(is_wp_error($res)) throw new Exception($res->get_error_message());
    $d = json_decode(wp_remote_retrieve_body($res), true);
    return $d['choices'][0]['message']['content'] ?? 'Error';
}

// ... (Existing Google Search, Image Gen, Upload functions remain same) ...
function mp_ai_google_search($query, $key, $cx) {
    $url = "https://www.googleapis.com/customsearch/v1?key=$key&cx=$cx&q=" . urlencode($query);
    $res = wp_remote_get($url);
    if(is_wp_error($res)) return $res;
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if(!isset($body['items'])) return "No results.";
    $out = "";
    foreach(array_slice($body['items'], 0, 4) as $item) $out .= "- " . $item['title'] . ": " . $item['snippet'] . "\n";
    return $out;
}

function mp_ai_generate_image_gemini($prompt, $key, $model) {
    // Force predict endpoint
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:predict?key=" . $key;
    $body = ['instances' => [['prompt' => $prompt]], 'parameters' => ['sampleCount' => 1]];
    $res = wp_remote_post($url, ['body' => json_encode($body), 'headers' => ['Content-Type'=>'application/json'], 'timeout' => 60]);
    
    if(is_wp_error($res)) throw new Exception($res->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($res), true);
    
    if(isset($data['predictions'][0]['bytesBase64Encoded'])) {
        return ['base64' => $data['predictions'][0]['bytesBase64Encoded'], 'mime' => 'image/png'];
    }
    if(isset($data['error']['message'])) throw new Exception($data['error']['message']);
    throw new Exception("Model $model failed.");
}

function mp_ai_generate_image_openai($prompt, $key, $model) {
    $url = "https://api.openai.com/v1/images/generations";
    $body = ['model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024', 'response_format' => 'b64_json'];
    $res = wp_remote_post($url, ['body' => json_encode($body), 'headers' => ['Content-Type'=>'application/json', 'Authorization'=>"Bearer $key"], 'timeout' => 60]);
    if(is_wp_error($res)) throw new Exception($res->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if(isset($data['data'][0]['b64_json'])) return ['base64' => $data['data'][0]['b64_json'], 'mime' => 'image/png'];
    throw new Exception($data['error']['message'] ?? 'Unknown Image Error');
}

function mp_ai_upload_base64_image($base64, $mime, $prompt) {
    $binary = base64_decode($base64);
    $upload_dir = wp_upload_dir();
    $filename = 'ai-gen-' . time() . '.png';
    $file_path = $upload_dir['path'] . '/' . $filename;
    file_put_contents($file_path, $binary);
    $attachment = ['post_mime_type' => $mime, 'post_title' => $prompt, 'post_content' => '', 'post_status' => 'inherit'];
    $attach_id = wp_insert_attachment($attachment, $file_path);
    if(is_wp_error($attach_id)) return $attach_id;
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    return ['id' => $attach_id, 'url' => wp_get_attachment_url($attach_id)];
}

function mp_ai_scrape_url($url) {
    $res = wp_remote_get($url, ['timeout'=>15]);
    if(is_wp_error($res) || empty(wp_remote_retrieve_body($res))) return new WP_Error('err', 'Failed');
    $dom = new DOMDocument(); @$dom->loadHTML(wp_remote_retrieve_body($res));
    foreach($dom->getElementsByTagName('script') as $s) $s->parentNode->removeChild($s);
    foreach($dom->getElementsByTagName('style') as $s) $s->parentNode->removeChild($s);
    return ['title'=>'Ref', 'content'=>trim(substr($dom->textContent, 0, 5000))];
}
