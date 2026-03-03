<?php

namespace App\Controller\Back;

use App\Entity\User; // Assuming you have a User entity
use App\Repository\MatiereRepository;
use App\Repository\UserRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[Route('/admin/certificate', name: 'admin_certificate_')]
class CertificateController extends AbstractController
{
    // --- 1. DOWNLOAD ACTION ---
    #[Route('/download/{userId}/{subjectId}', name: 'download')]
    public function download($userId, $subjectId, UserRepository $userRepo, MatiereRepository $matRepo): Response
    {
        // 1. Fetch Real Data
        $user = $userRepo->find($userId);
        $matiere = $matRepo->find($subjectId);

        if (!$user || !$matiere) {
            throw $this->createNotFoundException('User or Subject not found');
        }

        // 2. Generate PDF Content
        $dompdf = $this->generatePdf($user, $matiere);

        // 3. Stream Download
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Certificate-' . $user->getNom() . '.pdf"',
        ]);
    }

    // --- 2. EMAIL ACTION ---
    #[Route('/send/{userId}/{subjectId}', name: 'email')]
    public function sendEmail($userId, $subjectId, UserRepository $userRepo, MatiereRepository $matRepo, MailerInterface $mailer): Response
    {
        $user = $userRepo->find($userId);
        $matiere = $matRepo->find($subjectId);

        if (!$user || !$matiere) {
            $this->addFlash('error', 'User or Subject not found');
            return $this->redirectToRoute('admin_dashboard'); // Change to your actual admin dashboard route
        }

        // 1. Generate PDF Content
        $dompdf = $this->generatePdf($user, $matiere);
        $pdfOutput = $dompdf->output();

        // 2. Create Email
        $email = (new Email())
            ->from('admin@fahamni.com')
            ->to($user->getEmail())
            ->subject('Congratulations! Here is your Certificate')
            ->text('Hello ' . $user->getPrenom() . ', attached is your certificate for ' . $matiere->getTitre())
            ->attach($pdfOutput, 'Certificate.pdf', 'application/pdf');

        // 3. Send
        $mailer->send($email);

        $this->addFlash('success', 'Certificate sent to ' . $user->getEmail());

        return $this->redirectToRoute('admin_dashboard'); // Redirect back to admin list
    }

    // --- PRIVATE HELPER TO GENERATE PDF ---
    private function generatePdf($user, $matiere): Dompdf
    {
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($pdfOptions);

        $html = $this->renderView('back/certificate/pdf_design.html.twig', [
            'user' => $user,
            'matiere' => $matiere,
            'date' => new \DateTime(),
            'certificateId' => strtoupper(uniqid('CERT-'))
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf;
    }
}