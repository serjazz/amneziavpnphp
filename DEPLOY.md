# Развёртывание Amnezia VPN Panel на VPS (Ubuntu 24.04)

Инструкция для установки панели рядом с уже работающим Amnezia VPN сервером.

## Предварительные требования

- VPS с Ubuntu 24.04
- Docker и Docker Compose уже установлены (ставятся вместе с Amnezia VPN)
- Amnezia VPN работает (контейнеры `amnezia-xray`, `amnezia-awg2` и т.д.)

## Шаг 1. Клонирование проекта

```bash
cd /opt
git clone https://github.com/infosave2007/amneziavpnphp.git
cd amneziavpnphp
```

## Шаг 2. Настройка окружения

```bash
cp .env.production.example .env
```

Отредактируйте `.env` — замените все `CHANGE_ME_*` на реальные значения:

```bash
nano .env
```

Обязательно измените:

| Переменная | Что поставить |
|---|---|
| `DB_PASSWORD` | Надёжный пароль БД (16+ символов) |
| `DB_ROOT_PASSWORD` | Надёжный root-пароль БД |
| `ADMIN_PASSWORD` | Пароль для входа в панель |
| `JWT_SECRET` | Случайная строка 32+ символов |
| `HTTP_AUTH_USER` | Логин для HTTP Basic Auth |
| `HTTP_AUTH_PASS` | Пароль для HTTP Basic Auth |

Для генерации случайных паролей:

```bash
openssl rand -base64 24
```

## Шаг 3. Запуск

```bash
docker compose up -d --build
```

Дождитесь, пока БД станет healthy:

```bash
docker compose ps
```

Должно быть 3 контейнера в статусе `Up`:
- `amnezia-panel-web`
- `amnezia-panel-db`
- `amnezia-panel-dind`

## Шаг 4. Проверка

Откройте в браузере:

```
http://IP_ВАШЕГО_СЕРВЕРА:8082
```

1. Появится окно HTTP Basic Auth — введите `HTTP_AUTH_USER` / `HTTP_AUTH_PASS`
2. Появится страница входа панели — введите `ADMIN_EMAIL` / `ADMIN_PASSWORD`

## Шаг 5. Добавление VPS как VPN-сервера

В панели:

1. **Servers → Add Server**
2. Заполните:
   - Name: любое имя (например, "My VPS")
   - Host: `IP вашего VPS` (внешний IP, не 127.0.0.1)
   - SSH Port: `22`
   - Username: `root` (или другой пользователь с sudo)
   - Authentication: пароль или SSH-ключ
3. Нажмите **Create Server**

Панель подключится по SSH и сможет управлять VPN-протоколами.

## Проверка совместимости с Amnezia VPN

Убедитесь, что порты не конфликтуют:

```bash
docker ps --format "table {{.Names}}\t{{.Ports}}"
```

Ожидаемый вывод (порты могут отличаться):

```
NAMES                PORTS
amnezia-panel-web    0.0.0.0:8082->80/tcp
amnezia-panel-db     3306/tcp
amnezia-panel-dind   2375-2376/tcp
amnezia-xray         0.0.0.0:443->443/tcp
amnezia-awg2         0.0.0.0:45346->45346/udp
```

Панель использует порт `8082`, Amnezia VPN — свои порты (443, 45346 и т.д.). Конфликтов нет.

## Обновление панели

```bash
cd /opt/amneziavpnphp
git pull
docker compose up -d --build
```

Или через встроенный скрипт:

```bash
bash update.sh
```

## Логи и диагностика

```bash
# Логи веб-панели
docker compose logs -f web

# Логи БД
docker compose logs -f db

# Проверка HTTP Auth
curl -v http://localhost:8082/
# Ожидаемый ответ: 401 Unauthorized

curl -u admin:your_http_password http://localhost:8082/
# Ожидаемый ответ: 200 OK (HTML страница)
```

## Файрвол (рекомендуется)

Откройте только необходимые порты:

```bash
# SSH
ufw allow 22/tcp

# Панель управления
ufw allow 8082/tcp

# Amnezia VPN (порты из ваших контейнеров)
ufw allow 443/tcp
ufw allow 45346/udp

# Включить файрвол
ufw enable
```

> **Важно:** порты Amnezia VPN могут отличаться. Проверьте актуальные через `docker ps`.
