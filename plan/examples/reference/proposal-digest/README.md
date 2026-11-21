# Векторы digest предложения Office v1

`vectors.json` — общие векторы для PHP, Node и Python. Секция `jcs` содержит текст JSON на входе, ожидаемый текст JCS, байты UTF-8 в hex, SHA-256 и решение профиля digest (`accept`/`reject`). Секция `proposals` содержит допустимые по схеме пары `office.prepare`, каноническую запись, digest и ту же запись с обратным порядком ключей.

| Путь | Что это |
| --- | --- |
| `php/ProposalDigest.php` | Образец `Vaulter\Ai\Core\Protocol\ProposalDigest`: PHP 8.4 stdlib + mbstring, профиль безопасных целых |
| `node/proposal-digest.mjs` | Node stdlib, полный RFC 8785 плюс проверка профиля |
| `../../../checks/verify-fixtures.py` (`jcs*`, `proposal_digest`) | Независимый эталон на Python |
| `php-mmccook/` | Изолированный composer-lock с `mmccook/php-json-canonicalization-scheme` 1.0.0 (тот же commit `cd6d3e76`, что в AgenTools) и `compare.php` с точным набором расхождений |
| `build-vectors.py` | Однократная сборка: ожидаемые тексты JCS записаны вручную, механически получены только hex и sha256 |

Почему эталон независим:

- Он написан на другом языке и с другим парсером: `json.loads` с отказом на дубли и NaN.
- Строки экранируются явно, без `json.dumps`. Ключи сортируются по `str.encode("utf-16-be")`. Числа раскладываются по алгоритму ECMAScript из кратчайших цифр `repr`.
- Python-эталон, PHP и Node проходят два вектора, опубликованных в RFC 8785 (§3.2.2 и §3.2.3). Четыре digest из `office-sdk.json` были получены раньше другим путём и совпадают.

Запуск: `bash plan/checks/sdk/run.sh`.
