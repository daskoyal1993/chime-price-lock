<?php
/**
 * Dynamic funnel settings.
 * Values can be overridden with environment variables so credentials stay out of the code.
 * Local overrides: put FUNNEL_… values in .env (see .env.example) — loaded here, blocked from the web by .htaccess.
 */
require_once __DIR__ . '/lib/Env.php';
Env::load(__DIR__ . '/.env');

return [
    // Which design/content project to use: projects/<project>/ (site.json + one JSON per page).
    'project' => Env::get('FUNNEL_PROJECT', 'chime'),

    // Endpoint that returns the funnel definition ({ "success": true, "data": { ... } }).
    // Leave empty to use the local sample file below (sample_file).
    'api_url'     => Env::get('FUNNEL_API_URL', ''),
    'api_token'   => Env::get('FUNNEL_API_TOKEN', ''),   // sent as "Authorization: Bearer <token>" when set
    'api_timeout' => 10,                                  // seconds
    'cache_ttl'   => 300,                                 // seconds to reuse a fetched definition

    //'sample_file' => __DIR__ . '/data/updated-sample-funnel.json',
    //r4mf-v1-funnel.json
    'sample_file' => __DIR__ . '/data/r4mf-v1-funnel.json',
    'storage_dir' => __DIR__ . '/storage',

    // Which questionnaire set to render (slug). null = the first one in the response.
    'questionnaire_slug' => 'offer-intake',

    // Validation limits that the API does not provide.
    'min_age'    => 18,
    'weight_min' => 50,
    'weight_max' => 700,

    // Page flow. The visitor's query string (e.g. prospect_id) is kept on every step.
    'product_url'      => 'product.php',
    'checkout_url'     => 'checkout.php',
    'thank_you_url'    => 'thank-you.php',
    // After the card is authorized: Vouched verified → verified_url (process.php → patient portal), not verified → idcheck_url.
    'verified_url'     => 'process.php',
    'idcheck_url'      => 'idcheck.php',
    // ID check failed (like demo/usernotverified.php): this page, and the GHL contact gets ghl.failed_id_tag.
    'not_verified_url' => 'usernotverified.php',
    'disqualified_url' => '',   // empty = show the built-in disqualified screen

    // Lead storage (lib/LeadRepository.php, table from database/001_create_dynamic_remedy.sql).
    // Leave host empty to turn saving off — the funnel works the same without a database.
    'db' => [
        'host'   => Env::get('FUNNEL_DB_HOST', 'localhost'),
        'port'   => (int) Env::get('FUNNEL_DB_PORT', 3306),
        'name'   => Env::get('FUNNEL_DB_NAME', 'amerilean_dynamicdb'),
        'user'   => Env::get('FUNNEL_DB_USER', 'amerilean_dynamicuser'),
        'pass'   => Env::get('FUNNEL_DB_PASS', ';%_[Drt2uWui,]RJ'),
        'table'  => Env::get('FUNNEL_DB_TABLE', 'dynamic_remedy'),
        'source' => 'dynamic',   // stored in `source` on new rows
        'tags'   => '',          // stored in `tags` on new rows
    ],

    // Membership check (lib/Membership.php) — decides campaign_set vs membership_campaign_set.
    // member (true) → membership_campaign_set + membership_campaign_set_products; not a member (false) → campaign_set + products.
    // api_url empty = built-in sample (returns false for now). PLACEHOLDER until the real API exists.
    'membership' => [
        'api_url'   => Env::get('FUNNEL_MEMBERSHIP_API_URL', ''),   // e.g. http://localhost/dscript/sample-api/membership-check.php
        'api_token' => Env::get('FUNNEL_MEMBERSHIP_API_TOKEN', ''),
        'timeout'   => 10,
        'fallback'  => false,  // answer used when the API fails or before the email is known (false = campaign_set)
    ],

    // Email / phone verification when the contact screen is submitted (lib/XverifyClient.php, same API as demo/xverify.php).
    // Leave api_key empty to skip. Only the statuses in block_statuses stop the visitor; API errors never do.
    'xverify' => [
        'api_url'        => Env::get('FUNNEL_XVERIFY_API_URL', 'https://api.xverify.com/v2'),
        // TEMPORARY: key and domain copied from demo/xverify.php (NOTES-OPEN-ITEMS.md).
        'api_key'        => Env::get('FUNNEL_XVERIFY_API_KEY', '1024692-6A65809C'),
        'domain'         => Env::get('FUNNEL_XVERIFY_DOMAIN', 'amerilean.com'),
        'block_statuses' => ['invalid'],
        'timeout'        => 10,
    ],

    // QA error log (lib/ErrorLogger.php): every PHP / API / DB / JS error in storage/logs/qa-errors.log and the error_logs table
    // (database/004_create_error_logs.sql, optional). Test runs: ?qa_test=start … ?qa_test=end. Read: php tools/qa-errors.php, or qa-errors.php?key=…
    'qa_errors' => [
        'enabled'        => Env::get('FUNNEL_QA_ERRORS', '1') !== '0',
        'env'            => Env::get('FUNNEL_ENV', 'local'),   // local | staging | live
        'key'            => Env::get('FUNNEL_QA_KEY', ''),     // needed for the web viewer, and for ?qa_test=start on live
        'viewer_on_live' => Env::get('FUNNEL_QA_VIEWER_ON_LIVE', '0') === '1',
        'file'           => __DIR__ . '/storage/logs/qa-errors.log',
        'table'          => 'error_logs',
        'max_bytes'      => 5 * 1024 * 1024,   // then renamed to qa-errors-1.log
    ],

    // Progress tracking (lib/FlowTracker.php, tables from database/003_create_flow_tracking.sql), in the db above.
    // The last step each visitor reached; completed = checkout payment approved. Needs no DB of its own.
    'tracking' => [
        'enabled'      => true,
        'table'        => 'flow_tracking',
        'events_table' => 'flow_tracking_events',
        'cookie'       => 'fid',   // tracking id cookie (HttpOnly)
        'cookie_days'  => 365,
    ],

    // Where each lead step (MEMBERSHIP / DB / VRIO / GHL) is logged. storage/ is blocked from the web by .htaccess.
    'lead_log' => __DIR__ . '/storage/logs/lead-flow.log',

    // Vrio prospect creation (lib/VrioClient.php, same request as demo/create-prospect.php).
    // Leave api_key empty to skip Vrio. The campaign comes from the membership check (offer_funnel() in bootstrap.php):
    // member → membership_campaign_set, not a member → campaign_set.
    'vrio' => [
        'api_url'       => Env::get('FUNNEL_VRIO_API_URL', 'https://api.vrio.app/orders'),
        'api_key'       => Env::get('FUNNEL_VRIO_API_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6ImFkbWluIiwib3JnYW5pemF0aW9uIjoibWVkYXhpcy52cmlvIiwiaWQiOiIyNDQyNjNkMy1hNGI3LTQwOWQtYjBjOS1mMDhjMDMzYTVkNWQiLCJpYXQiOjE3ODIxNTIyNjgsImF1ZCI6InVybjp2cmlvOmFwaTp1c2VyIiwiaXNzIjoidXJuOnZyaW86YXBpOmF1dGhlbnRpY2F0b3IiLCJzdWIiOiJ1cm46dnJpbzphcGk6OSJ9.6NOCMu57KO64ry5Y8WftOmVQknfUOh0jpauuj8G4fBE'),         // ← your Vrio API key (sent as the X-Api-Key header)
        'connection_id' => Env::get('FUNNEL_VRIO_CONNECTION_ID', '1'),
        'shipping_profile_id' => Env::get('FUNNEL_VRIO_SHIPPING_PROFILE_ID', '1'),   // ← VRIO_SHIPPING_PROFILE_ID in the demo's vrio-config.php (pre-auth at checkout)   // ← your Vrio connection id
        'timeout'       => 30,
    ],

    // GoHighLevel contact create/update (lib/GhlClient.php, same API as demo/sample-create-contact.php).
    // Leave token empty to skip GHL. Custom field ids come from the funnel API (question → ghl);
    // field_map adds the sample's fields for questions the API has no id for.
    'ghl' => [
        'api_url'     => Env::get('FUNNEL_GHL_API_URL', 'https://services.leadconnectorhq.com'),
        'version'     => '2021-07-28',
        // TEMPORARY: token copied from demo/sample-create-contact.php; will come from the funnel JSON later (NOTES-OPEN-ITEMS.md).
        'token'       => Env::get('FUNNEL_GHL_TOKEN', 'pit-c395d6a9-f891-4898-8a90-e4ac12dd79e1'),
        'location_id' => Env::get('FUNNEL_GHL_LOCATION_ID', 'AeheBO22OOorfyaJeD8X'),
        'source'      => 'chime-dynamic API',
        'tags'        => ['lead'],   // added when the contact is created
        'failed_id_tag' => 'failed_id',   // added when the ID check fails (usernotverified.php, like the demo)
        'timeout'     => 30,
        // "<question>@date" / "<question>@time" = when that consent was ticked. An entry can be a list (one answer → several
        // fields) and may leave out the id: it is then looked up in GHL by key (GhlClient::fieldIds(), cached one day).
        'field_map'   => [
            'female_health_status' => ['id' => 'hcjkAqqtuXuWO1TVugNL', 'key' => 'contact.currently_breastfeeding_or_pregnant'],
            'heart_arrhythmia'     => ['id' => 'NS4aC2IJOSoSgM2jVmJL', 'key' => 'contact.do_you_now_or_have_you_ever_had_any_heart_arrhythmia_or_irregular_heartbeat'],
            'elderly_consent@date' => ['id' => 'CPJwLR4Evdmubvagk7vN', 'key' => 'contact.date_of_elderly_consent'],
            'elderly_consent@time' => ['id' => 'LEq5MGZDXLdB6dPaAJ5q', 'key' => 'contact.time_of_elderly_consent'],
            'sms_consent@date'     => ['id' => '6OLaeQuj06NPmgUadWOc', 'key' => 'contact.date_of_sms_consent'],
            'sms_consent@time'     => ['id' => '22jxJGSEV0VLvqdwPOCC', 'key' => 'contact.time_of_sms_consent'],
            // GLP consent: the date field comes from the funnel API, the time field from here.
            'glp_consent@time'     => ['key' => 'contact.time_of_glp_consent'],
            // BMI consent ("I acknowledge that with this BMI …").
            'metabolic_consent@date' => ['key' => 'contact.date_of_bmi_consent'],
            'metabolic_consent@time' => ['key' => 'contact.time_of_bmi_consent'],
            // "I agree to the Truthfulness Consent, Telehealth Consent & Informed Treatment Consent" — one tick, three consents.
            'contact_agree@date' => [
                ['key' => 'contact.date_of_truthfulness_consent'],
                ['key' => 'contact.date_of_telehealth_consent'],
                ['key' => 'contact.date_of_informed_consent'],
            ],
            'contact_agree@time' => [
                ['key' => 'contact.time_of_truthfulness_consent'],
                ['key' => 'contact.time_of_telehealth_consent'],
                ['key' => 'contact.time_of_informed_consent'],
            ],
        ],
        // Consent checkboxes whose first "ticked" server time is recorded (quiz JSON → consent_timestamps).
        'consent_questions' => ['elderly_consent', 'metabolic_consent', 'glp_consent', 'contact_agree', 'sms_consent'],
        'consent_timezone'  => 'UTC',
        'consent_date_format' => 'F j, Y',    // "September 24, 2026" — same as the live site's server-time.php
        'consent_time_format' => 'g:i A T',   // "2:49 PM UTC"
    ],

    // Google Places address autocomplete on checkout.php (assets/checkout.js). A browser key: it is visible in the page,
    // so restrict it in Google Cloud to your domains (and localhost for testing). Empty = no autocomplete.
    'google_maps' => [
        'api_key' => Env::get('FUNNEL_GOOGLE_MAPS_KEY', 'AIzaSyDxhi_ZmKg9x2XekssUSIt3--cDj-eI0H0'),
        'country' => 'us',   // suggestions limited to this country
    ],

    // Portal → Site Settings → Secret Key. Checks the signature of the credentials in the funnel JSON
    // (tenant.ghl.credentials, tenant.vouched — lib/EncodeDecodeHelper.php). Empty = they are read without the signature check.
    'tenant_secret_key' => Env::get('FUNNEL_TENANT_SECRET_KEY', ''),

    // Vouched identity check after payment (lib/VouchedClient.php, like demo/identity_verify.php) and the ID check page
    // (idcheck.php). api_key / app_id come from the funnel JSON (tenant.vouched) when it has them, else from here.
    'vouched' => [
        'api_url'        => Env::get('FUNNEL_VOUCHED_API_URL', 'https://verify.vouched.id/api'),
        'api_key'        => Env::get('FUNNEL_VOUCHED_API_KEY', ''),   // private key (prv.…), server side only
        'app_id'         => Env::get('FUNNEL_VOUCHED_APP_ID', ''),    // public key (pub.…) for the ID check plugin
        'min_confidence' => 80,                                       // crosscheck % needed (with a DOB match)
        'timeout'        => 20,                                       // seconds per call; checkout must answer within 120 s (Cloudflare)
    ],

    // Patient portal order hand-off on process.php (lib/PortalClient.php, like demo/process/process.php).
    // The payload is signed with tenant_secret_key above, so that must be set too.
    'portal' => [
        //'api_url'          => Env::get('FUNNEL_PORTAL_API_URL', 'https://chime-health.medaxis.io/api/offer/process'),
        'api_url'          => Env::get('FUNNEL_PORTAL_API_URL', 'https://chime-health.stagingtenant.axismd.io/api/offer/process'),
        'api_key'          => Env::get('FUNNEL_PORTAL_API_KEY', ''),   // X-API-Key (MEDAXIS_API_KEY in the demo)
        'source'           => 'chime-dynamic API',
        'product_category' => ['weightloss', 'antiaging'],             // as in the demo
        'timeout'          => 30,
    ],

    // Payment processing for checkout.php (see lib/Payment.php).
    //   'vrio' - card pre-authorization in Vrio on "Complete Purchase" (like demo/preauth.php) — REAL authorizations
    //   'none' - checkout validates the form but refuses to charge
    //   'test' - LOCAL TESTING ONLY: approves the test_card below without charging anything (with 'vrio' the test_card is sent to Vrio)
    'payment_driver' => Env::get('FUNNEL_PAYMENT_DRIVER', 'vrio'),

    // Card number the 'test' driver approves (spaces allowed). With payment_driver = test or vrio it also skips the
    // card-number checksum, so a made-up number like this one can be used ('vrio' sends it to Vrio, which decides).
    // Any other card is declined by the test driver. On a live site set FUNNEL_TEST_CARD='' in .env to turn it off.
    'test_card' => Env::get('FUNNEL_TEST_CARD', '4111 2222 3333 4444'),
];
