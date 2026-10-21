<?php

declare(strict_types=1);

namespace Phycode\Llm\Llama;

final readonly class LlamaProps
{
    public function __construct(
        public ?int $contextWindow,
        public ?string $modelPath,
        public string $build,
        public bool $router,
        public ?string $chatTemplate,
        public ?bool $tools,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $settings = is_array($data['default_generation_settings'] ?? null) ? $data['default_generation_settings'] : [];
        $caps = is_array($data['chat_template_caps'] ?? null) ? $data['chat_template_caps'] : [];
        $context = $settings['n_ctx'] ?? null;
        $path = $data['model_path'] ?? null;
        $template = $data['chat_template'] ?? null;

        return new self(
            contextWindow: is_int($context) && $context > 0 ? $context : null,
            modelPath: is_string($path) && $path !== '' && $path !== 'none' ? $path : null,
            build: is_string($data['build_info'] ?? null) ? $data['build_info'] : '',
            router: ($data['role'] ?? null) === 'router',
            chatTemplate: is_string($template) && $template !== '' ? $template : null,
            tools: is_bool($caps['supports_tools'] ?? null) ? $caps['supports_tools'] : null,
        );
    }
}
