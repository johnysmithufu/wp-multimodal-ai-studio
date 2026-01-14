export async function apiFetch(path, options = {}) {
    const { headers = {}, ...rest } = options;

    // Add Nonce if available
    if ( window.mpAiSettings && window.mpAiSettings.nonce ) {
        headers['X-WP-Nonce'] = window.mpAiSettings.nonce;
    }

    headers['Content-Type'] = 'application/json';

    const response = await fetch( path, {
        headers,
        ...rest
    });

    return response;
}
