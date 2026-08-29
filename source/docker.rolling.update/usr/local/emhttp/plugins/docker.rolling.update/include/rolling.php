<?php
/* docker.rolling.update - pure functions (no Unraid/Docker dependency), testable anywhere.
 * `php rolling.php` runs the self-test. GPL-2.0. */

/** Extracts from an Unraid template (XML v2) what the plugin needs. Tolerates invalid XML. */
function template_info(string $xml): array {
  $info = ['network'=>'', 'myip'=>'', 'extra'=>'', 'tailscale'=>false, 'ports'=>0, 'labels'=>[]];
  $x = @simplexml_load_string($xml);
  if ($x === false) return $info;
  $info['network']   = trim((string)$x->Network);
  $info['myip']      = trim((string)$x->MyIP);
  $info['extra']     = trim((string)$x->ExtraParams);
  $info['tailscale'] = trim((string)$x->TailscaleEnabled) === 'true';
  foreach ($x->Config as $c) {
    $type = (string)$c['Type'];
    if ($type === 'Port') $info['ports']++;
    if ($type === 'Label') {
      // same rule as xmlToCommand: entered value, otherwise Default
      $value = strlen((string)$c) ? (string)$c : (string)$c['Default'];
      $info['labels'][html_entity_decode((string)$c['Target'], ENT_XML1, 'UTF-8')] = html_entity_decode($value, ENT_XML1, 'UTF-8');   // = Unraid's xml_decode()
    }
  }
  return $info;
}

/** A `--health-cmd` in Extra Parameters counts as a HEALTHCHECK. */
function extra_has_health(string $extra): bool {
  return str_contains($extra, '--health-cmd');
}

/** Decides the health probe from the labels, the presence of a healthcheck, and the global config. */
function resolve_probe(array $labels, bool $hasHealth, array $cfg): array {
  $warnings = [];
  $timeout  = max(5, (int)($labels['rolling.timeout'] ?? $cfg['TIMEOUT'] ?? 120));
  $grace    = max(1, (int)($labels['rolling.grace']   ?? $cfg['GRACE']   ?? 15));
  $default  = $hasHealth ? 'health' : 'running';
  $raw      = trim($labels['rolling.probe'] ?? '');
  if ($raw === '')                                           $type = $default;
  elseif (in_array($raw, ['health', 'running', 'none'], true)) $type = $raw;
  elseif (preg_match('#^https?://\S+$#', $raw))              $type = 'http';
  elseif (preg_match('#^tcp://[^:/\s]+:\d+$#', $raw))        $type = 'tcp';
  else { $warnings[] = "Unknown rolling.probe value '$raw', using '$default'"; $type = $default; }
  if ($type === 'health' && !$hasHealth) {
    $warnings[] = "rolling.probe=health but the container has no healthcheck, using 'running'";
    $type = 'running';
  }
  if ($grace > $timeout) $timeout = $grace;   // the running probe must be able to complete
  return ['type'=>$type, 'target'=>$raw, 'timeout'=>$timeout, 'grace'=>$grace, 'warnings'=>$warnings];
}

/** Missing Traefik labels for a second instance to be merged into the same load-balanced service. */
function traefik_missing(array $labels): array {
  $routers = [];
  foreach ($labels as $k => $v) {
    if (preg_match('/^traefik\.(http|tcp|udp)\.routers\.([^.]+)\.(.+)$/', $k, $m)) {
      $key = "$m[1]/$m[2]";
      $routers[$key] ??= ['proto'=>$m[1], 'name'=>$m[2], 'service'=>''];
      if ($m[3] === 'service') $routers[$key]['service'] = trim($v);
    }
  }
  if (!$routers) return ['No Traefik router label found (traefik.http.routers.<name>.rule=...)'];
  $missing = [];
  foreach ($routers as $r) {
    if ($r['service'] === '') { $missing[] = "Add label traefik.{$r['proto']}.routers.{$r['name']}.service=<service-name>"; continue; }
    $port = "traefik.{$r['proto']}.services.{$r['service']}.loadbalancer.server.port";
    if (!isset($labels[$port])) $missing[] = "Add label $port=<container-port>";
  }
  return $missing;
}

/** Prerequisites for the bluegreen strategy. Returns the list of what is missing (empty = OK). */
function check_bluegreen(array $info, bool $wasRunning, bool $hasHealth, ?string $networkDriver): array {
  $m   = [];
  $net = $info['network'];
  if (in_array($net, ['', 'bridge', 'host', 'none'], true) || str_starts_with($net, 'container:') || $networkDriver !== 'bridge')
    $m[] = "Network must be a user-defined bridge network (current: '".($net ?: 'bridge')."', driver: ".($networkDriver ?? 'unknown').")";
  $extraPort = (bool)preg_match('/(^|\s)(-p|--publish)[\s=]/', $info['extra']);
  if ($info['ports'] > 0 || $extraPort)
    $m[] = "No host port may be published (found {$info['ports']} port mapping(s) in the template".($extraPort ? ' and -p in Extra Parameters' : '').')';
  if ($info['myip'] !== '' || preg_match('/(^|\s)--ip6?[\s=]/', $info['extra']))
    $m[] = 'No fixed IP allowed (Fixed IP field or --ip in Extra Parameters)';
  if (!$hasHealth)
    $m[] = 'A healthcheck is required (image HEALTHCHECK or --health-cmd in Extra Parameters)';
  $m = array_merge($m, traefik_missing($info['labels']));
  if ($info['tailscale']) $m[] = 'Tailscale must be disabled for this container';
  if (!$wasRunning)       $m[] = 'Container is not running (nothing to overlap)';
  return $m;
}

/** Whether a notification of the given Unraid importance (normal|warning|alert) is enabled in the settings. */
function notify_wanted(string $importance, array $cfg): bool {
  $key = ['normal'=>'NOTIFY', 'warning'=>'NOTIFY_WARNING', 'alert'=>'NOTIFY_ERROR'][$importance] ?? 'NOTIFY_ERROR';
  return ($cfg[$key] ?? 'yes') !== 'no';
}

function rolling_selftest(): bool {
  $fails = 0; $n = 0;
  $t = function (bool $cond, string $msg) use (&$fails, &$n) { $n++; if (!$cond) { $fails++; fwrite(STDERR, "FAIL: $msg\n"); } };

  $xml = <<<'XML'
<?xml version="1.0"?>
<Container version="2">
  <Name>Demo</Name>
  <Repository>nginx:stable-alpine</Repository>
  <Network>frontend</Network>
  <MyIP/>
  <ExtraParams>--health-cmd="wget -qO- http://127.0.0.1/ || exit 1" --health-interval=5s</ExtraParams>
  <TailscaleEnabled>false</TailscaleEnabled>
  <Config Name="HTTP" Target="80" Default="8080" Mode="tcp" Type="Port" Display="always" Required="false" Mask="false">8080</Config>
  <Config Name="enable" Target="traefik.enable" Default="true" Mode="" Type="Label" Display="always" Required="false" Mask="false"></Config>
  <Config Name="rule" Target="traefik.http.routers.demo.rule" Default="" Mode="" Type="Label" Display="always" Required="false" Mask="false">Host(`demo.test`)</Config>
  <Config Name="probe" Target="rolling.probe" Default="" Mode="" Type="Label" Display="always" Required="false" Mask="false">health</Config>
</Container>
XML;
  // --- template_info ---
  $i = template_info($xml);
  $t($i['network'] === 'frontend', 'network');
  $t($i['myip'] === '', 'myip empty');
  $t($i['ports'] === 1, 'ports count');
  $t($i['labels']['traefik.enable'] === 'true', 'label falls back to Default when text is empty');
  $t($i['labels']['traefik.http.routers.demo.rule'] === 'Host(`demo.test`)', 'label value');
  $t($i['labels']['rolling.probe'] === 'health', 'rolling label');
  $t($i['tailscale'] === false, 'tailscale off');
  $t(extra_has_health($i['extra']), 'extra has --health-cmd');
  $t(!extra_has_health('--restart=unless-stopped'), 'extra without --health-cmd');
  $t(template_info('not xml') === ['network'=>'', 'myip'=>'', 'extra'=>'', 'tailscale'=>false, 'ports'=>0, 'labels'=>[]], 'invalid xml gives defaults');

  // --- resolve_probe ---
  $cfg = ['TIMEOUT'=>'120', 'GRACE'=>'15'];
  $p = resolve_probe([], true, $cfg);
  $t($p['type'] === 'health' && $p['timeout'] === 120 && $p['grace'] === 15 && $p['warnings'] === [], 'default: health when image has healthcheck');
  $p = resolve_probe([], false, $cfg);
  $t($p['type'] === 'running', 'default: running without healthcheck');
  $p = resolve_probe(['rolling.probe'=>'health'], false, $cfg);
  $t($p['type'] === 'running' && count($p['warnings']) === 1, 'health requested without healthcheck falls back to running with a warning');
  $p = resolve_probe(['rolling.probe'=>'http://10.0.0.1:8080/health', 'rolling.timeout'=>'30'], false, $cfg);
  $t($p['type'] === 'http' && $p['target'] === 'http://10.0.0.1:8080/health' && $p['timeout'] === 30, 'http probe with label timeout');
  $p = resolve_probe(['rolling.probe'=>'tcp://10.0.0.1:5432'], false, $cfg);
  $t($p['type'] === 'tcp' && $p['target'] === 'tcp://10.0.0.1:5432', 'tcp probe');
  $p = resolve_probe(['rolling.probe'=>'tcp://nohost'], false, $cfg);
  $t($p['type'] === 'running' && count($p['warnings']) === 1, 'malformed tcp falls back with a warning');
  $p = resolve_probe(['rolling.probe'=>'banana'], true, $cfg);
  $t($p['type'] === 'health' && count($p['warnings']) === 1, 'unknown value falls back with a warning');
  $p = resolve_probe(['rolling.probe'=>'none'], false, $cfg);
  $t($p['type'] === 'none', 'none');
  $p = resolve_probe(['rolling.grace'=>'200', 'rolling.timeout'=>'60'], false, $cfg);
  $t($p['timeout'] === 200 && $p['grace'] === 200, 'grace above timeout raises timeout');
  $p = resolve_probe(['rolling.timeout'=>'abc', 'rolling.grace'=>'3'], false, $cfg);
  $t($p['timeout'] === 5 && $p['grace'] === 3, 'non-numeric timeout clamps to the 5 s minimum');
  $p = resolve_probe([], false, ['TIMEOUT'=>'10', 'GRACE'=>'20']);
  $t($p['timeout'] === 20 && $p['grace'] === 20, 'cfg grace above cfg timeout raises timeout');
  $p = resolve_probe([], false, []);
  $t($p['timeout'] === 120 && $p['grace'] === 15, 'missing cfg uses built-in defaults');

  // --- traefik_missing ---
  $t(traefik_missing([]) === ['No Traefik router label found (traefik.http.routers.<name>.rule=...)'], 'no router');
  $t(traefik_missing(['traefik.http.routers.a.rule'=>'Host(`a`)']) === ['Add label traefik.http.routers.a.service=<service-name>'], 'router without service');
  $t(traefik_missing(['traefik.http.routers.a.rule'=>'Host(`a`)', 'traefik.http.routers.a.service'=>'svc']) === ['Add label traefik.http.services.svc.loadbalancer.server.port=<container-port>'], 'service without port');
  $t(traefik_missing(['traefik.http.routers.a.rule'=>'Host(`a`)', 'traefik.http.routers.a.service'=>'svc', 'traefik.http.services.svc.loadbalancer.server.port'=>'80']) === [], 'complete labels');
  $t(count(traefik_missing(['traefik.http.routers.a.rule'=>'x', 'traefik.tcp.routers.b.rule'=>'y'])) === 2, 'two routers, two missing services');
  // --- check_bluegreen ---
  $ok = ['network'=>'frontend', 'myip'=>'', 'extra'=>'--health-cmd=x', 'tailscale'=>false, 'ports'=>0,
         'labels'=>['traefik.http.routers.a.rule'=>'r', 'traefik.http.routers.a.service'=>'s', 'traefik.http.services.s.loadbalancer.server.port'=>'80']];
  $t(check_bluegreen($ok, true, true, 'bridge') === [], 'all prerequisites met');
  $t(count(check_bluegreen($ok, false, false, 'bridge')) === 2, 'not running + no healthcheck = 2 missing');
  $t(count(check_bluegreen(['network'=>'bridge'] + $ok, true, true, 'bridge')) === 1, 'default bridge refused');
  $t(count(check_bluegreen(['network'=>'br0'] + $ok, true, true, 'macvlan')) === 1, 'macvlan refused');
  $t(count(check_bluegreen(['network'=>'container:vpn'] + $ok, true, true, null)) === 1, 'container: refused');
  $t(count(check_bluegreen(['network'=>'ghost'] + $ok, true, true, null)) === 1, 'unknown network refused');
  $t(count(check_bluegreen(['ports'=>2] + $ok, true, true, 'bridge')) === 1, 'template ports refused');
  $t(count(check_bluegreen(['extra'=>'-p 80:80 --health-cmd=x'] + $ok, true, true, 'bridge')) === 1, '-p in extra refused');
  $t(count(check_bluegreen(['myip'=>'10.0.0.5'] + $ok, true, true, 'bridge')) === 1, 'fixed ip refused');
  $t(count(check_bluegreen(['extra'=>'--ip=10.0.0.5 --health-cmd=x'] + $ok, true, true, 'bridge')) === 1, '--ip refused');
  $t(count(check_bluegreen(['tailscale'=>true] + $ok, true, true, 'bridge')) === 1, 'tailscale refused');
  $t(count(check_bluegreen(['labels'=>[]] + $ok, true, true, 'bridge')) === 1, 'no traefik labels refused');

  $t(notify_wanted('normal', []) && notify_wanted('warning', []) && notify_wanted('alert', []), 'notifications default to yes');
  $t(!notify_wanted('normal', ['NOTIFY'=>'no']) && notify_wanted('alert', ['NOTIFY'=>'no']), 'NOTIFY=no only silences success');
  $t(!notify_wanted('warning', ['NOTIFY_WARNING'=>'no']) && notify_wanted('normal', ['NOTIFY_WARNING'=>'no']), 'NOTIFY_WARNING=no only silences warnings');
  $t(!notify_wanted('alert', ['NOTIFY_ERROR'=>'no']) && notify_wanted('warning', ['NOTIFY_ERROR'=>'no']), 'NOTIFY_ERROR=no only silences errors');

  echo $fails ? "selftest FAILED ($fails/$n)\n" : "selftest OK ($n checks)\n";
  return $fails === 0;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) exit(rolling_selftest() ? 0 : 1);
