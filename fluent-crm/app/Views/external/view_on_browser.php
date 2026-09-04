<?php
/*
 * @var array         $business
 * @var string        $email_heading
 * @var \FluentCrm\App\Models\CampaignEmail|null $email
 * @var array|string  $email_body  Either ['rendered' => $html] or a plain HTML string (error states).
 * @var array         $cssAssets
 */

/*
 * Pass the email payload and renderer through WordPress's script loader instead
 * of emitting a hardcoded inline <script>. Both the email payload and the
 * renderer go via wp_add_inline_script (see below for why not wp_localize_script
 * for the payload), attached to the DOMPurify handle — so a site owner's
 * CSP/nonce plugin can filter the tags (the page no longer requires
 * `script-src 'unsafe-inline'`) and there's nothing to ship under assets/,
 * which the build wipes and regenerates from resources/.
 */
foreach ((array) $cssAssets as $cssIndex => $cssAsset) {
    // Version is already baked into the URL as a query arg, so pass null for $ver.
    wp_enqueue_style('fluentcrm_view_on_browser_' . $cssIndex, $cssAsset, [], null);
}

// DOMPurify ships from node_modules via the vite copy step, so the cache buster
// is derived from the built file itself. A hardcoded version string would go
// stale on a dependency bump and keep serving a cached, outdated sanitizer.
$domPurifyAsset = FLUENTCRM_PLUGIN_PATH . 'assets/libs/purify/purify.min.js';
$domPurifyVersion = file_exists($domPurifyAsset) ? (string) filemtime($domPurifyAsset) : FLUENTCRM_PLUGIN_VERSION;
wp_enqueue_script('fluentcrm_dompurify', fluentCrmMix('libs/purify/purify.min.js'), [], $domPurifyVersion, true);

// Normalize the body shape: callers pass either ['rendered' => $html] (success)
// or a plain HTML string (error states). The renderer always expects `rendered`.
if (is_array($email_body)) {
    $renderedBody = isset($email_body['rendered']) ? $email_body['rendered'] : '';
} else {
    $renderedBody = (string) $email_body;
}

// Email payload as window.fluentCrmEmail, printed BEFORE DOMPurify's tag.
// Deliberately NOT wp_localize_script: that runs html_entity_decode() on the
// value (WP_Scripts::localize), which would turn escaped entities in the email
// body (e.g. `&lt;style&gt;`) into live HTML before DOMPurify ever sees it.
// wp_add_inline_script preserves the payload verbatim; the JSON_HEX_* flags
// neutralize any `</script>` breakout from the inline script context.
$fcEmailData = wp_json_encode(
    ['rendered' => $renderedBody],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
wp_add_inline_script('fluentcrm_dompurify', 'window.fluentCrmEmail = ' . $fcEmailData . ';', 'before');

// Renderer: sanitize the email document and mount it inside a closed shadow root
// so its styles stay isolated from (and can't leak into) the surrounding page.
// Runs after DOMPurify loads (default 'after' position).
$fcViewOnBrowserScript = <<<'JS'
(function () {
    var data = window.fluentCrmEmail;
    if (!data || !data.rendered) {
        return;
    }
    var host = document.getElementById('fluent_email_body');
    if (!host || typeof host.attachShadow !== 'function' || typeof window.DOMPurify === 'undefined') {
        return;
    }
    var parseDocument = function (html) {
        if (typeof window.DOMParser !== 'function') {
            return null;
        }
        return (new window.DOMParser()).parseFromString(html || '', 'text/html');
    };
    var hasRel = function (link, relName) {
        return (' ' + (link.getAttribute('rel') || '').toLowerCase() + ' ').indexOf(' ' + relName + ' ') !== -1;
    };
    var isSafeBunnyFontLink = function (link) {
        var href = link.getAttribute('href') || '';
        try {
            var url = new URL(href, window.location.href);
            if (url.protocol !== 'https:' || url.hostname !== 'fonts.bunny.net') {
                return false;
            }
            return (hasRel(link, 'stylesheet') && url.pathname.indexOf('/css') === 0) || hasRel(link, 'preconnect');
        } catch (e) {
            return false;
        }
    };
    var appendSafeFontLinks = function (sourceDocument) {
        if (!sourceDocument || !sourceDocument.head) {
            return;
        }
        Array.prototype.forEach.call(sourceDocument.head.querySelectorAll('link[href]'), function (link) {
            if (!isSafeBunnyFontLink(link)) {
                return;
            }
            var href = link.href;
            var rel = hasRel(link, 'preconnect') ? 'preconnect' : 'stylesheet';
            var alreadyLoaded = Array.prototype.some.call(
                document.head.querySelectorAll('link[data-fcrm-web-preview-font]'),
                function (existing) {
                    return existing.href === href && existing.rel === rel;
                }
            );
            if (alreadyLoaded) {
                return;
            }
            var fontLink = document.createElement('link');
            fontLink.setAttribute('data-fcrm-web-preview-font', '1');
            fontLink.rel = rel;
            fontLink.href = href;
            if (link.hasAttribute('crossorigin')) {
                fontLink.crossOrigin = 'anonymous';
            }
            document.head.appendChild(fontLink);
        });
    };
    var appendHeadStyles = function (sourceRoot, target) {
        var sourceHead = sourceRoot && sourceRoot.querySelector ? sourceRoot.querySelector('head') : null;
        if (!sourceHead) {
            return;
        }
        Array.prototype.forEach.call(sourceHead.querySelectorAll('style'), function (style) {
            target.appendChild(style.cloneNode(true));
        });
    };
    var appendBody = function (sourceRoot, target) {
        var sourceBody = sourceRoot && sourceRoot.querySelector ? sourceRoot.querySelector('body') : null;
        var body = document.createElement('body');
        body.className = 'fcrm_email_document' + (sourceBody && sourceBody.className ? ' ' + sourceBody.className : '');
        ['dir', 'lang', 'style'].forEach(function (attribute) {
            if (sourceBody && sourceBody.hasAttribute(attribute)) {
                body.setAttribute(attribute, sourceBody.getAttribute(attribute));
            }
        });
        Array.prototype.forEach.call(sourceBody ? sourceBody.childNodes : [], function (child) {
            body.appendChild(child.cloneNode(true));
        });
        target.appendChild(body);
    };
    appendSafeFontLinks(parseDocument(data.rendered));
    var cleanRoot = window.DOMPurify.sanitize(data.rendered, {
        WHOLE_DOCUMENT: true,
        RETURN_DOM: true,
        // svg/svgFilters are required: the Gutenberg social-icons block emits
        // inline <svg> into the rendered email body (GutenbergEmailParser).
        // mathMl is intentionally omitted — it never appears in email output.
        USE_PROFILES: { html: true, svg: true, svgFilters: true },
        ADD_TAGS: ['style'],
        ADD_ATTR: ['target']
    });
    if (!cleanRoot || cleanRoot.nodeType !== 1 || !cleanRoot.querySelector) {
        return;
    }
    var shadow = host.attachShadow({ mode: 'closed' });
    var wrapper = document.createElement('div');
    var reset = document.createElement('style');
    reset.textContent = 'body.fcrm_email_document{display:block;margin:0;}';
    wrapper.appendChild(reset);
    appendHeadStyles(cleanRoot, wrapper);
    appendBody(cleanRoot, wrapper);
    shadow.appendChild(wrapper);
})();
JS;

wp_add_inline_script('fluentcrm_dompurify', $fcViewOnBrowserScript);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <meta http-equiv="Imagetoolbar" content="No"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?php echo esc_attr($email_heading); ?></title>
    <?php
        wp_head();
        $email && do_action('fluent_crm/view_on_browser_head', $email);
    ?>
</head>
<body class="fluentcrm_web_body">
<div class="fluentcrm_web_view_wrapper">
    <?php $email && do_action('fluent_crm/view_on_browser_before_heading', $email); ?>

    <div class="fluentcrm_web_view_header">
        <div class="fluentcrm_web_logo">
            <?php if (!empty($business['logo'])): ?>
                <a href="<?php echo esc_url(site_url()); ?>">
                    <img src="<?php echo esc_url($business['logo']); ?>"
                         alt="<?php echo esc_html($business['business_name']); ?>"/>
                </a>
            <?php endif; ?>
        </div>
        <div class="fluentcrm_web_heading">
            <h1><?php echo wp_kses_post($email_heading); ?></h1>
        </div>
    </div>

    <?php $email && do_action('fluent_crm/view_on_browser_before_email_body', $email); ?>

    <div class="fluentcrm_email_wrapper">
        <div id="fluent_email_body"></div>
    </div>

    <?php $email && do_action('fluent_crm/view_on_browser_after_email_body', $email); ?>
</div>
<?php $email && do_action('fluent_crm/view_on_browser_footer', $email); ?>
<?php wp_footer(); ?>
</body>
</html>
