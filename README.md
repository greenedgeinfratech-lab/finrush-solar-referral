# Finrush Solar Referral Programme + CRM

PHP 8 + MySQL app for finrush.co.in (Hostinger). Auto-deployed from `main`.

- `/` landing page, `/terms.html`, `/disclosure.html`, `/privacy.html`
- `/quote.php?ref=FR0001` customer quote form (partner referral links)
- `/partner/` partner portal: register, log in, add leads, add updates, view stages and earnings
- `/admin/` team panel: leads pipeline, partners and KYC, payouts (commissions and bonuses), team, settings, CSV exports
- `/setup.php` one-time setup; writes `finrush-crm-config.php` OUTSIDE public_html. Locked after first run.
- `/migrate.php` one-time WordPress backup + move (runs only from the old /solar-partner/ folder)

Secrets never go in this repo: DB credentials live in the private config file; the setup key is stored only as a hash in `inc/setup-key.php`.

Preview mode: remove the `X-Robots-Tag` line in `.htaccess` and the robots meta tags in the HTML pages when ready for Google.
