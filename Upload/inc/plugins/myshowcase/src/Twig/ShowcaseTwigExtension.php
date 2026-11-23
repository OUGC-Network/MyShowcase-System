<?php

declare(strict_types=1);

namespace MyShowcase\Twig;

use MyShowcase\Service\TemplateSelectionRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Resolves @ext.myshowcase includes through the effective template package (TP-D1 B). */
final class ShowcaseTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('showcase_template', TemplateSelectionRegistry::resolveTwigTemplateName(...)),
        ];
    }
}