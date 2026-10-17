---
title: Быстрый старт
description: Минимальный рабочий диск Vaulter меньше чем за минуту — открыть диск для сущности, создать папку, загрузить файл, получить список, затем встроить Vue-UI.
outline: deep
---

# Быстрый старт

Это путь «как надо» за ≤60 секунд: от чистой установки до рабочего диска — папка, загруженный
файл и список, — а затем Vue-UI сверху.

::: tip Предусловие
Установлен `axioma-studio/vaulter-drive` (этот разбор использует `Vaulter::drive()`,
`DriveHandle`, загрузки — всё это функции Drive) и выполнены миграции. Если нет —
сначала пройдите [Установку](/ru/getting-started/installation) (это две команды).
:::

## 1. (Авторизация работает «из коробки»)

Vaulter по умолчанию использует автономный драйвер ролей **`database`**, поэтому чистому
Laravel-приложению **не нужна никакая настройка авторизации**, чтобы начать — доступ решают
гранты на узлы (`v_node_grants`), которые читает собственный `AccessResolver` Vaulter.

К этому возвращаются, только когда нужно, чтобы роли из *вашего* приложения учитывались. Чтобы
сменить полосу, задайте один ключ конфига:

<!-- snippet: config -->

```php
// config/vaulter.php
'access' => [
    'permission_driver' => 'gate', // database (по умолчанию) | gate | spatie | azgard | <ваш FQCN>
],
```

::: warning Прод
Драйвер по умолчанию `database` безопасен для прода. Открытый драйвер `null` отвергается
валидацией конфига вне local/testing — никогда не деплойте панель, привязанную к нему.
:::

## 2. Открыть диск и работать с ним

`Vaulter::drive($entity)` находит-или-создаёт диск сущности (и его корневую папку) и
возвращает `DriveHandle` — хэндл для всего диска; `actingAs($user)` выполняет каждый вызов с
правами этого пользователя. Всё ниже — настоящие методы на нём:

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#quick-start-open -->

```php
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;

// $project — любая Eloquent-модель; $user — вошедший пользователь, от имени которого работает диск.
$drive = Vaulter::drive($project)->actingAs($user);

// Создать папку под корнем.
$reports = $drive->folders()->create('Reports');

// Загрузить байты — путь, UploadedFile, поток или строка, обёрнутые в UploadSource.
$node = $drive->files()->upload(
    UploadSource::fromUploadedFile(request()->file('document')),
    new UploadOptions(parent: $reports),
);

// Список прямых потомков папки (null = корень).
$page = $drive->nodes()->children($reports);   // Vaulter\Data\Page из NodeSnapshot
```

Это полностью рабочий диск. Несколько остальных методов `DriveHandle`:

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#quick-start-methods -->

```php
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\UploadSource;

$drive->nodes()->rename($node, 'Q3 report.pdf');
$drive->nodes()->move($node, $reports);
$drive->nodes()->copy($node, $reports, new CopyOptions(name: 'Q3 report (copy).pdf'));
$drive->nodes()->trash($node);                         // мягкая корзина (восстановимо)
$drive->trash()->restore($node);
$drive->files()->addRevision($node, UploadSource::fromString($newBytes, 'Q3 report.pdf')); // новая ревизия файла
```

::: details Куда попадают загрузки?
`upload()` стримит байты в контент-адресуемое хранилище блобов: считает `sha256`,
дедуплицирует в настроенной области и привязывает строку `v_files` / версии файла к новому
узлу. Вы никогда не работаете с дисками и путями напрямую.
:::

## 3. Отдать наружу по API (один маршрут)

Drive REST-маршруты уже смонтированы под `api/vaulter` (`routes.prefix`). Вашему приложению
нужно лишь передать id хранилища на фронтенд — обычно из сущности:

<!-- snippet: host -->

```php
// routes/web.php (или ответ Inertia/контроллера)
use Vaulter\Drive\Facades\Vaulter;

Route::get('/projects/{project}/drive', function (App\Models\Project $project) {
    return inertia('Drive', [
        'driveId' => Vaulter::drive($project)->snapshot()->id,
    ]);
});
```

Смонтированные Drive-эндпоинты, с которыми общается фронтенд, среди прочего:

```
GET    api/vaulter/v1/drives/{drive}/nodes       список папки
POST   api/vaulter/v1/drives/{drive}/folders     создать папку
POST   api/vaulter/v1/drives/{drive}/files       загрузить файл
PATCH  api/vaulter/v1/nodes/{node}               переименовать (If-Match)
POST   api/vaulter/v1/nodes/{node}/move          переместить (If-Match)
POST   api/vaulter/v1/nodes/{node}/trash         в корзину (If-Match)
```

Изменение существующего узла требует его текущий `ETag` в `If-Match`.

## 4. Встроить Vue-UI

С [установленным плагином](/ru/getting-started/installation#_2-фронтенд-—-vue-пакет) встройте компонент
файлового менеджера и передайте ему `driveId`:

::: code-group

<!-- snippet: host -->

```vue [VaulterDrive.vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

defineProps<{ driveId: string }>()

function openNode(node: { id: string; kind: string }) {
  // открыть/просмотреть/редактировать узел в вашем приложении
}
</script>

<template>
  <VaulterDrive :drive-id="driveId" @open-node="openNode" />
</template>
```

<!-- snippet: host -->

```ts [useNodes (уровнем ниже)]
import { useNodes } from '@vaulter/vue'

// Composable за VaulterDrive — соберите свой UI на нём.
const { nodes, parentId, loading, error, refresh, navigate, createFolder, rename, move, trash } =
  useNodes(driveId)

await createFolder('Reports')
await refresh()
```

:::

`<VaulterDrive>` даёт хлебные крошки, тулбар (новая папка, загрузка, поиск, переключение вида)
и список узлов с проводкой действий строк к API. Он эмитит `open-node` для не-папок, чтобы ваше
приложение решило, как их открывать.

## Готово — что дальше?

- [**Конфигурация**](/ru/guides/configuration) — все секции `config/vaulter.php`.
- [**Авторизация**](/ru/concepts/access-model) — двухполосная модель и выбор драйвера.
- [**Storage Panels**](/ru/concepts/drives-and-profiles) — изолированный настроенный диск для сущности.
