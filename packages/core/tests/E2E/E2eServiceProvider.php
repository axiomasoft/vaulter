<?php

declare(strict_types=1);

namespace Vaulter\Tests\E2E;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Vaulter\Documents\Actions\CreateDocumentNodeAction;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Data\CreateDocumentNodeData;
use Vaulter\Documents\Models\NodeDocumentVersion;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Facades\Vaulter;
use Vaulter\Support\Config;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;
use Vaulter\ValueObjects\Documents\TipTapContent;

/**
 * Провайдер e2e-стенда realtime-коллаборации (P1.1 мастер-плана).
 *
 * Поднимается ТОЛЬКО под `testbench serve` (см. testbench.yaml) — боевой пакет его не
 * грузит. Делает три вещи:
 *   1. Включает фичи document_versions И collaboration (P3.1 — realtime теперь отдельный
 *      опт-ин поверх document_versions) и database-драйвер прав, фиксирует bigint-id —
 *      чтобы маршруты collab/token + вебхуки collab/{authenticate,store} были смонтированы,
 *      а гранты реально резолвились (register() — раньше boot, где гейтятся маршруты).
 *   2. Подмешивает тестовую аутентификацию (E2eActingAs) в группу vaulter-маршрутов.
 *   3. Отдаёт GET /e2e/setup — идемпотентно НЕ нужно: каждый вызов создаёт свежее
 *      хранилище + документ-узел + гранты (writer=1, viewer=2) и возвращает node_id/room,
 *      по которому два браузера заходят в одну комнату.
 */
final class E2eServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Должно встать ДО boot VaulterServiceProvider, где маршруты гейтятся по фиче.
        config([
            'vaulter.features.document_versions' => true,
            // P3.1 (F-P1.6-01, D20): the realtime surface now needs its OWN opt-in
            // ON TOP of document_versions — the e2e stand exercises collab/token +
            // the webhooks, so it must flip this too (default false).
            'vaulter.features.collaboration' => true,
            'vaulter.drivers.permission' => 'database',
            'vaulter.ids.default' => 'bigint',
            'vaulter.middleware' => ['api', E2eActingAs::class],
        ]);

        // Вебхук collab/authenticate несёт только JWT sub (id) — материализуем по нему
        // TestUser (морф совпадает с субъектом грантов), чтобы AccessResolver отработал.
        // Боевой хост биндит свой HostUserResolver на свою таблицу пользователей.
        $this->app->bind(HostUserResolver::class, static fn (): FakeHostUserResolver => new FakeHostUserResolver);

        // DB-связка из env, не завязана на базовый database.php. По умолчанию —
        // персистентный SQLite-файл (host dev-loop, миграции пакета sqlite-совместимы).
        // В docker-стенде DB_CONNECTION=pgsql включает Postgres-контейнер.
        $connection = env('DB_CONNECTION', 'sqlite');
        config(['database.default' => $connection]);

        if ($connection === 'pgsql') {
            config(['database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'vaulter_e2e'),
                'username' => env('DB_USERNAME', 'vaulter'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]]);
        } else {
            // Laravel sqlite не создаёт файл сам — гарантируем существование, чтобы
            // `testbench migrate` и `serve` били в один персистентный файл без ручного touch.
            $database = env('DB_DATABASE', sys_get_temp_dir().'/vaulter-e2e.sqlite');
            if (!is_file($database)) {
                @touch($database);
            }

            config(['database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }
    }

    public function boot(): void
    {
        Route::get('/e2e/health', static fn () => response()->json(['status' => 'ok']));

        // Проверка персистентности снапшота: вернуть размер последнего yjs_state узла,
        // чтобы e2e-тест убедился, что debounced-снапшот хаба долетел до БД.
        Route::get('/e2e/snapshot/{node}', static function (int|string $node) {
            $latest = NodeDocumentVersion::query()
                ->where('node_id', $node)
                ->orderByDesc('version_no')
                ->first();

            return response()->json([
                'node_id' => $node,
                'version_no' => $latest?->version_no,
                'yjs_state_size' => $latest?->yjs_state_size,
                'has_state' => $latest !== null
                    && $latest->yjs_state_size !== null
                    && $latest->yjs_state_size > 0,
            ]);
        });

        // Создаёт сцену для одного прогона e2e и возвращает её координаты.
        Route::get('/e2e/setup', static function () {
            $host = DriveTestHelpers::host(random_int(1, 2_000_000_000));
            $drive = Vaulter::for($host);
            $storage = $drive->storage();

            $content = TipTapContent::fromArray([
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Hello from setup'],
                        ],
                    ],
                ],
            ]);

            $node = app(CreateDocumentNodeAction::class)->execute(new CreateDocumentNodeData(
                storage: $storage,
                name: 'E2E Spec',
                content: $content,
                parent: $drive->root(),
            ));

            AccessTestHelpers::grant($node, GrantLevel::Writer, subjectId: 1);
            AccessTestHelpers::grant($node, GrantLevel::Reader, subjectId: 2);

            return response()->json([
                'node_id' => $node->getKey(),
                'writer_user' => 1,
                'viewer_user' => 2,
                'room' => Config::collaborationRoom($node->getKey()),
            ]);
        });
    }
}
