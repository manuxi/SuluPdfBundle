<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Controller;

use Manuxi\SuluPdfBundle\Service\PdfRenderer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /pdf/{resourceKey}/{id}?locale=de - the download. 404 unless the profile of the resource key allows a PDF for it.
 */
final class PdfController
{
    public function __construct(private readonly PdfRenderer $renderer)
    {
    }

    public function __invoke(string $resourceKey, string $id, Request $request): Response
    {
        $pdf = $this->renderer->render($resourceKey, $id, $request);

        return new Response($pdf->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->filename, $pdf->filename),
        ]);
    }
}
