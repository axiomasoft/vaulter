---
title: Инспекторы загрузок
---

# Инспекторы загрузок

Инспектор проверяет загрузку до её принятия — правило содержимого, которое нужно вашему приложению
сверх размера и MIME-типа. Он отклоняет, бросая доменное исключение; обычный возврат принимает.

<!-- snippet: signature Vaulter\Drive\Contracts\Uploads\UploadInspector -->

```php
interface UploadInspector
{
    // Стабильный идентификатор инспектора.
    public function key(): string;

    // Меньшее значение выполняется раньше.
    public function priority(): int;

    public function inspect(UploadFacts $facts, UploadPolicy $policy, DriveContext $context): void;
}
```

Инспекторы работают с фактами, собранными Vaulter, а не с вашим потоком. Сканирование на вредоносное
ПО — отдельный устойчивый механизм: см. [Эксплуатация](/ru/guides/operations) и [Загрузки](/ru/guides/uploads).
