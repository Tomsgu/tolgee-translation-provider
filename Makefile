vendor: composer.json composer.lock
	composer install
.PHONY: vendor

phpstan: vendor/ phpstan.neon
	vendor/bin/phpstan analyse -c phpstan.neon --no-progress
.PHONY: phpstan

psalm: vendor/ psalm.xml
	vendor/bin/psalm
.PHONY: psalm

ecs: vendor/ ecs.php
	vendor/bin/ecs check
.PHONY: ecs

ecs-fix: vendor/ ecs.php
	vendor/bin/ecs check --fix
.PHONY: ecs-fix

test: vendor/
	vendor/bin/phpunit
.PHONY: test
