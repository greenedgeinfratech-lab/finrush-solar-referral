# Finrush Solar Referral Programme

Static site + PHP form handler deployed to https://finrush.co.in/solar-partner/
(cloned by Hostinger into public_html/solar-partner).

- Submissions are saved as CSV in `domains/finrush.co.in/solar-partner-data/` (outside public_html) and emailed to the addresses in `submit.php`.
- Remove the `noindex` meta tags and the X-Robots-Tag line in `.htaccess` when approved to go public.
