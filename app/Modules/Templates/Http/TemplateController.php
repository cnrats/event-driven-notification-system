<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Templates\Models\Template;
use App\Modules\Templates\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class TemplateController
{
    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, channel?: string|null, body: string} $data */
        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{1,99}$/', Rule::unique('templates', 'name')],
            'channel' => ['nullable', 'string', Rule::in(Channel::values())],
            'body' => ['required', 'string', 'max:100000'],
        ])->validate();

        $template = Template::query()->create([
            'name' => $data['name'],
            'channel' => $data['channel'] ?? null,
            'body' => $data['body'],
        ]);

        return response()->json(['data' => $this->present($template)], 201);
    }

    public function index(): JsonResponse
    {
        $templates = Template::query()->orderBy('name')->get();

        return response()->json([
            'data' => array_values($templates->map($this->present(...))->all()),
        ]);
    }

    public function show(string $name): JsonResponse
    {
        $template = Template::query()->where('name', $name)->firstOrFail();

        return response()->json(['data' => $this->present($template)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Template $template): array
    {
        return [
            'name' => $template->name,
            'channel' => $template->channel?->value,
            'body' => $template->body,
            'variables' => TemplateRenderer::placeholders($template->body),
            'created_at' => $template->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
