# Docker Rolling Update - Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un plugin Unraid `docker.rolling.update` qui remplace le bouton Update/Update All natif par une mise à jour séquentielle avec porte de santé et rollback automatique, plus une stratégie opt-in `bluegreen` (zéro-downtime derrière Traefik).

**Architecture:** Un script PHP CLI `scripts/rolling_update` (appelé par le `openDocker()` natif, sortie streamée dans la modale native via nchan) fait tout le travail en réutilisant les classes PHP d'Unraid (`DockerClient`, `xmlToCommand`, `publish`). Les fonctions pures (parsing du template, décision de sonde, prérequis bluegreen) vivent dans `include/rolling.php`, testables sans Docker. Une `.page` sans titre injecte 25 lignes de JS dans la page Docker pour router Update/Update All vers le script ; une page Settings expose 3 réglages ; le reste se règle par labels sur le container.

**Tech Stack:** PHP 8.4 (Unraid 7.3.2 ; PHP 8.5 local via Homebrew pour l'auto-test), jQuery/swal d'Unraid, Docker CLI, rsync/ssh vers `test-server`, GNU make. Aucune dépendance externe.

**Spec:** `docs/superpowers/specs/2026-08-28-docker-rolling-update-design.md`

## Global Constraints

- Cible Unraid **7.3.2** (serveur de test, alias SSH `root@<server-ip>`) ; `.plg` avec `min="7.3.0"`.
- Nom du plugin : `docker.rolling.update`. Fichiers déployés sous `/usr/local/emhttp/plugins/docker.rolling.update/` (RAM disk, perdu au reboot - boucle de dev via `make deploy`).
- Config : `/boot/config/plugins/docker.rolling.update/docker.rolling.update.cfg` avec `TIMEOUT="120"`, `GRACE="15"`, `NOTIFY="yes"` ; défauts dans `default.cfg` du plugin (`parse_plugin_cfg('docker.rolling.update')` fusionne les deux).
- Labels par container : `rolling.strategy` (`safe`|`bluegreen`, défaut `safe`), `rolling.probe` (`health`|`running`|`http://…`|`tcp://host:port`|`none`), `rolling.timeout` (s), `rolling.grace` (s).
- Noms temporaires : `<nom>.rollback` (ancien container, précieux) et `<nom>.new` (nouvelle instance bluegreen, jetable).
- Textes affichés (modale, notifications, Settings) en **anglais** (intégrés à l'UI Unraid, plugin publiable) ; commentaires de code en **français**.
- Licence **GPL-2.0** : le script réutilise des portions de `dynamix.docker.manager` (© Lime Technology / Bergware International).
- Aucun container de production de test-server n'est jamais touché : les tests passent par `RollingTest` (mode sûr) et `RollingBG` (bluegreen), jetables.
- `ROLLING_STDOUT=1` fait aussi écrire le script sur stdout (test en SSH sans la modale).
- Commit après chaque tâche ; pas de push avant la tâche 12.
- Helpers Unraid disponibles en 7.3.2 (vérifié) : `xmlToCommand`, `getXmlVal`, `addRoute`, `execCommand`, `pullImage`, `stopContainer`, `removeContainer`, `removeImage`, `DockerUtil::ensureImageTag`, `DockerUpdate::reloadUpdateStatus/setUpdateStatus`, `DockerClient::getContainerDetails/getImageID/flushCaches/startContainer/pullImage`. **Absent en 7.3.2** : `connectExtraNetworks` → toujours derrière `function_exists`.

## Structure des fichiers

```
Makefile                                   # deploy / selftest / testct / testbg / measure / testclean / build
LICENSE                                    # GPL-2.0
README.md
docker.rolling.update.plg                  # manifeste d'installation (tâche 12)
archive/docker.rolling.update-<ver>.txz    # paquet construit (tâche 12, committé : le .plg pointe dessus)
tests/templates/my-RollingTest.xml         # template du container de test mode sûr (@PROBE@/@TIMEOUT@ substitués par make)
tests/templates/my-RollingBG.xml           # template du container de test bluegreen (+ <!--PORT--> pour le test de repli)
source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/
  default.cfg                              # TIMEOUT / GRACE / NOTIFY
  include/rolling.php                      # fonctions pures : template_info, extra_has_health, resolve_probe, traefik_missing, check_bluegreen, rolling_selftest
  scripts/rolling_update                   # CLI : orchestration, nchan, Docker, rollback, bluegreen, notifications, abort
  RollingUpdate.Docker.page                # Menu="Docker" sans Title : override JS de updateContainer/updateAll
  RollingUpdateSettings.page               # Menu="Utilities" : formulaire TIMEOUT/GRACE/NOTIFY + aide labels
```

Responsabilités : `rolling.php` ne connaît ni Docker ni Unraid (entrées : chaînes/arrays, sorties : arrays) ; `rolling_update` ne contient aucune logique de décision, seulement l'orchestration et les effets de bord.

---

### Task 1 : Squelette du dépôt et boucle de déploiement

**Files:**
- Create: `Makefile`
- Create: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/default.cfg`
- Create: `.gitignore`

**Interfaces:**
- Produces: cibles `make deploy` (rsync vers test-server + création du cfg), `make selftest`, `make testct`, `make testbg`, `make measure`, `make testclean` utilisées par toutes les tâches suivantes. Variables surchargeables : `PROBE` (défaut `health`), `TIMEOUT` (défaut `120`), `PORTS` (défaut vide ; `1` ajoute un port au template RollingBG), `URL`/`HOSTHDR` pour `measure`.

- [ ] **Step 1 : Créer `default.cfg`**

```ini
TIMEOUT="120"
GRACE="15"
NOTIFY="yes"
```

- [ ] **Step 2 : Créer `.gitignore`**

```
.DS_Store
```

- [ ] **Step 3 : Créer le `Makefile`**

```make
NAME    := docker.rolling.update
HOST    := test-server
PLUGDIR := usr/local/emhttp/plugins/$(NAME)
SRC     := source/$(NAME)/$(PLUGDIR)
TPL     := /boot/config/plugins/dockerMan/templates-user
DM      := /usr/local/emhttp/plugins/dynamix.docker.manager/scripts
PROBE   ?= health
TIMEOUT ?= 120
PORTS   ?=
URL     ?= http://127.0.0.1:8080/
HOSTHDR ?= rollingbg.test
VERSION ?= $(shell date +%Y.%m.%d)
ifeq ($(PORTS),1)
PORTLINE := <Config Name="HTTP" Target="80" Default="18081" Mode="tcp" Description="" Type="Port" Display="always" Required="false" Mask="false">18081</Config>
endif

.PHONY: deploy selftest testct testbg measure testclean build

deploy: ## rsync du plugin vers le RAM disk du serveur (perdu au reboot) + cfg initial
	rsync -a --delete $(SRC)/ $(HOST):/$(PLUGDIR)/
	ssh $(HOST) 'mkdir -p /boot/config/plugins/$(NAME); [ -f /boot/config/plugins/$(NAME)/$(NAME).cfg ] || cp /$(PLUGDIR)/default.cfg /boot/config/plugins/$(NAME)/$(NAME).cfg'

selftest: ## auto-test des fonctions pures, aucun Docker requis
	php $(SRC)/include/rolling.php

testct: ## container jetable RollingTest (bridge, port 18080) avec « update ready » simulé
	sed -e 's|@PROBE@|$(PROBE)|' -e 's|@TIMEOUT@|$(TIMEOUT)|' tests/templates/my-RollingTest.xml | ssh $(HOST) 'cat > $(TPL)/my-RollingTest.xml'
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingTest >/dev/null; docker start RollingTest >/dev/null && $(DM)/dockerupdate check nonotify >/dev/null; docker ps --filter name=^RollingTest$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

testbg: ## container jetable RollingBG (frontend, Traefik, bluegreen) avec « update ready » simulé
	sed -e 's|@PROBE@|$(PROBE)|' -e 's|@TIMEOUT@|$(TIMEOUT)|' -e 's|<!--PORT-->|$(PORTLINE)|' tests/templates/my-RollingBG.xml | ssh $(HOST) 'cat > $(TPL)/my-RollingBG.xml'
	ssh $(HOST) 'docker rm -f RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingBG >/dev/null; docker start RollingBG >/dev/null && $(DM)/dockerupdate check nonotify >/dev/null; docker ps --filter name=^RollingBG$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

measure: ## 60 s de requêtes à 100 ms depuis l'hôte ; affiche requests= failures=
	ssh $(HOST) 'end=$$((SECONDS+60)); ko=0; n=0; while [ $$SECONDS -lt $$end ]; do c=$$(curl -s -o /dev/null -m 2 -w "%{http_code}" -H "Host: $(HOSTHDR)" $(URL)); n=$$((n+1)); [ "$$c" = 200 ] || ko=$$((ko+1)); sleep 0.1; done; echo "requests=$$n failures=$$ko"'

testclean: ## supprime containers, templates et image de test
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; rm -f $(TPL)/my-RollingTest.xml $(TPL)/my-RollingBG.xml; docker image rm nginx:1.27.0-alpine >/dev/null 2>&1; true'

build: deploy ## construit archive/<name>-<version>.txz (tar GNU du serveur, owner root) et met à jour version+md5 du .plg
	mkdir -p archive
	ssh $(HOST) 'cd / && tar -cJf - --owner=0 --group=0 $(PLUGDIR)' > archive/$(NAME)-$(VERSION).txz
	md5=$$(md5 -q archive/$(NAME)-$(VERSION).txz); sed -i '' -e "s|<!ENTITY version *\".*\">|<!ENTITY version   \"$(VERSION)\">|" -e "s|<!ENTITY md5 *\".*\">|<!ENTITY md5       \"$$md5\">|" $(NAME).plg; echo "archive/$(NAME)-$(VERSION).txz md5=$$md5"
```

Pourquoi `rebuild_container` et pas `update_container` pour créer le container de test : `update_container` commence par un pull, ce qui remplacerait le tag `nginx:stable-alpine` que l'on vient de faire pointer sur `1.27.0-alpine` ; `rebuild_container` crée depuis l'image locale, donc le container tourne sur l'ancienne image et le badge « update ready » apparaît après `dockerupdate check`.

- [ ] **Step 4 : Vérifier le déploiement**

Run : `make deploy && ssh test-server 'ls -la /usr/local/emhttp/plugins/docker.rolling.update/ && cat /boot/config/plugins/docker.rolling.update/docker.rolling.update.cfg'`
Expected : `default.cfg` listé, le cfg affiche les trois clés.

- [ ] **Step 5 : Commit**

```bash
git add Makefile .gitignore source/
git commit -m "chore: squelette du plugin et boucle de déploiement vers test-server"
```

---

### Task 2 : `include/rolling.php` - lecture du template

**Files:**
- Create: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/include/rolling.php`

**Interfaces:**
- Produces: `template_info(string $xml): array` → `['network'=>string, 'myip'=>string, 'extra'=>string, 'tailscale'=>bool, 'ports'=>int, 'labels'=>array<string,string>]` ; `extra_has_health(string $extra): bool` ; `rolling_selftest(): bool` (exécuté par `php rolling.php`).

- [ ] **Step 1 : Écrire le fichier avec l'auto-test seul (les fonctions n'existent pas encore)**

```php
<?php
/* docker.rolling.update - fonctions pures (aucune dépendance Unraid/Docker), testables partout.
 * `php rolling.php` lance l'auto-test. GPL-2.0. */

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
```

- [ ] **Step 2 : Vérifier que l'auto-test échoue**

Run : `make selftest`
Expected : `PHP Fatal error: Uncaught Error: Call to undefined function template_info()`.

- [ ] **Step 3 : Ajouter les fonctions au-dessus de `rolling_selftest`**

```php
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
```

- [ ] **Step 4 : Vérifier que l'auto-test passe**

Run : `make selftest`
Expected : `selftest OK (10 checks)`.

- [ ] **Step 5 : Commit**

```bash
git add source/
git commit -m "feat: template_info - lecture réseau/ports/labels du template Unraid"
```

---

### Task 3 : `resolve_probe` - décision de la sonde

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/include/rolling.php`
- Modify: `docs/superpowers/specs/2026-08-28-docker-rolling-update-design.md` (§5, une phrase)

**Interfaces:**
- Consumes: `$labels` = `template_info()['labels']`.
- Produces: `resolve_probe(array $labels, bool $hasHealth, array $cfg): array` → `['type'=>'health'|'running'|'http'|'tcp'|'none', 'target'=>string, 'timeout'=>int, 'grace'=>int, 'warnings'=>string[]]`. `$cfg` = `parse_plugin_cfg('docker.rolling.update')` (valeurs string).

- [ ] **Step 1 : Ajouter les checks dans `rolling_selftest`, avant la ligne `echo $fails ? …`**

```php
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
  $p = resolve_probe(['rolling.timeout'=>'abc'], false, $cfg);
  $t($p['timeout'] === 5, 'non-numeric timeout clamps to the 5 s minimum');
  $p = resolve_probe([], false, []);
  $t($p['timeout'] === 120 && $p['grace'] === 15, 'missing cfg uses built-in defaults');
```

- [ ] **Step 2 : Vérifier l'échec**

Run : `make selftest`
Expected : `Call to undefined function resolve_probe()`.

- [ ] **Step 3 : Implémenter, au-dessus de `rolling_selftest`**

```php
/** Décide de la sonde de santé à partir des labels, de la présence d'un healthcheck et de la config globale. */
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
  if ($grace > $timeout) $timeout = $grace;   // la sonde running doit pouvoir aboutir
  return ['type'=>$type, 'target'=>$raw, 'timeout'=>$timeout, 'grace'=>$grace, 'warnings'=>$warnings];
}
```

- [ ] **Step 4 : Vérifier**

Run : `make selftest`
Expected : `selftest OK (21 checks)`.

- [ ] **Step 5 : Aligner le spec (la présence du healthcheck est lue sur l'image + ExtraParams, pas sur `State.Health`)**

Dans le spec, remplacer la phrase « La présence d'un `HEALTHCHECK` est lue sur le nouveau container (`State.Health`). » par « La présence d'un `HEALTHCHECK` est lue après le pull sur l'image (`Config.Healthcheck`, `Test[0] ≠ NONE`) ou via `--health-cmd` dans `ExtraParams` - connue avant de toucher au container. » et, dans le tableau, « `health` si l'image a un `HEALTHCHECK` (`State.Health` présent) » par « `health` si l'image a un `HEALTHCHECK` (ou `--health-cmd`) ».

- [ ] **Step 6 : Commit**

```bash
git add source/ docs/
git commit -m "feat: resolve_probe - choix de la sonde depuis les labels et la config"
```

---

### Task 4 : `traefik_missing` et `check_bluegreen` - prérequis blue/green

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/include/rolling.php`

**Interfaces:**
- Consumes: `template_info()`.
- Produces: `traefik_missing(array $labels): string[]` ; `check_bluegreen(array $info, bool $wasRunning, bool $hasHealth, ?string $networkDriver): string[]` (vide = tous les prérequis satisfaits ; chaque entrée est un message anglais prêt à afficher). `$networkDriver` = sortie de `docker network inspect -f '{{.Driver}}'`, `null` si le réseau n'existe pas.

- [ ] **Step 1 : Ajouter les checks dans `rolling_selftest`**

```php
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
```

- [ ] **Step 2 : Vérifier l'échec**

Run : `make selftest`
Expected : `Call to undefined function traefik_missing()`.

- [ ] **Step 3 : Implémenter, au-dessus de `rolling_selftest`**

```php
/** Labels Traefik manquants pour qu'une seconde instance soit fusionnée dans le même service load-balancé. */
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

/** Prérequis de la stratégie bluegreen. Retourne la liste de ce qui manque (vide = OK). */
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
```

- [ ] **Step 4 : Vérifier**

Run : `make selftest`
Expected : `selftest OK (38 checks)`.

- [ ] **Step 5 : Commit**

```bash
git add source/
git commit -m "feat: check_bluegreen - vérification des prérequis Traefik/réseau/ports/healthcheck"
```

---

### Task 5 : `scripts/rolling_update` - squelette CLI, sortie nchan, pull, dry-run

**Files:**
- Create: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update` (exécutable)
- Create: `tests/templates/my-RollingTest.xml`

**Interfaces:**
- Consumes: `template_info`, `extra_has_health`, `resolve_probe`, `check_bluegreen`, `rolling_selftest` (include/rolling.php) ; Unraid : `DockerClient`, `DockerUpdate`, `DockerTemplates`, `DockerUtil`, `xmlToCommand`, `publish`, `parse_plugin_cfg`, `_var`.
- Produces (utilisés par les tâches 6-8 et 11) : `write(...$messages)`, `log_line(string $html)`, `log_info(string)`, `log_warn(string)`, `log_err(string)`, `dk(string $args, ?int &$rc=null): string` (docker CLI, stdout+stderr), `ct_exists(string $name): bool`, `image_has_health(string $imageID): bool`, `network_driver(string $network): ?string`, `container_logs(string $name, int $tail=50): void`, `notify_unraid(string $importance, string $subject, string $description): void`, les helpers natifs `stopContainer_nchan`, `removeContainer_nchan`, `removeImage_nchan`, `pullImage_nchan`, `execCommand_nchan`, et le contexte `$u` d'`update_one` : `['name','rollback','new','repo','cmd','xml','info','exists','wasRunning','oldImageID','newImageID','probe','stoppedOld']`.

- [ ] **Step 1 : Créer le template de test `tests/templates/my-RollingTest.xml`**

```xml
<?xml version="1.0"?>
<Container version="2">
  <Name>RollingTest</Name>
  <Repository>nginx:stable-alpine</Repository>
  <Registry>https://hub.docker.com/_/nginx</Registry>
  <Network>bridge</Network>
  <MyIP/>
  <Shell>sh</Shell>
  <Privileged>false</Privileged>
  <Support/>
  <Project/>
  <Overview>Throwaway container for docker.rolling.update tests</Overview>
  <Category>Tools:</Category>
  <WebUI>http://[IP]:[PORT:80]</WebUI>
  <TemplateURL/>
  <Icon/>
  <ExtraParams>--health-cmd="wget -qO- http://127.0.0.1/ || exit 1" --health-interval=5s --health-timeout=3s</ExtraParams>
  <PostArgs/>
  <CPUset/>
  <DateInstalled>0</DateInstalled>
  <DonateText/>
  <DonateLink/>
  <Requires/>
  <Config Name="HTTP" Target="80" Default="18080" Mode="tcp" Description="" Type="Port" Display="always" Required="false" Mask="false">18080</Config>
  <Config Name="rolling.probe" Target="rolling.probe" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">@PROBE@</Config>
  <Config Name="rolling.timeout" Target="rolling.timeout" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">@TIMEOUT@</Config>
</Container>
```

- [ ] **Step 2 : Créer le container de test et vérifier le badge**

Run : `make testct && ssh test-server 'grep -o "\"nginx:stable-alpine\":{[^}]*}" /var/lib/docker/unraid-update-status.json'`
Expected : `RollingTest Up … (healthy) nginx:stable-alpine` puis `"status":"false"` (= update disponible). Sur la page Docker de test-server, RollingTest affiche « update ready ».

- [ ] **Step 3 : Écrire le script (préambule, helpers, dry-run)**

```php
#!/usr/bin/php -q
<?php
/* docker.rolling.update - mise à jour séquentielle avec porte de santé et rollback automatique.
 * Appelé par openDocker('rolling_update nom1*nom2') ; sortie streamée dans la modale native (canal nchan 'docker').
 * GPL-2.0. Réutilise des portions de dynamix.docker.manager (Copyright Lime Technology / Bergware International). */

if (($argv[1] ?? '') === '--selftest') {           // avant tout include Unraid : tourne sur n'importe quel PHP
  require __DIR__.'/../include/rolling.php';
  exit(rolling_selftest() ? 0 : 1);
}

$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/webGui/include/Wrappers.php";
extract(parse_plugin_cfg('dynamix', true));          // $display, $notify (méthodes de notification Docker)
$_SERVER['REQUEST_URI'] = '';
$login_locale = _var($display, 'locale');
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
require_once "$docroot/webGui/include/publish.php";
require_once __DIR__.'/../include/rolling.php';

$cfg = parse_plugin_cfg('docker.rolling.update');
$var = parse_ini_file('/var/local/emhttp/var.ini');
$DockerClient    = new DockerClient();
$DockerUpdate    = new DockerUpdate();
$DockerTemplates = new DockerTemplates();
$custom = DockerUtil::custom();                       // requis par xmlToCommand
$subnet = DockerUtil::network($custom);
$cpus   = DockerUtil::cpus();

// ---------- sortie vers la modale (protocole natif) + stdout optionnel ----------
function write(...$messages) {
  foreach ($messages as $m) {
    publish('docker', $m, 1, false);
    if (getenv('ROLLING_STDOUT')) echo trim(strip_tags(str_replace(["\0", '<br>'], [' ', "\n"], $m)))."\n";
  }
}
function log_line(string $html): void { write("addLog\0$html"); }
function log_info(string $txt): void  { log_line(htmlspecialchars($txt)); }
function log_warn(string $txt): void  { log_line("<span class='orange-text'><b>Warning:</b> ".htmlspecialchars($txt).'</span>'); }
function log_err(string $txt): void   { log_line("<span class='error'><b>Error:</b> ".htmlspecialchars($txt).'</span>'); }

// ---------- petits helpers Docker ----------
function dk(string $args, ?int &$rc = null): string { exec("docker $args 2>&1", $out, $rc); return trim(implode("\n", $out)); }
function ct_exists(string $name): bool { global $DockerClient; return !empty($DockerClient->getContainerDetails($name)); }
function image_has_health(string $imageID): bool {
  if ($imageID === '') return false;
  $j = json_decode(dk("image inspect -f '{{json .Config.Healthcheck}}' ".escapeshellarg($imageID)), true);
  return is_array($j) && !empty($j['Test']) && $j['Test'][0] !== 'NONE';
}
function network_driver(string $network): ?string {
  if ($network === '') return null;
  $d = dk("network inspect -f '{{.Driver}}' ".escapeshellarg($network), $rc);
  return $rc === 0 ? $d : null;
}
function container_logs(string $name, int $tail = 50): void {
  $l = dk("logs --tail $tail ".escapeshellarg($name));
  if ($l !== '') log_line("<fieldset class='docker'><legend>Last $tail log lines of ".htmlspecialchars($name)."</legend><pre style='white-space:pre-wrap'>".htmlspecialchars($l).'</pre></fieldset>');
}
function notify_unraid(string $importance, string $subject, string $description): void {
  global $docroot, $var, $notify;
  $server = strtoupper(_var($var, 'NAME', 'tower'));
  $output = _var($notify, 'docker_notify');           // mêmes canaux que les notifications Docker natives
  exec("$docroot/webGui/scripts/notify -e ".escapeshellarg('Docker Rolling Update').' -s '.escapeshellarg("Notice [$server] - $subject").' -d '.escapeshellarg($description).' -i '.escapeshellarg(trim("$importance $output"))." -l '/Docker' -x");
}

// ---------- helpers natifs (copie de dynamix.docker.manager/scripts/update_container) ----------
function stopContainer_nchan($name) {
  global $DockerClient;
  $waitID = mt_rand();
  write("<p class='logLine'></p>","addLog\0<fieldset class='docker'><legend>"._('Stopping container').": ".htmlspecialchars($name)."</legend><p class='logLine'></p><span id='wait-$waitID'>"._('Please wait')." </span></fieldset>","show_Wait\0$waitID");
  $retval = $DockerClient->stopContainer($name);
  $out = ($retval === true) ? _('Successfully stopped container').": $name" : _('Error').": ".$retval;
  write("stop_Wait\0$waitID","addLog\0<b>".htmlspecialchars($out)."</b>");
}
function removeContainer_nchan($name) {
  global $DockerClient;
  $waitID = mt_rand();
  write("<p class='logLine'></p>","addLog\0<fieldset class='docker'><legend>"._('Removing container').": ".htmlspecialchars($name)."</legend><p class='logLine'></p><span id='wait-$waitID'>"._('Please wait')." </span></fieldset>","show_Wait\0$waitID");
  $retval = $DockerClient->removeContainer($name);
  $out = ($retval === true) ? _('Successfully removed container').": $name" : _('Error').": ".$retval;
  write("stop_Wait\0$waitID","addLog\0<b>".htmlspecialchars($out)."</b>");
}
function removeImage_nchan($image) {
  global $DockerClient;
  $waitID = mt_rand();
  write("<p class='logLine'></p>","addLog\0<fieldset class='docker'><legend>"._('Removing orphan image').": ".htmlspecialchars($image)."</legend><p class='logLine'></p><span id='wait-$waitID'>"._('Please wait')." </span></fieldset>","show_Wait\0$waitID");
  $retval = $DockerClient->removeImage($image);
  $out = ($retval === true) ? _('Successfully removed orphan image').": $image" : _('Error').": ".$retval;
  write("stop_Wait\0$waitID","addLog\0<b>".htmlspecialchars($out)."</b>");
}
function pullImage_nchan($name, $image) {
  global $DockerClient, $DockerTemplates, $DockerUpdate;
  $waitID = mt_rand();
  if (!preg_match("/:\S+$/", $image)) $image .= ":latest";
  write("<p class='logLine'></p>","addLog\0<fieldset class='docker'><legend>"._('Pulling image').": ".htmlspecialchars($image)."</legend><p class='logLine'></p><span id='wait-$waitID'>"._('Please wait')." </span></fieldset>","show_Wait\0$waitID");
  $alltotals = [];
  $laststatus = [];
  $strError = '';
  $DockerClient->pullImage($image, function ($line) use (&$alltotals, &$laststatus, &$waitID, &$strError, $image, $DockerClient, $DockerUpdate) {
    $cnt = json_decode($line, true);
    $id = $cnt['id'] ?? '';
    $status = $cnt['status'] ?? '';
    if (isset($cnt['error'])) $strError = $cnt['error'];
    if ($waitID !== false) {
      write("stop_Wait\0$waitID");
      $waitID = false;
    }
    if (empty($status)) return;
    if (!empty($id)) {
      if (!empty($cnt['progressDetail']) && !empty($cnt['progressDetail']['total'])) {
        $alltotals[$id] = $cnt['progressDetail']['total'];
      }
      if (empty($laststatus[$id])) {
        $laststatus[$id] = '';
      }
      switch ($status) {
      case 'Waiting':
        break;
      case 'Downloading':
        if ($laststatus[$id] != $status) {
          write("addToID\0$id\0".htmlspecialchars($status));
        }
        $total = $cnt['progressDetail']['total'];
        $current = $cnt['progressDetail']['current'];
        if ($total > 0) {
          $percentage = round(($current / $total) * 100);
          write("progress\0$id\0 ".$percentage."% "._('of')." ".$DockerClient->formatBytes($total));
        } else {
          $alltotals[$id] = $current;
          write("progress\0$id\0".$DockerClient->formatBytes($current));
        }
        break;
      default:
        if ($laststatus[$id] == "Downloading") {
          write("progress\0$id\0 100% "._('of')." ".$DockerClient->formatBytes($alltotals[$id]));
        }
        if ($laststatus[$id] != $status) {
          write("addToID\0".($id=='latest'?mt_rand():$id)."\0".htmlspecialchars($status));
        }
        break;
      }
      $laststatus[$id] = $status;
    } else {
      if (strpos($status, 'Status: ') === 0) {
        write("addLog\0".htmlspecialchars($status));
      }
      if (strpos($status, 'Digest: ') === 0) {
        $DockerUpdate->setUpdateStatus($image, substr($status,8));
      }
    }
  });
  write("addLog\0<br><b>"._('TOTAL DATA PULLED').":</b> ".$DockerClient->formatBytes(array_sum($alltotals)));
  if (!empty($strError)) {
    write("addLog\0<br><span class='error'><b>"._('Error').":</b> ".htmlspecialchars($strError)."</span>");
    return false;
  }
  return true;
}
function execCommand_nchan($command) {
  $waitID = mt_rand();
  [$cmd,$args] = explode(' ',$command,2);
  write("<p class='logLine'></p>","addLog\0<fieldset class='docker'><legend>"._('Command execution')."</legend>".basename($cmd).' '.str_replace(" -","<br>&nbsp;&nbsp;-",htmlspecialchars($args))."<br><span id='wait-$waitID'>"._('Please wait')." </span><p class='logLine'></p></fieldset>","show_Wait\0$waitID");
  $proc = popen("$command 2>&1",'r');
  while ($out = fgets($proc)) {
    $out = preg_replace("%[\t\n\x0B\f\r]+%", '',$out);
    write("addLog\0".htmlspecialchars($out));
  }
  $retval = pclose($proc);
  $out = $retval ? _('The command failed').'.' : _('The command finished successfully').'!';
  write("stop_Wait\0$waitID","addLog\0<br><b>$out</b>");
  return $retval===0;
}

// ---------- un container ----------
/** Retourne 'ok' | 'rolled' | 'skipped'. */
function update_one(string $name): string {
  global $DockerClient, $DockerTemplates, $cfg;
  $tmpl = $DockerTemplates->getUserTemplate($name);
  if (!$tmpl) { log_err("Configuration not found for '$name'. Was this container created using the Docker template system?"); return 'skipped'; }
  $xml = file_get_contents($tmpl);
  [$cmd, $Name, $Repository] = xmlToCommand($tmpl);
  $info = template_info($xml);
  $u = ['name'=>$Name, 'rollback'=>"$Name.rollback", 'new'=>"$Name.new", 'repo'=>DockerUtil::ensureImageTag($Repository),
        'cmd'=>$cmd, 'xml'=>$xml, 'info'=>$info, 'exists'=>false, 'wasRunning'=>false,
        'oldImageID'=>'', 'newImageID'=>'', 'probe'=>[], 'stoppedOld'=>false];
  log_line("<p class='logLine'></p><b>=== ".htmlspecialchars($Name).' ===</b>');
  if (ct_exists($u['rollback'])) {
    log_err("A container named {$u['rollback']} already exists (a previous update was interrupted). Check it, then remove or rename it manually.");
    return 'skipped';
  }
  $old = $DockerClient->getContainerDetails($Name);
  $u['exists']     = !empty($old);
  $u['wasRunning'] = !empty($old['State']['Running']);
  $u['oldImageID'] = $DockerClient->getImageID($Repository) ?: '';
  if (!pullImage_nchan($Name, $Repository)) return 'skipped';   // rien n'a été touché
  $DockerClient->flushCaches();
  $u['newImageID'] = $DockerClient->getImageID($Repository) ?: '';
  $hasHealth  = image_has_health($u['newImageID']) || extra_has_health($info['extra']);
  $u['probe'] = resolve_probe($info['labels'], $hasHealth, $cfg);
  $strategy = 'safe';
  if (($info['labels']['rolling.strategy'] ?? 'safe') === 'bluegreen') {
    $missing = check_bluegreen($info, $u['wasRunning'], $hasHealth, network_driver($info['network']));
    if (!$missing) $strategy = 'bluegreen';
    else { log_warn('bluegreen strategy not applicable, using safe mode:'); foreach ($missing as $m) log_line('&nbsp;&nbsp;- '.htmlspecialchars($m)); }
  }
  // dry-run (tâche 5) : remplacé par update_safe / update_bluegreen dans les tâches 6 et 11
  log_info("DRY RUN: strategy=$strategy probe={$u['probe']['type']} timeout={$u['probe']['timeout']}s grace={$u['probe']['grace']}s running=".($u['wasRunning'] ? 'yes' : 'no')." oldImage={$u['oldImageID']} newImage={$u['newImageID']}");
  return 'skipped';
}

// ---------- boucle principale ----------
write("<style>.logLine{font-family:bitstream!important;font-size:1.2rem!important;margin:0;padding:0}fieldset.docker{border:solid thin;margin-top:8px}legend{font-size:1.1rem!important;font-weight:bold}</style><p class='logLine'></p>");
$summary = ['ok'=>[], 'rolled'=>[], 'skipped'=>[]];
foreach (array_filter(explode('*', rawurldecode($argv[1] ?? ''))) as $name) {
  $summary[update_one($name)][] = $name;
}
log_line('<br><b>Summary:</b> '.count($summary['ok']).' updated, '.count($summary['rolled']).' rolled back, '.count($summary['skipped']).' skipped');
write('_DONE_', '');
exit(count($summary['rolled']) ? 1 : 0);
```

- [ ] **Step 4 : Rendre exécutable, auto-test local via le dispatch, déployer**

Run : `chmod +x source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update && php source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update --selftest && make deploy`
Expected : `selftest OK (38 checks)` puis rsync sans erreur.

- [ ] **Step 5 : Dry-run sur le serveur**

Run : `ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest'`
Expected : lignes `=== RollingTest ===`, le pull de `nginx:stable-alpine` avec `TOTAL DATA PULLED`, puis `DRY RUN: strategy=safe probe=health timeout=120s grace=15s running=yes oldImage=sha256:… newImage=sha256:…` (IDs différents), `Summary: 0 updated, 0 rolled back, 1 skipped`, `_DONE_`. Le container n'a pas été touché : `ssh test-server docker ps --filter name=RollingTest` le montre toujours `Up`.

- [ ] **Step 6 : Vérifier le streaming dans la modale native**

Ouvrir `http://<server-ip>/Docker`, console du navigateur : `openDocker('rolling_update RollingTest','rolling test','','loadlist')`.
Expected : la modale native s'ouvre, affiche le pull puis `DRY RUN…`, se termine avec le bouton Done, la liste se recharge.

- [ ] **Step 7 : Commit**

```bash
git add source/ tests/
git commit -m "feat: rolling_update - squelette CLI, sortie nchan, pull et dry-run"
```

---

### Task 6 : Mode sûr - recréation par `rename` (sans porte de santé)

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update`

**Interfaces:**
- Consumes: `$u`, helpers de la tâche 5, `addRoute`, `getXmlVal`.
- Produces: `tailscale_wrap(array $u): string`, `connect_extra(string $ctname, array $u): bool`, `finish_ok(array $u): void`, `update_safe(array $u): string`. La porte de santé et le rollback arrivent en tâche 7 : ici, un échec de `docker run` laisse `<nom>.rollback` en place et retourne `'rolled'` (message explicite).

- [ ] **Step 1 : Ajouter les fonctions avant `update_one`**

```php
// ---------- recréation ----------
/** Miroir de l'astuce Tailscale native : injecte ORG_ENTRYPOINT/ORG_CMD dans la commande. No-op si Tailscale est désactivé. */
function tailscale_wrap(array $u): string {
  global $DockerClient;
  $cmd = $u['cmd'];
  if (!$u['info']['tailscale']) return $cmd;
  exec('docker create --name '.escapeshellarg($u['name']).' '.escapeshellarg($u['repo']).' 2>/dev/null');
  $ci  = $DockerClient->getContainerDetails($u['name']);
  $ts  = isset($ci['Config']['Entrypoint']) ? '-e ORG_ENTRYPOINT="'.implode(' ', $ci['Config']['Entrypoint']).'" ' : '';
  $ts .= isset($ci['Config']['Cmd'])        ? '-e ORG_CMD="'.implode(' ', $ci['Config']['Cmd']).'" ' : '';
  $cmd = str_replace('-l net.unraid.docker.managed=dockerman', $ts.'-l net.unraid.docker.managed=dockerman', $cmd);
  exec('docker rm '.escapeshellarg($u['name']).' 2>/dev/null');
  return $cmd;
}
/** connectExtraNetworks n'existe pas en 7.3.2 : ne rien faire dans ce cas. */
function connect_extra(string $ctname, array $u): bool {
  if (!function_exists('connectExtraNetworks')) return true;
  return connectExtraNetworks($ctname, getXmlVal($u['xml'], 'ExtraNetworks'), $u['info']['network']);
}
/** Fin de mise à jour réussie : supprime l'ancien container, l'ancienne image, notifie. */
function finish_ok(array $u): void {
  global $DockerClient, $cfg;
  if (ct_exists($u['rollback'])) removeContainer_nchan($u['rollback']);
  $DockerClient->flushCaches();
  if ($u['oldImageID'] !== '' && $u['oldImageID'] !== $u['newImageID']) removeImage_nchan($u['oldImageID']);
  if (($cfg['NOTIFY'] ?? 'yes') === 'yes') notify_unraid('normal', "{$u['name']}: updated", "Container {$u['name']} was updated and passed its health gate.");
  log_line('<b>'.htmlspecialchars($u['name']).' updated successfully</b>');
}
/** Mode sûr : stop → rename en .rollback → run → (porte de santé, tâche 7) → nettoyage. */
function update_safe(array $u): string {
  global $DockerClient;
  $GLOBALS['inflight'] = $u;
  if ($u['wasRunning']) { stopContainer_nchan($u['name']); $GLOBALS['inflight']['stoppedOld'] = true; }
  if ($u['exists']) {
    $out = dk('rename '.escapeshellarg($u['name']).' '.escapeshellarg($u['rollback']), $rc);
    if ($rc !== 0) {
      log_err("Could not rename {$u['name']} to {$u['rollback']}: $out");
      if ($u['wasRunning']) $DockerClient->startContainer($u['name']);
      return 'rolled';
    }
    log_info("Previous container kept as {$u['rollback']} until the new one is verified");
  }
  $cmd = tailscale_wrap($u);
  if ($u['wasRunning']) $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  $err = null;
  if (!execCommand_nchan($cmd))              $err = 'docker run failed';
  elseif (!connect_extra($u['name'], $u))    $err = 'could not connect one or more additional networks';
  elseif ($u['wasRunning'])                  addRoute($u['name']);
  if ($err !== null) {
    log_err("Update of {$u['name']} failed: $err. Previous container is still available as {$u['rollback']}.");   // rollback automatique en tâche 7
    return 'rolled';
  }
  unset($GLOBALS['inflight']);
  finish_ok($u);
  return 'ok';
}
```

- [ ] **Step 2 : Remplacer le dry-run dans `update_one`**

Remplacer les deux lignes `// dry-run …` et `log_info("DRY RUN: …"); return 'skipped';` par :

```php
  return $strategy === 'bluegreen' ? update_safe($u) : update_safe($u);   // update_bluegreen arrive en tâche 11
```

- [ ] **Step 3 : Déployer et tester le chemin nominal**

Run : `make deploy && make testct && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest; echo "rc=$?"; docker ps -a --filter name=RollingTest --format "{{.Names}} {{.Status}} {{.Image}}"; docker images nginx --format "{{.Tag}} {{.ID}}"'`
Expected : log `Stopping container`, `Previous container kept as RollingTest.rollback`, `Command execution … docker run -d --name='RollingTest' …`, `The command finished successfully!`, `Removing container: RollingTest.rollback`, `Removing orphan image`, `RollingTest updated successfully`, `Summary: 1 updated, 0 rolled back, 0 skipped`, `rc=0`. `docker ps -a` : un seul `RollingTest`, `Up … (healthy)` ; aucune image `1.27.0-alpine` taguée `stable-alpine` restante.

- [ ] **Step 4 : Vérifier le badge**

Run : `ssh test-server '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/dockerupdate check nonotify >/dev/null; grep -o "\"nginx:stable-alpine\":{[^}]*}" /var/lib/docker/unraid-update-status.json'`
Expected : `"status":"true"` (à jour).

- [ ] **Step 5 : Container arrêté (pas de porte, `docker create`)**

Run : `make testct && ssh test-server 'docker stop RollingTest >/dev/null; ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest | grep -E "docker (create|run)|updated successfully"; docker ps -a --filter name=RollingTest --format "{{.Names}} {{.Status}}"'`
Expected : la commande contient `docker create` (pas `run -d`), `RollingTest updated successfully`, statut `Created`.

- [ ] **Step 6 : Commit**

```bash
git add source/
git commit -m "feat: mode sûr - recréation par rename, l'ancien container survit jusqu'à validation"
```

---

### Task 7 : Porte de santé et rollback automatique

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update`

**Interfaces:**
- Consumes: `$u['probe']` (`resolve_probe`), `getContainerDetails`, `restore_image`.
- Produces: `wait_healthy(string $name, array $probe): ?string` (null = sain, sinon la raison), `restore_image(string $repo, string $oldImageID, string $newImageID): void`, `rollback_safe(array $u, string $reason): void`.

- [ ] **Step 1 : Ajouter les fonctions avant `update_safe`**

```php
// ---------- porte de santé ----------
/** Attend que $name soit sain selon $probe. Retourne null si OK, sinon la raison de l'échec. */
function wait_healthy(string $name, array $probe): ?string {
  global $DockerClient;
  foreach ($probe['warnings'] as $w) log_warn($w);
  if ($probe['type'] === 'none') { log_info('Health gate: none (rolling.probe=none)'); return null; }
  $waitID = mt_rand();
  $what   = $probe['type'].(in_array($probe['type'], ['http', 'tcp'], true) ? ' '.$probe['target'] : '');
  write("<p class='logLine'></p>", "addLog\0<fieldset class='docker'><legend>Health gate: ".htmlspecialchars($what)." (timeout {$probe['timeout']}s)</legend><p class='logLine'></p><span id='wait-$waitID'>Please wait </span></fieldset>", "show_Wait\0$waitID");
  $start = time(); $deadline = $start + $probe['timeout']; $ok = false; $reason = '';
  while (true) {
    $d = $DockerClient->getContainerDetails($name);
    $state = $d['State'] ?? [];
    if (empty($state['Running'])) { $reason = 'container exited (code '.($state['ExitCode'] ?? '?').')'; break; }
    switch ($probe['type']) {
      case 'health':
        $h = $state['Health']['Status'] ?? null;
        if ($h === 'healthy')   $ok = true;
        if ($h === 'unhealthy') $reason = 'healthcheck reported unhealthy';
        break;
      case 'running':
        if (time() - $start >= $probe['grace']) {
          if ((int)($d['RestartCount'] ?? 0) === 0) $ok = true; else $reason = 'container restarted during the grace period';
        }
        break;
      case 'http':
        exec('curl -sf -o /dev/null --max-time 3 '.escapeshellarg($probe['target']).' 2>/dev/null', $o, $rc);
        $ok = ($rc === 0);
        break;
      case 'tcp':
        [$h, $p] = explode(':', substr($probe['target'], 6), 2);
        if ($fp = @fsockopen($h, (int)$p, $errno, $errstr, 3)) { fclose($fp); $ok = true; }
        break;
    }
    if ($ok || $reason !== '') break;
    if (time() >= $deadline) { $reason = "timeout after {$probe['timeout']}s (state: ".($state['Health']['Status'] ?? $state['Status'] ?? '?').')'; break; }
    sleep(2);
  }
  write("stop_Wait\0$waitID");
  if ($ok) { log_line('<b>Healthy after '.(time() - $start).'s</b>'); return null; }
  log_err("Health gate failed: $reason");
  return $reason;
}

// ---------- rollback ----------
/** Re-pointe le tag sur l'ancienne image (ne peut pas échouer), supprime la nouvelle en best-effort, rafraîchit le badge. */
function restore_image(string $repo, string $oldImageID, string $newImageID): void {
  global $DockerClient, $DockerUpdate;
  if ($oldImageID === '' || $oldImageID === $newImageID) return;
  dk('tag '.escapeshellarg($oldImageID).' '.escapeshellarg($repo));
  $DockerClient->removeImage($newImageID);            // échoue sans conséquence si une autre container l'utilise
  $DockerClient->flushCaches();
  $DockerUpdate->reloadUpdateStatus($repo);           // le badge « update ready » revient : l'état vrai
  log_info("Image tag $repo restored to the previous image");
}
/** Mode sûr : supprime le nouveau container, remet l'ancien en place (nom, état, image), notifie. */
function rollback_safe(array $u, string $reason): void {
  global $DockerClient;
  log_err("Update of {$u['name']} rolled back: $reason");
  if (ct_exists($u['name'])) { container_logs($u['name']); dk('rm -f '.escapeshellarg($u['name'])); }
  if (ct_exists($u['rollback'])) {
    dk('rename '.escapeshellarg($u['rollback']).' '.escapeshellarg($u['name']), $rc);
    if ($rc !== 0) {
      log_err("Could not rename {$u['rollback']} back to {$u['name']} - manual intervention required");
      notify_unraid('alert', "{$u['name']}: manual intervention required", "Rollback failed: {$u['rollback']} could not be renamed back to {$u['name']}.");
      return;
    }
    if ($u['wasRunning']) {
      $r = $DockerClient->startContainer($u['name']);   // startContainer appelle déjà addRoute
      if ($r !== true) {
        log_err("Could not restart the previous container: $r - manual intervention required");
        notify_unraid('alert', "{$u['name']}: manual intervention required", "Previous container could not be restarted: $r");
        return;
      }
      log_info("Previous container {$u['name']} restarted");
    }
  }
  restore_image($u['repo'], $u['oldImageID'], $u['newImageID']);
  $DockerClient->flushCaches();
  notify_unraid('alert', "{$u['name']}: update rolled back", $reason);
}
```

- [ ] **Step 2 : Brancher la porte et le rollback dans `update_safe`**

Remplacer, dans `update_safe`, le bloc depuis `$err = null;` jusqu'à `return 'rolled'; }` (inclus) par :

```php
  $err = null;
  if (!execCommand_nchan($cmd))              $err = 'docker run failed';
  elseif (!connect_extra($u['name'], $u))    $err = 'could not connect one or more additional networks';
  elseif ($u['wasRunning']) { addRoute($u['name']); $err = wait_healthy($u['name'], $u['probe']); }
  if ($err !== null) { rollback_safe($u, $err); unset($GLOBALS['inflight']); return 'rolled'; }
```

- [ ] **Step 3 : Chemin d'échec (sonde TCP sur un port fermé)**

Run : `make deploy && make testct PROBE=tcp://127.0.0.1:1 TIMEOUT=20 && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest; echo "rc=$?"; docker ps -a --filter name=RollingTest --format "{{.Names}} {{.Status}} {{.Image}}"; docker inspect -f "{{.Image}}" RollingTest; docker images --digests nginx --format "{{.Tag}} {{.ID}}"; grep -o "\"nginx:stable-alpine\":{[^}]*}" /var/lib/docker/unraid-update-status.json'`
Expected : `Health gate: tcp tcp://127.0.0.1:1 (timeout 20s)`, après ~20 s `Health gate failed: timeout after 20s (state: healthy)`, `Update of RollingTest rolled back: …`, le fieldset `Last 50 log lines of RollingTest`, `Previous container RollingTest restarted`, `Image tag nginx:stable-alpine restored to the previous image`, `Summary: 0 updated, 1 rolled back, 0 skipped`, `rc=1`. Un seul `RollingTest`, `Up … (healthy)`, son `.Image` = l'ID de `nginx:1.27.0-alpine` ; le tag `stable-alpine` pointe sur ce même ID ; le statut est `"status":"false"` (update ready de nouveau).

- [ ] **Step 4 : Chemin d'échec (container qui meurt)**

Run : `make testct && ssh test-server 'sed -i "s|--health-timeout=3s|--health-timeout=3s --entrypoint=/bin/false|" /boot/config/plugins/dockerMan/templates-user/my-RollingTest.xml; ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest | grep -E "Health gate|rolled back|restarted"; docker ps --filter name=RollingTest --format "{{.Names}} {{.Status}}"'`
Expected : `Health gate failed: container exited (code 1)`, rollback, `Previous container RollingTest restarted`, container `Up`.

- [ ] **Step 5 : Chemin de succès avec healthcheck**

Run : `make testct PROBE=health && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest | grep -E "Health gate|Healthy after|updated successfully|Summary"'`
Expected : `Health gate: health (timeout 120s)`, `Healthy after Ns` (N ≤ 15), `RollingTest updated successfully`, `Summary: 1 updated, 0 rolled back, 0 skipped`.

- [ ] **Step 6 : Sonde `running` (image sans healthcheck ni --health-cmd)**

Run : `make testct && ssh test-server 'sed -i "s|<ExtraParams>.*</ExtraParams>|<ExtraParams></ExtraParams>|" /boot/config/plugins/dockerMan/templates-user/my-RollingTest.xml; ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest | grep -E "Health gate|Healthy after|Warning"'`
Expected : `Warning: rolling.probe=health but the container has no healthcheck, using 'running'`, `Health gate: running (timeout 120s)`, `Healthy after 15s` (ou 16).

- [ ] **Step 7 : Commit**

```bash
git add source/
git commit -m "feat: porte de santé (health/running/http/tcp) et rollback automatique avec restauration de l'image"
```

---

### Task 8 : Notifications vérifiées, abort et arrêt brutal

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update`

**Interfaces:**
- Consumes: `$GLOBALS['inflight']` (posé par `update_safe`, plus tard `update_bluegreen`), `rollback_safe`.
- Produces: `abort_handler(): void` branché sur SIGTERM/SIGHUP/SIGINT et sur les erreurs fatales.

- [ ] **Step 1 : Ajouter après la définition de `notify_unraid` (avant les helpers natifs)**

```php
// ---------- abort / erreur fatale : remettre l'ancien container en service ----------
$inflight = null;   // contexte $u du container en cours, posé par update_safe / update_bluegreen, retiré au point de non-retour
function abort_handler(): void {
  global $DockerClient;
  $u = $GLOBALS['inflight'] ?? null;
  if ($u) {
    unset($GLOBALS['inflight']);
    log_err("Operation interrupted - restoring {$u['name']}");
    if (ct_exists($u['new'])) dk('rm -f '.escapeshellarg($u['new']));
    if (ct_exists($u['rollback'])) rollback_safe($u, 'operation interrupted');
    elseif (!empty($u['stoppedOld']) && ct_exists($u['name'])) $DockerClient->startContainer($u['name']);
  }
  write('_DONE_', '');
  exit(130);
}
pcntl_async_signals(true);
foreach ([SIGTERM, SIGHUP, SIGINT] as $sig) pcntl_signal($sig, 'abort_handler');
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true) && !empty($GLOBALS['inflight'])) abort_handler();
});
```

Note : bash ≥ 5.1 exec la dernière commande du wrapper `bash -c`, donc le bouton d'abort de la bannière (kill du pid) atteint bien ce script en 7.3.2. Ce handler sert pour un `kill` manuel, le futur tray de `master` (SIGTERM au groupe) et les erreurs fatales PHP.

- [ ] **Step 2 : Tester l'interruption pendant la porte de santé**

Run : `make deploy && make testct PROBE=tcp://127.0.0.1:1 TIMEOUT=60 && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest > /tmp/rolling.log 2>&1 & sleep 25; pkill -TERM -f "scripts/rolling_update RollingTest"; sleep 5; tail -5 /tmp/rolling.log; docker ps -a --filter name=RollingTest --format "{{.Names}} {{.Status}}"'`
Expected : `Operation interrupted - restoring RollingTest`, `Previous container RollingTest restarted`, `_DONE_` ; un seul `RollingTest` `Up`, pas de `.rollback`.

- [ ] **Step 3 : Vérifier les notifications Unraid**

Run : `ssh test-server 'ls -t /tmp/notifications/unread/ | head -3; f=$(ls -t /tmp/notifications/unread/ | head -1); cat "/tmp/notifications/unread/$f"'`
Expected : la dernière notification a `event=Docker Rolling Update`, `subject=Notice [SERVER] - RollingTest: update rolled back`, `importance=alert`, `description=operation interrupted`. Elle est visible dans la cloche de l'UI.

- [ ] **Step 4 : Vérifier la notification de succès**

Run : `make testct && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest >/dev/null; f=$(ls -t /tmp/notifications/unread/ | head -1); grep -E "subject|importance" "/tmp/notifications/unread/$f"'`
Expected : `subject=Notice [SERVER] - RollingTest: updated`, `importance=normal`.

- [ ] **Step 5 : Commit**

```bash
git add source/
git commit -m "feat: restauration sur interruption (signaux, erreur fatale)"
```

---

### Task 9 : Injection JS dans la page Docker

**Files:**
- Create: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/RollingUpdate.Docker.page`

**Interfaces:**
- Consumes: globales JS natives `openDocker`, `updateContainer`, `updateAll`, `docker[]`, `loadlist`, `swal`, `_`.
- Produces: `updateContainer(name)` et `updateAll()` redéfinis pour appeler `rolling_update`.

- [ ] **Step 1 : Créer la page**

```
Menu="Docker"
Cond="is_file('/var/run/dockerd.pid')"
---
<script>
// docker.rolling.update : route les actions Update / Update All natives vers scripts/rolling_update.
// docker.js est chargé en synchrone dans la page Docker, donc les globales existent au DOM ready.
$(function(){
  if (typeof openDocker !== 'function' || typeof updateContainer !== 'function') return;
  window.updateContainer = function(container) {
    swal({
      title:_('Are you sure?'),
      text:_('Update container')+': '+container+'<br><small>Rolling update: health gate + automatic rollback</small>',
      type:'warning', html:true, showCancelButton:true, closeOnConfirm:false,
      confirmButtonText:_('Yes, update it!'), cancelButtonText:_('Cancel')
    }, function(){
      openDocker('rolling_update '+encodeURIComponent(container), _('Update container')+': '+container, '', 'loadlist');
    });
  };
  window.updateAll = function() {
    $('input[type=button]').prop('disabled', true);
    var ct = [];
    for (var i=0, d; d=docker[i]; i++) if (d.update==1) ct.push(encodeURIComponent(d.name));
    if (!ct.length) { loadlist(); return; }
    openDocker('rolling_update '+ct.join('*'), _('Updating all Containers')+' ('+ct.length+')', '', 'loadlist');
  };
});
</script>
```

- [ ] **Step 2 : Déployer et tester depuis l'UI**

Run : `make deploy && make testct`
Puis dans le navigateur, `http://<server-ip>/Docker` : cliquer « update ready » sur la ligne RollingTest.
Expected : le dialogue de confirmation affiche la ligne « Rolling update: health gate + automatic rollback » ; après confirmation, la modale native montre le pull, `Health gate: health`, `Healthy after Ns`, `RollingTest updated successfully` ; à la fermeture, la liste se recharge et RollingTest est « up-to-date ».

- [ ] **Step 3 : Tester Update All**

Run : `make testct` puis, dans l'UI, bouton « Update All ».
Expected : titre de modale `Updating all Containers (1)` (seul RollingTest a un update), même déroulé. Si d'autres containers de production ont un update disponible, **ne pas cliquer Update All** : tester à la place via la console `updateAll` n'est pas nécessaire - vérifier seulement que `updateAll.toString()` dans la console contient `rolling_update`.

- [ ] **Step 4 : Vérifier la neutralité sur les autres actions**

Dans l'UI : menu contextuel d'un container → Start/Stop/Logs fonctionnent ; « Check for Updates » fonctionne.
Expected : aucune erreur console (F12), comportement natif.

- [ ] **Step 5 : Commit**

```bash
git add source/
git commit -m "feat: page Docker - Update et Update All passent par rolling_update"
```

---

### Task 10 : Page Settings

**Files:**
- Create: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/RollingUpdateSettings.page`

**Interfaces:**
- Consumes: `parse_plugin_cfg('docker.rolling.update')`, helpers Unraid `mk_option`, `done()`, `/update.php`.
- Produces: écriture de `TIMEOUT`, `GRACE`, `NOTIFY` dans `/boot/config/plugins/docker.rolling.update/docker.rolling.update.cfg`, lus par `rolling_update` au prochain lancement.

- [ ] **Step 1 : Créer la page**

```
Menu="Utilities"
Title="Docker Rolling Update"
Icon="refresh"
---
<?
$cfg = parse_plugin_cfg('docker.rolling.update');
?>
<form markdown="1" method="POST" action="/update.php" target="progressFrame">
<input type="hidden" name="#file" value="docker.rolling.update/docker.rolling.update.cfg">

_(Health gate timeout)_ (seconds):
: <input type="number" name="TIMEOUT" min="5" max="3600" value="<?=htmlspecialchars($cfg['TIMEOUT'] ?? '120')?>">

> Maximum time to wait for the new container to become healthy before rolling back. Per-container override: label `rolling.timeout`.

_(Grace period)_ (seconds):
: <input type="number" name="GRACE" min="1" max="3600" value="<?=htmlspecialchars($cfg['GRACE'] ?? '15')?>">

> For containers without a healthcheck: how long the new container must stay up without restarting. Per-container override: label `rolling.grace`.

_(Notify on successful update)_:
: <select name="NOTIFY"><?=mk_option($cfg['NOTIFY'] ?? 'yes', 'yes', _('Yes'))?><?=mk_option($cfg['NOTIFY'] ?? 'yes', 'no', _('No'))?></select>

> Rollbacks always send an alert notification.

&nbsp;
: <input type="submit" value="_(Apply)_"><input type="button" value="_(Done)_" onclick="done()">
</form>

### Per-container labels

Edit the container → *Add another Path, Port, Variable, Label or Device* → **Label**.

| Label | Values | Default |
|---|---|---|
| `rolling.strategy` | `safe`, `bluegreen` | `safe` |
| `rolling.probe` | `health`, `running`, `http://host:port/path`, `tcp://host:port`, `none` | `health` if the image has a HEALTHCHECK (or `--health-cmd` in Extra Parameters), otherwise `running` |
| `rolling.timeout` | seconds | global setting |
| `rolling.grace` | seconds | global setting |

**`bluegreen`** (zero-downtime, Traefik Docker provider only) requires: a user-defined bridge network, no host port mapping, no fixed IP, a healthcheck, Traefik labels with an explicit `traefik.http.routers.<r>.service=<s>` and `traefik.http.services.<s>.loadbalancer.server.port=<port>`, Tailscale disabled - and an application that tolerates two instances running on the same data. When a prerequisite is missing the update falls back to safe mode and lists what is missing.
```

- [ ] **Step 2 : Déployer et tester**

Run : `make deploy`
Dans l'UI : Settings → Utilities → Docker Rolling Update. Mettre `Health gate timeout` à 60, Apply.
Run : `ssh test-server cat /boot/config/plugins/docker.rolling.update/docker.rolling.update.cfg`
Expected : `TIMEOUT="60"`, `GRACE="15"`, `NOTIFY="yes"`. La page ré-affiche 60 après rechargement.

- [ ] **Step 3 : Vérifier la prise en compte**

Run : `make testct && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest | grep "Health gate:"'`
Expected : `Health gate: health (timeout 60s)`. Remettre 120 dans l'UI ensuite.

- [ ] **Step 4 : Commit**

```bash
git add source/
git commit -m "feat: page Settings (timeout, grace, notify) et aide sur les labels"
```

---

### Task 11 : Stratégie `bluegreen`

**Files:**
- Modify: `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update`
- Create: `tests/templates/my-RollingBG.xml`

**Interfaces:**
- Consumes: `check_bluegreen` (déjà appelé dans `update_one`), `wait_healthy`, `restore_image`, `finish_ok`, `stopContainer_nchan`, `execCommand_nchan`, `connect_extra`, `addRoute`.
- Produces: `update_bluegreen(array $u): string`, `rollback_bg(array $u, string $reason): void`.

- [ ] **Step 1 : Créer `tests/templates/my-RollingBG.xml`**

```xml
<?xml version="1.0"?>
<Container version="2">
  <Name>RollingBG</Name>
  <Repository>nginx:stable-alpine</Repository>
  <Registry>https://hub.docker.com/_/nginx</Registry>
  <Network>frontend</Network>
  <MyIP/>
  <Shell>sh</Shell>
  <Privileged>false</Privileged>
  <Support/>
  <Project/>
  <Overview>Throwaway container for docker.rolling.update blue/green tests</Overview>
  <Category>Tools:</Category>
  <WebUI>http://rollingbg.test/</WebUI>
  <TemplateURL/>
  <Icon/>
  <ExtraParams>--health-cmd="wget -qO- http://127.0.0.1/ || exit 1" --health-interval=5s --health-timeout=3s</ExtraParams>
  <PostArgs/>
  <CPUset/>
  <DateInstalled>0</DateInstalled>
  <DonateText/>
  <DonateLink/>
  <Requires/>
  <!--PORT-->
  <Config Name="traefik.enable" Target="traefik.enable" Default="true" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">true</Config>
  <Config Name="router rule" Target="traefik.http.routers.rollingbg.rule" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">Host(`rollingbg.test`)</Config>
  <Config Name="router entrypoints" Target="traefik.http.routers.rollingbg.entrypoints" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">web</Config>
  <Config Name="router service" Target="traefik.http.routers.rollingbg.service" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">rollingbg</Config>
  <Config Name="service port" Target="traefik.http.services.rollingbg.loadbalancer.server.port" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">80</Config>
  <Config Name="rolling.strategy" Target="rolling.strategy" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">bluegreen</Config>
  <Config Name="rolling.probe" Target="rolling.probe" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">@PROBE@</Config>
  <Config Name="rolling.timeout" Target="rolling.timeout" Default="" Mode="" Description="" Type="Label" Display="always" Required="false" Mask="false">@TIMEOUT@</Config>
</Container>
```

- [ ] **Step 2 : Créer le container et vérifier le routage Traefik (entrypoint `web` = port host 8080, sans redirection HTTPS - vérifié dans `traefik.yaml`)**

Run : `make testbg && ssh test-server 'sleep 3; curl -s -o /dev/null -w "%{http_code}\n" -H "Host: rollingbg.test" http://127.0.0.1:8080/'`
Expected : `RollingBG Up … (healthy)` puis `200`.

- [ ] **Step 3 : Ajouter les fonctions avant `update_one`**

```php
// ---------- bluegreen ----------
/** L'ancien a été arrêté : le remettre en service, supprimer la nouvelle instance, restaurer l'image. */
function rollback_bg(array $u, string $reason): void {
  global $DockerClient;
  log_err("Update of {$u['name']} rolled back: $reason");
  if (ct_exists($u['rollback'])) dk('rename '.escapeshellarg($u['rollback']).' '.escapeshellarg($u['name']));
  if (ct_exists($u['new']))      dk('rm -f '.escapeshellarg($u['new']));
  if (ct_exists($u['name'])) {
    $r = $DockerClient->startContainer($u['name']);
    if ($r !== true) log_err("Could not restart the previous container: $r - manual intervention required");
    else log_info("Previous container {$u['name']} restarted");
  }
  restore_image($u['repo'], $u['oldImageID'], $u['newImageID']);
  $DockerClient->flushCaches();
  notify_unraid('alert', "{$u['name']}: update rolled back", $reason);
}
/** Nouvelle instance <nom>.new à côté de l'ancienne ; Traefik bascule une fois healthy ; puis double rename. */
function update_bluegreen(array $u): string {
  global $DockerClient;
  $GLOBALS['inflight'] = $u;
  log_line('<b>Strategy: bluegreen</b> - new instance started alongside the old one, switched by Traefik once healthy');
  if (ct_exists($u['new'])) { log_warn("Removing leftover {$u['new']}"); dk('rm -f '.escapeshellarg($u['new'])); }
  $cmd = str_replace('/docker create ', '/docker run -d ', $u['cmd']);
  $cmd = str_replace('--name='.escapeshellarg($u['name']), '--name='.escapeshellarg($u['new']), $cmd);   // format exact de xmlToCommand
  $err = null;
  if (!execCommand_nchan($cmd))            $err = 'docker run failed';
  elseif (!connect_extra($u['new'], $u))   $err = 'could not connect one or more additional networks';
  else                                     $err = wait_healthy($u['new'], $u['probe']);
  if ($err !== null) {                     // rollback sans coupure : l'ancien n'a jamais été arrêté
    log_err("Update of {$u['name']} rolled back: $err");
    if (ct_exists($u['new'])) { container_logs($u['new']); dk('rm -f '.escapeshellarg($u['new'])); }
    restore_image($u['repo'], $u['oldImageID'], $u['newImageID']);
    $DockerClient->flushCaches();
    notify_unraid('alert', "{$u['name']}: update rolled back", $err);
    unset($GLOBALS['inflight']);
    return 'rolled';
  }
  stopContainer_nchan($u['name']);         // Traefik retire l'ancienne instance sur l'événement stop
  $GLOBALS['inflight']['stoppedOld'] = true;
  $out = dk('rename '.escapeshellarg($u['name']).' '.escapeshellarg($u['rollback']), $rc);
  if ($rc !== 0) { rollback_bg($u, "rename failed: $out"); unset($GLOBALS['inflight']); return 'rolled'; }
  $out = dk('rename '.escapeshellarg($u['new']).' '.escapeshellarg($u['name']), $rc);
  if ($rc !== 0) { rollback_bg($u, "rename failed: $out"); unset($GLOBALS['inflight']); return 'rolled'; }
  unset($GLOBALS['inflight']);             // point de non-retour : la nouvelle instance porte le nom
  addRoute($u['name']);
  log_info("Switched: {$u['name']} is now the new instance");
  finish_ok($u);
  return 'ok';
}
```

- [ ] **Step 4 : Brancher dans `update_one`**

Remplacer `return $strategy === 'bluegreen' ? update_safe($u) : update_safe($u);` par :

```php
  return $strategy === 'bluegreen' ? update_bluegreen($u) : update_safe($u);
```

- [ ] **Step 5 : Succès bluegreen avec mesure de disponibilité**

Terminal A : `make measure` (60 s de requêtes toutes les 100 ms).
Terminal B (dans les 5 s) : `make deploy && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingBG | grep -E "Strategy|Health gate|Healthy after|Switched|updated successfully|Summary"; docker ps -a --filter name=RollingBG --format "{{.Names}} {{.Status}} {{.Image}}"'`
Expected B : `Strategy: bluegreen`, `Health gate: health (timeout 120s)`, `Healthy after Ns`, `Stopping container: RollingBG`, `Switched: RollingBG is now the new instance`, `Removing container: RollingBG.rollback`, `RollingBG updated successfully`, `Summary: 1 updated…` ; un seul `RollingBG` `Up (healthy)` sur la nouvelle image.
Expected A : `requests=~550 failures=0`.

- [ ] **Step 6 : Comparaison mode sûr (même mesure sur RollingTest, port 18080)**

Terminal A : `make measure URL=http://127.0.0.1:18080/ HOSTHDR=localhost`
Terminal B : `make testct && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingTest >/dev/null'`
Expected A : `failures=` > 0 (le stop → start se voit). Noter les deux chiffres dans le README (tâche 12).

- [ ] **Step 7 : Échec bluegreen = rollback sans coupure**

Terminal A : `make measure`
Terminal B : `make testbg PROBE=tcp://127.0.0.1:1 TIMEOUT=20 && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingBG | grep -E "Strategy|Health gate|rolled back|restored"; docker ps -a --filter name=RollingBG --format "{{.Names}} {{.Status}}"; grep -o "\"nginx:stable-alpine\":{[^}]*}" /var/lib/docker/unraid-update-status.json'`
Expected B : `Health gate failed: timeout after 20s`, `Update of RollingBG rolled back`, `Image tag … restored`, un seul `RollingBG` `Up (healthy)` jamais arrêté (son `Status` affiche un uptime continu), pas de `RollingBG.new`, `"status":"false"`.
Expected A : `failures=0`.

- [ ] **Step 8 : Prérequis manquant = repli explicite**

Run : `make testbg PORTS=1 && ssh test-server 'ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update RollingBG | grep -E "bluegreen|No host port|Previous container kept|updated successfully"'`
Expected : `Warning: bluegreen strategy not applicable, using safe mode:`, `- No host port may be published (found 1 port mapping(s) in the template)`, puis le déroulé du mode sûr (`Previous container kept as RollingBG.rollback`, `RollingBG updated successfully`).

- [ ] **Step 9 : Nettoyer et committer**

Run : `make testclean`

```bash
git add source/ tests/
git commit -m "feat: stratégie bluegreen - nouvelle instance à côté, bascule Traefik, rollback sans coupure"
```

---

### Task 12 : Packaging, licence, README, installation via le Plugins tab

**Files:**
- Create: `docker.rolling.update.plg`
- Create: `LICENSE`
- Create: `README.md`
- Create: `archive/docker.rolling.update-<version>.txz` (par `make build`)

**Interfaces:**
- Consumes: `make build` (tâche 1), dépôt GitHub `greite/unraid-docker-rolling-update` (à créer par l'utilisateur ; ajuster l'entité `github` si le nom diffère).
- Produces: URL d'installation `https://raw.githubusercontent.com/greite/unraid-docker-rolling-update/main/docker.rolling.update.plg`.

- [ ] **Step 1 : Licence**

Run : `curl -s https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt -o LICENSE && head -3 LICENSE`
Expected : `GNU GENERAL PUBLIC LICENSE / Version 2, June 1991`.

- [ ] **Step 2 : Créer `docker.rolling.update.plg`**

```xml
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "docker.rolling.update">
<!ENTITY author    "greite">
<!ENTITY github    "greite/unraid-docker-rolling-update">
<!ENTITY version   "2026.08.28">
<!ENTITY md5       "00000000000000000000000000000000">
<!ENTITY launch    "Settings/RollingUpdateSettings">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
<!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/main/&name;.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" launch="&launch;" pluginURL="&pluginURL;" icon="refresh" min="7.3.0" support="https://github.com/&github;/issues">

<CHANGES>
###2026.08.28
- Initial release: sequential updates with health gate and automatic rollback (container and image), opt-in blue/green strategy for containers behind Traefik.
</CHANGES>

<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz" Run="upgradepkg --install-new">
<URL>https://raw.githubusercontent.com/&github;/main/archive/&name;-&version;.txz</URL>
<MD5>&md5;</MD5>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
mkdir -p /boot/config/plugins/&name;
[ -f /boot/config/plugins/&name;/&name;.cfg ] || cp &plugdir;/default.cfg /boot/config/plugins/&name;/&name;.cfg
rm -f $(ls /boot/config/plugins/&name;/&name;-*.txz 2>/dev/null | grep -v '&version;')
echo ""
echo "-----------------------------------------------------------"
echo " &name; has been installed. Version: &version;"
echo " Update / Update All on the Docker page now use rolling updates."
echo "-----------------------------------------------------------"
echo ""
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
removepkg &name;-&version;
rm -rf &plugdir;
rm -rf /boot/config/plugins/&name;
echo "&name; has been removed - the native Update button is back."
</INLINE>
</FILE>

</PLUGIN>
```

- [ ] **Step 3 : README.md**

```markdown
# docker.rolling.update - Unraid plugin

Makes the Docker page's **Update** / **Update All** safe: containers are updated one at a time, the new
version must pass a **health gate** (Docker HEALTHCHECK or a fallback probe), and on failure the previous
container **and its image** are restored automatically, with an Unraid alert explaining why.

Opt-in **blue/green** strategy for containers behind Traefik (Docker provider): the new instance starts
next to the old one, Traefik switches once it is healthy - zero downtime.

## Install

Plugins → Install Plugin → `https://raw.githubusercontent.com/greite/unraid-docker-rolling-update/main/docker.rolling.update.plg`

Uninstalling restores the native Update button.

## Per-container labels (Edit container → Add Label)

| Label | Values | Default |
|---|---|---|
| `rolling.strategy` | `safe`, `bluegreen` | `safe` |
| `rolling.probe` | `health`, `running`, `http://host:port/path`, `tcp://host:port`, `none` | `health` if the image has a HEALTHCHECK (or `--health-cmd` in Extra Parameters), else `running` |
| `rolling.timeout` | seconds | Settings (120) |
| `rolling.grace` | seconds | Settings (15) |

## Blue/green prerequisites

User-defined bridge network, no host port mapping, no fixed IP, a healthcheck, Traefik labels with an
explicit `traefik.http.routers.<r>.service=<s>` and `traefik.http.services.<s>.loadbalancer.server.port`,
Tailscale disabled - and an application that tolerates two instances on the same data (no SQLite, no
migrations at startup). Missing prerequisite → safe mode, with the list of what to fix in the update log.

Measured on a throwaway nginx (requests every 100 ms during the update): safe mode `failures=N`, blue/green `failures=0`.

## Development

`make deploy` (rsync to the server's RAM disk), `make selftest`, `make testct` / `make testbg` / `make measure` / `make testclean`, `make build`.

## License

GPL-2.0. Reuses portions of Unraid's `dynamix.docker.manager` (© Lime Technology / Bergware International).
```

Remplacer `failures=N` par la valeur mesurée à la tâche 11 étape 6.

- [ ] **Step 4 : Construire le paquet**

Run : `make build && ls -la archive/ && grep -E "ENTITY (version|md5)" docker.rolling.update.plg && ssh test-server 'md5sum -' < archive/docker.rolling.update-$(date +%Y.%m.%d).txz`
Expected : le txz existe, le `.plg` contient la version du jour et un md5 sur 32 hex identique à celui calculé par `md5sum` côté serveur.

- [ ] **Step 5 : Commit et push (le dépôt GitHub `greite/unraid-docker-rolling-update` doit exister, branche `main`)**

```bash
git add LICENSE README.md docker.rolling.update.plg archive/
git commit -m "feat: packaging .plg + txz, licence GPL-2.0, README"
git remote add origin git@github.com:greite/unraid-docker-rolling-update.git
git push -u origin main
```

- [ ] **Step 6 : Installation propre depuis le Plugins tab**

Run : `ssh test-server 'rm -rf /usr/local/emhttp/plugins/docker.rolling.update'` (retire la version rsync), puis dans l'UI : Plugins → Install Plugin → coller l'URL du `.plg` → Install.
Expected : log d'installation `docker.rolling.update has been installed. Version: 2026.08.28`, le plugin apparaît dans Plugins → Installed Plugins, la page Settings → Utilities → Docker Rolling Update existe, et `make testct` + clic « update ready » dans l'UI déroulent un rolling update.

- [ ] **Step 7 : Désinstallation / réinstallation**

Dans l'UI : Plugins → Installed Plugins → Remove `docker.rolling.update`.
Run : `ssh test-server 'ls /usr/local/emhttp/plugins/ | grep rolling; ls /boot/config/plugins/ | grep rolling; echo done'`
Expected : rien avant `done` ; sur la page Docker, `updateContainer.toString()` ne contient plus `rolling_update`. Réinstaller ensuite via l'URL.

- [ ] **Step 8 : Nettoyage final**

Run : `make testclean`

---

## Auto-revue (faite à la rédaction)

- **Couverture du spec** : §4 flux mode sûr → tâches 5-7 ; §4bis bluegreen (prérequis, flux, migration listée dans le message de repli et le README) → tâches 4, 11 ; §5 porte de santé → tâches 3, 7 ; §6 rollback (ordre re-tag → suppression, badge) → tâche 7 ; §7 UI → tâches 9, 10 ; §8 cas limites (`.rollback` résiduel → tâche 5, `.new` résiduel → tâche 11, abort → tâche 8, image partagée → `restore_image`/`finish_ok` best-effort, container arrêté → tâche 6 étape 5) ; §9 tests → étapes de test de chaque tâche + `make measure` ; §10 packaging → tâches 1, 12.
- **Types** : `wait_healthy(): ?string` (null = OK) utilisé tel quel dans `update_safe` et `update_bluegreen` ; `check_bluegreen()` retourne `string[]` ; `$u` a les mêmes clés partout (`name, rollback, new, repo, cmd, xml, info, exists, wasRunning, oldImageID, newImageID, probe, stoppedOld`).
- **Point d'attention** : `update_container` natif appelle `removeContainer($Name)` qui purge aussi `webui-info.json` pour ce nom ; avec le `rename`, l'entrée reste et sert au nouveau container du même nom - comportement voulu.
