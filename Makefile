NAME    := docker.rolling.update
-include local.mk   # local values not version-controlled (e.g. BGHOST = a real subdomain with a certificate)
HOST    ?= unraid
PLUGDIR := usr/local/emhttp/plugins/$(NAME)
SRC     := source/$(NAME)/$(PLUGDIR)
TPL     := /boot/config/plugins/dockerMan/templates-user
DM      := /usr/local/emhttp/plugins/dynamix.docker.manager/scripts
PROBE   ?= health
TIMEOUT ?= 120
PORTS   ?=
BGHOST  ?= rollingbg.test
HOSTHDR ?= $(BGHOST)
PORT    ?= 4443
SCHEME  ?= https
VERSION ?= $(shell date +%Y.%m.%d)
ifeq ($(PORTS),1)
PORTLINE := <Config Name="HTTP" Target="80" Default="18081" Mode="tcp" Description="" Type="Port" Display="always" Required="false" Mask="false">18081</Config>
endif

.PHONY: deploy selftest testct testbg measure testclean build

deploy: ## rsync the plugin to the server's RAM disk (lost on reboot) + initial cfg
	rsync -a --delete $(SRC)/ $(HOST):/$(PLUGDIR)/
	ssh $(HOST) 'mkdir -p /boot/config/plugins/$(NAME); [ -f /boot/config/plugins/$(NAME)/$(NAME).cfg ] || cp /$(PLUGDIR)/default.cfg /boot/config/plugins/$(NAME)/$(NAME).cfg'

selftest: ## self-test of the pure functions, no Docker required
	php $(SRC)/include/rolling.php

testct: ## disposable RollingTest container (bridge, port 18080) with a simulated "update ready"
	sed -e 's|@PROBE@|$(PROBE)|' -e 's|@TIMEOUT@|$(TIMEOUT)|' tests/templates/my-RollingTest.xml | ssh $(HOST) 'cat > $(TPL)/my-RollingTest.xml'
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingTest >/dev/null; docker start RollingTest >/dev/null && php -r '"'"'$$docroot="/usr/local/emhttp"; require "$$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; (new DockerTemplates())->getAllInfo(true);'"'"' >/dev/null; docker ps --filter name=^RollingTest$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

testbg: ## disposable RollingBG container (frontend, Traefik, bluegreen) with a simulated "update ready"
	sed -e 's|@PROBE@|$(PROBE)|' -e 's|@TIMEOUT@|$(TIMEOUT)|' -e 's|@BGHOST@|$(BGHOST)|' -e 's|<!--PORT-->|$(PORTLINE)|' tests/templates/my-RollingBG.xml | ssh $(HOST) 'cat > $(TPL)/my-RollingBG.xml'
	ssh $(HOST) 'docker rm -f RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingBG >/dev/null; docker start RollingBG >/dev/null && php -r '"'"'$$docroot="/usr/local/emhttp"; require "$$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; (new DockerTemplates())->getAllInfo(true);'"'"' >/dev/null; docker ps --filter name=^RollingBG$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

measure: ## 60 s of requests every 100 ms from the host; prints requests= failures=
	ssh $(HOST) 'end=$$((SECONDS+60)); ko=0; n=0; while [ $$SECONDS -lt $$end ]; do c=$$(curl -sk --resolve $(HOSTHDR):$(PORT):127.0.0.1 -o /dev/null -m 2 -w "%{http_code}" $(SCHEME)://$(HOSTHDR):$(PORT)/); n=$$((n+1)); [ "$$c" = 200 ] || ko=$$((ko+1)); sleep 0.1; done; echo "requests=$$n failures=$$ko"'

testclean: ## removes test containers, templates and image
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; rm -f $(TPL)/my-RollingTest.xml $(TPL)/my-RollingBG.xml; docker image rm nginx:1.27.0-alpine >/dev/null 2>&1; true'

build: deploy ## builds archive/<name>-<version>.txz (GNU tar from the server, owner root) and updates the .plg's version+md5
	mkdir -p archive
	ssh $(HOST) 'cd / && tar -cJf - --owner=0 --group=0 $(PLUGDIR)' > archive/$(NAME)-$(VERSION).txz
	md5=$$(md5 -q archive/$(NAME)-$(VERSION).txz); sed -i '' -e "s|<!ENTITY version *\".*\">|<!ENTITY version   \"$(VERSION)\">|" -e "s|<!ENTITY md5 *\".*\">|<!ENTITY md5       \"$$md5\">|" $(NAME).plg; echo "archive/$(NAME)-$(VERSION).txz md5=$$md5"
