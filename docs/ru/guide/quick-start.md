---
title: Быстрый старт
description: Минимальный рабочий диск Vaulter меньше чем за минуту — открыть диск для сущности, создать папку, загрузить файл, получить список, затем встроить Vue-UI.
outline: deep
---

# Быстрый старт

Это путь «как надо» за ≤60 секунд: от чистой установки до рабочего диска — папка, загруженный
файл и список, — а затем Vue-UI сверху.

::: tip Предусловие
Установлен `axioma-studio/vaulter-drive` (этот разбор использует `Vaulter::for()`,
`DriveContext`, загрузки — всё это функции Drive) и выполнены миграции. Если нет —
сначала пройдите [Установку](/ru/guide/installation) (это две команды).
:::

## 1. (Авторизация работает «из коробки»)

Vaulter по умолчанию использует автономный драйвер ролей **`database`**, поэтому чистому
Laravel-приложению **не нужна никакая настройка авторизации**, чтобы начать — доступ решают
гранты на узлы (`v_node_grants`), которые читает собственный `AccessResolver` Vaulter.

К этому возвращаются, только когда нужно, чтобы роли из *вашего* приложения учитывались. Чтобы
сменить полосу, задайте один ключ конфига:

```php
// config/vaulter.php
'drivers' => [
    'permission' => 'gate', // database (по умолчанию) | gate | spatie | azgard | <ваш FQCN>
],
```

::: warning Прод
Драйвер по умолчанию `database` безопасен для прода. Открытый драйвер `null` отвергается
валидацией конфига вне local/testing — никогда не деплойте панель, привязанную к нему.
:::

## 2. Открыть диск и работать с ним

`Vaulter::for($entity)` находит-или-создаёт хранилище сущности (и его корневую папку) и
возвращает `DriveContext` — удобный хэндл для всего диска. Всё ниже — настоящие методы на нём:

```php
use Vaulter\Facades\Vaulter;

// $project — любая Eloquent-модель; Vaulter::for() привязывает к ней хранилище.
$drive = Vaulter::for($project);

// Создать папку под корнем.
$reports = $drive->createFolder('Reports');

// Загрузить байты — строка, SplFileInfo/UploadedFile, ресурс-поток
// или PSR-7 StreamInterface. $into по умолчанию — корень.
$node = $drive->upload(
    source: request()->file('document'),
    into: $reports,
);

// Список прямых детей папки (null = корень).
$drive->list($reports);   // Illuminate\Database\Eloquent\Collection<Node>
```

Это полностью рабочий диск. Несколько остальных методов `DriveContext`:

```php
$drive->rename($node, 'Q3 report.pdf');
$drive->move($node, into: $reports);
$drive->copy($node, into: $reports, name: 'Q3 report (copy).pdf');
$drive->trash($node);           // мягкое удаление в корзину (восстановимо)
$drive->restore($node);
$drive->forceDelete($node);     // безвозвратно
$drive->uploadVersion($node, source: $newBytes); // новая версия на файл
```

::: details Куда попадают загрузки?
`upload()` стримит байты в контент-адресуемое хранилище блобов: считает `sha256`,
дедуплицирует в настроенной области и привязывает строку `v_files` / версии файла к новому
узлу. Вы никогда не работаете с дисками и путями напрямую.
:::

## 3. Отдать наружу по API (один маршрут)

Drive REST-маршруты уже смонтированы под `api/vaulter` (`route_prefix`). Вашему приложению
нужно лишь передать id хранилища на фронтенд — обычно из сущности:

```php
// routes/web.php (или ответ Inertia/контроллера)
use Vaulter\Facades\Vaulter;

Route::get('/projects/{project}/drive', function (App\Models\Project $project) {
    return inertia('Drive', [
        'storageId' => Vaulter::for($project)->storage()->getKey(),
    ]);
});
```

Смонтированные Drive-эндпоинты, с которыми общается фронтенд, среди прочего:

```
GET    api/vaulter/storages/{storage}/nodes      список папки
POST   api/vaulter/storages/{storage}/folders    создать папку
POST   api/vaulter/storages/{storage}/uploads    загрузить файл
PATCH  api/vaulter/nodes/{node}                   переименовать
POST   api/vaulter/nodes/{node}/move             переместить
DELETE api/vaulter/nodes/{node}                   в корзину
```

## 4. Встроить Vue-UI

С [установленным плагином](/ru/guide/installation#_2-фронтенд-vue-пакет) встройте компонент
файлового менеджера и передайте ему `storageId`:

::: code-group

```vue [VaulterDrive.vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

defineProps<{ storageId: string }>()

function openNode(node: { id: string; kind: string }) {
  // открыть/просмотреть/редактировать узел в вашем приложении
}
</script>

<template>
  <VaulterDrive :storage-id="storageId" @open-node="openNode" />
</template>
```

```ts [useNodes (уровнем ниже)]
import { useNodes } from '@vaulter/vue'

// Composable за VaulterDrive — соберите свой UI на нём.
const { nodes, parentId, loading, error, refresh, navigate, createFolder, rename, move, trash } =
  useNodes(storageId)

await createFolder('Reports')
await refresh()
```

:::

`<VaulterDrive>` даёт хлебные крошки, тулбар (новая папка, загрузка, поиск, переключение вида)
и список узлов с проводкой действий строк к API. Он эмитит `open-node` для не-папок, чтобы ваше
приложение решило, как их открывать.

## Готово — что дальше?

- [**Конфигурация**](/ru/guide/configuration) — все секции `config/vaulter.php`.
- [**Авторизация**](/ru/concepts/authorization) — двухполосная модель и выбор драйвера.
- [**Storage Panels**](/ru/concepts/storage-panels) — изолированный настроенный диск для сущности.
