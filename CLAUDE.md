# docker.rolling.update - Unraid plugin

## Layout
- `source/docker.rolling.update/usr/local/emhttp/plugins/docker.rolling.update/` - what ships: `scripts/rolling_update` (PHP CLI, all side effects), `include/rolling.php` (pure logic + self-test), `RollingUpdate.Docker.page` (JS override of Update/Update All), `RollingUpdateSettings.page`, `README.md` (Plugins-tab display name), `default.cfg`
- `docs/superpowers/specs/*.md` is the design authority; `docs/superpowers/plans/*.md` is historical
- `archive/*.txz` + `docker.rolling.update.plg` are build outputs and ARE committed (the .plg URL points at them on `main`)

## Commands
- `make selftest` - 39 pure checks, local PHP, no Docker (run before every commit)
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
- `xmlToCommand()` emits `--name=` + `escapeshellarg(name)` and interpolates ExtraParams/PostArgs raw
- `openDocker('<script> <args>')` runs any `plugins/*/scripts/<script>`; output goes to nchan channel `docker`, end with `_DONE_`
- Traefik's Docker provider skips `starting`/`unhealthy` containers (the health gate is what makes blue/green safe)
- The native `*_nchan` helpers in `rolling_update` are verbatim GPL copies of Unraid's `update_container`: keep them verbatim

## Conventions
- UI/log strings in English, code comments in French
- Per-container settings are labels (`rolling.strategy|probe|timeout|grace`), not new UI
- Gitflow: work on `feature/*` from `develop`, releases to `main` with a signed `vYYYY.MM.DD` tag; commit messages in English (conventional commits)
- Never commit or mention in commits: the test server name, personal domains, real hosts (use `local.mk`); never pass `-c user.email` - the repo's git profile signs commits
