---
title: Написание движка/обработчика
description: Чек-лист для стороннего файлового обработчика с нуля — контракт, инварианты capability-политики, асимметрия payload'а и где заканчивается шов.
outline: deep
---

# Написание движка/обработчика

[Обработчики документов](./document-handlers.md) показывает контракт и разобранный пример CSV.
Этот гайд — чек-лист для постройки **своего** обработчика от и до: rich-text-движок в духе
TipTap, WOPI-класс офисного вьюера (iframe + токен) или простая поверхность preview/download —
и инварианты, которые обязан держать ваш обработчик, чтобы безопасно сосуществовать со всеми
уже зарегистрированными.

::: tip Терминология
«Движок» и «обработчик» называют один и тот же шов. Контракт — `FileHandler`, kind-нейтральный
по замыслу (§8.1): он описывает ЛЮБОЙ файл, не только редактируемые документы.
`DownloadOnlyHandler` или `PreviewHandler` — такой же обработчик, как rich-text-редактор;
«движок» — неформальное имя для *редактируемого* обработчика (`capabilities.editable === true`).
:::

## 1. Реализовать контракт

Если ваш обработчик — только preview/download, начните с
[Обработчики документов → разобранный пример CSV](./document-handlers.md#adding-a-new-format-a-worked-example).
Остальная часть гайда предполагает **редактируемый** обработчик — более богатый случай.

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

final class MyEditorHandler implements FileHandler
{
    public const int PRIORITY = 90;

    public function key(): string
    {
        return 'acme/my-editor';
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return ['application/vnd.my-editor+json'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return ['myd'];
    }

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable: true,
            previewable: true,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        return new ViewerDescriptor(
            key: 'acme/my-editor',
        );
    }
}
```

Прямая реализация интерфейса — всегда достаточна. `Vaulter\Handlers\Handlers\
AbstractFileHandler` — ОПЦИОНАЛЬНАЯ DX-база, которая дефолтит `extensions()` в `[]` для
mime-only обработчика — экономит один метод, не больше. Встроенные обработчики её не
наследуют — они остаются `final` (H.5).

## 2. Инварианты capability-политики (прочитать до релиза)

`ContentCapabilities` — это **аддитивное, открытое множество осей** (P0.5). Три правила
держат ваш обработчик (и каждый следующий) безопасно составимым:

1. **Незаявленная capability = `false`, никогда не выводится** (кроме `downloadable`, который у
   файла по умолчанию `true`). Форма контента — не capability: обработчик описывает файл, а
   структурированное редактируемое содержимое — это [тип документа](./document-types.md) со
   своим кодеком.
2. **Будущая новая capability-ось не должна ломать ваш обработчик.** От вас не требуется знать
   про каждый флаг, который несёт `ContentCapabilities` — конструктор даёт каждому флагу
   безопасный дефолт. Клиент зеркалит это: на TypeScript-стороне `ContentCapabilities` несёт
   открытую индекс-сигнатуру (`[capability: string]: boolean | undefined`), так что клиентская
   сборка, собранная до появления нового флага, продолжает компилироваться и игнорирует
   незнакомый ключ.
3. **Приоритет, а не порядок регистрации, решает конфликт на одном расширении.** Два
   обработчика вправе законно претендовать на одно расширение по разным причинам (случай
   класса ONLYOFFICE: голый `.xml` может быть и текстовым документом, и таблицей в
   зависимости от контекста) —
   [алгоритм резолва](./document-handlers.md#resolution-algorithm) реестра —
   mime-first → extension-fallback → capability-filter → **priority-sort**. Выберите
   приоритет выше `DownloadOnlyHandler` (`-1000`) — верхнего потолка нет; выбирайте значение,
   отражающее, насколько специфично ваше совпадение относительно известных вам соседей.

## 3. Асимметрия payload'а — не насильно приводить всех к форме TipTap

Не каждый редактируемый обработчик выглядит как rich-text-редактор. Два семейства payload'ов
существуют бок о бок, и контракт не отдаёт предпочтение ни одному:

- **Rich-text-класс** (в духе TipTap): клиент редактирует структурированный JSON напрямую;
  сервер валидирует его по allow-list на записи и защитно «decay»-ит на чтении. `viewer.props`
  несёт конфигурацию редактора (например, список расширений).
- **WOPI/офисный класс**: реальное редактирование происходит в СТОРОННЕЙ поверхности (iframe,
  указывающий на внешний сервис, или нативное приложение через протокольный хендлер).
  `viewer.props` несёт всё, что нужно этой поверхности для запуска — как правило, action-url и
  короткоживущий токен — **не** контент-payload вовсе. Сервер никогда не парсит и не
  валидирует тело документа — только брокерит сессию.

```php
// WOPI-класс обработчика — payload у viewer() совсем другой формы.
public function viewer(FileDescriptor $file): ViewerDescriptor
{
    return new ViewerDescriptor(
        key: 'acme/office-frame',
        props: [
            'actionUrl' => $this->wopi->actionUrlFor($file),
            'accessToken' => $this->wopi->issueToken($file),
            'tokenTtl' => 900,
        ],
    );
}
```

Контракт НЕ заставляет ваш обработчик повторять форму `props` другого viewer'а — `props` это
произвольная, решаемая обработчиком карта.
Задокументируйте форму `props` СВОЕГО обработчика для клиентской команды-потребителя — общей
схемы, которой нужно соответствовать сверх «сериализуемо», нет.

## 4. Версионирование собственной формы контента

Обработчик отдаёт байты файла и своей схемы содержимого не имеет. Если ваш редактор хранит
структурированное содержимое в Vaulter, напишите [тип документа](./document-types.md): его
`schemaVersion()` хранится с каждым документом, а `UpgradesSchema::upgrade()` поднимает старое
содержимое при чтении, не переписывая историю.

## 5. Границы лицензий в сторонних SDK редакторов

Если ваш обработчик оборачивает SDK с двойной лицензией (форма, в которой поставляется Univer:
пакеты `@univerjs/*` — Apache-2.0, `@univerjs-pro/*` — коммерческие), задокументируйте, внутри
какой границы импортов держится фронтенд-код вашего обработчика, и обеспечьте её так же, как
любую другую границу зависимостей (lint-правило, ограничение импорта, или хотя бы комментарий
в коде у точки импорта) — транзитивный `-pro`-импорт, протянутый фичей, которую вы не собирались
гейтить, — лёгкая лицензионная ошибка, которую легко совершить незаметно.

## 6. Регистрация

Те же три канала, что и у любого обработчика — см.
[Обработчики документов → Зарегистрировать](./document-handlers.md#2-register-it):
список FQCN в конфиге (cache-safe), `Vaulter::handlers()->register()` (рантайм-шов) или тегированный
биндинг `'vaulter.file_handlers'` (канал, которым core регистрирует свои базовые обработчики).

## 7. Монтирование на клиенте

См. [Обработчики документов → Монтирование резолвленного обработчика во Vue](./document-handlers.md#mounting-the-resolved-handler-in-vue)
про два канала регистрации (`componentMap`-проп vs. глобальный
`registerEngineComponent(id, component)`) — реестр на сервере не предполагает конкретный
фронтенд-стек. Ниже — сам клиентский скелет: компонент, который монтирует
`VaulterDocumentNode`, получает один проп `handler` типа `NodePresentation` (экспортируется
`@vaulter/vue`), несущий вашу карту `viewer.props` без изменений.

```vue
<!-- AppMyEditor.vue — компонент, зарегистрированный для id обработчика "my-editor" -->
<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentNodeEditorProps } from '@vaulter/vue'

// `VaulterDocumentNode` биндит эту форму (`DocumentNodeEditorProps`) — `content`/`onChange`
// типизированы под TipTap в экспортируемом интерфейсе (встроенный редактор), поэтому
// не-rich-text-обработчик сужает `handler.ui.props` до своей формы, не полагаясь на `content`.
// `handler.ui.props` — то, что вернул ваш PHP-обработчик из viewer() (шаг 1/3) — произвольная,
// решаемая обработчиком карта; контракт её не валидирует.
interface MyEditorProps {
  format: string
  extensions: string[]
}

const props = defineProps<DocumentNodeEditorProps>()

const editorProps = computed(() => props.handler.ui.props as unknown as MyEditorProps)
</script>

<template>
  <div class="app-my-editor">
    <!-- Читайте editorProps.format / editorProps.extensions здесь; вызывайте onChange(next)
         на каждое локальное изменение, если не readonly. -->
  </div>
</template>
```

Зарегистрируйте один раз при старте, так же как и [любой компонент-обработчик](./document-handlers.md#mounting-the-resolved-handler-in-vue):

```ts
// main.ts
import { registerEngineComponent } from '@vaulter/vue'
import AppMyEditor from './AppMyEditor.vue'

registerEngineComponent('my-editor', AppMyEditor)
```

Отдельный фронтенд-пакет не требуется (D8, VLT-CANON P1.1): сторонний движок поставляется как
обычный код хост-приложения — файл `.vue` плюс один вызов `registerEngineComponent` — так же,
как сам `@vaulter/vue` бандлит свой редактор TipTap, а не публикует его отдельно.
Отдельный пакет `@vaulter/vue-engine-<name>` вводится позже, лениво, только когда появится
реальный внешний потребитель конкретно этого движка.

## Чек-лист

- [ ] `key()` — стабильная, уникальная строка, никогда не переименовывается после релиза (H.5:
      обработчик — это SemVer-замороженная публичная поверхность с момента релиза).
- [ ] Обработчик обслуживает файлы; редактируемое структурированное содержимое — тип документа, а
      не обработчик.
- [ ] `priority()` отражает специфичность совпадения относительно известных конфликтующих
      обработчиков на том же расширении/mime.
- [ ] Форма `viewer.props` задокументирована для вашего клиентского кода — не предполагается, что
      она совпадает с любым другим viewer'ом.
- [ ] Если оборачиваете SDK с двойной лицензией, граница free/commercial-импортов обеспечена, а
      не просто держится в памяти.
