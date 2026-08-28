# Docker Rolling Update pour Unraid — Design

Date : 2026-08-28
Cible : Unraid 7.3.2 (serveur de test, vérifié) ; compatible avec la branche
`master` du webgui (futur tray de tâches `TaskQueue`) — les deux résolvent
`openDocker(cmd)` dans `plugins/*/scripts/<cmd>`.
Plugin : `docker.rolling.update`

## 1. Objectif

Rendre le bouton **Update** / **Update All** de la page Docker d'Unraid sûr :
chaque container est mis à jour **un par un**, la nouvelle version passe une
**porte de santé** (healthcheck Docker, ou sonde de repli), et en cas d'échec
l'ancien container est **restauré automatiquement** (image comprise), avec une
notification Unraid expliquant pourquoi.

### Non-objectifs (v1)

- Zéro-downtime réel (nouvelle version démarrée à côté de l'ancienne). Impossible
  sur Unraid pour la majorité des containers : ports host publiés, IP fixe `br0`,
  volumes partagés (migrations de BDD sur données live). Le downtime reste celui
  du natif : stop → start.
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
| Sur `master` (post-7.3.2) le même `openDocker` passe par `TaskQueue.php` (`task_resolve`, même glob) — rien à changer | `plugins/dynamix/include/TaskQueue.php:275` |
| `update_container` 7.3.2 = master moins `connectExtraNetworks()` (helper absent en 7.3.2) | diff serveur vs master |
| Une `.page` `Menu="Docker"` sans `Title` est évaluée dans la page Docker sans onglet | `webGui/include/DefaultPageLayout/MainContentTabbed.php:39` |
| `docker.js` chargé en synchrone dans la page Docker → les globales `updateContainer`/`updateAll` sont redéfinissables au DOM ready | `DockerContainers.page:66` |
| Templates : pas de champ health, mais `Config Type="Label"` et `ExtraParams` passés tels quels à `docker run` | `include/Helpers.php` (`xmlToCommand`) |
| Protocole modale : `publish('docker', "addLog\0<html>")`, `show_Wait\0id`, `stop_Wait\0id`, `progress\0id\0txt`, sentinelle `_DONE_` | `scripts/update_container` (`write()`), `HeadInlineJS.php` (`openDone`) |
| Statut « update ready » : `DockerUpdate::setUpdateStatus()` sur le `Digest:` du pull ; `reloadUpdateStatus($image)` recompare local/remote | `include/DockerClient.php` |
| Notifications : `/usr/local/emhttp/webGui/scripts/notify -e -s -d -i normal\|warning\|alert -l /Docker -x` | `webGui/scripts/notify` |
| Config plugin : `parse_plugin_cfg('<nom>')` lit `/boot/config/plugins/<nom>/<nom>.cfg` | convention Unraid |

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
   `[$cmd, $Name, $Repository]`. Mémoriser `oldImageID`, `wasRunning`
   (`getContainerDetails` → `State.Running`).
   - Template introuvable → message natif « Configuration not found », suivant.
   - `<nom>.rollback` existe déjà (crash précédent) → « nettoyage manuel requis »,
     suivant.
2. **Pull** : copie du `pullImage_nchan` natif (progression par layer,
   `setUpdateStatus` sur `Digest:`). Échec → suivant (rien n'a été touché).
3. **Stop gracieux** si `wasRunning` (`DockerClient::stopContainer`).
4. **`docker rename <nom> <nom>.rollback`** — à la place du `rm` natif. L'ancien
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

## 5. Porte de santé

Lue sur les **labels du nouveau container** (`Config.Labels` via inspect) ; les
valeurs absentes tombent sur la config globale.

| Label | Valeurs | Défaut |
|---|---|---|
| `rolling.probe` | `health` · `running` · `http://…`/`https://…` · `tcp://host:port` · `none` | `health` si l'image a un `HEALTHCHECK` (`State.Health` présent), sinon `running` |
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
   best-effort (échoue sans conséquence si un autre container l'utilise — sur
   test-server, WordPress et Woocommerce partagent `wordpress-redis:latest`).
   Le `RepoDigest` de l'ancienne image est conservé par Docker → après
   `DockerUpdate::reloadUpdateStatus($Repository)` le badge **« update ready »
   réapparaît**, ce qui est l'état vrai : la mise à jour existe, elle est cassée.
5. Notification `alert` : « <nom> : mise à jour annulée — <raison> ».
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
- Abort (7.3.2 : `kill <pid>` du wrapper bash depuis la bannière ; `master` :
  SIGTERM sur le groupe) : `pcntl` est disponible dans le PHP CLI d'Unraid 7.3.2
  (vérifié) → handler SIGTERM/SIGHUP + `register_shutdown_function` qui, si un
  `<nom>.rollback` existe pour le container en cours, exécute le rollback avant
  de sortir. Le garde-fou de l'étape 4.1 couvre le cas d'un `kill -9`.
- Container arrêté avant l'update : recréé avec `docker create`, pas de porte,
  rollback seulement si la commande échoue (miroir natif + sécurité).
- Container hors template (`getUserTemplate` false) : ignoré avec message natif.
- `Repository` sans tag : `:latest` ajouté (miroir natif) avant `rmi`/`tag`.
- Pull réussi mais image identique (`oldImageID == newImageID`) : on recrée quand
  même (miroir natif — le bouton n'est visible que si un update est détecté).
- Image partagée par plusieurs containers (WordPress/Woocommerce) : la
  suppression de l'ancienne image après succès échoue tant qu'un autre container
  l'utilise ; non fatal, même comportement que le natif. Elle sera supprimée à
  l'update du dernier container qui l'utilise.

## 9. Tests

- **Auto-test local** (ponytail : un seul check exécutable) :
  `php scripts/rolling_update --selftest` — asserts sur le parseur de labels /
  décision de sonde (`resolveProbe(labels, hasHealth, cfg)`), aucun Docker
  requis. Tourne sur macOS.
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

## 10. Packaging et boucle de dev

- `make build` : `tar -cJf archive/<nom>-<version>.txz -C source/<nom> .`,
  md5, injection version+md5 dans le `.plg` (entités XML `&version;`/`&md5;`,
  modèle folder.view).
- `make deploy` : `rsync` de `source/…/plugins/docker.rolling.update/` vers
  `root@<host>:/usr/local/emhttp/plugins/docker.rolling.update/` (RAM disk,
  perdu au reboot — parfait pour itérer ; recharger la page Docker suffit).
- Installation propre : Plugins → Install Plugin → URL raw GitHub du `.plg`.
  Le `.plg` : `upgradepkg --install-new` du txz, post-install crée
  `/boot/config/plugins/docker.rolling.update/` et copie `default.cfg` si absent,
  `Method="remove"` supprime le tout. `min="7.0.0"`.

## 11. Pistes v2 (hors périmètre)

- Auto-update par cron réutilisant `rolling_update` (remplace CA Auto Update
  pour Docker).
- Option « garder l'image précédente » + entrée « Rollback » dans le menu
  contextuel.
- Dépendants `--network container:X` — **pertinent sur test-server** : 8 containers
  (Prowlarr, Sonarr, Radarr, Bazarr, qBittorrent, Flaresolverr, Chaptarr,
  Unmonitarr) partagent le namespace réseau de `PIA-Tun`. Après un update
  réussi de X ils passent « rebuild ready » (flux natif `rebuildAll`) ; après un
  rollback de X (même container, même ID) un simple `docker restart` suffit.
  v2 : `docker restart` des dépendants après rollback, `rebuild_container` après
  succès.
- Vérification sur Unraid 7.0–7.2 (même `StartCommand.php` a priori).
