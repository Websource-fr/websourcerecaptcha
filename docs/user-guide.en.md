# Websource reCAPTCHA — User guide (short version)

Free PrestaShop module by [Websource](https://www.websource.fr). PrestaShop 1.7.8 to 9.x, PHP 7.2 to 8.5.
The full guide is available in French: [guide-utilisateur.md](guide-utilisateur.md).

## 1. What it does

The module adds Google reCAPTCHA to the forms you select (contact, account creation, login, forgotten password, newsletter, product reviews, guest order). Each submission is **verified server-side** with Google before PrestaShop processes it, so bots that skip JavaScript are blocked as well. Blocked submissions show a translated error message inside the form (no blank page). No core override is used.

## 2. Choosing a version

| | reCAPTCHA v2 — "I'm not a robot" checkbox | reCAPTCHA v2 — invisible | reCAPTCHA v3 — score (no interaction) |
|---|---|---|---|
| Visitor sees | Checkbox, sometimes an image challenge | Nothing, challenge only if suspicious | Nothing |
| Displayed element | Widget in the form | Badge | Badge |
| Suspicious visitor | Image challenge | Image challenge | **Rejected, no fallback challenge** |
| Best for | Heavy spam, visible protection | Most shops | Conversion-first shops monitoring their statistics |

The default after installation is the v2 checkbox. A Google key only works with the version it was created for.

## 3. Creating Google keys

1. Go to <https://www.google.com/recaptcha/admin> and click **+**.
2. Pick the type matching the module setting: **v2 → "I'm not a robot" checkbox**, **v2 → Invisible reCAPTCHA badge**, or **v3**.
3. Add your shop domain without `https://` (e.g. `my-shop.com`), plus any staging or secondary domains.
4. Copy the **site key** and the **secret key** (format `6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`). Never share the secret key.

Google may ask you to link the keys to a Google Cloud project; usage stays free within Google's published quotas.

## 4. Installation

1. Download `websourcerecaptcha-1.0.0.zip` from <https://github.com/Websource-fr/websourcerecaptcha/releases> (do not unzip).
2. Back office: **Modules > Module Manager > Upload a module**, drop the zip.
3. Click **Configure**. Nothing is blocked until both keys are saved.

## 5. Settings

**reCAPTCHA keys and version**

| Setting | Effect | Recommended |
|---|---|---|
| Protection enabled | Master switch | Yes |
| Version | v2 checkbox / v2 invisible / v3 score | v2 checkbox (default) or v2 invisible |
| Site key / Secret key | Google keys; the secret is never displayed again nor sent to the browser (leave empty to keep it) | — |
| Delete the saved secret key | Removes the stored secret on save | No |
| v3 threshold | Minimum accepted score, 0.0–1.0 | 0.5 |
| Theme (v2), Size (v2 checkbox) | Light/Dark, Normal/Compact | Light, Normal |
| Badge position (invisible / v3) | Bottom right, Bottom left, Inline in the form | Bottom right |
| Hide the badge | Hides the badge and adds Google's legal notice to every protected form | No |
| Check the hostname | Rejects tokens issued on another domain (keep off with Google test keys) | No (default), Yes in production |
| Let submissions through if Google is unreachable | Yes: forms stay usable after a 5 s timeout; No: submissions blocked | Yes |

**Exclusions**: *Do not protect for logged-in customers* (default No), *Allowed IP addresses* (one address or CIDR block per line, IPv4/IPv6), *Do not protect for maintenance IPs* (default No).

**Cookie consent**: *Load reCAPTCHA after consent* (default No), *Websource Cookie Consent category* (default Services; the module listens to `wsconsent:update`), *Generic JavaScript event* (for other consent tools; `window.wsrcConsentGranted = true` also works). Without consent, the form shows an **Allow reCAPTCHA** button.

**Log**: *Log failures* (default Yes) — date, form, anonymised IP (IPv4 /24, IPv6 /48), score, error codes; auto-purged after 30 days.

**Multistore**: settings follow the shop context selected in the back office; statistics and log are per shop.

### Protected forms

Eight forms, all enabled at install: Contact form, Account creation, Login, Forgotten password, Newsletter (ps_emailsubscription), Newsletter (Websource Newsletter), Product reviews (productcomments), Guest order (checkout). A form whose module is missing is flagged and ignored. Each has a **CSS selector** (defaults fit the Classic and Websource themes; several selectors can be comma-separated) and a **v3 action**. **Hummingbird and custom themes have no default selectors**: inspect the `<form>` tag and enter a matching selector (e.g. `form#contact-form`). A wrong selector blocks real customers (no token is sent), so test each form. AJAX-reloaded forms are supported.

## 6. Testing

The **Test keys** panel offers two tests (neither saves the configuration):

- **Run the full test** (recommended): the browser gets a real token with the site key and version entered, the server verifies it with the secret key. It checks the key pair, the key type and the allowed domain.
- **Quick Google connection test**: server-side call without a token, using the saved secret key. Checks that Google is reachable and detects Google's public test secret key. It cannot validate a real secret key (Google no longer returns `invalid-input-secret` for an empty token).

Then open a **private window**, check the widget/badge appears without a red "ERROR for site owner" message, and submit each protected form.

## 7. Statistics and log

**Statistics** shows blocked and validated submissions over 7 and 30 days, globally and per form (daily aggregated counters, no personal data). **Failure log (last 50 entries)** lists date, form, anonymised IP, score, codes and result (*Blocked* or *Let through (Google unreachable)*). **Clear the log** empties it. Common codes: `missing-input-response` (no token), `invalid-input-response` (invalid token or mismatched keys), `timeout-or-duplicate`, `low-score`, `action-mismatch`, `score-missing` (v2 key with v3 selected), `hostname-mismatch`.

## 8. GDPR

reCAPTCHA is a security measure and may rely on **legitimate interest** (GDPR art. 6.1.f), which is why "Load reCAPTCHA after consent" is off by default. Mention Google reCAPTCHA in your privacy policy (purpose, data sent to Google, legal basis, links to Google's [Privacy Policy](https://policies.google.com/privacy) and [Terms](https://policies.google.com/terms)). If you hide the badge, Google requires the notice *"This site is protected by reCAPTCHA and the Google Privacy Policy and Terms of Service apply."* to be visible; the module adds it automatically.

## 9. Troubleshooting

| Problem | Fix |
|---|---|
| "Invalid key type" | The Google key type does not match the selected version: create matching keys or change the version |
| "Invalid domain for site key" | Add the exact shop domain to the key in the Google console |
| Every submission blocked | Run the full test; check log codes; lower the v3 threshold; clear the cache; check no cookie tool or blocker prevents Google's script |
| Widget missing on a form | Adjust the form's CSS selector (always needed on Hummingbird), clear the cache, test in a private window |
| Changes not visible | **Advanced Parameters > Performance > Clear cache** (also regenerates CCC files) |

**Known limitations**

- Forms submitted by JavaScript without a `submit` event cannot receive a token and are rejected server-side.
- Guest checkout on PrestaShop 1.7.8: the entered data is lost when verification fails (kept on 8.x and 9.x).
- v2 checkbox: after an AJAX submission the checkbox resets about 1.5 s later (a token is single-use).
- v3: a low score is rejected without a fallback challenge.

## 10. Uninstall

**Disable** keeps settings, statistics and log. **Uninstall** removes the configuration (including keys), the log and statistics tables, and the hooks.

Support: <https://github.com/Websource-fr/websourcerecaptcha/issues> — Websource, PrestaShop agency: <https://www.websource.fr/agence-prestashop> — License AFL-3.0.
