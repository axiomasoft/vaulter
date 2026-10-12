# Права (abilities) — интеграция с фронтендом

Vaulter вычисляет права на узел на стороне сервера (по [ролям и грантам](/ru/concepts/access-model#способности-и-роли) актора) и отдаёт их фронтенду, поэтому
ваши Vue-компоненты отрисовывают только те действия, которые пользователю доступны. Никаких
лишних обращений к API, никакого дублирования логики прав в JavaScript.

## В ответах API

Каждый ресурс узла встраивает объект `abilities` — по одному boolean на право, вычисленному
для авторизованного актора:

```json
{
  "data": {
    "id": "01J...",
    "name": "Q3 Proposal",
    "abilities": {
      "view": true, "download": true, "comment": true, "edit": true, "create": true, "move": true,
      "copy": true, "trash": true, "restore": false, "share": true, "manage": false, "delete": false
    }
  }
}
```

`GET /nodes/{node}/abilities` возвращает тот же объект отдельно. Vue-редактор читает
`abilities.edit`, чтобы решить, монтироваться ли в режиме только чтения.

## В PHP

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#abilities-read -->

```php
$abilities = $drive->nodes()->abilities($node);   // NodeAbilities
$canEdit = $abilities->flags['edit'];             // имя права => разрешено

// Один проход резолвера на весь список вместо вызова на каждую строку.
$byNode = $drive->nodes()->abilitiesMany([$node, $other]);   // id узла => NodeAbilities
```

Без действующего пользователя каждый флаг — `false`: клиент получает оболочку только для
чтения, а не действия, которые ему нельзя выполнить.

## Inertia

Передайте права узлов текущей страницы как Inertia-props и привязывайте прямо во Vue —
булевы значения уже вычислены на сервере.
