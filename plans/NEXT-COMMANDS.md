# Следующие команды

Запускать по порядку. Публикация пакетов, split-репозитории, npm, Packagist,
GHCR, live/load campaigns и mutation в эти команды не входят.

## 1. Завершить текущие локальные срезы

**Модель:** GPT-6 Astra, `xhigh`.

```bash
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access 'Проверь текущее состояние Vaulter после архивации старых планов. Установи происхождение каждого оставшегося изменения и сохрани чужую работу. Доведи уже начатые кодовые срезы до отдельных scoped commits с подходящими offline package checks. Release pipeline только проверь статически и локально: ничего не публикуй, не создавай split repositories, не обращайся к npm, Packagist или GHCR и не требуй токены. AzGuard является публичным репозиторием: при необходимости сверяй последний commit GitHub без токена. Не запускай live, load campaigns и mutation. Не создавай и не возвращай активные планы. В конце рабочее дерево должно быть чистым; перечисли коммиты, проверки и сохранённые ограничения.'
```

## 2. Проверить чистоту архива и отсутствие старой очереди

**Модель:** GPT-6 Astra, `high`.

```bash
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Проведи финальную read-only reconciliation планового слоя Vaulter после архивации. Убедись, что в plans вне archive остался только NEXT-COMMANDS.md, старые handoff и Next не образуют текущую очередь, VLT-MUT отложен до API freeze, а release, live и mutation prerequisites не объявлены выполненными. Проверь ссылки и git status. Ничего не публикуй, не запускай live, load campaigns, mutation или CI dispatch, не создавай новые планы и листы. Результат дай только в чат.'
```

## 3. Начать новый аудит после стабилизации API

**Модель:** GPT-6 Astra, `high`.

```bash
codex -C /home/vostrikov/projects/packages/vaulter -m gpt-6-astra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design new VLT-FRESH-AUDIT — новый аудит текущей кодовой базы Vaulter с нуля после закрытия исторических планов; проверить публичный API, package boundaries, security, correctness, test gaps и release readiness; не импортировать старые Todo без повторного доказательства актуальности'
```
