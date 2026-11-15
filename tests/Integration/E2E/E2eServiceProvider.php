<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\E2E;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Collaboration\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * Провайдер e2e-стенда realtime-коллаборации.
 *
 * Поднимается ТОЛЬКО под `testbench serve` (см. testbench.yaml) — боевой пакет его не
 * грузит. Делает три вещи:
 *   1. Включает фичи document_versions И collaboration (realtime теперь отдельный
 *      опт-ин поверх document_versions) и database-драйвер прав, фиксирует bigint-id —
 *      чтобы маршруты collaboration/session + хаб collaboration/hub/* были смонтированы,
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
            // The collaboration module is opt-in — the e2e stand exercises the collaboration session +
            // the hub endpoints, so it must switch it on (default off).
            'vaulter.modules.collaboration' => true,
            'vaulter-drive.access.permission_driver' => 'database',
            'vaulter.ids.strategy' => 'bigint',
            'vaulter.routes.middleware.authenticated' => ['api', E2eActingAs::class],
        ]);

        // Эндпоинт collaboration/hub/authenticate несёт только JWT sub (id) — материализуем по нему
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

        // Проверка персистентности снапшота: вернуть размер последнего collab_state узла,
        // чтобы e2e-тест убедился, что debounced-снапшот хаба долетел до БД.
        Route::get('/e2e/snapshot/{node}', static function (int|string $node) {
            $latest = DocumentRevision::query()
                ->where('node_id', $node)
                ->orderByDesc('number')
                ->first();

            return response()->json([
                'node_id' => $node,
                'number' => $latest?->number,
                'collab_state_size' => $latest?->collab_state_size,
                'has_state' => $latest !== null
                    && $latest->collab_state_size !== null
                    && $latest->collab_state_size > 0,
            ]);
        });

        // Создаёт сцену для одного прогона e2e и возвращает её координаты.
        Route::get('/e2e/setup', static function () {
            $host = DriveTestHelpers::host(random_int(1, 2_000_000_000));
            $drive = Vaulter::drive($host)->asSystem('test fixture');
            $storage = $drive->model();

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

            $node = app(CreateDocumentAction::class)->execute(DriveContext::system($storage, 'e2e fixture'), new CreateDocumentInput(
                storage: $storage,
                name: 'E2E Spec',
                content: $content,
                parent: DriveTestHelpers::model($drive->root()),
            ));

            AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 1);
            AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 2);
            $document = Document::query()->where('node_id', $node->getKey())->firstOrFail();

            return response()->json([
                'node_id' => $node->getKey(),
                'drive_id' => $node->drive_id,
                'first_revision_id' => $document->current_revision_id,
                'writer_user' => 1,
                'viewer_user' => 2,
                'room' => app(CollaborationConfig::class)->room($node->drive_id, $node->getKey(), $document->collab_epoch),
            ]);
        });

        // The working state as stored — what the restore-epoch e2e asserts against.
        Route::get('/e2e/document/{node}', static function (int|string $node) {
            $document = Document::query()->where('node_id', $node)->firstOrFail();

            return response()->json([
                'node_id' => $node,
                'epoch' => $document->collab_epoch,
                'state_version' => $document->state_version,
                'collab_state_size' => $document->collab_state_size,
                'content' => $document->content,
            ]);
        });
    }
}
