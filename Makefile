.PHONY: up down bash config install migrate init ci stan cs cs-fix test feature

up:
	docker-compose up -d

down:
	docker-compose down

bash:
	docker-compose exec php bash

install:
	docker-compose exec php composer install
