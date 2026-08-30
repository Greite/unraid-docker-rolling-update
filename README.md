# docker.rolling.update - Unraid plugin

Makes the Docker page's **Update** / **Update All** safe: containers are updated one at a time, the new
version must pass a **health gate** (Docker HEALTHCHECK or a fallback probe), and on failure the previous
container **and its image** are restored automatically, with an Unraid alert explaining why.

Opt-in **blue/green** strategy for containers behind Traefik (Docker provider): the new instance starts
next to the old one, Traefik switches once it is healthy - zero downtime.

## Install

Plugins → Install Plugin → `https://raw.githubusercontent.com/Greite/unraid-docker-rolling-update/main/docker.rolling.update.plg`

Uninstalling restores the native Update button.

## Per-container labels (Edit container → Add Label)

| Label | Values | Default |
|---|---|---|
| `rolling.strategy` | `safe`, `bluegreen` | `safe` |
| `rolling.probe` | `health`, `running`, `http://host:port/path`, `tcp://host:port`, `none` | `health` if the image has a HEALTHCHECK (or `--health-cmd` in Extra Parameters), else `running` |
| `rolling.timeout` | seconds | Settings (120) |
| `rolling.grace` | seconds | Settings (15) (if grace > timeout, timeout is raised to grace) |

## Choosing the containers

Settings → Docker Rolling Update lists every template container, all checked by default. Unchecked containers
are updated the native way (pull, stop, remove, recreate: no health gate, no rollback), in the same update run.
Containers created later are handled by the rolling update until unchecked.

## Notifications

Settings → Docker Rolling Update: three independent toggles - success (normal), success with warnings
(warning: invalid label, probe fallback, blue/green prerequisites missing), errors (alert: rollback, skipped
update, manual intervention required). All enabled by default.

## Blue/green prerequisites

User-defined bridge network, no host port mapping, no fixed IP, a healthcheck, Traefik labels with an
explicit `traefik.http.routers.<r>.service=<s>` and `traefik.http.services.<s>.loadbalancer.server.port`,
Tailscale disabled - and an application that tolerates two instances on the same data (no SQLite, no
migrations at startup). Missing prerequisite → safe mode, with the list of what to fix in the update log.

Measured on a throwaway nginx behind Traefik (HTTPS, requests every 100 ms during the update): safe mode 54 failures / 563 requests (the stop → start window); blue/green 2 / 533 and 2 / 527 over two runs (one 502 and one connection reset at the instant the old instance is stopped - in-flight requests, the documented residual); blue/green rollback 1 / 425 (the old instance is never stopped).

## Development

`make deploy` (rsync to the server's RAM disk), `make selftest`, `make testct` / `make testbg` / `make measure` / `make testclean`, `make build`.

## License

GPL-2.0. Reuses portions of Unraid's `dynamix.docker.manager` (© Lime Technology / Bergware International).
