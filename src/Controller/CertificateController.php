<?php

namespace App\Controller;

use App\Repository\MatiereRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CertificateController extends AbstractController
{
    #[Route('/certificate/{id}', name: 'app_certificate_download')]
    public function index($id, MatiereRepository $matiereRepository): Response
    {
        // 1. Get the Subject
        $matiere = $matiereRepository->find($id);

        if (!$matiere) {
            throw $this->createNotFoundException('Subject not found');
        }

        // 2. Get the User (WITH PROTOTYPE BYPASS)
        $user = $this->getUser();

        // --- START BYPASS: If no user is logged in, create a Fake Student ---
       if (!$user) {
         $user = new \stdClass(); // Creates an empty object
          $user->nom = "Test";     // Fake Last Name
         $user->prenom = "Student"; // Fake First Name
         $user->email = "student@fahamni.com";
      }
        // --- END BYPASS --- 

        // 3. Configure DomPDF
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($pdfOptions);

        // 4. Render the HTML Template
        $html = $this->renderView('certificate/index.html.twig', [
            'user' => $user,
            'matiere' => $matiere,
            'date' => new \DateTime(),
            'certificateId' => strtoupper(uniqid('CERT-'))
        ]);

        // 5. Load HTML to DomPDF
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // 6. Output PDF
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="certificate_' . $matiere->getId() . '.pdf"',
        ]);
    }
}