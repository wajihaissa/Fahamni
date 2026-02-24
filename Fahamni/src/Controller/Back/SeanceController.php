<?php

namespace App\Controller\Back;

use App\Entity\Reservation;
use App\Entity\Seance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/seance', name: 'admin_seance_')]
final class SeanceController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $totalSeances = (int) $entityManager->getRepository(Seance::class)->count([]);
        $totalPages = max(1, (int) ceil($totalSeances / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $seances = $entityManager->getRepository(Seance::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.tuteur', 't')->addSelect('t')
            ->orderBy('s.startAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $seanceIds = [];
        foreach ($seances as $seance) {
            if ($seance instanceof Seance && $seance->getId() !== null) {
                $seanceIds[] = (int) $seance->getId();
            }
        }

        $reservationCounts = [];
        if ($seanceIds !== []) {
            $rows = $entityManager->createQueryBuilder()
                ->select('IDENTITY(r.seance) AS seanceId, COUNT(r.id) AS reservationsCount')
                ->from(Reservation::class, 'r')
                ->andWhere('r.seance IN (:seanceIds)')
                ->setParameter('seanceIds', $seanceIds)
                ->groupBy('r.seance')
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $seanceId = (int) ($row['seanceId'] ?? 0);
                if ($seanceId > 0) {
                    $reservationCounts[$seanceId] = (int) ($row['reservationsCount'] ?? 0);
                }
            }
        }

        return $this->render('back/seance/index.html.twig', [
            'seances' => $seances,
            'reservationCounts' => $reservationCounts,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalSeances' => $totalSeances,
        ]);
    }

    #[Route('/{id}/show', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Seance $seance, EntityManagerInterface $entityManager): Response
    {
        $reservations = $entityManager->getRepository(Reservation::class)
            ->createQueryBuilder('r')
            ->leftJoin('r.participant', 'p')->addSelect('p')
            ->andWhere('r.seance = :seance')
            ->setParameter('seance', $seance)
            ->orderBy('r.reservedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('back/seance/show.html.twig', [
            'seance' => $seance,
            'reservations' => $reservations,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Seance $seance, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('delete_seance_' . $seance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('admin_seance_index');
        }

        $entityManager->remove($seance);
        $entityManager->flush();

        $this->addFlash('success', 'Seance supprimee avec succes.');

        return $this->redirectToRoute('admin_seance_index');
    }
}
