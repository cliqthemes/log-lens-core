<?php
declare(strict_types=1);

namespace LogLens\Http\Endpoints;

use LogLens\Http\HttpError;
use LogLens\Http\LogLensRequest;
use LogLens\Http\LogLensResponse;
use LogLens\Services\ModuleService;
use PDO;

/** Modules CRUD — extracted from `ApiController`. */
final class ModuleEndpoints
{
    private readonly ModuleService $modules;

    public function __construct(PDO $db)
    {
        $this->modules = new ModuleService($db);
    }

    public function modules(LogLensRequest $request): LogLensResponse
    {
        if ($request->method === 'GET') {
            return LogLensResponse::json(['data' => $this->modules->all()]);
        }
        if ($request->method === 'POST') {
            $data = $request->body;
            return LogLensResponse::json($this->modules->create(
                (string) ($data['name'] ?? ''),
                (string) ($data['slug'] ?? ''),
                (string) ($data['color'] ?? '#6366f1'),
            ), 201);
        }
        throw new HttpError(405, 'GET or POST required.');
    }
}
