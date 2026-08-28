<?php
/* docker.rolling.update — fonctions pures (aucune dépendance Unraid/Docker), testables partout.
 * `php rolling.php` lance l'auto-test. GPL-2.0. */

/** Extrait d'un template Unraid (XML v2) ce dont le plugin a besoin. Tolère un XML invalide. */
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
      // même règle que xmlToCommand : valeur saisie, sinon Default
      $value = strlen((string)$c) ? (string)$c : (string)$c['Default'];
      $info['labels'][html_entity_decode((string)$c['Target'], ENT_XML1, 'UTF-8')] = html_entity_decode($value, ENT_XML1, 'UTF-8');   // = xml_decode() d'Unraid
    }
  }
  return $info;
}

/** Un `--health-cmd` dans Extra Parameters vaut HEALTHCHECK. */
function extra_has_health(string $extra): bool {
  return str_contains($extra, '--health-cmd');
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

  echo $fails ? "selftest FAILED ($fails/$n)\n" : "selftest OK ($n checks)\n";
  return $fails === 0;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) exit(rolling_selftest() ? 0 : 1);
