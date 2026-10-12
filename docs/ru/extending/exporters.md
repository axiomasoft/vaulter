---
title: Экспортеры
---

# Экспортеры

Экспортер превращает содержимое документа в формат файла (`html`, `markdown`, ваш). Создайте заготовку:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:exporter Pdf
```

Экспортер реализует `DocumentExporter`. Он получает только декодированное встроенное содержимое и
не должен читать хранилище, блобы или URL.

<!-- snippet: signature Vaulter\Documents\Contracts\DocumentExporter -->

```php
interface DocumentExporter
{
    // 'vendor/name', например 'vaulter/html'.
    public function key(): string;

    // Сегмент URL, который запрашивают клиенты, например 'html'.
    public function format(): string;

    public function mimeType(): string;

    // Расширение файла без точки.
    public function extension(): string;

    // Ключи типов документов, которые экспортер умеет отображать.
    public function documentTypes(): array;

    public function export(DocumentContent $content, DocumentType $type): string;
}
```

Зарегистрируйте его через `Documents::exporters()`. Клиенты должны терпимо относиться к неизвестному
формату. О встроенных экспортерах — [Экспорт](/ru/guides/export).
