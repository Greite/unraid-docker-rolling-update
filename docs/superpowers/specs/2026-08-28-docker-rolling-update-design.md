# Docker Rolling Update pour Unraid - Design

Date : 2026-08-28
Cible : Unraid 7.3.2 (serveur de test, vérifié) ; compatible avec la branche
`master` du webgui (futur tray de tâches `TaskQueue`) - les deux résolvent
`openDocker(cmd)` dans `plugins/*/scripts/<cmd>`.
Plugin : `docker.rolling.update`

## 1. Objectif

Rendre le bouton **Update** / **Update All** de la page Docker d'Unraid sûr :
chaque container est mis à jour **un par un**, la nouvelle version passe une
**porte de santé** (healthcheck Docker, ou sonde de repli), et en cas d'échec
l'ancien container est **restauré automatiquement** (image comprise), avec une
notification Unraid expliquant pourquoi.

En option, par label `rolling.strategy=bluegreen`, les containers derrière
**Traefik (provider Docker)** sont mis à jour **sans coupure** : nouvelle
instance démarrée à côté de l'ancienne, bascule par le proxy une fois `healthy`
(§4bis). Si un prérequis manque, le plugin retombe sur le mode sûr en disant
pourquoi.

### Non-objectifs (v1)

- Zéro-downtime **général**. Il exige un composant qui bascule le trafic ; hors
  Traefik (provider Docker) et hors applications tolérant deux instances
  simultanées sur les mêmes données, le downtime reste celui du natif
  (stop → start). Périmètre exact en §4bis.
- Auto-update planifié (cron). CA Auto Update reste sur le flux natif ; v2.
- Containers gérés par compose (`net.unraid.docker.managed=composeman`) : ignorés,
  comme le bouton Update natif.
- Rollback a posteriori une fois l'update validé (l'ancienne image est supprimée,
  comme le natif) ; v2 : option « garder l'image précédente ».
- Redémarrage des containers dépendants en `--network container:X` après
  recréation de X (limite native identique) ; v2.

## 2. Faits établis sur Unraid 7.3 (repo `unraid/webgui`)

| Fait | Où |
|---|---|
| Update → `openDocker('update_container <nom>', …)` ; Update All joint les noms par `*` | `plugins/dynamix.docker.manager/javascript/docker.js:106,181` |
| `update_container` : pull → stop → **rm** → run → **rmi ancienne image**. Aucun health check, aucun rollback | `plugins/dynamix.docker.manager/scripts/update_container` |
| `openDocker(cmd)` (7.3.2) : `nchan_docker.start()` puis POST `StartCommand.php {cmd,start}` → résout `cmd` dans `plugins/*/scripts/<cmd>`, lance `nohup bash -c 'sleep .3 && <script> <args>'`, renvoie le pid ; la modale suit le canal nchan `docker`, `_DONE_` termine, callback `loadlist` | `webGui/include/StartCommand.php`, `webGui/include/DefaultPageLayout/HeadInlineJS.php` (serveur) |
| Sur `master` (post-7.3.2) le même `openDocker` passe par `TaskQueue.php` (`task_resolve`, même glob) - rien à changer | `plugins/dynamix/include/TaskQueue.php:275` |
| `update_container` 7.3.2 = master moins `connectExtraNetworks()` (helper absent en 7.3.2) | diff serveur vs master |
| Une `.page` `Menu="Docker"` sans `Title` est évaluée dans la page Docker sans onglet | `webGui/include/DefaultPageLayout/MainContentTabbed.php:39` |
| `docker.js` chargé en synchrone dans la page Docker → les globales `updateContainer`/`updateAll` sont redéfinissables au DOM ready | `DockerContainers.page:66` |
| Templates : pas de champ health, mais `Config Type="Label"` et `ExtraParams` passés tels quels à `docker run` | `include/Helpers.php` (`xmlToCommand`) |
| Protocole modale : `publish('docker', "addLog\0<html>")`, `show_Wait\0id`, `stop_Wait\0id`, `progress\0id\0txt`, sentinelle `_DONE_` | `scripts/update_container` (`write()`), `HeadInlineJS.php` (`openDone`) |
| Statut « update ready » : `DockerUpdate::setUpdateStatus()` sur le `Digest:` du pull ; `reloadUpdateStatus($image)` recompare local/remote | `include/DockerClient.php` |
| Notifications : `/usr/local/emhttp/webGui/scripts/notify -e -s -d -i normal\|warning\|alert -l /Docker -x` | `webGui/scripts/notify` |
| Config plugin : `parse_plugin_cfg('<nom>')` lit `/boot/config/plugins/<nom>/<nom>.cfg` | convention Unraid |
| Traefik 3.7 sur test-server : provider Docker, `exposedByDefault: false`, `network: frontend`. Le provider **ignore les containers dont le health est `starting`/`unhealthy`** ; deux containers définissant le même router avec des valeurs différentes → router supprimé (erreur de merge) ; le même service défini par deux containers → serveurs fusionnés (load-balancing, mécanisme de `compose --scale`) | `/mnt/user/appdata/traefik/traefik.yaml`, provider Docker de Traefik |
| Les containers `frontend` de test-server ont des routers nommés (`portfolio-https`…) **sans `service` explicite**, et publient tous un port host hérité du template | `docker inspect` sur test-server |

## 3. Architecture

```
docker.rolling.update.plg                        # manifeste d'installation
source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/
  scripts/rolling_update                          # PHP CLI : tout le travail
  RollingUpdate.Docker.page                       # Menu="Docker", sans Title : override JS
  RollingUpdateSettings.page                      # Menu="Utilities" : 3 réglages
  default.cfg                                     # valeurs par défaut
archive/docker.rolling.update-<version>.txz       # généré
Makefile                                          # build / deploy / bump
```

Un seul script fait le travail ; le JS ne fait que router vers lui ; les
réglages par container vivent dans des **labels** édités via le formulaire natif
« Edit container → Add Label ».

## 4. Flux `rolling_update nom1*nom2…`

Séquentiel, un container à la fois (c'est le « rolling » à l'échelle du parc).
Pour chaque nom :

1. **Résolution** : `DockerTemplates::getUserTemplate(nom)` → `xmlToCommand()` →
   `[$cmd, $Name, $Repository]`. Lecture des labels `rolling.*` du template (§5).
   Mémoriser `oldImageID`, `wasRunning` (`getContainerDetails` → `State.Running`).
   - Template introuvable → message natif « Configuration not found », suivant.
   - `<nom>.rollback` existe déjà (crash précédent) → « nettoyage manuel requis »,
     suivant.
   - `rolling.strategy=bluegreen` et prérequis §4bis satisfaits → **flux §4bis**.
     Prérequis manquant → la modale liste ce qui manque, et on continue ici.
2. **Pull** : copie du `pullImage_nchan` natif (progression par layer,
   `setUpdateStatus` sur `Digest:`). Échec → suivant (rien n'a été touché).
3. **Stop gracieux** si `wasRunning` (`DockerClient::stopContainer`).
4. **`docker rename <nom> <nom>.rollback`** - à la place du `rm` natif. L'ancien
   container et son image restent intacts.
5. **Astuce Tailscale** (miroir natif) : si `TailscaleEnabled`, `docker create`
   temporaire pour extraire Entrypoint/Cmd, injection `ORG_ENTRYPOINT`/`ORG_CMD`,
   `docker rm` du temporaire.
6. **Création** : `docker run -d …` si `wasRunning`, sinon `docker create …`
   (miroir natif). Puis `connectExtraNetworks()` **si `function_exists`**
   (absent en 7.3.2), `addRoute()` si démarré. Commande en échec → **rollback** (§6).
7. **Porte de santé** (§5) si `wasRunning`. Échec → **rollback**.
8. **Succès** : `docker rm <nom>.rollback`, `removeImage(oldImageID)` si différent
   du nouveau (miroir natif), `flushCaches()`. Notification `normal` si
   `NOTIFY=yes`.
9. Résumé final : « N mis à jour, M restaurés ». `write('_DONE_')`. Code de
   sortie 1 si M > 0, 0 sinon (ignoré en 7.3.2, exploité par le tray de `master`).

## 4bis. Stratégie `bluegreen` (opt-in : label `rolling.strategy=bluegreen`)

Zéro-downtime pour les containers derrière **Traefik (provider Docker)**. Le
proxy joue le rôle de l'orchestrateur : il découvre la nouvelle instance par ses
labels, ne lui envoie du trafic qu'une fois `healthy`, répartit entre les deux
instances, puis retire l'ancienne à son arrêt.

### Prérequis

Vérifiés **avant toute action** ; un seul manquant → repli sur le mode sûr, la
modale liste exactement ce qui manque (checklist de migration ci-dessous).

| # | Prérequis | Vérification | Pourquoi |
|---|---|---|---|
| 1 | Réseau = bridge utilisateur (`frontend`…) | template `<Network>` ∉ {`bridge`,`host`,`none`,`container:*`} et `docker network inspect -f '{{.Driver}}'` = `bridge` | Traefik joint le container par son IP sur ce réseau |
| 2 | Aucun port host publié | aucun `<Config Type="Port">` dans le template, pas de `-p`/`--publish` dans `ExtraParams` | un port host n'a qu'un propriétaire |
| 3 | Pas d'IP fixe | `<MyIP>` vide, pas de `--ip` dans `ExtraParams` | idem pour l'IP |
| 4 | Healthcheck | après pull : image `Config.Healthcheck` présent et `Test[0] ≠ NONE`, ou `--health-cmd` dans `ExtraParams` | Traefik 3 ignore `starting`/`unhealthy` ; sans healthcheck il routerait vers une instance en plein boot |
| 5 | Labels Traefik complets | pour chaque `traefik.(http\|tcp\|udp).routers.<r>.*` : un `…routers.<r>.service=<s>` existe **et** `traefik.<proto>.services.<s>.loadbalancer.server.port` existe ; au moins un router défini | sans service explicite Traefik lie chaque router au service par défaut nommé d'après le container → deux définitions différentes du même router pendant le recouvrement → router supprimé = coupure |
| 6 | Tailscale désactivé | `<TailscaleEnabled>` ≠ `true` | sidecar lié au nom du container |
| 7 | Container en cours d'exécution | `wasRunning` | rien à recouvrir sinon |

L'application doit **tolérer deux instances simultanées sur les mêmes données**
(stateless, ou BDD externe avec migrations compatibles). Le plugin ne peut pas
le vérifier : c'est documenté, et poser le label est la responsabilité de
l'utilisateur. Sur test-server : Portfolio, ITTools (oui) ; WordPress/Woocommerce
(oui, avec réserve) ; pas Vaultwarden/Jellyfin/Seerr (SQLite) ni Immich
(migrations Postgres au boot).

### Flux

1. Résolution + prérequis. Un `<nom>.new` résiduel (crash précédent) est
   supprimé d'office (`docker rm -f`) : il est toujours jetable, contrairement
   à `<nom>.rollback`.
2. Pull (identique au mode sûr).
3. **`docker run -d` du nouveau sous le nom `<nom>.new`** : commande du template
   avec `'--name='.escapeshellarg($Name)` (format exact de `xmlToCommand`,
   `Helpers.php:400`) remplacé par `'--name='.escapeshellarg("$Name.new")` -
   même technique de `str_replace` que le natif pour `create` → `run -d`.
   `.` est autorisé dans les noms Docker. Labels identiques →
   Traefik fusionne les deux containers dans le même service. Puis
   `connectExtraNetworks()` si disponible.
4. **Porte de santé sur `<nom>.new`** (§5, défaut `health`). Échec →
   **rollback sans coupure** : l'ancien n'a jamais été arrêté. `docker rm -f
   <nom>.new`, re-tag de l'image (§6.4), `reloadUpdateStatus`, notification
   `alert`, suivant.
5. Succès → Traefik répartit déjà entre les deux. **Stop gracieux de l'ancien**
   (Traefik le retire sur l'événement `stop`, ~1 s).
6. `docker rename <nom> <nom>.rollback` puis `docker rename <nom>.new <nom>`.
   Routers/services étant nommés explicitement, la config Traefik ne change
   pas ; le nom DNS Docker `<nom>` bascule sur la nouvelle instance (fenêtre de
   quelques ms entre les deux renames). Un rename en échec → restauration :
   rename inverse si nécessaire, `docker start` de l'ancien, `rm -f <nom>.new`,
   notification `alert`.
7. Nettoyage identique au mode sûr (§4.8) : `rm <nom>.rollback`, suppression de
   l'ancienne image, notification.

Coupure résiduelle : les requêtes en vol sur l'ancienne instance au moment du
`stop` (Traefik ne retente pas par défaut). Pendant quelques secondes les deux
versions servent en parallèle - c'est la définition d'un rolling update.
Pendant le recouvrement, la page Docker d'Unraid affiche `<nom>.new` comme
container orphelin ; c'est transitoire.

### Migrer un container existant (checklist, reprise dans le message de repli)

1. Retirer les mappings de ports du template (derrière Traefik ils ne servent
   qu'au bouton WebUI : mettre l'URL publique dans le champ `WebUI`).
2. Ajouter un healthcheck si l'image n'en a pas :
   `--health-cmd=… --health-interval=10s` en Extra Parameters.
3. Ajouter les labels `traefik.http.routers.<r>.service=<s>` et
   `traefik.http.services.<s>.loadbalancer.server.port=<port>`.
4. Ajouter le label `rolling.strategy=bluegreen`.

## 5. Porte de santé

Tous les labels `rolling.*` sont lus dans le **template XML**
(`<Config Type="Label" Target="rolling.probe">…</Config>`) - source de vérité de
ce qui va être appliqué, et disponible avant de toucher au container. La
présence d'un `HEALTHCHECK` est lue après le pull sur l'image (`Config.Healthcheck`, `Test[0] ≠ NONE`) ou via `--health-cmd` dans `ExtraParams` - connue avant de toucher au container.
Les valeurs absentes tombent sur la config globale.

| Label | Valeurs | Défaut |
|---|---|---|
| `rolling.strategy` | `safe` · `bluegreen` (§4bis) | `safe` |
| `rolling.probe` | `health` · `running` · `http://…`/`https://…` · `tcp://host:port` · `none` | `health` si l'image a un `HEALTHCHECK` (ou `--health-cmd`), sinon `running` |
| `rolling.timeout` | secondes | `TIMEOUT` global (120) |
| `rolling.grace` | secondes (sonde `running`) | `GRACE` global (15) |

Boucle : inspect toutes les 2 s jusqu'à `timeout`.

- À chaque tour : `!State.Running` → **échec** « container sorti (code X) ».
- `health` : `State.Health.Status` = `healthy` → OK ; `unhealthy` → **échec** ;
  `starting` → continuer. Si `State.Health` absent → se comporte comme `running`
  (avertissement dans le log).
- `running` : OK dès que `grace` secondes écoulées avec `State.Running` et
  `RestartCount == 0`.
- `http(s)://…` : `curl -sf --max-time 3`, OK sur 2xx/3xx.
- `tcp://host:port` : `fsockopen` timeout 3 s, OK si connexion.
- `none` : pas de porte (équivalent natif, mais avec rollback sur échec de la
  commande `docker run`).
- Timeout atteint → **échec** « délai dépassé (état : … ) ». Si `grace` >
  `timeout`, `timeout` est relevé à `grace` (la sonde `running` doit pouvoir
  aboutir).
- Valeur de label invalide → avertissement + défaut (une faute de frappe ne doit
  pas casser un update).

Rationale : les sondes HTTP/TCP sont lancées depuis l'hôte, l'utilisateur écrit
l'URL complète (il connaît son réseau : port host en bridge, IP du container en
`br0`). Pas de `docker exec` (les images n'ont pas toutes `curl`).

## 6. Rollback

Déclenché par : échec de `docker run`/`create`, échec de la porte de santé.

1. Log : 50 dernières lignes du nouveau container dans la modale (la raison de
   l'échec est visible sans quitter l'UI).
2. `docker rm -f <nom>` (nouveau).
3. `docker rename <nom>.rollback <nom>` ; `docker start <nom>` si `wasRunning` ;
   `addRoute()`.
4. Image : `docker tag <oldImageID> <Repository>` d'abord (re-pointe le tag sur
   l'ancienne image, ne peut pas échouer), puis `removeImage(<newImageID>)` en
   best-effort (échoue sans conséquence si un autre container l'utilise - sur
   test-server, WordPress et Woocommerce partagent `wordpress-redis:latest`).
   Le `RepoDigest` de l'ancienne image est conservé par Docker → après
   `DockerUpdate::reloadUpdateStatus($Repository)` le badge **« update ready »
   réapparaît**, ce qui est l'état vrai : la mise à jour existe, elle est cassée.
5. Notification `alert` : « <nom> : mise à jour annulée - <raison> ».
6. Si le `docker start` de l'ancien échoue : log en rouge, notification `alert`
   « intervention manuelle requise », suivant.

Le rollback est instantané (aucune régénération de commande) et exact (le
container original est réutilisé tel quel, Tailscale compris).

## 7. UI

### `RollingUpdate.Docker.page`

```
Menu="Docker"
Cond="is_file('/var/run/dockerd.pid')"
---
<script>
$(function(){
  window.updateContainer = function(container){
    swal({ /* même dialogue que le natif */ }, function(){
      openDocker('rolling_update '+encodeURIComponent(container),
                 _('Update container')+' (rolling): '+container, '', 'loadlist');
    });
  };
  window.updateAll = function(){
    /* même collecte que le natif : docker[i].update==1 */
    openDocker('rolling_update '+ct.join('*'),
               _('Updating all Containers')+' (rolling, '+ct.length+')', '', 'loadlist');
  };
});
</script>
```

Le dialogue de confirmation, la modale de log, le tray, l'abort et le
rafraîchissement de la liste restent natifs. `rebuildAll` (statut « rebuild
ready ») n'est pas touché. Désinstaller le plugin restaure le comportement natif.

### `RollingUpdateSettings.page`

`Menu="Utilities"`, `Title="Rolling Update"`, `Icon="refresh"`. Formulaire
Unraid standard (`/update.php`, `#file=docker.rolling.update/docker.rolling.update.cfg`) :

| Clé | Défaut | Description |
|---|---|---|
| `TIMEOUT` | `120` | Délai max de la porte de santé (s) |
| `GRACE` | `15` | Durée d'observation de la sonde `running` (s) |
| `NOTIFY` | `yes` | Notification sur succès (les rollbacks notifient toujours) |

Plus un rappel des labels disponibles.

## 8. Gestion d'erreurs et cas limites

- Deux exécutions simultanées : `StartCommand.php` avec `start=0` refuse de
  relancer un script déjà en cours (`pgrep`), et `master` sérialise par type.
- Abort (7.3.2 : `kill <pid>` - bash ≥ 5.1 exec la dernière commande du
  wrapper, le signal atteint le script ; `master` : SIGTERM sur le groupe) :
  `pcntl` est disponible dans le PHP CLI d'Unraid 7.3.2
  (vérifié) → handler SIGTERM/SIGHUP + `register_shutdown_function` qui, si un
  `<nom>.rollback` existe pour le container en cours, exécute le rollback avant
  de sortir. Le garde-fou de l'étape 4.1 couvre le cas d'un `kill -9`.
- Container arrêté avant l'update : recréé avec `docker create`, pas de porte,
  rollback seulement si la commande échoue (miroir natif + sécurité).
- Container hors template (`getUserTemplate` false) : ignoré avec message natif.
- `Repository` sans tag : `:latest` ajouté (miroir natif) avant `rmi`/`tag`.
- Pull réussi mais image identique (`oldImageID == newImageID`) : on recrée quand
  même (miroir natif - le bouton n'est visible que si un update est détecté).
- Image partagée par plusieurs containers (WordPress/Woocommerce) : la
  suppression de l'ancienne image après succès échoue tant qu'un autre container
  l'utilise ; non fatal, même comportement que le natif. Elle reste alors en
  image orpheline (`<none>`), comme avec le flux natif ; un `docker image
  prune` la supprime.
- `<nom>.new` résiduel (bluegreen interrompu) : supprimé automatiquement au
  prochain passage (§4bis.1). Si l'interruption a eu lieu entre les deux renames
  (fenêtre de quelques ms), on retrouve `<nom>.rollback` sans `<nom>` : le
  garde-fou `.rollback` s'applique (nettoyage manuel signalé).
- Abort pendant un bluegreen : le handler de signal supprime `<nom>.new` et,
  si l'ancien a déjà été arrêté, le redémarre.

## 9. Tests

- **Auto-test local** (ponytail : un seul check exécutable) :
  `php scripts/rolling_update --selftest` - asserts sur le parseur de labels,
  la décision de sonde (`resolveProbe(labels, hasHealth, cfg)`) et la
  vérification des prérequis bluegreen (`checkBlueGreen(xml, labels, …)`) sur
  des templates fixtures, aucun Docker requis. Tourne sur macOS.
- **Sur le serveur de test** (déploiement rsync sur le RAM disk). Aucun
  container de production n'est touché : tout passe par un container jetable.
  1. Container jetable `RollingTest` (`nginx:stable-alpine`, port host 18080)
     créé via Add Container, avec `--health-cmd="wget -qO- http://127.0.0.1/ || exit 1"`
     `--health-interval=5s` en Extra Params. Pour faire apparaître « update ready »
     sans attendre : `docker pull nginx:1.27.0-alpine && docker tag nginx:1.27.0-alpine nginx:stable-alpine`
     puis « Check for Updates » (le `RepoDigest` local diffère du distant).
     Alternative sans badge : lancer `scripts/rolling_update RollingTest` en SSH,
     le script recrée toujours.
  2. Chemin d'échec : label `rolling.probe=tcp://127.0.0.1:1`, `rolling.timeout=20`
     → clic Update → rollback en ~20 s, ancien container `running`, badge
     « update ready » toujours présent, notification `alert` reçue, aucun
     `.rollback` résiduel, image locale = ancienne.
  3. Chemin de succès : `rolling.probe=health` → « healthy » → `.rollback`
     supprimé, ancienne image supprimée, badge « up-to-date ».
  4. Batch : Update All avec deux containers dont un en échec → l'autre est mis à
     jour, résumé « 1 mis à jour, 1 restauré », tâche marquée erreur dans le tray.
  5. Container arrêté : Update → recréé arrêté, pas de porte.
  6. **Blue/green, succès** : container jetable `RollingBG` (`nginx:stable-alpine`)
     sur `frontend`, sans port, healthcheck en Extra Params, labels
     `traefik.enable=true`, `traefik.http.routers.rollingbg.rule=Host(\`rollingbg.test\`)`,
     `traefik.http.routers.rollingbg.entrypoints=web` (entrypoint `:80` de
     `traefik.yaml`, publié sur le port host 8080),
     `traefik.http.routers.rollingbg.service=rollingbg`,
     `traefik.http.services.rollingbg.loadbalancer.server.port=80`,
     `rolling.strategy=bluegreen`. Pas de TLS ni de DNS : test via l'entrypoint
     HTTP (port host 8080) avec l'en-tête `Host`. Boucle de mesure depuis l'hôte
     pendant l'update :
     `while :; do curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: rollingbg.test' http://127.0.0.1:8080/; sleep 0.1; done`
     → **0 code ≠ 200** attendu (contre plusieurs en mode sûr sur le même
     container, mesuré pour comparaison).
  7. **Blue/green, échec** : `rolling.probe=tcp://127.0.0.1:1`, `rolling.timeout=20`
     → `RollingBG.new` supprimé, ancien jamais arrêté (0 erreur dans la boucle),
     badge « update ready », notification `alert`.
  8. **Blue/green, prérequis manquant** : ajouter un port host au template → la
     modale liste le prérequis manquant et l'update se déroule en mode sûr.

## 10. Packaging et boucle de dev

- `make build` : `tar -cJf archive/<nom>-<version>.txz -C source/<nom> .`,
  md5, injection version+md5 dans le `.plg` (entités XML `&version;`/`&md5;`,
  modèle folder.view).
- `make deploy` : `rsync` de `source/…/plugins/docker.rolling.update/` vers
  `test-server:/usr/local/emhttp/plugins/docker.rolling.update/` (RAM disk,
  perdu au reboot - parfait pour itérer ; recharger la page Docker suffit).
- Installation propre : Plugins → Install Plugin → URL raw GitHub du `.plg`.
  Le `.plg` : `upgradepkg --install-new` du txz, post-install crée
  `/boot/config/plugins/docker.rolling.update/` et copie `default.cfg` si absent,
  `Method="remove"` supprime le tout. `min="7.3.0"`.

## 11. Pistes v2 (hors périmètre)

- Auto-update par cron réutilisant `rolling_update` (remplace CA Auto Update
  pour Docker).
- Option « garder l'image précédente » + entrée « Rollback » dans le menu
  contextuel.
- Dépendants `--network container:X` - **pertinent sur test-server** : 8 containers
  (Prowlarr, Sonarr, Radarr, Bazarr, qBittorrent, Flaresolverr, Chaptarr,
  Unmonitarr) partagent le namespace réseau de `PIA-Tun`. Après un update
  réussi de X ils passent « rebuild ready » (flux natif `rebuildAll`) ; après un
  rollback de X (même container, même ID) un simple `docker restart` suffit.
  v2 : `docker restart` des dépendants après rollback, `rebuild_container` après
  succès.
- Vérification sur Unraid 7.0–7.2 (même `StartCommand.php` a priori).
- Blue/green pour d'autres proxies (NPM, SWAG, Caddy) via swap de nom/IP sur un
  réseau Docker custom : fragile avec nginx (résolution DNS au chargement de la
  conf → 502 jusqu'au reload), impossible quand le proxy vise `IP:port host`.
  Non prioritaire.
