<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Form\FormLayout;
use App\Service\TenantContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The sample data the header and footer editors show their preview with. */
final class DocLayoutExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('doc_layout_sample', $this->sample(...))];
    }

    /**
     * What each field of a header or footer says in the preview: the centre's own data and logos when a centre is selected, an example
     * otherwise.
     *
     * @return array<string, string> token => text; `logo` and `logo2` are the addresses of the images (empty when there are none to show)
     */
    public function sample(?string $code = null, ?string $title = null): array
    {
        $centre = $this->tenant->getSelectedCentre();
        $today  = $this->clock->now()->format('d/m/Y');

        $sample = [
            'centro'           => $centre?->getName() ?? 'IES Nombre del centro',
            'localidad'        => $centre?->getCity() ?? 'Localidad',
            'codigo'           => $code !== null && $code !== '' ? $code : 'MD850211',
            'titulo'           => $title !== null && $title !== '' ? $title : 'Título del modelo',
            'revision'         => '2',
            'fecha_revision'   => $today,
            'fecha_aprobacion' => $today,
            'pagina'           => '1',
            'paginas'          => '3',
            'logo'             => '',
            'logo2'            => '',
        ];
        if ($centre !== null) {
            $sample['logo']  = $this->urls->generate('app_centre_logo', ['slot' => '1']);
            $sample['logo2'] = $this->urls->generate('app_centre_logo', ['slot' => '2']);
        }

        // Every token has an entry (the preview replaces what it knows and leaves the rest).
        return array_intersect_key($sample, array_flip(FormLayout::TOKENS));
    }
}
