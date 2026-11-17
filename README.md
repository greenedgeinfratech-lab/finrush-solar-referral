# Finrush Solar Referral Programme + CRM

PHP 8 + MySQL app for finrush.co.in (Hostinger). Auto-deployed from `main`.

- `/` landing page, `/terms.html`, `/disclosure.html`, `/privacy.html`
- `/quote.php?ref=FR0001` customer quote form (partner referral links)
- `/partner/` partner portal: register, log in, add leads, add updates, view stages and earnings
- `/admin/` team panel: leads pipeline, partners and KYC, payouts (commissions and bonuses), team, settings, CSV exports
- `/setup.php` one-time setup; writes `finrush-crm-config.php` OUTSIDE public_html. Locked after first run.

Secrets never go in this repo: DB credentials live in the private config file; the setup key is stored only as a hash in `inc/setup-key.php`.

Public pages (home, terms, disclosure, privacy) are indexable and listed in `sitemap.xml`. The partner portal, team panel and quote form are kept out of search engines by `robots.txt` and a noindex meta tag. Maintenance: `php inc/cli.php delete-partner FR0001` (command line only).
