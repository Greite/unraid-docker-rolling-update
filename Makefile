NAME    := docker.rolling.update
HOST    ?= unraid
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
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingTest >/dev/null; docker start RollingTest >/dev/null && php -r '"'"'$$docroot="/usr/local/emhttp"; require "$$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; (new DockerTemplates())->getAllInfo(true);'"'"' >/dev/null; docker ps --filter name=^RollingTest$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

testbg: ## container jetable RollingBG (frontend, Traefik, bluegreen) avec « update ready » simulé
	sed -e 's|@PROBE@|$(PROBE)|' -e 's|@TIMEOUT@|$(TIMEOUT)|' -e 's|<!--PORT-->|$(PORTLINE)|' tests/templates/my-RollingBG.xml | ssh $(HOST) 'cat > $(TPL)/my-RollingBG.xml'
	ssh $(HOST) 'docker rm -f RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; docker pull -q nginx:1.27.0-alpine >/dev/null && docker tag nginx:1.27.0-alpine nginx:stable-alpine && $(DM)/rebuild_container RollingBG >/dev/null; docker start RollingBG >/dev/null && php -r '"'"'$$docroot="/usr/local/emhttp"; require "$$docroot/plugins/dynamix.docker.manager/include/DockerClient.php"; (new DockerTemplates())->getAllInfo(true);'"'"' >/dev/null; docker ps --filter name=^RollingBG$$ --format "{{.Names}} {{.Status}} {{.Image}}"'

measure: ## 60 s de requêtes à 100 ms depuis l'hôte ; affiche requests= failures=
	ssh $(HOST) 'end=$$((SECONDS+60)); ko=0; n=0; while [ $$SECONDS -lt $$end ]; do c=$$(curl -s -o /dev/null -m 2 -w "%{http_code}" -H "Host: $(HOSTHDR)" $(URL)); n=$$((n+1)); [ "$$c" = 200 ] || ko=$$((ko+1)); sleep 0.1; done; echo "requests=$$n failures=$$ko"'

testclean: ## supprime containers, templates et image de test
	ssh $(HOST) 'docker rm -f RollingTest RollingTest.rollback RollingTest.new RollingBG RollingBG.rollback RollingBG.new >/dev/null 2>&1; rm -f $(TPL)/my-RollingTest.xml $(TPL)/my-RollingBG.xml; docker image rm nginx:1.27.0-alpine >/dev/null 2>&1; true'

build: deploy ## construit archive/<name>-<version>.txz (tar GNU du serveur, owner root) et met à jour version+md5 du .plg
	mkdir -p archive
	ssh $(HOST) 'cd / && tar -cJf - --owner=0 --group=0 $(PLUGDIR)' > archive/$(NAME)-$(VERSION).txz
	md5=$$(md5 -q archive/$(NAME)-$(VERSION).txz); sed -i '' -e "s|<!ENTITY version *\".*\">|<!ENTITY version   \"$(VERSION)\">|" -e "s|<!ENTITY md5 *\".*\">|<!ENTITY md5       \"$$md5\">|" $(NAME).plg; echo "archive/$(NAME)-$(VERSION).txz md5=$$md5"
