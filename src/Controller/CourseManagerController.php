<?php

namespace App\Controller;

use App\Entity\Chapter;
use App\Entity\Matiere;
use App\Entity\Section;
use App\Entity\Resource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/admin/course')]
class CourseManagerController extends AbstractController
{
    // 1. MAIN PAGE - VIEW THE COURSE STRUCTURE
    #[Route('/{id}/manage', name: 'app_course_manage')]
    public function manage(Matiere $matiere): Response
    {
        return $this->render('back/course/manage.html.twig', [
            'matiere' => $matiere,
        ]);
    }

    // 2. CHAPTER ACTIONS
    #[Route('/{id}/add-chapter', name: 'app_chapter_add', methods: ['POST'])]
    public function addChapter(Request $request, Matiere $matiere, EntityManagerInterface $em): Response
    {
        $chapter = new Chapter();
        $chapter->setTitre($request->request->get('title'));
        $chapter->setMatiere($matiere);
        
        $em->persist($chapter);
        $em->flush();
        
        return $this->redirectToRoute('app_course_manage', ['id' => $matiere->getId()]);
    }

    #[Route('/chapter/{id}/delete', name: 'app_chapter_delete')]
    public function deleteChapter(Chapter $chapter, EntityManagerInterface $em): Response
    {
        $matiereId = $chapter->getMatiere()->getId();
        $em->remove($chapter);
        $em->flush();
        return $this->redirectToRoute('app_course_manage', ['id' => $matiereId]);
    }

    // 3. SECTION ACTIONS
    #[Route('/chapter/{id}/add-section', name: 'app_section_add', methods: ['POST'])]
    public function addSection(Request $request, Chapter $chapter, EntityManagerInterface $em): Response
    {
        $section = new Section();
        $section->setTitre($request->request->get('title'));
        $section->setChapter($chapter);
        
        $em->persist($section);
        $em->flush();

        return $this->redirectToRoute('app_course_manage', ['id' => $chapter->getMatiere()->getId()]);
    }

    #[Route('/section/{id}/delete', name: 'app_section_delete')]
    public function deleteSection(Section $section, EntityManagerInterface $em): Response
    {
        $matiereId = $section->getChapter()->getMatiere()->getId();
        $em->remove($section);
        $em->flush();
        return $this->redirectToRoute('app_course_manage', ['id' => $matiereId]);
    }

    // 4. RESOURCE ACTIONS (UPLOAD LOGIC)
    #[Route('/section/{id}/add-resource', name: 'app_resource_add', methods: ['POST'])]
    public function addResource(Request $request, Section $section, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $resource = new Resource();
        $resource->setTitre($request->request->get('title'));
        $resource->setType($request->request->get('type'));
        $resource->setSection($section);

        $type = $request->request->get('type'); // video, pdf, or link
        $uploadedFile = $request->files->get('file_upload');
        $link = $request->request->get('link_url');

        // Logic for File Uploads (Video or PDF)
        if (($type === 'video' || $type === 'pdf') && $uploadedFile) {
            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $newFilename = $safeFilename.'-'.uniqid().'.'.$uploadedFile->guessExtension();

            try {
                // Files are saved to /public/uploads/course_content
                $uploadedFile->move(
                    $this->getParameter('kernel.project_dir').'/public/uploads/course_content',
                    $newFilename
                );
                $resource->setFilePath($newFilename);
            } catch (FileException $e) {
                // handle exception if needed
            }
        } 
        // Logic for Links
        elseif ($type === 'link') {
            $resource->setLink($link);
        }

        $em->persist($resource);
        $em->flush();

        return $this->redirectToRoute('app_course_manage', ['id' => $section->getChapter()->getMatiere()->getId()]);
    }

    #[Route('/resource/{id}/delete', name: 'app_resource_delete')]
    public function deleteResource(Resource $resource, EntityManagerInterface $em): Response
    {
        $matiereId = $resource->getSection()->getChapter()->getMatiere()->getId();
        
        // Optional: Delete the actual file from server to save space
        if ($resource->getFilePath()) {
            $path = $this->getParameter('kernel.project_dir').'/public/uploads/course_content/'.$resource->getFilePath();
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $em->remove($resource);
        $em->flush();

        return $this->redirectToRoute('app_course_manage', ['id' => $matiereId]);
    }
}