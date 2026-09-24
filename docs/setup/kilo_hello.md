готов
1) Учебный сервис предварительной оценки заявки на заём под ПТС: принимает заявку, считает LTV и возвращает `approve` / `review` / `reject`.
2) Makefile: `make help`, `make up`, `make down`, `make ps`, `make logs`, `make install`, `make test`, `make lint`, `make seed`; docker-compose.yml: backend запускается `php -S 0.0.0.0:8080 -t backend/public backend/public/router.php`, сервисы `backend` и `db` (MySQL 8), порты `${APP_PORT:-8080}:8080` и `${DB_PORT:-3307}:3306`.
3) `backend/src/Domain/DecisionEngine.php`.
модель: training-2026-09-gpt-5.6-terra
