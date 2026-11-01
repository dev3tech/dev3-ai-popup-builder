=== DEV3 AI Popup & Lead Capture Builder for WordPress ===
Contributors: dev3tech
Tags: popup, lead generation, exit intent, newsletter, a/b testing
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Popups, slide-ins, floating bars, full-screen mats and inline forms with smart triggers, targeting, A/B tests, lead storage and DEV3 AI copywriting.

== Description ==

Build lead capture campaigns that convert without annoying visitors or hurting Core Web Vitals.

**Formats**: centered popups, corner slide-ins, floating top/bottom bars, full-screen welcome mats and inline forms (block, shortcode or automatic after posts). 12 templates and a visual editor with a live desktop/phone preview.

**Triggers**: exit intent (fast scroll-up on phones), scroll depth, time on page, click (any link, class or CSS selector, plus a "Popup button" block), inactivity and page load.

**Targeting**: pages, post types, categories, URLs and WooCommerce pages (checked on the server, cache-safe); devices; referrer (search, social, direct or custom); UTM tags; logged-in state; new vs returning visitors; pages viewed; WooCommerce cart value; schedule; frequency caps (once per visit, every N days, max N times, never after sign-up).

**A/B tests**: up to 4 variants with traffic weights, sticky assignment, views/leads/conversion per variant, uplift and statistical confidence, one-click "Declare winner".

**Leads**: stored in WordPress with consent proof, UTM and page data; search, filters, CSV export; Mailchimp, Brevo and signed webhooks (Zapier, Make, n8n); retry on failure; GDPR export/erase; retention; unique one-time WooCommerce coupons.

**Respectful by default**: mobile-safe mode turns popups into non-blocking bottom sheets on phones (Google intrusive interstitials guidance); one overlay per page view; focus trap, Escape, aria-modal and reduced motion; no layout shift (overlays never push content, inline forms are server-rendered) and a ~10 KB deferred script that only loads where a campaign can show.

**DEV3 AI** (Google Gemini, your own key): writes copy, proposes offer variants for A/B tests, suggests triggers and targeting, explains test results and drafts whole campaigns from one sentence. Every result is a draft you accept, edit or reject. AI never publishes anything.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin and activate it.
2. Open **DEV3 AI Popups** → Campaigns → New campaign and pick a template.
3. Edit the content, design, triggers and targeting, then click **Publish**.
4. Optional: add a Google Gemini API key under **API Config** for the DEV3 AI features, and Mailchimp/Brevo keys under **Integrations**.

== Frequently Asked Questions ==

= Does it work with page caching? =
Yes. Page rules are evaluated per URL on the server; visitor rules (device, referrer, UTM, login, cart, frequency) run in the browser. Public endpoints need no nonce.

= Will it hurt my Core Web Vitals? =
No markup is printed for overlays until a trigger fires, the script is deferred and idle-loaded, overlays are fixed-position (no CLS) and the stylesheet does not block rendering on pages with only overlays.

= Do I need an AI key? =
No. Templates, the editor, triggers, targeting, A/B tests, leads and integrations all work without one.

= Is WooCommerce required? =
No. With WooCommerce you also get cart-value targeting and unique coupons.

== Screenshots ==

1. Visual editor with live preview.
2. Template gallery.
3. Dashboard.
4. A/B test results.
5. Popup on the front end.
6. Mobile-safe bottom sheet on a phone.

== Changelog ==

= 1.0.0 =
* Initial release.
