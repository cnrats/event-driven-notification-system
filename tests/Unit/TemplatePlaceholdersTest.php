<?php

declare(strict_types=1);

use App\Modules\Templates\TemplateRenderer;

it('finds placeholders with or without inner spaces', function (): void {
    expect(TemplateRenderer::placeholders('Hi {{ name }}, your code is {{code}}. Bye {{ name }}!'))
        ->toBe(['name', 'code']);
});

it('ignores text that only looks like a placeholder', function (): void {
    expect(TemplateRenderer::placeholders('Price: { 10 } {{ 1abc }} {{}}'))->toBe([]);
});
