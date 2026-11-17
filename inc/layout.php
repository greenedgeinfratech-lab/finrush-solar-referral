<?php
/** Page chrome for the partner portal and team panel. */

function logo_svg(int $size = 32): string {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="22" fill="#f59e0b"/><g stroke="#15803d" stroke-width="8" stroke-linecap="round"><path d="M50 6v12M50 82v12M6 50h12M82 50h12M19 19l8 8M73 73l8 8M19 81l8-8M73 27l8-8"/></g></svg>';
}

function page_start(string $title, string $area, array $nav = [], string $active = ''): void {
    $flash = take_flash();
    $who = $area === 'admin' ? current_user() : ($area === 'partner' ? current_partner() : null);
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> | Finrush Referral</title>
<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#15803d">
<link rel="stylesheet" href="<?= h(url('/assets/app.css')) ?>">
</head><body class="app area-<?= h($area) ?>">
<header class="topbar"><div class="tb-in">
  <a class="tb-brand" href="<?= h(url($area === 'admin' ? '/admin/' : ($area === 'partner' ? '/partner/' : '/'))) ?>"><?= logo_svg(30) ?><span>Finrush Referral<small><?= $area === 'admin' ? 'Team panel' : ($area === 'partner' ? 'Partner portal' : 'Solar partner programme') ?></small></span></a>
  <?php if ($who): ?>
  <button class="tb-menu" type="button" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <nav class="tb-nav">
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= h(url($href)) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <span class="tb-who"><?= h($who['name']) ?></span>
    <a href="<?= h(url($area === 'admin' ? '/admin/logout.php' : '/partner/logout.php')) ?>" class="tb-out">Log out</a>
  </nav>
  <?php endif; ?>
</div></header>
<main class="wrap-app">
<?php foreach ($flash as [$t, $m]): ?><div class="alert <?= $t === 'ok' ? 'ok' : 'err' ?>"><?= h($m) ?></div><?php endforeach; ?>
<?php
}

function page_end(): void {
    ?></main>
<footer class="appfoot">Finrush Solar Referral Programme · Finrush Smart Services Pvt. Ltd. · Solar systems by Greenedge Infratech Pvt. Ltd. · <a href="<?= h(url('/')) ?>">Programme home</a></footer>
</body></html><?php
}

function partner_nav(): array {
    return [
        'home'     => ['Dashboard', '/partner/'],
        'leads'    => ['My leads', '/partner/leads.php'],
        'new'      => ['+ Add lead', '/partner/lead-new.php'],
        'earnings' => ['Earnings', '/partner/earnings.php'],
        'profile'  => ['Profile', '/partner/profile.php'],
    ];
}
function admin_nav(?array $u = null): array {
    $n = [
        'home'     => ['Dashboard', '/admin/'],
        'leads'    => ['Leads', '/admin/leads.php'],
        'partners' => ['Partners', '/admin/partners.php'],
    ];
    if ($u && $u['role'] === 'admin') {
        $n['payouts']  = ['Payouts', '/admin/payouts.php'];
        $n['team']     = ['Team', '/admin/team.php'];
        $n['settings'] = ['Settings', '/admin/settings.php'];
    }
    $n['profile'] = ['My account', '/admin/profile.php'];
    return $n;
}

function select_opts(array $opts, $current): string {
    $o = '';
    foreach ($opts as $v => $label) $o .= '<option value="' . h($v) . '"' . ((string) $v === (string) $current ? ' selected' : '') . '>' . h($label) . '</option>';
    return $o;
}

function event_list(array $events, bool $teamView): string {
    if (!$events) return '<p class="muted">No updates yet.</p>';
    $html = '<ol class="timeline">';
    foreach ($events as $e) {
        $who = $e['actor_type'] === 'partner' ? 'Partner' : ($e['actor_type'] === 'team' ? ($teamView ? ($e['actor_name'] ?? 'Team') : 'Finrush team') : 'System');
        $html .= '<li class="ev ev-' . h($e['kind']) . (!$e['partner_visible'] ? ' ev-internal' : '') . '">';
        $html .= '<div class="ev-head"><b>' . h($who) . '</b> · ' . h(dt($e['created_at'], true));
        if ($teamView && !$e['partner_visible']) $html .= ' <span class="tag-int">internal</span>';
        $html .= '</div>';
        if ($e['kind'] === 'stage') $html .= '<div class="ev-stage">' . ($e['from_stage'] ? stage_badge($e['from_stage']) . ' → ' : '') . stage_badge((string) $e['to_stage']) . '</div>';
        if ($e['body'] !== null && $e['body'] !== '') $html .= '<div class="ev-body">' . nl2br(h($e['body'])) . '</div>';
        $html .= '</li>';
    }
    return $html . '</ol>';
}
