# docker.rolling.update - Unraid plugin

## Layout
- `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/` - what ships: `scripts/rolling_update` (PHP CLI, all side effects), `include/rolling.php` (pure logic + self-test), `RollingUpdate.Docker.page` (JS override of Update/Update All), `RollingUpdateSettings.page`, `README.md` (Plugins-tab display name), `default.cfg`
- `docs/superpowers/specs/*.md` is the design authority; `docs/superpowers/plans/*.md` is historical
- `archive/*.txz` + `docker.rolling.update.plg` are build outputs and ARE committed (the .plg URL points at them on `main`)

## Commands
- `make selftest` - 43 pure checks, local PHP, no Docker (run before every commit)
- `make deploy` - rsync to the test server RAM disk; overwrites a package install (same files, fine)
- `make build` - rebuilds the txz on the server and re-stamps the md5 in the .plg: run it and commit `archive/` + `.plg` after ANY change under `source/`
- `make testct` / `make testbg [PROBE= TIMEOUT= PORTS=1]` - throwaway containers `RollingTest` / `RollingBG` with a simulated "update ready" (old nginx tag); never touch other containers on the test server
- `make measure [SCHEME= PORT= HOSTHDR=]` - 60 s request loop on the server; the real blue/green host comes from git-ignored `local.mk` (`BGHOST=`)
- `ROLLING_STDOUT=1 /usr/local/emhttp/plugins/docker.rolling.update/scripts/rolling_update <name>` over SSH - run the script outside the UI modal

## Unraid 7.3.2 gotchas (all verified on a real server)
- `DockerClient::getContainerDetails()` returns the 404 JSON body for a missing container: test `['Id']`, never `!empty()`
- `DockerUpdate::reloadUpdateStatus()` reuses the cached `local` digest: after re-tagging an image, reset it with `setUpdateStatus(repo, inspectLocalVersion(repo))`
- `connectExtraNetworks()` does not exist on 7.3.2: keep the `function_exists` guard
- `notify -x` deduplicates by event name: never use it for per-update notifications
- The Docker tab caches `updated` per container in `state/plugins/dynamix.docker.manager/docker.json`; native `removeContainer()` drops the entry, our rename does not: call `forget_webui_info($name)` after any change (`make checkbadge CT=<name>` shows what the tab will display)
- `xmlToCommand()` emits `--name=` + `escapeshellarg(name)` and interpolates ExtraParams/PostArgs raw
- `openDocker('<script> <args>')` runs any `plugins/*/scripts/<script>`; output goes to nchan channel `docker`, end with `_DONE_`
- Traefik's Docker provider skips `starting`/`unhealthy` containers (the health gate is what makes blue/green safe)
- The native `*_nchan` helpers in `rolling_update` are verbatim GPL copies of Unraid's `update_container`: keep them verbatim

## Conventions
- UI/log strings, code comments and commit messages all in English
- Per-container settings are labels (`rolling.strategy|probe|timeout|grace`), not new UI
- Gitflow: work on `feature/*` from `develop`, releases to `main` with a signed `vYYYY.MM.DD` tag; commit messages in English (conventional commits)
- Never commit or mention in commits: the test server name, personal domains, real hosts (use `local.mk`); never pass `-c user.email` - the repo's git profile signs commits

## Release
- `git checkout -b release/YYYY.MM.DD develop` -> add a `###YYYY.MM.DD` entry to `<CHANGES>` in the .plg -> `make build VERSION=YYYY.MM.DD` (rewrites the version/md5 entities only) -> commit -> merge --no-ff into `main`, back into `develop` -> `git tag -s vYYYY.MM.DD` -> push `main develop --tags`
- `.plg` `author` is `GreiteTurtle` and GitHub paths are `Greite/...` (same as the other plugins); the Plugins tab reads `plugins/<id>/README.md` (first bold line = display name)
- raw.githubusercontent.com caches the .plg/.txz for a few minutes after a push: do not `plugin install` from the URL right away
- The test server name lives in git-ignored `local.mk` (`HOST = ...`), never in committed files
