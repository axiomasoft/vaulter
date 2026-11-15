---
title: Написание движка/обработчика
description: Чек-лист для стороннего файлового обработчика с нуля — контракт, инварианты capability-политики, асимметрия payload'а и где заканчивается шов.
outline: deep
---

# Написание движка/обработчика

[Обработчики документов](/ru/extending/file-handlers) показывает контракт и разобранный пример CSV.
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
[Обработчики документов → разобранный пример CSV](/ru/extending/file-handlers#добавление-нового-формата-—-разобранный-пример).
Остальная часть гайда предполагает **редактируемый** обработчик — более богатый случай.

<!-- snippet: host -->

```php
<?php

declare(strict_types=1);

namespace App\Vaulter\Handlers;

use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\ValueObjects\Handlers\FileDescriptor;
use Vaulter\Drive\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\Drive\ValueObjects\Handlers\ViewerDescriptor;

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

Прямая реализация интерфейса — всегда достаточна. `Vaulter\Drive\Handlers\AbstractFileHandler` — ОПЦИОНАЛЬНАЯ DX-база, которая дефолтит `extensions()` в `[]` для
mime-only обработчика — экономит один метод, не больше. Встроенные обработчики её не
наследуют — они остаются `final` (H.5).

## 2. Инварианты capability-политики (прочитать до релиза)

`ContentCapabilities` — это **аддитивное, открытое множество осей**. Три правила
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
   [алгоритм резолва](/ru/extending/file-handlers#алгоритм-подбора) реестра —
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

<!-- snippet: host -->

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
[Обработчики документов → Зарегистрировать](/ru/extending/file-handlers#_2-регистрируем):
список FQCN в конфиге (cache-safe), `Vaulter::handlers()->register()` (рантайм-шов) или тегированный
биндинг `'vaulter.file_handlers'` (канал, которым core регистрирует свои базовые обработчики).

## 7. Монтирование на клиенте

Как `presentation.viewer.key` попадает в компонент — см.
[Обработчики документов → Монтирование подобранного обработчика во Vue](/ru/extending/file-handlers#монтирование-подобранного-обработчика-во-vue):
реестр на сервере не предполагает конкретный фронтенд-стек. Дальше — сам клиентский каркас:
компонент, зарегистрированный под вашим ключом вьюера, получает `node`, `readonly` и каждую
запись карты `viewer.props`, которую вернул ваш PHP-обработчик, без изменений.

<!-- snippet: host -->

```vue
<!-- AppMyEditor.vue — компонент, зарегистрированный под ключом вьюера "acme/my-editor" -->
<script setup lang="ts">
import { computed } from 'vue'
import type { Node } from '@vaulter/vue'

// `viewer.props` — это то, что вернул viewer() вашего PHP-обработчика (шаг 1/3): произвольная
// карта, форму решает обработчик; контракт её не валидирует. Серверные props идут первыми,
// поэтому не могут перекрыть `node` или `readonly`.
const props = defineProps<{
  node: Node
  readonly: boolean
  format?: string
  extensions?: string[]
}>()

const editable = computed(() => !props.readonly && props.node.abilities.edit)
</script>

<template>
  <div class="app-my-editor">
    <!-- Читайте props.format / props.extensions здесь; не принимайте правки, пока `editable` не true. -->
  </div>
</template>
```

Зарегистрируйте один раз при старте — так же, как [вьюер любого обработчика](/ru/extending/file-handlers#монтирование-подобранного-обработчика-во-vue):

<!-- snippet: host -->

```ts
// main.ts
import { createApp } from 'vue'
import { VaulterPlugin } from '@vaulter/vue'
import App from './App.vue'
import AppMyEditor from './AppMyEditor.vue'

createApp(App)
  .use(VaulterPlugin, { viewers: { 'acme/my-editor': AppMyEditor } })
  .mount('#app')
```

Отдельный фронтенд-пакет не нужен: сторонний движок поставляется обычным кодом
хост-приложения — файл `.vue` плюс одна запись в опции плагина `viewers` — так же, как сам
`@vaulter/vue` вкладывает свою TipTap-оболочку, а не публикует её отдельно. Выделенный пакет
`@vaulter/vue-engine-<name>` появится позже, лениво, только когда появится реальный внешний
потребитель именно этого движка.

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

Типы документов возвращают `DocumentViewer` и собственные `DocumentFeatures` из
`Vaulter\Documents\Data\Types`. `DocumentPresenter` преобразует их в VO Drive;
поля REST не меняются. `collaborative` вычисляется по доступной для записи схеме,
`SupportsCollaboration` и активному драйверу коллаборации. Без драйвера rich text
остаётся локально редактируемым. `DocumentFeatures::readOnly()` запрещает изменение
и сохраняет предпросмотр. Тип без клиентского редактора использует `vaulter/unsupported`.

Viewer props описывают представление, а не авторизацию. Выбирайте realtime-транспорт по protocol/version нейтральной сессии, проверяйте options и endpoint до подключения. Локальное редактирование не требует realtime-драйвера. См. [collaboration protocols](/ru/extending/collaboration-protocols).
