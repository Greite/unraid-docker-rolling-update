**Docker Rolling Update**

Makes the Docker page's Update / Update All safe: containers are updated one at a time, the new version must pass a health gate, and on failure the previous container and image are restored automatically. Opt-in blue/green (zero-downtime) strategy for containers behind Traefik.
