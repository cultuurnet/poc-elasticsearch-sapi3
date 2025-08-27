.PHONY: up down bash install build

up:
	docker-compose up -d

down:
	docker-compose down

bash:
	docker-compose exec php bash

install:
	docker-compose exec php composer install

build:
	docker-compose build