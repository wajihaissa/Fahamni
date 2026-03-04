<?php
namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class PDFGeneratorService
{
    public function __construct(
        private Environment $twig
    ) {}

    public function generateCertificatePDF(array $data): string
    {
        // Configure Dompdf
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        
        // Render HTML template
        $html = $this->twig->render('email/certificate_pdf.html.twig', $data);
        
        // Load HTML to Dompdf
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        
        // Return PDF as string
        return $dompdf->output();
    }
}